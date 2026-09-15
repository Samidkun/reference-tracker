<?php

namespace Tests\Feature;

use App\Models\Reference;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class AuditProbe2Test extends TestCase
{
    use RefreshDatabase;

    private function import(User $u, string $bib)
    {
        return $this->actingAs($u)->post('/references/import', [
            'file' => UploadedFile::fake()->createWithContent('x.bib', $bib),
        ]);
    }

    /** P11: capture the 500 body to see whether it leaks internals */
    public function test_p11_500_body_leak(): void
    {
        $me = User::factory()->create();
        $res = $this->import($me, "@article{y,\n title = {Overflow},\n author = {Doe, J},\n year = {999999}\n}\n");
        $body = $res->getContent();
        fwrite(STDERR, "\n[P11] status=" . $res->getStatusCode() . " bodyLen=" . strlen($body) . "\n");
        foreach (['SQLSTATE', 'Stack trace', 'vendor/laravel', 'Out of range', 'IGNITION', 'whoops', 'Exception'] as $needle) {
            fwrite(STDERR, "[P11] contains '" . $needle . "': " . (str_contains($body, $needle) ? 'YES' : 'no') . "\n");
        }
        fwrite(STDERR, "[P11] head: " . substr(preg_replace('/\s+/', ' ', strip_tags($body)), 0, 300) . "\n");
        $this->assertTrue(true);
    }

    /** P12: DOI longer than 255 chars on import (column is VARCHAR(255)) */
    public function test_p12_import_long_doi(): void
    {
        $me = User::factory()->create();
        $doi = '10.1234/' . str_repeat('x', 300);
        $res = $this->import($me, "@article{d,\n title = {D},\n author = {Doe, J},\n year = {2020},\n doi = {{$doi}}\n}\n");
        fwrite(STDERR, "\n[P12] long-doi status=" . $res->getStatusCode()
            . " refs=" . Reference::count()
            . " storedDoiLen=" . (Reference::first() ? strlen((string) Reference::first()->doi) : 'n/a') . "\n");
        $this->assertTrue(true);
    }

    /** P13: notes longer than the TEXT column on import */
    public function test_p13_import_long_notes(): void
    {
        $me = User::factory()->create();
        $notes = str_repeat('N', 100000);
        $res = $this->import($me, "@article{n,\n title = {N},\n author = {Doe, J},\n year = {2020},\n note = {{$notes}}\n}\n");
        fwrite(STDERR, "\n[P13] long-notes status=" . $res->getStatusCode()
            . " refs=" . Reference::count() . "\n");
        $this->assertTrue(true);
    }

    /** P14: 50k-entry bib -> unbounded work / row count */
    public function test_p14_import_many_entries(): void
    {
        $me = User::factory()->create();
        $bib = '';
        for ($i = 0; $i < 5000; $i++) {
            $bib .= "@article{k{$i},\n title = {T{$i}},\n author = {Doe, J},\n year = {2020}\n}\n";
        }
        $t0 = microtime(true);
        $res = $this->import($me, $bib);
        $dt = round(microtime(true) - $t0, 2);
        fwrite(STDERR, "\n[P14] 5000-entry import status=" . $res->getStatusCode()
            . " refs=" . Reference::count() . " seconds={$dt}\n");
        $this->assertTrue(true);
    }

    /** P15: update a foreign reference — does validation run before authorization? */
    public function test_p15_update_foreign_validation_order(): void
    {
        $me = User::factory()->create();
        $other = User::factory()->create();
        $ref = Reference::factory()->for($other)->create(['title' => 'Theirs', 'doi' => '10.1/owned']);
        // Send invalid payload to a foreign ref: if we get 422 the validator ran
        // before the ownership check (existence/ownership oracle + wasted work).
        $res = $this->actingAs($me)->put("/references/{$ref->id}", ['title' => '']);
        fwrite(STDERR, "\n[P15] foreign+invalid status=" . $res->getStatusCode() . "\n");
        $res2 = $this->actingAs($me)->put("/references/{$ref->id}", [
            'title' => 'Valid', 'authors' => ['A, B'], 'type' => 'journal',
        ]);
        fwrite(STDERR, "[P15] foreign+valid status=" . $res2->getStatusCode() . "\n");
        $this->assertTrue(true);
    }

    /** P16: case-variant duplicate DOI via the manual form (import lowercases, form does not) */
    public function test_p16_case_variant_doi(): void
    {
        $me = User::factory()->create();
        $a = $this->actingAs($me)->post('/references', [
            'title' => 'A', 'authors' => ['A, B'], 'type' => 'journal', 'doi' => '10.1000/ABC',
        ]);
        $b = $this->actingAs($me)->post('/references', [
            'title' => 'B', 'authors' => ['A, B'], 'type' => 'journal', 'doi' => '10.1000/abc',
        ]);
        fwrite(STDERR, "\n[P16] first=" . $a->getStatusCode() . " second=" . $b->getStatusCode()
            . " refs=" . Reference::count() . "\n");
        $this->assertTrue(true);
    }

    /** P17: what does the login response Set-Cookie look like */
    public function test_p17_set_cookie(): void
    {
        $res = $this->get('/login');
        fwrite(STDERR, "\n[P17] Set-Cookie: " . var_export($res->headers->all('set-cookie'), true) . "\n");
        $this->assertTrue(true);
    }
}
