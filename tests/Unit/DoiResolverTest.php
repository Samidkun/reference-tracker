<?php

namespace Tests\Unit;

use App\Support\DoiResolver;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * TDD RED phase — DOI -> reference metadata via Crossref.
 *
 * Network code is where apps break in production: timeouts, 404s, rate
 * limits, malformed payloads. Every one of these must degrade gracefully
 * into "fill it in manually", never into a 500.
 */
class DoiResolverTest extends TestCase
{
    private function crossrefPayload(): array
    {
        return [
            'message' => [
                'title' => ['Attention Is All You Need'],
                'author' => [
                    ['family' => 'Vaswani', 'given' => 'Ashish'],
                    ['family' => 'Shazeer', 'given' => 'Noam'],
                ],
                'published-print' => ['date-parts' => [[2017, 6]]],
                'type' => 'journal-article',
                'DOI' => '10.48550/arXiv.1706.03762',
                'URL' => 'https://doi.org/10.48550/arXiv.1706.03762',
            ],
        ];
    }

    public function test_resolves_a_valid_doi_into_reference_fields(): void
    {
        Http::fake([
            'api.crossref.org/*' => Http::response($this->crossrefPayload(), 200),
        ]);

        $result = (new DoiResolver())->resolve('10.48550/arXiv.1706.03762');

        $this->assertSame('Attention Is All You Need', $result['title']);
        $this->assertSame(['Vaswani, Ashish', 'Shazeer, Noam'], $result['authors']);
        $this->assertSame(2017, $result['year']);
        $this->assertSame('journal', $result['type']);
        $this->assertSame('10.48550/arXiv.1706.03762', $result['doi']);
    }

    public function test_returns_null_when_the_doi_does_not_exist(): void
    {
        Http::fake(['api.crossref.org/*' => Http::response('Not Found', 404)]);

        $this->assertNull((new DoiResolver())->resolve('10.9999/does-not-exist'));
    }

    public function test_returns_null_on_server_error_instead_of_throwing(): void
    {
        Http::fake(['api.crossref.org/*' => Http::response('Boom', 500)]);

        $this->assertNull((new DoiResolver())->resolve('10.1000/xyz'));
    }

    public function test_returns_null_on_network_failure(): void
    {
        Http::fake(['api.crossref.org/*' => fn () => throw new \Illuminate\Http\Client\ConnectionException('timeout')]);

        $this->assertNull((new DoiResolver())->resolve('10.1000/xyz'));
    }

    public function test_returns_null_on_malformed_payload(): void
    {
        Http::fake(['api.crossref.org/*' => Http::response(['unexpected' => 'shape'], 200)]);

        $this->assertNull((new DoiResolver())->resolve('10.1000/xyz'));
    }

    /**
     * Note: test fixtures use 10.1000/... because a real DOI registrant
     * prefix is 4-9 digits. Using "10.1/x" would be rejected by the format
     * pre-filter before any network call, which is correct behaviour but
     * makes the fixture unusable for testing the response handling.
     */
    public function test_rejects_obviously_invalid_doi_format_without_calling_the_api(): void
    {
        Http::fake();

        $this->assertNull((new DoiResolver())->resolve('not-a-doi'));
        $this->assertNull((new DoiResolver())->resolve(''));
        Http::assertNothingSent();
    }

    public function test_maps_crossref_types_to_internal_types(): void
    {
        $cases = [
            'journal-article' => 'journal',
            'book' => 'book',
            'proceedings-article' => 'conference',
            'dissertation' => 'thesis',
            'posted-content' => 'web',
        ];

        // IMPORTANT: Http::fake() called repeatedly inside a loop does NOT
        // re-register — the first stub keeps winning for every later call
        // (verified against the framework). Use ONE fake whose callback
        // inspects the request URL, so each DOI resolves independently.
        Http::fake(function ($request) use ($cases) {
            $doi = rawurldecode(basename(parse_url($request->url(), PHP_URL_PATH)));

            foreach ($cases as $crossrefType => $internal) {
                if ($doi === '10.1000/' . md5($crossrefType)) {
                    return Http::response([
                        'message' => [
                            'title' => ['T'],
                            'author' => [['family' => 'A', 'given' => 'B']],
                            'type' => $crossrefType,
                            'DOI' => $doi,
                        ],
                    ], 200);
                }
            }

            return Http::response('Not Found', 404);
        });

        foreach ($cases as $crossrefType => $expected) {
            $result = (new DoiResolver())->resolve('10.1000/' . md5($crossrefType));

            $this->assertNotNull($result, "resolve returned null for {$crossrefType}");
            $this->assertSame($expected, $result['type'], "crossref type: {$crossrefType}");
        }
    }

    public function test_falls_back_to_issued_date_when_published_print_is_missing(): void
    {
        Http::fake([
            'api.crossref.org/*' => Http::response([
                'message' => [
                    'title' => ['Online First'],
                    'author' => [['family' => 'Doe', 'given' => 'J']],
                    'issued' => ['date-parts' => [[2021, 3, 1]]],
                    'type' => 'journal-article',
                    'DOI' => '10.1000/y',
                ],
            ], 200),
        ]);

        $this->assertSame(2021, (new DoiResolver())->resolve('10.1000/y')['year']);
    }
}
