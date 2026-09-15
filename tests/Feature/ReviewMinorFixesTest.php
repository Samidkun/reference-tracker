<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Regression tests for the Minor findings from the independent backend review.
 *
 * Each one is written to fail against the pre-fix behaviour; see the comment on
 * each test for what it would have caught.
 */
class ReviewMinorFixesTest extends TestCase
{
    /**
     * M4: the CSP nonce is present and matches the inline script.
     *
     * HONEST SCOPE: this proves the nonce WORKS. It does NOT prove the nonce is
     * request-scoped - the container-key version passed this test too, because
     * within one request both mechanisms produce the same value. Moving it to a
     * request attribute is a robustness fix (no global mutable state, no silent
     * empty fallback), verified by reading the middleware, not by this test.
     */
    public function test_the_csp_nonce_matches_the_inline_script(): void
    {
        $this->app['env'] = 'production';

        $response = $this->get('/login');
        $csp = (string) $response->headers->get('Content-Security-Policy');

        $this->assertMatchesRegularExpression(
            "/script-src 'self' 'nonce-[A-Za-z0-9_-]+' 'strict-dynamic'/",
            $csp,
            'the production CSP must carry a non-empty nonce',
        );

        // The nonce the CSP advertises must be the one the inline script gets,
        // or the browser blocks the script and the app dies in production.
        $nonce = null;
        preg_match("/'nonce-([A-Za-z0-9_-]+)'/", $csp, $m);
        $nonce = $m[1] ?? null;

        $this->assertNotNull($nonce, 'CSP must declare a nonce');
        $this->assertStringContainsString(
            'nonce="'.$nonce.'"',
            $response->getContent(),
            'the inline script must carry the same nonce the CSP advertises',
        );
    }

    /** M4: two requests must not share a nonce. */
    public function test_each_request_gets_a_fresh_nonce(): void
    {
        $this->app['env'] = 'production';

        preg_match(
            "/'nonce-([A-Za-z0-9_-]+)'/",
            (string) $this->get('/login')->headers->get('Content-Security-Policy'),
            $a,
        );
        preg_match(
            "/'nonce-([A-Za-z0-9_-]+)'/",
            (string) $this->get('/login')->headers->get('Content-Security-Policy'),
            $b,
        );

        $this->assertNotSame($a[1] ?? null, $b[1] ?? null, 'nonces must not repeat');
    }

