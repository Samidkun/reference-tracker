<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Resolves a DOI into reference metadata via the Crossref API.
 *
 * Contract: resolve() returns an array of fields on success, or null on ANY
 * failure (invalid format, 404, 5xx, timeout, malformed payload). It never
 * throws and never returns a partial shape — the caller shows "fill it in
 * manually" instead of a broken form.
 */
class DoiResolver
{
    private const ENDPOINT = 'https://api.crossref.org/works/';

    /** Crossref type -> our internal type. */
    private const TYPE_MAP = [
        'journal-article'     => 'journal',
        'book'                => 'book',
        'book-chapter'        => 'book',
        'proceedings-article' => 'conference',
        'dissertation'        => 'thesis',
        'posted-content'      => 'web',
        'report'              => 'web',
    ];

    /**
     * @return array<string, mixed>|null
     */
    public function resolve(string $doi): ?array
    {
        $doi = trim($doi);

        if (! $this->looksLikeDoi($doi)) {
            return null;
        }

        try {
            $response = Http::timeout(8)
                ->withHeaders(['Accept' => 'application/json'])
                ->get(self::ENDPOINT . rawurlencode($doi));
        } catch (ConnectionException) {
            return null;
        } catch (Throwable) {
            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        $message = $response->json('message');

        if (! is_array($message) || ! isset($message['title'][0])) {
            return null;
        }

        return [
            'title'   => (string) $message['title'][0],
            'authors' => $this->authors($message['author'] ?? []),
            'year'    => $this->year($message),
            'type'    => self::TYPE_MAP[$message['type'] ?? ''] ?? 'web',
            'doi'     => (string) ($message['DOI'] ?? $doi),
            'url'     => isset($message['URL']) ? (string) $message['URL'] : null,
            'notes'   => null,
        ];
    }

    /** DOIs look like 10.<registrant>/<suffix>. Cheap pre-filter, no network. */
    private function looksLikeDoi(string $doi): bool
    {
        return (bool) preg_match('#^10\.\d{4,9}/\S+$#', $doi);
    }

    /**
     * @param  array<int, array<string, mixed>>  $authors
     * @return array<int, string>
     */
    private function authors(array $authors): array
    {
        $out = [];

        foreach ($authors as $a) {
            $family = trim((string) ($a['family'] ?? ''));
            $given  = trim((string) ($a['given'] ?? ''));

            if ($family === '') {
                continue;
            }

            $out[] = $given !== '' ? "{$family}, {$given}" : $family;
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $message
     */
    private function year(array $message): ?int
    {
        foreach (['published-print', 'published-online', 'issued', 'created'] as $field) {
            $parts = $message[$field]['date-parts'][0] ?? null;
            if (is_array($parts) && isset($parts[0]) && is_numeric($parts[0])) {
                return (int) $parts[0];
            }
        }

        return null;
    }
}
