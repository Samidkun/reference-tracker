<?php

namespace Tests\Feature;

use App\Models\Reference;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tag filtering must happen SERVER-SIDE.
 *
 * The UI used to filter `references.data` - only the current page's rows -
 * while pagination links were rendered for the whole result set. With 29
 * references where the single tagged one sat on page 2, selecting the tag
 * rendered "0 rows + No references yet": the user concludes a saved reference
 * has vanished. For a reference manager that is the worst possible failure.
 *
 * These tests pin the property that fixes it: the filtered `data` and the
 * filtered `links` describe the SAME set.
 */
class TagFilterTest extends TestCase
{
    use RefreshDatabase;

    public function test_filtering_by_tag_returns_only_that_tags_references(): void
    {
        $me = User::factory()->create();
        $tag = Tag::factory()->for($me)->create();

        $tagged = Reference::factory()->for($me)->create(['title' => 'Tagged One']);
        $tagged->tags()->attach($tag);
        Reference::factory()->for($me)->count(3)->create(['title' => 'Untagged']);

        $this->actingAs($me)
            ->get("/references?tag={$tag->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('References/Index')
                ->has('references.data', 1)
                ->where('references.data.0.title', 'Tagged One')
                ->where('filters.tag', $tag->id)
            );
    }

    public function test_a_tag_match_on_a_later_page_is_found(): void
    {
        $me = User::factory()->create();
        $tag = Tag::factory()->for($me)->create();

        // 25 untagged + 1 tagged that would land on page 2 (page size 20)
        Reference::factory()->for($me)->count(25)->create();
        $tagged = Reference::factory()->for($me)->create(['title' => 'On Page Two']);
        $tagged->tags()->attach($tag);

        $res = $this->actingAs($me)->get("/references?tag={$tag->id}");

        // server-side filtering means it is on page 1 of the FILTERED set
        $res->assertOk()->assertInertia(fn ($page) => $page
            ->has('references.data', 1)
            ->where('references.data.0.title', 'On Page Two')
        );
    }

    public function test_pagination_links_reflect_the_filtered_set(): void
    {
        $me = User::factory()->create();
        $tag = Tag::factory()->for($me)->create();

        $refs = Reference::factory()->for($me)->count(25)->create();
        foreach ($refs as $r) {
            $r->tags()->attach($tag);
        }

        $this->actingAs($me)
            ->get("/references?tag={$tag->id}")
            ->assertInertia(fn ($page) => $page
                // 25 tagged refs, 20 per page => 2 pages
                ->where('references.total', 25)
                ->has('references.data', 20)
            );
    }

    public function test_the_tag_filter_survives_pagination_links(): void
    {
        $me = User::factory()->create();
        $tag = Tag::factory()->for($me)->create();
        foreach (Reference::factory()->for($me)->count(25)->create() as $r) {
            $r->tags()->attach($tag);
        }

        $res = $this->actingAs($me)->get("/references?tag={$tag->id}");

        $res->assertInertia(function ($page) use ($tag) {
            $links = $page->toArray()['props']['references']['links'];
            $next = collect($links)->first(fn ($l) => str_contains((string) $l['url'], 'page=2'));

            $this->assertNotNull($next, 'expected a page-2 link');
            $this->assertStringContainsString(
                "tag={$tag->id}",
                (string) $next['url'],
                'pagination links must carry the tag filter, or the filter silently drops on page 2'
            );
        });
    }

    public function test_a_user_cannot_filter_by_another_users_tag(): void
    {
        $me = User::factory()->create();
        $other = User::factory()->create();
        $foreign = Tag::factory()->for($other)->create();

        $mine = Reference::factory()->for($me)->create(['title' => 'Mine']);
        $mine->tags()->attach($foreign); // even if somehow linked

        $this->actingAs($me)
            ->get("/references?tag={$foreign->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('references.data', 0));
    }

    public function test_search_and_tag_filter_combine(): void
    {
        $me = User::factory()->create();
        $tag = Tag::factory()->for($me)->create();

        $a = Reference::factory()->for($me)->create(['title' => 'Deep Learning']);
        $a->tags()->attach($tag);
        $b = Reference::factory()->for($me)->create(['title' => 'Deep Something Else']);
        $b->tags()->attach($tag);
        $c = Reference::factory()->for($me)->create(['title' => 'Deep Untagged']);

        $this->actingAs($me)
            ->get("/references?q=deep&tag={$tag->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('references.data', 2));
    }
}
