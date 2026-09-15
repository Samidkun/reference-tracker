import { execFileSync } from 'node:child_process';
import { existsSync, rmSync } from 'node:fs';
import { join } from 'node:path';

const E2E_DB = process.env.E2E_DB || 'reference_tracker_e2e';

/**
 * Rebuild the E2E database before every run.
 *
 * Why this shape:
 *  - The database is created through PHP/PDO, not the `mariadb` CLI: the CLI
 *    is not guaranteed to be on PATH for the process that runs Playwright,
 *    and a silent failure there produces a confusing 500 from the webserver.
 *  - migrate:fresh (not migrate) so a failed run cannot leave half-written
 *    rows that make the NEXT run fail for unrelated reasons.
 *  - Every step prints its output; a setup failure must be loud.
 */
export default async function globalSetup() {
    // --- 1. kill any stale Vite dev-server marker -----------------------
    // `public/hot` makes Laravel point asset URLs at the Vite dev server.
    // If it exists while no dev server is running, every page loads an empty
    // shell: React never mounts, so no form fields exist and every DOM-based
    // assertion times out with a useless "waiting for locator" message.
    // (This is exactly what happened the first time these tests ran.)
    const hot = join(process.cwd(), 'public', 'hot');
    if (existsSync(hot)) {
        rmSync(hot);
        console.log('[e2e] removed stale public/hot (would break asset loading)');
    }

    // --- 2. make sure a production build exists -------------------------
    // E2E runs against built assets, not the dev server.
    const manifest = join(process.cwd(), 'public', 'build', 'manifest.json');
    if (!existsSync(manifest)) {
        console.log('[e2e] no Vite manifest found - running npm run build');
        execFileSync('npm', ['run', 'build'], { stdio: 'inherit' });
    }

    // --- 3. create the database if missing (idempotent, via PDO) --------
    execFileSync(
        'php',
        [
            '-r',
            `$p = new PDO('mysql:host=127.0.0.1;port=3306', 'root', '');` +
                `$p->exec('CREATE DATABASE IF NOT EXISTS ${E2E_DB} ` +
                `CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');`,
        ],
        { stdio: 'inherit' },
    );

    // --- 4. rebuild the schema ------------------------------------------
    execFileSync('php', ['artisan', 'migrate:fresh', '--force'], {
        stdio: 'inherit',
        env: { ...process.env, DB_DATABASE: E2E_DB },
    });
}
