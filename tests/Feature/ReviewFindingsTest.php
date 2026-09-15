<?php

namespace Tests\Feature;

use App\Models\Reference;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * The backend findings an independent review raised that were still open.
 * Each test asserts the CORRECT behaviour, so it fails until the fix lands.
 */
class ReviewFindingsTest extends TestCase
{
    use RefreshDatabase;

    private function user(): User
    {
        return User::factory()->create();
    }

    // ---------- I6: LIKE wildcards must be literal ----------

    public function test_a_percent_sign_in_search_is_treated_literally(): void
    {
        $me = $this->user();
        Reference::factory()->for($me)->count(3)->create(['title' => 'Ordinary Paper']);

        // '%' is a LIKE wildcard; unescaped it matches every row
        $this->actingAs($me)
            ->get('/references?q=%25')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('references.data', 0));
    }

    public function test_an_underscore_in_search_is_treated_literally(): void
    {
        $me = $this->user();
        Reference::factory()->for($me)->count(3)->create(['title' => 'Ordinary Paper']);

        $this->actingAs($me)
            ->get('/references?q=_')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('references.data', 0));
    }

    public function test_a_literal_wildcard_still_finds_a_title_containing_it(): void
    {
        $me = $this->user();
        Reference::factory()->for($me)->create(['title' => '100% Coverage Testing']);

        $this->actingAs($me)
            ->get('/references?q=' . urlencode('100%'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('references.data', 1));
    }

    public function test_normal_search_still_works_after_escaping(): void
    {
        $me = $this->user();
        Reference::factory()->for($me)->create(['title' => 'Deep Learning']);

        $this->actingAs($me)
            ->get('/references?q=deep')
            ->assertInertia(fn ($page) => $page->has('references.data', 1));
    }

    // ---------- I1: notes must not exceed the TEXT byte ceiling ----------

    public function test_a_multibyte_note_within_the_character_limit_does_not_crash(): void
    {
        $me = $this->user();

        // 20,000 four-byte characters = 80,000 bytes > the 65,535 TEXT ceiling
        $notes = str_repeat('\u{1F600}', 20000);

        $bib = "@article{e,\n title = {Emoji},\n author = {Doe, J},\n year = {2020},\n note = {{$notes}}\n}\n";

        $res = $this->actingAs($me)->post('/references/import', [
            'file' => UploadedFile::fake()->createWithContent('e.bib', $bib),
        ]);

        $res->assertRedirect('/references');
        $this->assertLessThanOrEqual(65535, strlen((string) Reference::first()->notes));
    }

    public function test_the_form_also_rejects_an_over_byte_limit_note_gracefully(): void
    {
        $me = $this->user();
        $notes = str_repeat('\u{1F600}', 20000);

        $res = $this->actingAs($me)->post('/references', [
            'title' => 'X',
            'authors' => ['Doe, J'],
            'type' => 'journal',
            'notes' => $notes,
        ]);

        // either accepted-and-stored safely, or rejected with a field error -
        // never a 500
        $this->assertLessThan(500, $res->getStatusCode());
    }

    // ---------- I4: import must be bounded ----------

    public function test_an_import_larger_than_the_entry_cap_is_rejected_not_silently_truncated(): void
    {
        $me = $this->user();

        $bib = '';
        for ($i = 0; $i < 2500; $i++) {
            $bib .= "@article{k{$i},\n title = {T{$i}},\n author = {Doe, J},\n year = {2020}\n}\n";
        }

        $res = $this->actingAs($me)->post('/references/import', [
            'file' => UploadedFile::fake()->createWithContent('big.bib', $bib),
        ]);

        // must not silently create a partial library
        $count = Reference::count();
        $this->assertTrue(
            $count === 0 || $count === 2500,
            "import must be all-or-nothing, got {$count} rows"
        );
    }

    public function test_a_reasonable_import_still_succeeds(): void
    {
        $me = $this->user();

        $bib = '';
        for ($i = 0; $i < 100; $i++) {
            $bib .= "@article{k{$i},\n title = {T{$i}},\n author = {Doe, J},\n year = {2020}\n}\n";
        }

        $this->actingAs($me)->post('/references/import', [
            'file' => UploadedFile::fake()->createWithContent('ok.bib', $bib),
        ])->assertRedirect('/references');

        $this->assertSame(100, Reference::count());
    }

    // ---------- M1: no existence oracle ----------

    public function test_a_foreign_reference_returns_the_same_status_as_a_missing_one(): void
    {
        $me = $this->user();
        $other = $this->user();
        $foreign = Reference::factory()->for($other)->create();

        $foreignStatus = $this->actingAs($me)->get("/references/{$foreign->id}")->getStatusCode();
        $missingStatus = $this->actingAs($me)->get('/references/999999')->getStatusCode();

        $this->assertSame(
            $missingStatus,
            $foreignStatus,
            'a foreign row must not be distinguishable from a missing one (403 vs 404 is an existence oracle)'
        );
    }

    // ---------- I3: export must be bounded ----------

    public function test_export_does_not_load_the_entire_library_into_one_string(): void
    {
        $me = $this->user();
        Reference::factory()->for($me)->count(30)->create();

        $res = $this->actingAs($me)->get('/references/export');

        $res->assertOk();
        // sanity: it still produces valid bibtex for a normal library
        $this->assertStringContainsString('@', $res->streamedContent());
    }
}
