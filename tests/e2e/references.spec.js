import { expect, test } from '@playwright/test';
import { fillReference, register } from './helpers.js';

test.describe('reference library', () => {
    test.beforeEach(async ({ page }) => {
        await register(page);
    });

    test('a user can create, view, edit, and delete a reference', async ({ page }) => {
        // CREATE
        await page.getByRole('link', { name: 'Add reference' }).first().click();
        await page.waitForURL('**/references/create');

        await fillReference(page, {
            title: 'Attention Is All You Need',
            authors: 'Vaswani, Ashish\nShazeer, Noam',
            year: 2017,
            type: 'journal',
        });
        await page.getByRole('button', { name: 'Add reference' }).click();
        await page.waitForURL('**/references');

        const list = page.getByRole('table');
        await expect(list.getByText('Attention Is All You Need')).toBeVisible();
        // two authors render as 'A & B'; 'et al.' is reserved for 3+
        await expect(list.getByText('Vaswani, Ashish & Shazeer, Noam')).toBeVisible();

        // VIEW
        await page.getByRole('link', { name: 'Attention Is All You Need' }).click();
        await page.waitForURL(/\/references\/\d+$/);
        await expect(page.getByText('Shazeer, Noam')).toBeVisible();

        // EDIT
        await page.getByRole('link', { name: 'Edit' }).click();
        await page.waitForURL(/\/references\/\d+\/edit$/);
        await page.fill('#title', 'Attention Is All You Need (revised)');
        await page.getByRole('button', { name: 'Save changes' }).click();
        await page.waitForURL('**/references');

        await expect(list.getByText('Attention Is All You Need (revised)')).toBeVisible();

        // DELETE — an in-page dialog now, not window.confirm
        await page.getByRole('button', { name: 'Delete' }).first().click();
        await expect(page.getByRole('dialog')).toBeVisible();
        await page.getByRole('dialog').getByRole('button', { name: 'Delete' }).click();
        await page.waitForURL('**/references');

        await expect(page.getByText('No references yet')).toBeVisible();
    });

    test('required fields are validated', async ({ page }) => {
        await page.goto('/references/create');

        // submit completely empty
        await page.getByRole('button', { name: 'Add reference' }).click();

        // still on the form, no navigation happened
        await expect(page).toHaveURL(/\/references\/create$/);
        await expect(page.getByText(/title/i).first()).toBeVisible();
    });

    test('search narrows the list', async ({ page }) => {
        for (const [title, year] of [
            ['Deep Learning', 2015],
            ['Quantum Computing', 2020],
        ]) {
            await page.goto('/references/create');
            await fillReference(page, {
                title,
                authors: 'Doe, Jane',
                year,
                type: 'journal',
            });
            await page.getByRole('button', { name: 'Add reference' }).click();
            await page.waitForURL('**/references');
        }

        const list = page.getByRole('table');
        await expect(list.getByText('Deep Learning')).toBeVisible();
        await expect(list.getByText('Quantum Computing')).toBeVisible();

        await page.fill('input[type="search"]', 'quantum');
        await page.getByRole('button', { name: 'Search' }).click();
        await page.waitForURL(/q=quantum/);

        await expect(list.getByText('Quantum Computing')).toBeVisible();
        await expect(list.getByText('Deep Learning')).toHaveCount(0);
    });
});
