<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Parses BibTeX into our internal reference shape.
 *
 * Deliberately tolerant: real .bib files contain @string macros, comments,
 * inconsistent quoting, and the occasional broken entry. A malformed entry is
 * skipped rather than aborting the whole import — losing one reference is far
 * better than refusing the user's entire bibliography.
 */
class BibtexParser
{
    /** BibTeX entry type -> our internal type. */
    private const TYPE_MAP = [
        'article'       => 'journal',
        'book'          => 'book',
        'inproceedings' => 'conference',
        'conference'    => 'conference',
        'phdthesis'     => 'thesis',
        'mastersthesis' => 'thesis',
        'misc'          => 'web',
        'online'        => 'web',
    ];

    /**
     * @return array<int, array<string, mixed>>
     */
    public function parse(string $bibtex): array
    {
        $entries = [];

        foreach ($this->splitEntries($bibtex) as $raw) {
            $parsed = $this->parseEntry($raw);
            if ($parsed !== null) {
                $entries[] = $parsed;
            }
        }

        return $entries;
    }

    /**
     * Find each top-level @type{...} block, tracking brace depth so nested
     * braces inside values do not terminate the entry early.
     *
     * @return array<int, string>
     */
    private function splitEntries(string $bibtex): array
    {
        $blocks = [];
        $len = strlen($bibtex);
        $i = 0;

        while ($i < $len) {
            $at = strpos($bibtex, '@', $i);
            if ($at === false) {
                break;
            }

            $brace = strpos($bibtex, '{', $at);
            if ($brace === false) {
                break;
            }

            $depth = 0;
            $j = $brace;
            for (; $j < $len; $j++) {
                if ($bibtex[$j] === '{') {
                    $depth++;
                } elseif ($bibtex[$j] === '}') {
                    $depth--;
                    if ($depth === 0) {
                        break;
                    }
                }
            }

            if ($depth === 0) {
                $blocks[] = substr($bibtex, $at, $j - $at + 1);
                $i = $j + 1;
            } else {
                // unbalanced — stop scanning, keep what we have
                break;
            }
        }

        return $blocks;
    }

    /**
     * @return array<string, mixed>|null  null when the entry is unusable
     */
    private function parseEntry(string $raw): ?array
    {
        if (!preg_match('/^@(\w+)\s*\{\s*([^,\s}]*)\s*,/s', $raw, $m)) {
            return null;
        }

        $bibType = strtolower($m[1]);

        // @string / @preamble / @comment are not references
        if (in_array($bibType, ['string', 'preamble', 'comment'], true)) {
            return null;
        }

        $key = trim($m[2]);
        $body = substr($raw, strlen($m[0]), -1); // drop trailing }

        $fields = $this->parseFields($body);

        return [
            'key'     => $key,
            'type'    => self::TYPE_MAP[$bibType] ?? 'web',
            'title'   => $this->unescape($fields['title'] ?? ''),
            'authors' => $this->parseAuthors($fields['author'] ?? ''),
            'year'    => isset($fields['year']) && $fields['year'] !== ''
                ? (int) preg_replace('/\D/', '', $fields['year'])
                : null,
            'doi'     => $fields['doi'] ?? null,
            'url'     => $fields['url'] ?? null,
            'notes'   => isset($fields['note']) ? $this->unescape($fields['note']) : null,
        ];
    }

    /**
     * Read name = value pairs, handling both {braced} and "quoted" values,
     * with nested braces inside braced values.
     *
     * @return array<string, string>
     */
    private function parseFields(string $body): array
    {
        $fields = [];
        $len = strlen($body);
        $i = 0;

        while ($i < $len) {
            // field name
            if (!preg_match('/\G\s*([a-zA-Z][a-zA-Z0-9_-]*)\s*=\s*/', $body, $m, 0, $i)) {
                $i++;
                continue;
            }
            $name = strtolower($m[1]);
            $i += strlen($m[0]);

            if ($i >= $len) {
                break;
            }

            $opener = $body[$i];

            if ($opener === '{') {
                $depth = 0;
                $start = $i + 1;
                for (; $i < $len; $i++) {
                    if ($body[$i] === '{') {
                        $depth++;
                    } elseif ($body[$i] === '}') {
                        $depth--;
                        if ($depth === 0) {
                            break;
                        }
                    }
                }
                $fields[$name] = substr($body, $start, $i - $start);
                $i++;
            } elseif ($opener === '"') {
                $start = $i + 1;
                $i++;
                for (; $i < $len; $i++) {
                    if ($body[$i] === '"' && $body[$i - 1] !== '\\') {
                        break;
                    }
                }
                $fields[$name] = substr($body, $start, $i - $start);
                $i++;
            } else {
                // bare value up to comma
                $start = $i;
                while ($i < $len && $body[$i] !== ',') {
                    $i++;
                }
                $fields[$name] = trim(substr($body, $start, $i - $start));
            }

            // skip to next comma
            while ($i < $len && $body[$i] !== ',') {
                $i++;
            }
            $i++;
        }

        return $fields;
    }

    /**
     * @return array<int, string>
     */
    private function parseAuthors(string $author): array
    {
        if (trim($author) === '') {
            return [];
        }

        $parts = preg_split('/\s+and\s+/i', $author) ?: [];

        return array_values(array_filter(array_map(
            fn ($a) => trim($this->unescape($a)),
            $parts
        ), fn ($a) => $a !== ''));
    }

    /**
     * Undo the escaping applied on export, so the stored value is clean and
     * a later export escapes exactly once.
     */
    private function unescape(string $value): string
    {
        return strtr($value, [
            '\\&' => '&',
            '\\%' => '%',
            '\\$' => '$',
            '\\#' => '#',
            '\\_' => '_',
        ]);
    }
}
