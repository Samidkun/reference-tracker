/**
 * Small helpers so specs read as user intent, not selector soup.
 *
 * Selector policy: prefer role + accessible name over CSS. Breeze renders its
 * submit buttons through <PrimaryButton>, which emits a bare <button> with NO
 * type attribute — so `button[type="submit"]` matches nothing and the test
 * hangs until timeout. Accessible names are stable regardless of markup.
 */

let counter = 0;

/** A unique email per call so specs never collide on the users table. */
export function uniqueEmail(prefix = 'e2e') {
    counter += 1;
    return `${prefix}-${Date.now()}-${counter}@example.test`;
}

/** Register a brand new account and land on the library. */
export async function register(page, email = uniqueEmail()) {
    await page.goto('/register');
    await page.fill('#name', 'E2E User');
    await page.fill('#email', email);
    await page.fill('#password', 'password123');
    await page.fill('#password_confirmation', 'password123');
    await page.getByRole('button', { name: /register/i }).click();
    await page.waitForURL('**/references');
    return email;
}

/** Log in an existing account. */
export async function login(page, email, password = 'password123') {
    await page.goto('/login');
    await page.fill('#email', email);
    await page.fill('#password', password);
    await page.getByRole('button', { name: /log in/i }).click();
    await page.waitForURL('**/references');
}

/** Fill the reference form. Only the fields passed are touched. */
export async function fillReference(page, { title, authors, year, type } = {}) {
    if (title !== undefined) await page.fill('#title', title);
    if (authors !== undefined) await page.fill('#authors', authors);
    if (year !== undefined) await page.fill('#year', String(year));
    if (type !== undefined) await page.selectOption('#type', type);
}
