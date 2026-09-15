<?php

namespace Tests\Unit;

use App\Support\BibtexExporter;
use PHPUnit\Framework\TestCase;

/**
 * TDD RED phase — BibTeX export.
 *
 * This is the highest-risk logic in the app: type mapping, field escaping,
 * and author formatting all have well-defined but easy-to-get-wrong rules.
 * A malformed .bib silently breaks the user's thesis bibliography.
 */
class BibtexExporterTest extends TestCase
{
    public function test_exports_a_journal_article_with_required_fields(): void
    {
        $ref = [
            'type'   => 'journal',
            'title'  => 'Attention Is All You Need',
            'authors' => ['Vaswani, Ashish', 'Shazeer, Noam'],
            'year'   => 2017,
            'doi'    => '10.48550/arXiv.1706.03762',
            'url'    => null,
            'notes'  => null,
        ];

        $out = (new BibtexExporter())->export([$ref], 'vaswani2017');

        $this->assertStringContainsString('@article{vaswani2017,', $out);
        $this->assertStringContainsString('title = {Attention Is All You Need}', $out);
        $this->assertStringContainsString('author = {Vaswani, Ashish and Shazeer, Noam}', $out);
        $this->assertStringContainsString('year = {2017}', $out);
        $this->assertStringContainsString('doi = {10.48550/arXiv.1706.03762}', $out);
    }

    public function test_maps_each_reference_type_to_its_bibtex_entry(): void
    {
        $exporter = new BibtexExporter();
        $base = ['title' => 'T', 'authors' => ['A, B'], 'year' => 2020,
                 'doi' => null, 'url' => null, 'notes' => null];

        $expected = [
            'journal'   => '@article',
            'book'      => '@book',
            'conference' => '@inproceedings',
            'thesis'    => '@phdthesis',
            'web'       => '@misc',
        ];

        foreach ($expected as $type => $entry) {
            $out = $exporter->export([$base + ['type' => $type]], 'key');
            $this->assertStringContainsString($entry . '{key,', $out, "type {$type}");
        }
    }

    public function test_escapes_characters_that_break_bibtex(): void
    {
        $ref = [
            'type' => 'journal',
            'title' => 'Cost & Benefit: 100% of {Data} #1_under_score',
            'authors' => ['Smith, J.'],
            'year' => 2021,
            'doi' => null, 'url' => null, 'notes' => null,
        ];

        $out = (new BibtexExporter())->export([$ref], 'smith2021');

        // & % $ # _ { } must be neutralised or the entry will not parse
        $this->assertStringContainsString('\&', $out);
        $this->assertStringContainsString('\%', $out);
        $this->assertStringContainsString('\#', $out);
        $this->assertStringContainsString('\_', $out);
    }

    public function test_omits_optional_fields_when_empty(): void
    {
        $ref = [
            'type' => 'book',
            'title' => 'A Book',
            'authors' => ['Doe, Jane'],
            'year' => 2019,
            'doi' => null, 'url' => null, 'notes' => null,
        ];

        $out = (new BibtexExporter())->export([$ref], 'doe2019');

        $this->assertStringNotContainsString('doi =', $out);
        $this->assertStringNotContainsString('url =', $out);
        $this->assertStringNotContainsString('note =', $out);
    }

    public function test_generates_a_stable_citation_key_when_none_given(): void
    {
        $ref = [
            'type' => 'journal',
            'title' => 'Deep Learning',
            'authors' => ['LeCun, Yann'],
            'year' => 2015,
            'doi' => null, 'url' => null, 'notes' => null,
        ];

        $out = (new BibtexExporter())->export([$ref]);

        // surname + year, lowercased, ascii-only
        $this->assertStringContainsString('@article{lecun2015,', $out);
    }

    public function test_exporting_an_empty_list_returns_an_empty_string(): void
    {
        $this->assertSame('', (new BibtexExporter())->export([]));
    }
}
