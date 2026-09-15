import { expect, test } from '@playwright/test';
import { fillReference, register } from './helpers.js';

/**
 * The single most important E2E test: one account must never see or reach
 * another account's data. Unit tests cover the policy; this covers the whole
 * stack, including the Inertia props that actually reach the browser.
 */
test.describe('user isolation', () => {
    test('one user cannot see another user\'s references', async ({ page, browser }) => {
        // user A creates a reference
        await register(page, 'alice@example.test');
        await page.goto('/references/create');
        await fillReference(page, {
            title: 'ALICE SECRET PAPER',
            authors: 'Alice, A',
            year: 2020,
            type: 'journal',
        });
        await page.getByRole('button', { name: 'Add reference' }).click();
        await page.waitForURL('**/references');
        await expect(page.getByText('ALICE SECRET PAPER')).toBeVisible();

        // user B in a fresh context sees nothing
        const contextB = await browser.newContext();
        const pageB = await contextB.newPage();
        await register(pageB, 'bob@example.test');

        await expect(pageB.getByText('No references yet')).toBeVisible();
        await expect(pageB.getByText('ALICE SECRET PAPER')).toHaveCount(0);

        // and cannot reach it by guessing the id
        const response = await pageB.goto('/references/1');
        expect([403, 404]).toContain(response.status());

        await contextB.close();
    });
});
