import { expect, test } from '@playwright/test';
import { login, register, uniqueEmail } from './helpers.js';

test.describe('authentication', () => {
    test('a guest is sent to login when opening the library', async ({ page }) => {
        await page.goto('/references');
        await expect(page).toHaveURL(/\/login$/);
    });

    test('a new user can register and lands on an empty library', async ({ page }) => {
        await register(page);

        await expect(page.getByText('No references yet')).toBeVisible();
    });

    test('an existing user can log out and back in', async ({ page }) => {
        const email = await register(page);

        // log out via the account dropdown
        await page.getByRole('button', { name: /E2E User/i }).click();
        await page.getByRole('button', { name: 'Log Out' }).click();
        await page.waitForURL('**/login');

        await login(page, email);

        await expect(page.getByText('No references yet')).toBeVisible();
    });

    test('wrong credentials are rejected', async ({ page }) => {
        await page.goto('/login');
        await page.fill('#email', uniqueEmail('nobody'));
        await page.fill('#password', 'wrong-password');
        await page.getByRole('button', { name: /log in/i }).click();

        // stays on the login page with an error
        await expect(page).toHaveURL(/\/login$/);
        await expect(page.getByText(/credentials do not match/i)).toBeVisible();
    });
});
