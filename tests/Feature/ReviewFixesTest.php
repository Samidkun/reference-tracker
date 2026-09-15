<?php

namespace Tests\Feature;

use App\Models\Reference;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * Proof for the remaining independent-review findings.
 */
class ReviewFixesTest extends TestCase
{
    use RefreshDatabase;

    private function user(): User
    {
        return User::factory()->create();
    }

    // ---------- I4: import is throttled and entry-capped ----------

    public function test_import_is_rate_limited(): void
    {
        $me = $this->user();
        $bib = "@article{a,\n title = {T},\n author = {Doe, J},\n year = {2020}\n}\n";

        $statuses = [];
        for ($i = 0; $i < 14; $i++) {
            $statuses[] = $this->actingAs($me)->post('/references/import', [
                'file' => UploadedFile::fake()->createWithContent("f{$i}.bib", $bib),
            ])->getStatusCode();
        }

        $this->assertContains(429, $statuses, 'the 11th+ import within a minute must be throttled');
    }

    public function test_an_import_over_the_entry_cap_is_rejected_with_a_message(): void
    {
        $me = $this->user();

        $bib = '';
        for ($i = 0; $i < 2100; $i++) {
            $bib .= "@article{k{$i},\n title = {T{$i}},\n author = {Doe, J},\n year = {2020}\n}\n";
        }

        $res = $this->actingAs($me)->post('/references/import', [
            'file' => UploadedFile::fake()->createWithContent('big.bib', $bib),
        ]);

        $res->assertSessionHasErrors('file');
        $this->assertSame(0, Reference::count(), 'a rejected import must create nothing');
    }

    public function test_doi_lookup_is_rate_limited(): void
    {
        $me = $this->user();
        \Illuminate\Support\Facades\Http::fake([
            'api.crossref.org/*' => \Illuminate\Support\Facades\Http::response(['message' => [
                'title' => ['T'], 'author' => [['family' => 'A', 'given' => 'B']],
                'type' => 'journal-article', 'DOI' => '10.1000/x',
            ]], 200),
        ]);

        $statuses = [];
        for ($i = 0; $i < 35; $i++) {
            $statuses[] = $this->actingAs($me)->post('/references/doi', ['doi' => '10.1000/x'])->getStatusCode();
        }

        $this->assertContains(429, $statuses, 'the 31st+ DOI lookup within a minute must be throttled');
    }

    // ---------- M1: no existence oracle ----------

    public function test_foreign_and_missing_references_are_indistinguishable(): void
    {
        $me = $this->user();
        $other = $this->user();
        $foreign = Reference::factory()->for($other)->create();

        foreach (['get' => "/references/{$foreign->id}", 'edit' => "/references/{$foreign->id}/edit"] as $kind => $url) {
            $this->assertSame(
                404,
                $this->actingAs($me)->get($url)->getStatusCode(),
                "{$kind} on a foreign row must be 404, not 403"
            );
        }

        $this->assertSame(404, $this->actingAs($me)->get('/references/999999')->getStatusCode());
    }

    public function test_the_owner_can_still_reach_their_own_reference(): void
    {
        $me = $this->user();
        $mine = Reference::factory()->for($me)->create();

        $this->actingAs($me)->get("/references/{$mine->id}")->assertOk();
    }

    // ---------- I5: proxy-aware HTTPS detection ----------

    public function test_hsts_is_sent_when_a_trusted_proxy_forwards_https(): void
    {
        // a proxy terminating TLS forwards this header; with trustProxies
        // configured, isSecure() must become true and HSTS must appear
        $res = $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])
            ->withHeaders(['X-Forwarded-Proto' => 'https'])
            ->get('/login');

        $res->assertOk();
        $this->assertStringContainsString(
            'max-age=',
            (string) $res->headers->get('Strict-Transport-Security'),
            'HSTS must fire behind a TLS-terminating proxy'
        );
    }
}
