<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * CSP vs the inline scripts the page actually emits.
 *
 * `@routes` renders a ~23 kB inline Ziggy script, and Inertia's progress
 * component renders a second ~6 kB inline script. In production the
 * middleware originally sent `script-src 'self'` with neither
 * 'unsafe-inline' nor a nonce, so a real browser blocked BOTH - `route()`
 * would be undefined and the app would break. Locally it worked because the
 * local policy allows 'unsafe-inline' for the Vite dev client.
 *
 * A production-only, total-outage bug that no backend test caught, because
 * no test ran with APP_ENV=production.
 *
 * The first version of this test was too weak: it only checked that the CSP
 * contained *a* nonce. It passed while a second inline script had no nonce at
 * all. It now checks EVERY inline script individually.
 */
class CspInlineScriptTest extends TestCase
{
    /**
     * @return array{0: string, 1: string}
     */
    private function renderAsProduction(): array
    {
        $this->app['env'] = 'production';

        $response = $this->get('/login');

        return [
            (string) $response->headers->get('Content-Security-Policy'),
            (string) $response->getContent(),
        ];
    }

    /**
     * Every <script> with a body and no src attribute.
     *
     * @return array<int, array{attrs: string, body: string}>
     */
    private function inlineScripts(string $html): array
    {
        preg_match_all('/<script([^>]*)>(.*?)<\/script>/s', $html, $m, PREG_SET_ORDER);

        $inline = [];
        foreach ($m as $tag) {
            $attrs = $tag[1];
            $body = $tag[2];

            // external scripts load via src and are covered by 'self'
            if (stripos($attrs, 'src=') !== false) {
                continue;
            }
            // an empty body has nothing to execute
            if (trim($body) === '') {
                continue;
            }

            $inline[] = ['attrs' => $attrs, 'body' => $body];
        }

        return $inline;
    }

    public function test_the_page_emits_inline_scripts_at_all(): void
    {
        [, $html] = $this->renderAsProduction();

        $this->assertGreaterThan(
            0,
            count($this->inlineScripts($html)),
            'expected the page to contain inline scripts (Ziggy routes, Inertia progress)'
        );
    }

    public function test_every_inline_script_carries_a_nonce(): void
    {
        [, $html] = $this->renderAsProduction();

        $inline = $this->inlineScripts($html);
        $this->assertNotEmpty($inline);

        $missing = [];
        foreach ($inline as $i => $script) {
            if (! preg_match('/\bnonce="([^"]+)"/', $script['attrs'], $m)) {
                $missing[] = "#{$i} (body starts: " . substr(trim($script['body']), 0, 60) . ')';

                continue;
            }

            $this->assertNotSame('', $m[1], "inline script #{$i} has an empty nonce");
        }

        $this->assertSame(
            [],
            $missing,
            "inline script(s) without a nonce would be blocked by the production "
            . 'CSP and break the app: ' . implode('; ', $missing)
        );
    }

    public function test_the_nonce_on_every_script_matches_the_one_in_the_csp(): void
    {
        [$csp, $html] = $this->renderAsProduction();

        $this->assertSame(
            1,
            preg_match("/script-src[^;]*'nonce-([^']+)'/", $csp, $cm),
            'the CSP must declare exactly one nonce for script-src'
        );

        foreach ($this->inlineScripts($html) as $i => $script) {
            $this->assertSame(
                1,
                preg_match('/\bnonce="([^"]+)"/', $script['attrs'], $sm),
                "inline script #{$i} is missing a nonce attribute"
            );
            $this->assertSame(
                $cm[1],
                $sm[1],
                "inline script #{$i} carries a different nonce than the CSP declares"
            );
        }
    }

    /**
     * The weak version of this test only ran with withoutVite(), which strips
     * the @vite directive - and with it Laravel's inline prefetch script. So
     * the test never saw the very script that breaks in production.
     *
     * This one renders WITH Vite active, which is what actually ships.
     */
    public function test_inline_scripts_from_vite_also_carry_the_nonce(): void
    {
        $this->app['env'] = 'production';
        // undo withoutVite() for this test only
        $this->app->forgetInstance('Illuminate\Foundation\Vite');

        $response = $this->get('/login');
        $csp = (string) $response->headers->get('Content-Security-Policy');
        $html = (string) $response->getContent();

        preg_match("/script-src[^;]*'nonce-([^']+)'/", $csp, $cm);
        $this->assertNotEmpty($cm, 'production CSP must declare a script nonce');

        foreach ($this->inlineScripts($html) as $i => $script) {
            preg_match('/\bnonce="([^"]+)"/', $script['attrs'], $sm);
            $this->assertSame(
                $cm[1],
                $sm[1] ?? null,
                "inline script #{$i} (len " . strlen($script['body'])
                . ') does not carry the CSP nonce and would be blocked in production'
            );
        }
    }

    public function test_the_production_csp_still_forbids_objects_and_framing(): void
    {
        [$csp] = $this->renderAsProduction();

        $this->assertStringContainsString("object-src 'none'", $csp);
        $this->assertStringContainsString("frame-ancestors 'none'", $csp);
        $this->assertStringContainsString("default-src 'self'", $csp);
    }

    public function test_the_production_csp_does_not_allow_the_vite_dev_server(): void
    {
        [$csp] = $this->renderAsProduction();

        $this->assertStringNotContainsString('localhost:5173', $csp);
    }

    /**
     * 'unsafe-inline' must never appear in script-src — that is the directive
     * that would let injected code run. It IS allowed in style-src, because
     * Inertia's progress bar injects a <style> block with no nonce hook and
     * inline CSS cannot execute code. Assert the distinction, not a blanket ban.
     */
    public function test_script_src_never_allows_unsafe_inline(): void
    {
        [$csp] = $this->renderAsProduction();

        preg_match('/script-src([^;]*)/', $csp, $m);
        $this->assertNotEmpty($m, 'CSP must declare script-src');
        $this->assertStringNotContainsString(
            "'unsafe-inline'",
            $m[1],
            'script-src must never permit inline scripts'
        );
        $this->assertStringContainsString("'nonce-", $m[1], 'script-src must be nonce-gated');
    }

    public function test_style_src_may_allow_unsafe_inline_for_the_progress_bar(): void
    {
        [$csp] = $this->renderAsProduction();

        preg_match('/style-src([^;]*)/', $csp, $m);
        $this->assertNotEmpty($m, 'CSP must declare style-src');
        $this->assertStringContainsString(
            "'unsafe-inline'",
            $m[1],
            'nprogress injects a <style> block and offers no nonce; without this every page load logs a violation'
        );
    }
}
