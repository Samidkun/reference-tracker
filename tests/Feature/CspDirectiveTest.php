<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * A duplicated CSP directive is accepted by browsers but only the LAST
 * occurrence counts — so an accidental duplicate silently discards the first.
 * The middleware built connect-src twice (once in the base list, once in the
 * local-dev branch); this asserts each directive appears exactly once.
 */
class CspDirectiveTest extends TestCase
{
    private function directives(): array
    {
        $csp = (string) $this->get('/login')->headers->get('Content-Security-Policy');

        $names = [];
        foreach (explode(';', $csp) as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }
            $names[] = strtok($part, ' ');
        }

        return $names;
    }

    public function test_every_csp_directive_appears_exactly_once(): void
    {
        $names = $this->directives();

        $dupes = array_keys(array_filter(array_count_values($names), fn ($n) => $n > 1));

        $this->assertSame([], $dupes, 'duplicated CSP directive(s): ' . implode(', ', $dupes));
    }

    public function test_connect_src_keeps_crossref_and_gains_the_dev_websocket(): void
    {
        $csp = (string) $this->get('/login')->headers->get('Content-Security-Policy');

        $this->assertStringContainsString('https://api.crossref.org', $csp);
        $this->assertStringContainsString('ws://localhost:5173', $csp);
    }
}
