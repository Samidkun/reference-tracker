import { defineConfig, devices } from '@playwright/test';

// E2E runs against its OWN database, never the dev one. A test that
// deletes every reference should not be able to touch real data.
const E2E_DB = process.env.E2E_DB || 'reference_tracker_e2e';
const PORT = 8124;

// A production rehearsal starts the app itself (see
// scripts/rehearse-production.sh) and points the suite at that instance with
// REHEARSAL_BASE_URL. In that mode Playwright must NOT boot its own dev server,
// or the assertions would run against the loose dev policy instead of the
// strict production one - which is the entire point of the rehearsal.
const EXTERNAL_URL = process.env.REHEARSAL_BASE_URL || '';

export default defineConfig({
    testDir: './tests/e2e',
    fullyParallel: false,   // one shared database
    workers: 1,
    forbidOnly: !!process.env.CI,
    retries: 0,
    reporter: [['list'], ['html', { open: 'never' }]],
    globalSetup: './tests/e2e/global-setup.js',

    use: {
        baseURL: EXTERNAL_URL || `http://127.0.0.1:${PORT}`,
        trace: 'retain-on-failure',
        screenshot: 'only-on-failure',
    },

    projects: [
        { name: 'chromium', use: { ...devices['Desktop Chrome'] } },
    ],

    // Omitted entirely when rehearsing against an already-running instance.
    webServer: EXTERNAL_URL ? undefined : {
        command: `php artisan serve --port=${PORT}`,
        // The login page is the cheapest route that touches the DB-backed
        // session store, so it proves the app is actually usable.
        url: `http://127.0.0.1:${PORT}/login`,
        reuseExistingServer: false,
        timeout: 60_000,
        env: {
            // Overriding only DB_DATABASE is not enough: Laravel reads the
            // rest of the connection from .env, and a stale config cache
            // would point the tests at the dev database.
            DB_CONNECTION: 'mariadb',
            DB_HOST: '127.0.0.1',
            DB_PORT: '3306',
            DB_DATABASE: E2E_DB,
            DB_USERNAME: 'root',
            DB_PASSWORD: '',
        },
    },
});
