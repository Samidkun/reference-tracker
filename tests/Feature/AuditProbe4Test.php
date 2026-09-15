<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuditProbe4Test extends TestCase
{
    use RefreshDatabase;

    /** Dump every <script> tag the login page renders, in production mode. */
    public function test_prod_login_scripts_vs_csp(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
        config(['app.env' => 'production']);

        $res = $this->get('/login');
        $csp = (string) $res->headers->get('Content-Security-Policy');
        $body = $res->getContent();

        fwrite(STDERR, "\n[PROD-CSP] " . $csp . "\n");
        preg_match_all('/<script\b[^>]*>(.*?)<\/script>/s', $body, $m, PREG_SET_ORDER);
        fwrite(STDERR, "[PROD] total <script> tags: " . count($m) . "\n");
        foreach ($m as $i => $tag) {
            $open = substr($tag[0], 0, strpos($tag[0], '>') + 1);
            $hasSrc = str_contains($open, 'src=');
            $inline = trim($tag[1]);
            fwrite(STDERR, "  #{$i}: " . ($hasSrc ? 'EXTERNAL' : 'INLINE') . " open=" . preg_replace('/\s+/', ' ', $open) . " inlineLen=" . strlen($inline) . "\n");
        }
        // also look for a bare <script with no closing (Vite dev react refresh)
        fwrite(STDERR, "[PROD] raw '<script' count: " . substr_count($body, '<script') . "\n");
        $this->assertTrue(true);
    }

    /** Same, but in local/dev mode for comparison. */
    public function test_local_login_scripts_vs_csp(): void
    {
        config(['app.env' => 'local']);
        $res = $this->get('/login');
        $csp = (string) $res->headers->get('Content-Security-Policy');
        fwrite(STDERR, "\n[LOCAL-CSP] " . $csp . "\n");
        $this->assertTrue(true);
    }
}
