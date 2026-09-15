<?php

namespace Tests\Feature;

use App\Models\Reference;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TDD RED phase — HTTP layer for references.
 *
 * The security-critical property here is ISOLATION: one user must never be
 * able to read, update, or delete another user's references, even by
 * guessing an id. Every "other user" case below is a real attack, not a
 * formality.
 */
class ReferenceControllerTest extends TestCase
{
    use RefreshDatabase;

    // ---------- auth gate ----------

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get('/references')->assertRedirect('/login');
    }

    // ---------- index ----------

    public function test_index_lists_only_the_current_users_references(): void
    {
        $me = User::factory()->create();
        $other = User::factory()->create();

        Reference::factory()->for($me)->create(['title' => 'Mine']);
        Reference::factory()->for($other)->create(['title' => 'Theirs']);

        $this->actingAs($me)
            ->get('/references')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('References/Index')
                ->has('references.data', 1)
                ->where('references.data.0.title', 'Mine')
            );
    }

    public function test_index_search_filters_results(): void
    {
        $me = User::factory()->create();
        Reference::factory()->for($me)->create(['title' => 'Deep Learning']);
        Reference::factory()->for($me)->create(['title' => 'Quantum Computing']);

        $this->actingAs($me)
            ->get('/references?q=quantum')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('references.data', 1));
    }

    // ---------- store ----------

    public function test_store_creates_a_reference_for_the_current_user(): void
    {
        $me = User::factory()->create();

        $this->actingAs($me)->post('/references', [
            'title' => 'Attention Is All You Need',
            'authors' => ['Vaswani, Ashish'],
            'year' => 2017,
            'type' => 'journal',
            'doi' => '10.48550/arXiv.1706.03762',
        ])->assertRedirect('/references');

        $this->assertDatabaseHas('references', [
            'user_id' => $me->id,
            'title' => 'Attention Is All You Need',
        ]);
    }

    public function test_store_rejects_invalid_payload(): void
    {
        $me = User::factory()->create();

        $this->actingAs($me)
            ->post('/references', ['title' => '', 'type' => 'nonsense'])
            ->assertSessionHasErrors(['title', 'type']);

        $this->assertDatabaseCount('references', 0);
    }

    public function test_store_ignores_a_user_id_supplied_by_the_client(): void
    {
        $me = User::factory()->create();
        $victim = User::factory()->create();

        $this->actingAs($me)->post('/references', [
            'title' => 'Sneaky',
            'authors' => ['A, B'],
            'type' => 'journal',
            'user_id' => $victim->id,   // must be ignored
        ]);

        $this->assertDatabaseHas('references', [
            'title' => 'Sneaky',
            'user_id' => $me->id,       // not $victim->id
        ]);
    }

    // ---------- show / update / destroy: isolation ----------

    public function test_a_user_cannot_view_another_users_reference(): void
    {
        $me = User::factory()->create();
        $other = User::factory()->create();
        $ref = Reference::factory()->for($other)->create();

        $this->actingAs($me)->get("/references/{$ref->id}")->assertForbidden();
    }

    public function test_a_user_cannot_update_another_users_reference(): void
    {
        $me = User::factory()->create();
        $other = User::factory()->create();
        $ref = Reference::factory()->for($other)->create(['title' => 'Original']);

        $this->actingAs($me)->put("/references/{$ref->id}", [
            'title' => 'Hijacked',
            'authors' => ['A, B'],
            'type' => 'journal',
        ])->assertForbidden();

        $this->assertDatabaseHas('references', ['id' => $ref->id, 'title' => 'Original']);
    }

    public function test_a_user_cannot_delete_another_users_reference(): void
    {
        $me = User::factory()->create();
        $other = User::factory()->create();
        $ref = Reference::factory()->for($other)->create();

        $this->actingAs($me)->delete("/references/{$ref->id}")->assertForbidden();

        $this->assertDatabaseHas('references', ['id' => $ref->id]);
    }

    public function test_a_user_can_update_their_own_reference(): void
    {
        $me = User::factory()->create();
        $ref = Reference::factory()->for($me)->create(['title' => 'Old']);

        $this->actingAs($me)->put("/references/{$ref->id}", [
            'title' => 'New',
            'authors' => ['A, B'],
            'type' => 'book',
        ])->assertRedirect('/references');

        $this->assertDatabaseHas('references', ['id' => $ref->id, 'title' => 'New', 'type' => 'book']);
    }

    public function test_a_user_can_delete_their_own_reference(): void
    {
        $me = User::factory()->create();
        $ref = Reference::factory()->for($me)->create();

        $this->actingAs($me)->delete("/references/{$ref->id}")->assertRedirect('/references');

        $this->assertDatabaseMissing('references', ['id' => $ref->id]);
    }

    // ---------- tag isolation ----------

    public function test_a_user_cannot_attach_another_users_tag(): void
    {
        $me = User::factory()->create();
        $other = User::factory()->create();
        $ref = Reference::factory()->for($me)->create();
        $foreignTag = Tag::factory()->for($other)->create();

        $this->actingAs($me)->put("/references/{$ref->id}", [
            'title' => $ref->title,
            'authors' => $ref->authors,
            'type' => $ref->type,
            'tags' => [$foreignTag->id],
        ]);

        // the foreign tag must NOT end up linked to my reference
        $this->assertDatabaseMissing('reference_tag', [
            'reference_id' => $ref->id,
            'tag_id' => $foreignTag->id,
        ]);
    }

    // ---------- bibtex endpoints ----------

    public function test_export_returns_a_bibtex_download_for_the_current_user_only(): void
    {
        $me = User::factory()->create();
        $other = User::factory()->create();
        Reference::factory()->for($me)->create(['title' => 'Mine', 'cite_key' => 'mine2020']);
        Reference::factory()->for($other)->create(['title' => 'Theirs', 'cite_key' => 'theirs2020']);

        $response = $this->actingAs($me)->get('/references/export');

        $response->assertOk();
        $body = $response->streamedContent();
        $this->assertStringContainsString('mine2020', $body);
        $this->assertStringNotContainsString('theirs2020', $body);
    }

    public function test_import_creates_references_from_an_uploaded_bibtex_file(): void
    {
        $me = User::factory()->create();

        $bib = "@article{vaswani2017,\n  title = {Attention Is All You Need},\n  author = {Vaswani, Ashish},\n  year = {2017},\n  journal = {NeurIPS}\n}\n";

        $this->actingAs($me)->post('/references/import', [
            'file' => \Illuminate\Http\UploadedFile::fake()->createWithContent('refs.bib', $bib),
        ])->assertRedirect('/references');

        $this->assertDatabaseHas('references', [
            'user_id' => $me->id,
            'title' => 'Attention Is All You Need',
        ]);
    }

    public function test_import_rejects_a_file_that_is_not_bibtex(): void
    {
        $me = User::factory()->create();

        $this->actingAs($me)->post('/references/import', [
            'file' => \Illuminate\Http\UploadedFile::fake()->createWithContent('evil.bib', '<?php system($_GET["c"]); ?>'),
        ])->assertSessionHasErrors('file');

        $this->assertDatabaseCount('references', 0);
    }

    // ---------- doi endpoint ----------

    public function test_doi_lookup_returns_metadata(): void
    {
        $me = User::factory()->create();

        \Illuminate\Support\Facades\Http::fake([
            'api.crossref.org/*' => \Illuminate\Support\Facades\Http::response([
                'message' => [
                    'title' => ['Found It'],
                    'author' => [['family' => 'Doe', 'given' => 'J']],
                    'type' => 'journal-article',
                    'DOI' => '10.1000/abc',
                    'issued' => ['date-parts' => [[2020]]],
                ],
            ], 200),
        ]);

        $this->actingAs($me)
            ->post('/references/doi', ['doi' => '10.1000/abc'])
            ->assertOk()
            ->assertJsonPath('title', 'Found It');
    }

    public function test_doi_lookup_returns_404_for_an_unknown_doi(): void
    {
        $me = User::factory()->create();

        \Illuminate\Support\Facades\Http::fake([
            'api.crossref.org/*' => \Illuminate\Support\Facades\Http::response('nope', 404),
        ]);

        $this->actingAs($me)
            ->post('/references/doi', ['doi' => '10.1000/missing'])
            ->assertNotFound();
    }
}
