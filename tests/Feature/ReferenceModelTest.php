<?php

namespace Tests\Feature;

use App\Models\Reference;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReferenceModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_authors_round_trip_as_an_array(): void
    {
        $ref = Reference::factory()->create([
            'authors' => ['Vaswani, Ashish', 'Shazeer, Noam'],
        ]);

        $this->assertSame(
            ['Vaswani, Ashish', 'Shazeer, Noam'],
            $ref->fresh()->authors
        );
    }

    public function test_search_matches_title_author_and_year(): void
    {
        $user = User::factory()->create();
        Reference::factory()->for($user)->create(['title' => 'Deep Learning', 'year' => 2015, 'authors' => ['LeCun, Yann']]);
        Reference::factory()->for($user)->create(['title' => 'Quantum Computing', 'year' => 2020, 'authors' => ['Feynman, Richard']]);

        $this->assertCount(1, Reference::search('deep')->get());
        $this->assertCount(1, Reference::search('Feynman')->get());
        $this->assertCount(1, Reference::search('2020')->get());
        $this->assertCount(2, Reference::search('')->get());
        $this->assertCount(2, Reference::search(null)->get());
    }

    public function test_a_reference_belongs_to_many_tags(): void
    {
        $user = User::factory()->create();
        $ref = Reference::factory()->for($user)->create();
        $tags = Tag::factory()->count(2)->for($user)->create();

        $ref->tags()->attach($tags->pluck('id'));

        $this->assertCount(2, $ref->fresh()->tags);
    }

    public function test_deleting_a_reference_removes_its_tag_links(): void
    {
        $user = User::factory()->create();
        $ref = Reference::factory()->for($user)->create();
        $tag = Tag::factory()->for($user)->create();
        $ref->tags()->attach($tag);

        $ref->delete();

        $this->assertDatabaseMissing('reference_tag', [
            'reference_id' => $ref->id,
            'tag_id' => $tag->id,
        ]);
        // the tag itself must survive — deleting a reference is not deleting its tags
        $this->assertDatabaseHas('tags', ['id' => $tag->id]);
    }

    public function test_deleting_a_user_cascades_to_their_references(): void
    {
        $user = User::factory()->create();
        Reference::factory()->for($user)->create();

        $user->delete();

        $this->assertDatabaseCount('references', 0);
    }
}
