<?php

namespace Tests\Feature;

use App\Models\Reference;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Query-string input is attacker-controlled and can be ANY type.
 *
 * `?q[]=x` makes Laravel hand an ARRAY to a parameter typed `?string`, which
 * is a TypeError -> HTTP 500. With APP_DEBUG on (the default in local/staging)
 * that 500 renders ~930 kB of stack trace including SQLSTATE details and
 * vendor paths. Even with debug off, a user typing a malformed URL should get
 * a usable page, not an error.
 *
 * Same class of bug as the import path: the value arrives from outside and was
 * trusted to be the type the signature declared.
 */
class SearchInputTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, array{0: string}> */
    public static function hostileQueries(): array
    {
        return [
            'array param'        => ['q[]=x'],
            'assoc array param'  => ['q[foo]=x'],
            'nested array'       => ['q[a][b]=x'],
            'array of arrays'    => ['q[][]=x'],
            'very long string'   => ['q=' . str_repeat('a', 5000)],
            'only wildcards'     => ['q=%_%'],
            'sql-ish'            => ['q=%27%20OR%201%3D1--'],
            'null byte'          => ['q=%00abc'],
            'unicode'            => ['q=' . urlencode('日本語テスト')],
        ];
    }

    #[DataProvider('hostileQueries')]
    public function test_a_hostile_query_string_never_returns_a_server_error(string $query): void
    {
        $me = User::factory()->create();
        Reference::factory()->for($me)->create(['title' => 'Something']);

        $res = $this->actingAs($me)->get('/references?' . $query);

        $this->assertLessThan(
            500,
            $res->getStatusCode(),
            "query `{$query}` produced a server error"
        );
    }

    #[DataProvider('hostileQueries')]
    public function test_a_hostile_query_string_never_leaks_internals(string $query): void
    {
        $me = User::factory()->create();

        $body = (string) $this->actingAs($me)->get('/references?' . $query)->getContent();

        foreach (['SQLSTATE', 'Stack trace', 'vendor/laravel', 'IGNITION'] as $needle) {
            $this->assertStringNotContainsString(
                $needle,
                $body,
                "response for `{$query}` leaked `{$needle}`"
            );
        }
    }

    public function test_an_array_query_is_treated_as_an_empty_search(): void
    {
        $me = User::factory()->create();
        Reference::factory()->for($me)->count(3)->create();

        $res = $this->actingAs($me)->get('/references?q[]=x');

        $res->assertOk()
            ->assertInertia(fn ($page) => $page->has('references.data', 3));
    }

    public function test_the_search_scope_tolerates_a_non_string_argument(): void
    {
        $me = User::factory()->create();
        Reference::factory()->for($me)->create();

        // Non-string input must not explode. A non-scalar means "no filter",
        // so it returns everything; a numeric string is a real search term
        // and legitimately matches nothing here.
        $this->assertSame(1, Reference::search(['x'])->count(), 'array means no filter');
        $this->assertSame(1, Reference::search(null)->count(), 'null means no filter');
        $this->assertSame(1, Reference::search('')->count(), 'empty means no filter');
        $this->assertSame(0, Reference::search(123)->count(), '123 is a real term and matches nothing');
    }
}
