<?php

namespace Tests\Feature;

use App\Models\Reference;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * TDD RED phase — hardening found by probing the happy path's edges.
 *
 * Every test here documents a real defect observed in the app:
 *  - a duplicate DOI crashed the request with a raw 500
 *  - a failed import left half its rows behind (no transaction)
 *  - search was case-sensitive, so "vaswani" found nothing
 */
class ImportRobustnessTest extends TestCase
{
    use RefreshDatabase;

    private function bib(string $body): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('refs.bib', $body);
    }

    // ---------- duplicate DOI ----------

    public function test_importing_a_duplicate_doi_does_not_crash(): void
    {
        $me = User::factory()->create();

        $bib = "@article{a2020,\n title = {First},\n author = {Doe, J},\n year = {2020},\n doi = {10.1000/same}\n}\n\n@article{b2021,\n title = {Second},\n author = {Roe, R},\n year = {2021},\n doi = {10.1000/same}\n}\n";

        $res = $this->actingAs($me)->post('/references/import', ['file' => $this->bib($bib)]);

        $res->assertRedirect('/references');
        $res->assertSessionHasNoErrors();

        // first kept, second skipped as a duplicate — and the user is told
        $this->assertSame(1, Reference::where('user_id', $me->id)->count());
        $this->assertStringContainsString('skip', strtolower((string) session('success')));
    }

    public function test_importing_a_doi_that_already_exists_is_skipped_not_fatal(): void
    {
        $me = User::factory()->create();
        Reference::factory()->for($me)->create(['doi' => '10.1000/existing', 'title' => 'Already Here']);

        $bib = "@article{new2020,\n title = {New One},\n author = {Doe, J},\n year = {2020},\n doi = {10.1000/existing}\n}\n";

        $res = $this->actingAs($me)->post('/references/import', ['file' => $this->bib($bib)]);

        $res->assertRedirect('/references');
        $this->assertSame(1, Reference::where('user_id', $me->id)->count());
    }

    public function test_storing_a_reference_with_an_existing_doi_shows_a_validation_error(): void
    {
        $me = User::factory()->create();
        Reference::factory()->for($me)->create(['doi' => '10.1000/dup']);

        $payload = [
            'title' => 'Duplicate', 'authors' => ['Doe, J'],
            'type' => 'journal', 'doi' => '10.1000/dup',
        ];

        $res = $this->actingAs($me)->post('/references', $payload);

        $res->assertSessionHasErrors('doi');
        $this->assertSame(1, Reference::where('user_id', $me->id)->count());
    }

    // ---------- atomicity ----------

    public function test_a_failed_import_rolls_back_entirely(): void
    {
        $me = User::factory()->create();

        // entry 2 has a title longer than the 500-char column
        $long = str_repeat('x', 600);
        $bib = "@article{good2020,\n title = {Good One},\n author = {Doe, J},\n year = {2020}\n}\n\n@article{long2021,\n title = {{$long}},\n author = {Roe, R},\n year = {2021}\n}\n";

        $res = $this->actingAs($me)->post('/references/import', ['file' => $this->bib($bib)]);

        // Either it succeeds by sanitising the long title, or it fails cleanly.
        // What it must NOT do is leave half the rows behind.
        $count = Reference::where('user_id', $me->id)->count();

        if ($res->isRedirect()) {
            $this->assertSame(2, $count, 'sanitised import should keep both rows');
        } else {
            $this->assertSame(0, $count, 'a failed import must roll back completely');
        }
    }

    // ---------- case-insensitive search ----------

    public function test_search_is_case_insensitive(): void
    {
        $me = User::factory()->create();
        Reference::factory()->for($me)->create([
            'title' => 'Deep Learning',
            'authors' => ['LeCun, Yann'],
        ]);

        $this->assertSame(1, Reference::search('deep')->count(), 'lowercase finds uppercase title');
        $this->assertSame(1, Reference::search('DEEP')->count(), 'uppercase finds it too');
        $this->assertSame(1, Reference::search('lecun')->count(), 'lowercase finds author');
        $this->assertSame(1, Reference::search('LeCun')->count(), 'exact case finds author');
    }

    public function test_search_still_matches_year_and_doi_case_insensitively(): void
    {
        $me = User::factory()->create();
        Reference::factory()->for($me)->create([
            'title' => 'X', 'year' => 2015, 'doi' => '10.1038/NATURE14539',
        ]);

        $this->assertSame(1, Reference::search('2015')->count());
        $this->assertSame(1, Reference::search('nature')->count());
        $this->assertSame(1, Reference::search('NATURE')->count());
    }
}
