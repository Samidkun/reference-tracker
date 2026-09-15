<?php

namespace Tests\Feature;

use App\Models\Reference;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A partial update must not destroy data it was not asked to change.
 *
 * Before the fix, PUT /references/{id} without a `tags` key called
 * syncOwnedTags($ref, []) -> sync([]), which silently detached every tag.
 * The browser form always sends `tags`, so the UI never showed this - but any
 * other client (curl, an API consumer, a future mobile app, a partial update)
 * would wipe a user's tags by omitting one field.
 */
class TagPreservationTest extends TestCase
{
    use RefreshDatabase;

    private function referenceWithTags(User $user): array
    {
        $ref = Reference::factory()->for($user)->create(['title' => 'Tagged']);
        $tags = Tag::factory()->count(2)->for($user)->create();
        $ref->tags()->sync($tags->pluck('id'));

        return [$ref, $tags];
    }

    public function test_updating_without_a_tags_key_keeps_existing_tags(): void
    {
        $me = User::factory()->create();
        [$ref, $tags] = $this->referenceWithTags($me);

        $this->actingAs($me)->put("/references/{$ref->id}", [
            'title' => 'Renamed',
            'authors' => ['A, B'],
            'type' => 'journal',
            // no `tags` key at all
        ])->assertRedirect();

        $this->assertSame('Renamed', $ref->fresh()->title);
        $this->assertCount(2, $ref->fresh()->tags, 'omitting tags must not wipe them');
    }

    public function test_an_explicit_empty_tags_array_still_clears_them(): void
    {
        $me = User::factory()->create();
        [$ref, $tags] = $this->referenceWithTags($me);

        $this->actingAs($me)->put("/references/{$ref->id}", [
            'title' => 'Renamed',
            'authors' => ['A, B'],
            'type' => 'journal',
            'tags' => [], // explicit: the user unticked everything
        ])->assertRedirect();

        $this->assertCount(0, $ref->fresh()->tags, 'an explicit empty array means clear');
    }

    public function test_tags_can_be_replaced(): void
    {
        $me = User::factory()->create();
        [$ref, $tags] = $this->referenceWithTags($me);
        $new = Tag::factory()->for($me)->create();

        $this->actingAs($me)->put("/references/{$ref->id}", [
            'title' => 'Renamed',
            'authors' => ['A, B'],
            'type' => 'journal',
            'tags' => [$new->id],
        ])->assertRedirect();

        $current = $ref->fresh()->tags;
        $this->assertCount(1, $current);
        $this->assertSame($new->id, $current->first()->id);
    }
}