    /** M4: the extra hardening headers are present on every response. */
    public function test_hardening_headers_are_present(): void
    {
        $response = $this->get('/login');

        $this->assertSame('same-origin', $response->headers->get('Cross-Origin-Resource-Policy'));
        $this->assertSame('none', $response->headers->get('X-Permitted-Cross-Domain-Policies'));
        $this->assertSame('same-origin', $response->headers->get('Cross-Origin-Opener-Policy'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertSame('DENY', $response->headers->get('X-Frame-Options'));
    }

    /**
     * M4: the headers must also be on an error response. A 500 that omits them
     * is exactly where framing/sniffing protections matter most.
     */
    public function test_hardening_headers_are_on_error_responses(): void
    {
        $response = $this->get('/definitely-not-a-route');

        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame('same-origin', $response->headers->get('Cross-Origin-Resource-Policy'));
        $this->assertSame('DENY', $response->headers->get('X-Frame-Options'));
    }

    /**
     * M3: the session payload must be encrypted.
     *
     * This loads the config file with SESSION_ENCRYPT unset, so it tests the
     * DEFAULT rather than whatever .env happens to say. Asserting on the booted
     * config value would pass even if the default were still false, because
     * .env sets it explicitly - a test that cannot fail is not a test.
     */
    public function test_session_payload_is_encrypted_by_default(): void
    {
        $saved = $_ENV['SESSION_ENCRYPT'] ?? null;
        $savedServer = $_SERVER['SESSION_ENCRYPT'] ?? null;
        $savedGetenv = getenv('SESSION_ENCRYPT');

        try {
            // env() reads $_ENV, $_SERVER *and* putenv. Clearing only two of
            // the three left the value in place, so this assertion could not
            // fail no matter what the default was.
            unset($_ENV['SESSION_ENCRYPT'], $_SERVER['SESSION_ENCRYPT']);
            putenv('SESSION_ENCRYPT');

            $config = require config_path('session.php');

            $this->assertTrue(
                (bool) $config['encrypt'],
                'the DEFAULT must be true; a captured cookie should not be readable without APP_KEY',
            );
        } finally {
            if ($saved !== null) { $_ENV['SESSION_ENCRYPT'] = $saved; }
            if ($savedServer !== null) { $_SERVER['SESSION_ENCRYPT'] = $savedServer; }
            if ($savedGetenv !== false) { putenv('SESSION_ENCRYPT='.$savedGetenv); }
        }
    }

    /**
     * M3: the Secure flag must be off outside production, or login over
     * http://localhost breaks - the browser never sends a Secure cookie there.
     */
    public function test_secure_cookie_flag_is_off_in_non_production(): void
    {
        $this->assertFalse(
            config('session.secure'),
            'a Secure cookie is not sent over plain http, so it must be off in local/testing',
        );
    }

    /**
     * M3: and ON in production.
     *
     * Evaluating the config file directly, with APP_ENV forced to production,
     * is the only honest way to test this: config is loaded once at boot, so
     * setting $this->app['env'] afterwards cannot change an already-resolved
     * value - and re-typing the expression here would just test itself.
     */
    public function test_secure_cookie_flag_is_on_in_production(): void
    {
        $savedEnv = $_ENV['APP_ENV'] ?? null;
        $savedServer = $_SERVER['APP_ENV'] ?? null;
        $savedSecureEnv = $_ENV['SESSION_SECURE_COOKIE'] ?? null;
        $savedSecureServer = $_SERVER['SESSION_SECURE_COOKIE'] ?? null;

        try {
            $_ENV['APP_ENV'] = $_SERVER['APP_ENV'] = 'production';
            putenv('APP_ENV=production');
            unset($_ENV['SESSION_SECURE_COOKIE'], $_SERVER['SESSION_SECURE_COOKIE']);
            putenv('SESSION_SECURE_COOKIE');

            $config = require config_path('session.php');

            $this->assertTrue(
                (bool) $config['secure'],
                'with APP_ENV=production and no override, the Secure cookie flag must default ON',
            );
        } finally {
            if ($savedEnv === null) { unset($_ENV['APP_ENV']); } else { $_ENV['APP_ENV'] = $savedEnv; }
            if ($savedServer === null) { unset($_SERVER['APP_ENV']); } else { $_SERVER['APP_ENV'] = $savedServer; }
            if ($savedSecureEnv !== null) { $_ENV['SESSION_SECURE_COOKIE'] = $savedSecureEnv; }
            if ($savedSecureServer !== null) { $_SERVER['SESSION_SECURE_COOKIE'] = $savedSecureServer; }
        }
    }

    /**
     * M3: an explicit override must still win, so a deployment behind plain
     * HTTP can opt out deliberately.
     */
    public function test_secure_cookie_flag_honours_an_explicit_override(): void
    {
        $savedEnv = $_ENV['APP_ENV'] ?? null;
        $savedServer = $_SERVER['APP_ENV'] ?? null;
        $savedSecureEnv = $_ENV['SESSION_SECURE_COOKIE'] ?? null;
        $savedSecureServer = $_SERVER['SESSION_SECURE_COOKIE'] ?? null;

        try {
            $_ENV['APP_ENV'] = $_SERVER['APP_ENV'] = 'production';
            putenv('APP_ENV=production');
            $_ENV['SESSION_SECURE_COOKIE'] = $_SERVER['SESSION_SECURE_COOKIE'] = 'false';
            putenv('SESSION_SECURE_COOKIE=false');

            $config = require config_path('session.php');

            $this->assertFalse((bool) $config['secure'], 'an explicit false must win');
        } finally {
            if ($savedEnv === null) { unset($_ENV['APP_ENV']); } else { $_ENV['APP_ENV'] = $savedEnv; }
            if ($savedServer === null) { unset($_SERVER['APP_ENV']); } else { $_SERVER['APP_ENV'] = $savedServer; }
            if ($savedSecureEnv === null) { unset($_ENV['SESSION_SECURE_COOKIE']); } else { $_ENV['SESSION_SECURE_COOKIE'] = $savedSecureEnv; }
            if ($savedSecureServer === null) { unset($_SERVER['SESSION_SECURE_COOKIE']); } else { $_SERVER['SESSION_SECURE_COOKIE'] = $savedSecureServer; }
        }
    }
}
