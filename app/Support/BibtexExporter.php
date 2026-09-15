<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Converts reference records into BibTeX.
 *
 * Kept as pure logic (no Eloquent, no IO) so it can be tested without a
 * database — the formatting rules are the risky part, not the data access.
 */
class BibtexExporter
{
    /** Map our internal type names onto BibTeX entry types. */
    private const TYPE_MAP = [
        'journal'    => 'article',
        'book'       => 'book',
        'conference' => 'inproceedings',
        'thesis'     => 'phdthesis',
        'web'        => 'misc',
    ];

    /**
     * @param  array<int, array<string, mixed>>  $references
     */
    public function export(array $references, ?string $key = null): string
    {
        if ($references === []) {
            return '';
        }

        $entries = [];

        foreach ($references as $ref) {
            $entries[] = $this->entry($ref, $key);
        }

        return implode("\n\n", $entries) . "\n";
    }

    /**
     * @param  array<string, mixed>  $ref
     */
    private function entry(array $ref, ?string $key): string
    {
        $type = self::TYPE_MAP[$ref['type'] ?? ''] ?? 'misc';

        // Priority: explicit override > key stored on the row > derived.
        // A key that came in via BibTeX import MUST survive an export,
        // otherwise round-tripping a .bib file silently renames every entry.
        $citeKey = $key
            ?: ($ref['cite_key'] ?? null)
            ?: $this->keyFor($ref);

        $fields = [
            'title'  => $ref['title'] ?? null,
            'author' => $this->authors($ref['authors'] ?? []),
            'year'   => $ref['year'] ?? null,
            'doi'    => $ref['doi'] ?? null,
            'url'    => $ref['url'] ?? null,
            'note'   => $ref['notes'] ?? null,
        ];

        $lines = ["@{$type}{{$citeKey},"];

        foreach ($fields as $name => $value) {
            if ($value === null || $value === '' || $value === []) {
                continue;
            }
            $lines[] = sprintf('  %s = {%s},', $name, $this->escape((string) $value));
        }

        $lines[] = '}';

        return implode("\n", $lines);
    }

    /**
     * @param  array<int, string>  $authors
     */
    private function authors(array $authors): string
    {
        return implode(' and ', array_filter($authors));
    }

    /**
     * Build a citation key from the first author's surname plus the year,
     * e.g. "LeCun, Yann" + 2015 -> "lecun2015".
     *
     * @param  array<string, mixed>  $ref
     */
    private function keyFor(array $ref): string
    {
        $surname = 'anon';

        $first = $ref['authors'][0] ?? null;
        if (is_string($first) && $first !== '') {
            $surname = str_contains($first, ',')
                ? explode(',', $first)[0]
                : $first;
        }

        $surname = strtolower(preg_replace('/[^a-z]/i', '', $this->ascii(trim($surname))) ?? '');

        return $surname . (string) ($ref['year'] ?? '');
    }

    /**
     * Neutralise characters that would break BibTeX parsing.
     * strtr() replaces simultaneously, so escaping never double-applies.
     */
    private function escape(string $value): string
    {
        return strtr($value, [
            '&' => '\\&',
            '%' => '\\%',
            '$' => '\\$',
            '#' => '\\#',
            '_' => '\\_',
            '{' => '\\{',
            '}' => '\\}',
        ]);
    }

    private function ascii(string $value): string
    {
        $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);

        return $converted !== false
            ? $converted
            : (preg_replace('/[^\x20-\x7E]/', '', $value) ?? '');
    }
}
