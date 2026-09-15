<?php

namespace Tests\Feature;

use App\Models\Reference;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class AuditProbeTest extends TestCase
{
    use RefreshDatabase;

    /** PROBE 1: do error responses (404) carry the security headers? */
    public function test_probe_404_has_security_headers(): void
    {
        $res = $this->get('/definitely-not-a-real-route-xyz');
        $res->assertNotFound();
        fwrite(STDERR, "\n[P1] 404 X-Frame-Options=" . var_export($res->headers->get('X-Frame-Options'), true)
            . " CSP=" . var_export($res->headers->get('Content-Security-Policy'), true) . "\n");
        $this->assertTrue(true);
    }

    /** PROBE 2: does a 500 (unhandled DB error) carry the security headers? */
    public function test_probe_500_has_security_headers(): void
    {
        $me = User::factory()->create();
        // year far exceeds unsignedSmallInteger(65535) -> DB out-of-range
        $bib = "@article{x,\n title = {Huge Year},\n author = {Doe, J},\n year = {202020202020}\n}\n";
        $res = $this->actingAs($me)->post('/references/import', [
            'file' => UploadedFile::fake()->createWithContent('huge.bib', $bib),
        ]);
        fwrite(STDERR, "\n[P2] import huge-year status=" . $res->getStatusCode()
            . " X-Frame-Options=" . var_export($res->headers->get('X-Frame-Options'), true) . "\n");
        $this->assertTrue(true);
    }

    /** PROBE 3: import a bibtex year that overflows the column */
    public function test_probe_import_year_overflow(): void
    {
        $me = User::factory()->create();
        $bib = "@article{y,\n title = {Overflow},\n author = {Doe, J},\n year = {999999}\n}\n";
        $res = $this->actingAs($me)->post('/references/import', [
            'file' => UploadedFile::fake()->createWithContent('y.bib', $bib),
        ]);
        fwrite(STDERR, "\n[P3] year=999999 import status=" . $res->getStatusCode() . "\n");
        $this->assertTrue(true);
    }

    /** PROBE 4: two entries with an explicit empty doi field */
    public function test_probe_import_duplicate_empty_doi(): void
    {
        $me = User::factory()->create();
        $bib = "@article{a,\n title = {A},\n author = {Doe, J},\n year = {2020},\n doi = {}\n}\n"
             . "@article{b,\n title = {B},\n author = {Roe, R},\n year = {2021},\n doi = {}\n}\n";
        $res = $this->actingAs($me)->post('/references/import', [
            'file' => UploadedFile::fake()->createWithContent('d.bib', $bib),
        ]);
        fwrite(STDERR, "\n[P4] duplicate empty-doi status=" . $res->getStatusCode()
            . " refs=" . Reference::count() . "\n");
        $this->assertTrue(true);
    }

    /** PROBE 5: PUT without a 'tags' key wipes existing tags (data loss) */
    public function test_probe_update_without_tags_wipes_them(): void
    {
        $me = User::factory()->create();
        $ref = Reference::factory()->for($me)->create();
        $tag = Tag::factory()->for($me)->create();
        $ref->tags()->sync([$tag->id]);
        $this->assertSame(1, $ref->fresh()->tags()->count());

        $this->actingAs($me)->put("/references/{$ref->id}", [
            'title' => 'Updated',
            'authors' => ['A, B'],
            'type' => 'journal',
            // note: 'tags' deliberately omitted
        ]);

        $after = $ref->fresh()->tags()->count();
        fwrite(STDERR, "\n[P5] tags after PUT without 'tags' key = {$after}\n");
        $this->assertTrue(true);
    }

    /** PROBE 6: SQLi-shaped search term must not error or leak */
    public function test_probe_search_sqli(): void
    {
        $me = User::factory()->create();
        Reference::factory()->for($me)->create(['title' => 'Alpha']);
        $payload = "' OR 1=1 -- ";
        $res = $this->actingAs($me)->get('/references?q=' . urlencode($payload));
        fwrite(STDERR, "\n[P6] sqli search status=" . $res->getStatusCode() . "\n");
        $res->assertOk();
    }

    /** PROBE 7: production CSP vs Ziggy inline script in rendered HTML */
    public function test_probe_production_csp_vs_inline_scripts(): void
    {
        $this->app['env'] = 'production';
        config(['app.env' => 'production']);

        $res = $this->get('/login');
        $csp = (string) $res->headers->get('Content-Security-Policy');
        $body = $res->getContent();
        $inlineScripts = substr_count($body, '<script');
        fwrite(STDERR, "\n[P7] prod CSP=" . $csp . "\n[P7] '<script' occurrences in login HTML=" . $inlineScripts . "\n");
        if (preg_match_all('/<script[^>]*>(.{0,60})/s', $body, $m)) {
            foreach (array_slice($m[1], 0, 5) as $snippet) {
                fwrite(STDERR, "[P7] script starts: " . trim(preg_replace('/\s+/', ' ', $snippet)) . "\n");
            }
        }
        $this->assertTrue(true);
    }

    /** PROBE 8: session cookie security flags */
    public function test_probe_session_cookie_flags(): void
    {
        fwrite(STDERR, "\n[P8] session.secure=" . var_export(config('session.secure'), true)
            . " http_only=" . var_export(config('session.http_only'), true)
            . " same_site=" . var_export(config('session.same_site'), true)
            . " encrypt=" . var_export(config('session.encrypt'), true)
            . " driver=" . var_export(config('session.driver'), true)
            . " app.debug=" . var_export(config('app.debug'), true)
            . " app.env=" . var_export(config('app.env'), true) . "\n");
        $this->assertTrue(true);
    }

    /** PROBE 9: another user's id in route -> 403 vs 404 (existence oracle) */
    public function test_probe_foreign_reference_status(): void
    {
        $me = User::factory()->create();
        $other = User::factory()->create();
        $ref = Reference::factory()->for($other)->create();
        $foreign = $this->actingAs($me)->get("/references/{$ref->id}");
        $missing = $this->actingAs($me)->get('/references/999999');
        fwrite(STDERR, "\n[P9] foreign=" . $foreign->getStatusCode()
            . " missing=" . $missing->getStatusCode() . "\n");
        $this->assertTrue(true);
    }

    /** PROBE 10: import authors array unbounded / huge single author */
    public function test_probe_import_huge_author(): void
    {
        $me = User::factory()->create();
        $big = str_repeat('A', 100000);
        $bib = "@article{z,\n title = {Big},\n author = {{$big}},\n year = {2020}\n}\n";
        $res = $this->actingAs($me)->post('/references/import', [
            'file' => UploadedFile::fake()->createWithContent('big.bib', $bib),
        ]);
        fwrite(STDERR, "\n[P10] huge-author status=" . $res->getStatusCode()
            . " storedLen=" . (Reference::first() ? strlen(json_encode(Reference::first()->authors)) : 'n/a') . "\n");
        $this->assertTrue(true);
    }
}
