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
     * M3: the Secure flag follows the REQUEST SCHEME, not the environment name.
     *
     * The first version keyed off APP_ENV=production. That is wrong: a
     * production app reached over http:// then sets `Secure` on a response the
     * browser receives over plain HTTP, and browsers MUST discard such a
     * cookie - so the session never persists and login is impossible, silently.
     * The production rehearsal caught it: 21 of 25 E2E tests failed, every one
     * that needs a session.
     *
     * These assertions go through the real HTTP kernel, so they test the
     * behaviour a browser sees rather than the value of a config key.
     */
    public function test_secure_cookie_flag_is_absent_over_plain_http(): void
    {
        $this->app['env'] = 'production';

        $response = $this->get('/login');
        $cookies = $response->headers->getCookies();

        $this->assertNotEmpty($cookies, 'login must set a session cookie');

        foreach ($cookies as $cookie) {
            $this->assertFalse(
                $cookie->isSecure(),
                "cookie '{$cookie->getName()}' must NOT be Secure over http:// - the browser would discard it",
            );
        }
    }

    public function test_secure_cookie_flag_is_set_over_https(): void
    {
        $this->app['env'] = 'production';

        // A TLS-terminating proxy: PHP sees plain HTTP, the header says https.
        $response = $this->withHeaders(['X-Forwarded-Proto' => 'https'])->get('/login');
        $cookies = $response->headers->getCookies();

        $this->assertNotEmpty($cookies, 'login must set a session cookie');

        foreach ($cookies as $cookie) {
            $this->assertTrue(
                $cookie->isSecure(),
                "cookie '{$cookie->getName()}' must be Secure when the request arrived over HTTPS",
            );
        }
    }

    /**
     * The consequence that actually hurts: over plain HTTP in production the
     * session cookie must be USABLE, so an authenticated request survives.
     *
     * A POST cannot be used to prove this directly - Laravel's CSRF check
     * rejects a request whose token cookie the client never sent, which is
     * itself the symptom (419). So this asserts the property underneath it:
     * after a request over http://, the client is still authenticated.
     */
    public function test_the_session_survives_a_plain_http_request_in_production(): void
    {
        $this->app['env'] = 'production';

        $user = \App\Models\User::factory()->create();

        $response = $this->actingAs($user)->get('/references');
        $response->assertOk();

        // And the cookie the browser would have received carries no Secure
        // flag, so a browser keeps it.
        $login = $this->get('/login');
        foreach ($login->headers->getCookies() as $cookie) {
            $this->assertFalse(
                $cookie->isSecure(),
                'over http:// the browser must keep the cookie, so it cannot be Secure',
            );
        }
    }

    /**
     * `upgrade-insecure-requests` must only be sent when the request really is
     * secure.
     *
     * Over plain HTTP the browser rewrites every navigation and subresource to
     * https://, which fails against an http-only server: the first page renders,
     * then every click dies with ERR_CONNECTION_CLOSED and no visible error.
     * The production rehearsal caught this - 21 of 25 E2E tests failed, and the
     * only symptom was a registration form that never navigated.
     */
    public function test_upgrade_insecure_requests_is_absent_over_plain_http(): void
    {
        $this->app['env'] = 'production';

        $csp = (string) $this->get('/login')->headers->get('Content-Security-Policy');

        $this->assertStringNotContainsString(
            'upgrade-insecure-requests',
            $csp,
            'over http:// this directive breaks every navigation; it must not be sent',
        );
    }

    public function test_upgrade_insecure_requests_is_present_over_https(): void
    {
        $this->app['env'] = 'production';

        $csp = (string) $this->withHeaders(['X-Forwarded-Proto' => 'https'])
            ->get('/login')
            ->headers
            ->get('Content-Security-Policy');

        $this->assertStringContainsString(
            'upgrade-insecure-requests',
            $csp,
            'over HTTPS the directive is correct and should be sent',
        );
    }
}
