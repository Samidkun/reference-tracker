<?php

namespace Tests\Feature;

use App\Models\Reference;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * Import-path boundary conditions.
 *
 * These came out of an independent review that probed the import with values
 * the manual form would never send. Every one of them returned a raw 500 (or
 * stored garbage) before the sanitiser existed — because the form validated
 * its input and the import path did not. Same schema, two trust boundaries,
 * one of them unguarded.
 */
class ImportBoundaryTest extends TestCase
{
    use RefreshDatabase;

    private function import(User $user, string $bib)
    {
        return $this->actingAs($user)->post('/references/import', [
            'file' => UploadedFile::fake()->createWithContent('x.bib', $bib),
        ]);
    }

    public function test_an_out_of_range_year_does_not_crash_the_import(): void
    {
        $me = User::factory()->create();

        // the column is UNSIGNED SMALLINT (max 65535); 999999 used to be a 500
        $res = $this->import($me, "@article{y,\n title = {Overflow},\n author = {Doe, J},\n year = {999999}\n}\n");

        $res->assertRedirect('/references');
        $this->assertSame(1, Reference::count());
        $this->assertNull(Reference::first()->year, 'an impossible year becomes unknown, not a crash');
    }

    public function test_a_year_below_the_supported_range_is_dropped(): void
    {
        $me = User::factory()->create();

        $this->import($me, "@article{y,\n title = {Ancient},\n author = {Doe, J},\n year = {12}\n}\n");

        $this->assertSame(1, Reference::count());
        $this->assertNull(Reference::first()->year);
    }

    public function test_two_entries_with_an_empty_doi_do_not_collide(): void
    {
        $me = User::factory()->create();

        // '' is not NULL, so both rows hit unique(user_id, doi) -> 500
        $bib = "@article{a,\n title = {A},\n author = {Doe, J},\n doi = {}\n}\n\n@article{b,\n title = {B},\n author = {Roe, R},\n doi = {}\n}\n";
        $res = $this->import($me, $bib);

        $res->assertRedirect('/references');
        $this->assertSame(2, Reference::count(), 'both entries import; empty DOIs become NULL');
    }

    public function test_a_long_doi_is_dropped_rather_than_truncated(): void
    {
        $me = User::factory()->create();

        $doi = '10.1234/' . str_repeat('x', 300);
        $this->import($me, "@article{d,\n title = {D},\n author = {Doe, J},\n year = {2020},\n doi = {{$doi}}\n}\n");

        $this->assertSame(1, Reference::count());
        // truncating would yield a DOI that looks valid but resolves nowhere
        $this->assertNull(Reference::first()->doi);
    }

    public function test_a_huge_author_list_is_capped(): void
    {
        $me = User::factory()->create();

        $authors = [];
        for ($i = 0; $i < 200; $i++) {
            $authors[] = 'Author' . $i . ', A';
        }
        $bib = "@article{h,\n title = {H},\n author = {" . implode(' and ', $authors) . "},\n year = {2020}\n}\n";

        $this->import($me, $bib);

        $this->assertSame(1, Reference::count());
        $this->assertLessThanOrEqual(50, count(Reference::first()->authors), 'author list must be capped like the form does');
    }

    public function test_a_very_long_author_name_is_truncated_to_the_column_width(): void
    {
        $me = User::factory()->create();

        $name = str_repeat('N', 100000); // observed stored verbatim before the fix
        $this->import($me, "@article{n,\n title = {N},\n author = {{$name}},\n year = {2020}\n}\n");

        $this->assertSame(1, Reference::count());
        $stored = Reference::first()->authors;
        $this->assertLessThanOrEqual(200, mb_strlen($stored[0]));
    }

    public function test_very_long_notes_are_capped(): void
    {
        $me = User::factory()->create();

        $notes = str_repeat('N', 100000);
        $res = $this->import($me, "@article{n,\n title = {N},\n author = {Doe, J},\n year = {2020},\n note = {{$notes}}\n}\n");

        $res->assertRedirect('/references');
        $this->assertLessThanOrEqual(20000, mb_strlen((string) Reference::first()->notes));
    }

    public function test_an_unknown_type_falls_back_instead_of_failing(): void
    {
        $me = User::factory()->create();

        $this->import($me, "@nonsense{x,\n title = {Odd},\n author = {Doe, J},\n year = {2020}\n}\n");

        $this->assertSame(1, Reference::count());
        $this->assertContains(Reference::first()->type, ['journal', 'book', 'conference', 'thesis', 'web']);
    }

    public function test_a_large_but_reasonable_import_completes(): void
    {
        $me = User::factory()->create();

        $bib = '';
        for ($i = 0; $i < 500; $i++) {
            $bib .= "@article{k{$i},\n title = {T{$i}},\n author = {Doe, J},\n year = {2020}\n}\n";
        }

        $this->import($me, $bib);

        $this->assertSame(500, Reference::count());
    }

    public function test_the_manual_form_treats_doi_case_insensitively(): void
    {
        $me = User::factory()->create();

        $payload = fn ($doi) => ['title' => 'T', 'authors' => ['A, B'], 'type' => 'journal', 'doi' => $doi];

        $this->actingAs($me)->post('/references', $payload('10.1000/ABC'))->assertRedirect();

        // DOIs are case-insensitive (RFC 5870) - this must be rejected, not stored twice
        $this->actingAs($me)->post('/references', $payload('10.1000/abc'))->assertSessionHasErrors('doi');

        $this->assertSame(1, Reference::count());
    }
}
