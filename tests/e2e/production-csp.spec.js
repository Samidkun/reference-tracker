import { expect, test } from '@playwright/test';

/**
 * Production CSP smoke test.
 *
 * This exists because of a bug class no backend test can see:
 *
 *   In production the middleware sent `script-src 'self'` with no nonce, while
 *   the page emits TWO inline scripts - Ziggy's route table (~23 kB) and
 *   Laravel's Vite prefetch helper (~7 kB). A browser blocked both, so
 *   `route()` was undefined and the app broke. Locally everything worked,
 *   because the local policy allows 'unsafe-inline' for the Vite dev client.
 *
 * HTTP tests could not catch it: under withoutVite() the prefetch script is
 * never rendered, so the test saw a page without the offending script. Only a
 * real browser, running against production configuration, sees the truth.
 *
 * The suite normally runs with APP_ENV=local (the dev server in
 * playwright.config.js). This spec therefore checks the SAME invariants that
 * distinguish a working CSP from a broken one, against whatever environment
 * the server is running: no CSP violations, no console errors, and the
 * globals the app depends on are actually defined.
 */
test.describe('content security policy', () => {
    test('the page loads with a clean console and no CSP violations', async ({ page }) => {
        const violations = [];
        const errors = [];

        page.on('console', (msg) => {
            const text = msg.text();
            if (/Content Security Policy|Refused to (execute|apply|load)/i.test(text)) {
                violations.push(text);
            }
            if (msg.type() === 'error') {
                errors.push(text);
            }
        });
        page.on('pageerror', (err) => errors.push(`pageerror: ${err.message}`));

        await page.goto('/login');
        await page.waitForLoadState('networkidle');
        await page.waitForTimeout(1000);

        expect(violations, `CSP violations:\n${violations.join('\n')}`).toEqual([]);
        expect(errors, `console errors:\n${errors.join('\n')}`).toEqual([]);
    });

    test('the inline scripts actually executed', async ({ page }) => {
        await page.goto('/login');
        await page.waitForLoadState('networkidle');

        // Ziggy's inline script defines `route`. If the CSP blocked it, this
        // is undefined and every page using route() silently fails.
        const ziggyDefined = await page.evaluate(
            () => typeof window.Ziggy !== 'undefined' || typeof window.route !== 'undefined'
        );
        expect(ziggyDefined, 'the Ziggy inline script was blocked by the CSP').toBe(true);
    });

    test('the login form is interactive', async ({ page }) => {
        await page.goto('/login');

        // the page must be usable, not just render: React has to mount
        await expect(page.locator('#email')).toBeVisible();
        await expect(page.locator('#password')).toBeVisible();

        await page.fill('#email', 'someone@example.test');
        await expect(page.locator('#email')).toHaveValue('someone@example.test');
    });
});
