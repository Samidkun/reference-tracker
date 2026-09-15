<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Stage 10. Headers are easy to configure and easy to silently lose — a
 * middleware registered on the wrong group, or one that returns early, looks
 * fine in code review and serves nothing. These assert on the real response.
 */
class SecurityHeadersTest extends TestCase
{
    public function test_the_login_page_sends_the_security_headers(): void
    {
        $res = $this->get('/login');

        $res->assertOk();
        $res->assertHeader('X-Content-Type-Options', 'nosniff');
        $res->assertHeader('X-Frame-Options', 'DENY');
        $res->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
        $this->assertStringContainsString('frame-ancestors', $res->headers->get('Content-Security-Policy'));
    }

    public function test_redirects_carry_the_headers_too(): void
    {
        $res = $this->get('/');

        $res->assertRedirect();
        $res->assertHeader('X-Content-Type-Options', 'nosniff');
        $res->assertHeader('X-Frame-Options', 'DENY');
    }

    public function test_csp_blocks_objects_and_framing(): void
    {
        $csp = $this->get('/login')->headers->get('Content-Security-Policy');

        $this->assertStringContainsString("object-src 'none'", $csp);
        $this->assertStringContainsString("frame-ancestors 'none'", $csp);
        $this->assertStringContainsString("base-uri 'self'", $csp);
        $this->assertStringContainsString("default-src 'self'", $csp);
    }

    public function test_csp_allows_the_crossref_api_the_doi_lookup_needs(): void
    {
        $csp = $this->get('/login')->headers->get('Content-Security-Policy');

        // Without this the DOI lookup would be blocked in the browser while
        // working fine in every backend test.
        $this->assertStringContainsString('https://api.crossref.org', $csp);
    }

    public function test_hsts_is_not_sent_over_plain_http(): void
    {
        // Sending HSTS on http:// is meaningless and hides a misconfiguration.
        $this->assertNull($this->get('/login')->headers->get('Strict-Transport-Security'));
    }

    public function test_hsts_is_sent_when_the_request_is_secure(): void
    {
        $res = $this->get('https://localhost/login');

        $this->assertStringContainsString('max-age=', (string) $res->headers->get('Strict-Transport-Security'));
    }
}
