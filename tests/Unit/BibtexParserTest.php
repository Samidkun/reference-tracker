<?php

namespace Tests\Unit;

use App\Support\BibtexParser;
use PHPUnit\Framework\TestCase;

/**
 * TDD RED phase — BibTeX import.
 *
 * .bib files in the wild are messy: inconsistent indentation, braces vs
 * quotes, @string macros, comments, missing fields. A parser that only
 * handles the happy path will silently drop the user's references.
 */
class BibtexParserTest extends TestCase
{
    public function test_parses_a_single_entry(): void
    {
        $bib = <<<'BIB'
        @article{vaswani2017,
          title = {Attention Is All You Need},
          author = {Vaswani, Ashish and Shazeer, Noam},
          year = {2017},
          doi = {10.48550/arXiv.1706.03762}
        }
        BIB;

        $result = (new BibtexParser())->parse($bib);

        $this->assertCount(1, $result);
        $this->assertSame('vaswani2017', $result[0]['key']);
        // parsed into OUR internal type, not the raw BibTeX type:
        // article -> journal, so a later export maps journal -> article again
        $this->assertSame('journal', $result[0]['type']);
        $this->assertSame('Attention Is All You Need', $result[0]['title']);
        $this->assertSame(['Vaswani, Ashish', 'Shazeer, Noam'], $result[0]['authors']);
        $this->assertSame(2017, $result[0]['year']);
    }

    public function test_round_trips_through_export_and_import(): void
    {
        $original = [[
            'type' => 'conference',
            'title' => 'Round Trip',
            'authors' => ['Turing, Alan'],
            'year' => 1950,
            'doi' => '10.1/xyz',
            'url' => null,
            'notes' => null,
        ]];

        $bib = (new \App\Support\BibtexExporter())->export($original, 'turing1950');
        $back = (new BibtexParser())->parse($bib);

        $this->assertCount(1, $back);
        $this->assertSame('conference', $back[0]['type']);
        $this->assertSame('Round Trip', $back[0]['title']);
        $this->assertSame(['Turing, Alan'], $back[0]['authors']);
        $this->assertSame(1950, $back[0]['year']);
        $this->assertSame('10.1/xyz', $back[0]['doi']);
    }

    public function test_parses_multiple_entries_and_ignores_surrounding_noise(): void
    {
        $bib = <<<'BIB'
        % a comment line
        @string{ieee = {IEEE}}

        @book{knuth1984,
          title = {The TeXbook},
          author = {Knuth, Donald},
          year = {1984}
        }

        @misc{web2020,
          title = {Some Website},
          author = {Doe, Jane},
          year = {2020},
          url = {https://example.com}
        }
        BIB;

        $result = (new BibtexParser())->parse($bib);

        $this->assertCount(2, $result);
        $this->assertSame('knuth1984', $result[0]['key']);
        $this->assertSame('web2020', $result[1]['key']);
    }

    public function test_accepts_values_in_quotes_as_well_as_braces(): void
    {
        $bib = <<<'BIB'
        @article{quoted2021,
          title = "A Quoted Title",
          author = "Smith, J.",
          year = "2021"
        }
        BIB;

        $result = (new BibtexParser())->parse($bib);

        $this->assertSame('A Quoted Title', $result[0]['title']);
        $this->assertSame('Smith, J.', $result[0]['authors'][0]);
    }

    public function test_handles_nested_braces_inside_a_value(): void
    {
        $bib = <<<'BIB'
        @article{nested2022,
          title = {The {DNA} of {Software}},
          author = {Lee, A.},
          year = {2022}
        }
        BIB;

        $result = (new BibtexParser())->parse($bib);

        $this->assertSame('The {DNA} of {Software}', $result[0]['title']);
    }

    public function test_tolerates_missing_optional_fields(): void
    {
        $bib = <<<'BIB'
        @misc{minimal,
          title = {Only A Title}
        }
        BIB;

        $result = (new BibtexParser())->parse($bib);

        $this->assertCount(1, $result);
        $this->assertSame('Only A Title', $result[0]['title']);
        $this->assertSame([], $result[0]['authors']);
        $this->assertNull($result[0]['year']);
    }

    public function test_skips_malformed_entries_instead_of_throwing(): void
    {
        $bib = <<<'BIB'
        @article{good2020,
          title = {Valid One},
          year = {2020}
        }

        @article{broken,
          title = {Unclosed brace
        BIB;

        $result = (new BibtexParser())->parse($bib);

        // the valid entry survives; the broken one is dropped, not fatal
        $this->assertGreaterThanOrEqual(1, count($result));
        $this->assertSame('good2020', $result[0]['key']);
    }

    public function test_returns_empty_array_for_input_with_no_entries(): void
    {
        $this->assertSame([], (new BibtexParser())->parse(''));
        $this->assertSame([], (new BibtexParser())->parse('% only a comment'));
    }

    public function test_unescapes_characters_on_import(): void
    {
        $bib = <<<'BIB'
        @article{esc2020,
          title = {Cost \& Benefit \#1},
          year = {2020}
        }
        BIB;

        $result = (new BibtexParser())->parse($bib);

        // stored clean, so re-export escapes once (not twice)
        $this->assertSame('Cost & Benefit #1', $result[0]['title']);
    }
}
