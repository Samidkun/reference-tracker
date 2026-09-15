<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuditProbe7Test extends TestCase
{
    use RefreshDatabase;

    public function test_verify_email_routes(): void
    {
        $me = User::factory()->create();
        fwrite(STDERR, "\n[VE] User implements MustVerifyEmail? "
            . var_export($me instanceof \Illuminate\Contracts\Auth\MustVerifyEmail, true) . "\n");
        fwrite(STDERR, "[VE] hasVerifiedEmail method exists? "
            . var_export(method_exists($me, 'hasVerifiedEmail'), true) . "\n");

        try {
            $res = $this->actingAs($me)->get('/verify-email');
            fwrite(STDERR, "[VE] GET /verify-email -> " . $res->getStatusCode() . "\n");
        } catch (\Throwable $e) {
            fwrite(STDERR, "[VE] GET /verify-email THREW " . get_class($e) . ': ' . $e->getMessage() . "\n");
        }

        try {
            $res = $this->actingAs($me)->post('/email/verification-notification');
            fwrite(STDERR, "[VE] POST /email/verification-notification -> " . $res->getStatusCode() . "\n");
        } catch (\Throwable $e) {
            fwrite(STDERR, "[VE] POST /email/verification-notification THREW " . get_class($e) . ': ' . $e->getMessage() . "\n");
        }
        $this->assertTrue(true);
    }

    public function test_export_unbounded_and_headers(): void
    {
        $me = User::factory()->create();
        \App\Models\Reference::factory()->for($me)->count(3)->create();
        $res = $this->actingAs($me)->get('/references/export');
        fwrite(STDERR, "\n[EXPORT] status=" . $res->getStatusCode()
            . " content-disposition=" . var_export($res->headers->get('content-disposition'), true)
            . " content-type=" . var_export($res->headers->get('content-type'), true) . "\n");
        $this->assertTrue(true);
    }

    public function test_doi_lookup_requires_auth(): void
    {
        $res = $this->post('/references/doi', ['doi' => '10.1000/abc']);
        fwrite(STDERR, "\n[DOI] guest POST /references/doi -> " . $res->getStatusCode()
            . " location=" . var_export($res->headers->get('location'), true) . "\n");
        $this->assertTrue(true);
    }

    /** Search: does the JSON authors column search actually work, and is it parameterised? */
    public function test_search_behaviour(): void
    {
        $me = User::factory()->create();
        \App\Models\Reference::factory()->for($me)->create(['title' => 'Deep Learning', 'authors' => ['Vaswani, Ashish']]);
        $r1 = $this->actingAs($me)->get('/references?q=vaswani');
        $r2 = $this->actingAs($me)->get('/references?q=%25');   // literal %
        $r3 = $this->actingAs($me)->get('/references?q=_');      // literal _
        fwrite(STDERR, "\n[SEARCH] vaswani=" . $r1->getStatusCode() . " pct=" . $r2->getStatusCode() . " underscore=" . $r3->getStatusCode() . "\n");
        // wildcard injection: % should match everything if not escaped
        $all = \App\Models\Reference::factory()->for($me)->create(['title' => 'Zzz']);
        $r4 = $this->actingAs($me)->get('/references?q=%25');
        fwrite(STDERR, "[SEARCH] q=%25 returns " . $r4->json('props.references.data') !== null ? "json-ok" : "n/a");
        fwrite(STDERR, "\n");
        $this->assertTrue(true);
    }
}
