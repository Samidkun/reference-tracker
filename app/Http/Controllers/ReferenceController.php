<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\DoiLookupRequest;
use App\Http\Requests\ImportBibtexRequest;
use App\Http\Requests\StoreReferenceRequest;
use App\Http\Requests\UpdateReferenceRequest;
use App\Models\Reference;
use App\Models\Tag;
use App\Support\BibtexExporter;
use App\Support\BibtexParser;
use App\Support\DoiResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReferenceController extends Controller
{
    public function index(Request $request): Response
    {
        $references = Reference::query()
            ->where('user_id', Auth::id())
            ->search($request->query('q'))
            ->with('tags')
            ->latest()
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('References/Index', [
            'references' => $references,
            'filters'    => ['q' => $request->query('q', '')],
            'tags'       => Tag::where('user_id', Auth::id())->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('References/Form', [
            'reference' => null,
            'tags'      => Tag::where('user_id', Auth::id())->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function store(StoreReferenceRequest $request): RedirectResponse
    {
        $reference = Reference::create([
            ...$request->safe()->except('tags'),
            'user_id' => Auth::id(),   // ownership is server-side, always
        ]);

        $this->syncOwnedTags($reference, $request->validated('tags', []));

        return redirect()->route('references.index')->with('success', 'Reference saved.');
    }

    public function show(Reference $reference): Response
    {
        $this->authorize('view', $reference);

        return Inertia::render('References/Show', [
            'reference' => $reference->load('tags'),
        ]);
    }

    public function edit(Reference $reference): Response
    {
        $this->authorize('update', $reference);

        return Inertia::render('References/Form', [
            'reference' => $reference->load('tags'),
            'tags'      => Tag::where('user_id', Auth::id())->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function update(UpdateReferenceRequest $request, Reference $reference): RedirectResponse
    {
        $this->authorize('update', $reference);

        $reference->update($request->safe()->except('tags'));

        // Only touch tags if the client actually sent the field.
        //
        // A partial update (PUT without `tags`) used to call sync([]) and
        // silently detach every tag. The browser form always sends `tags`, so
        // the UI hid the bug - but curl, an API consumer, or a future mobile
        // client would wipe a user's tags by omitting one key. Absent means
        // "leave alone"; an explicit empty array means "clear".
        if ($request->has('tags')) {
            $this->syncOwnedTags($reference, $request->validated('tags', []));
        }

        return redirect()->route('references.index')->with('success', 'Reference updated.');
    }

    public function destroy(Reference $reference): RedirectResponse
    {
        $this->authorize('delete', $reference);

        $reference->delete();

        return redirect()->route('references.index')->with('success', 'Reference deleted.');
    }

    public function export(): StreamedResponse
    {
        $references = Reference::where('user_id', Auth::id())->with('tags')->get();

        $bibtex = (new BibtexExporter())->export($references->toArray());

        return response()->streamDownload(
            fn () => print($bibtex),
            'references-' . now()->format('Y-m-d') . '.bib',
            ['Content-Type' => 'application/x-bibtex'],
        );
    }

    /**
     * Import a .bib file.
     *
     * Three properties this must hold, all of which were broken before:
     *
     *  1. ATOMIC — either the whole file lands or none of it does. A failure
     *     halfway through used to leave the earlier rows committed.
     *  2. TOLERANT OF DUPLICATES — a DOI that already exists (in the file or
     *     in the library) is skipped and counted, not thrown as a unique-index
     *     violation that 500s the request.
     *  3. NEVER SURPRISING — the flash message reports what happened, so the
     *     user is not left guessing why 40 entries became 37.
     */
    public function import(ImportBibtexRequest $request): RedirectResponse
    {
        $entries = (new BibtexParser())->parse(
            (string) file_get_contents($request->file('file')->getRealPath())
        );

        $userId = Auth::id();
        $created = 0;
        $skipped = 0;

        DB::transaction(function () use ($entries, $userId, &$created, &$skipped) {
            // Seed the "seen" set with DOIs this user already owns, so a
            // re-import of the same file does not duplicate the library.
            $seen = Reference::where('user_id', $userId)
                ->whereNotNull('doi')
                ->pluck('doi')
                ->map(fn ($d) => mb_strtolower($d))
                ->all();
            $seen = array_flip($seen);

            foreach ($entries as $entry) {
                $fields = $this->sanitiseImportedEntry($entry);

                $doi = $fields['doi'] !== null
                    ? mb_strtolower($fields['doi'])
                    : null;

                if ($doi !== null && isset($seen[$doi])) {
                    $skipped++;

                    continue;
                }

                Reference::create([
                    'user_id'  => $userId,
                    ...$fields,
                ]);

                if ($doi !== null) {
                    $seen[$doi] = true;
                }

                $created++;
            }
        });

        $message = "Imported {$created} reference(s).";
        if ($skipped > 0) {
            $message .= " Skipped {$skipped} duplicate(s).";
        }

        return redirect()->route('references.index')->with('success', $message);
    }

    public function doiLookup(DoiLookupRequest $request, DoiResolver $resolver): JsonResponse
    {
        $result = $resolver->resolve($request->validated('doi'));

        if ($result === null) {
            return response()->json(['message' => 'DOI not found.'], 404);
        }

        return response()->json($result);
    }

    /**
     * Coerce one parsed .bib entry into a row the schema will actually accept.
     *
     * WHY THIS EXISTS: the manual form validates every field, but the import
     * path did not — so a .bib file could hand the database values the schema
     * rejects, and the user got a raw 500 with a 1.2 MB stack trace instead of
     * an imported library. Observed failures before this:
     *
     *   - year = 999999  -> "Out of range" (column is UNSIGNED SMALLINT, max 65535)
     *   - doi  = ''      -> duplicate-key violation (' ' is not NULL, so two
     *                       empty DOIs collide on unique(user_id, doi))
     *   - author = 100 kB -> stored verbatim, no length limit at all
     *   - title > 255    -> column overflow
     *
     * The parser stays tolerant (a malformed entry is skipped, not fatal), so
     * the sanitiser's job is to keep the GOOD entry and drop only the part
     * that cannot be stored — never to reject the whole file.
     *
     * @param  array<string, mixed>  $entry
     * @return array<string, mixed>
     */
    private function sanitiseImportedEntry(array $entry): array
    {
        $maxYear = (int) date('Y') + 1;

        $year = $entry['year'] ?? null;
        $year = (is_int($year) || (is_string($year) && ctype_digit($year)))
            ? (int) $year
            : null;

        // Out-of-range years become "unknown" rather than crashing the import.
        if ($year !== null && ($year < 1000 || $year > $maxYear)) {
            $year = null;
        }

        $authors = [];
        foreach ((array) ($entry['authors'] ?? []) as $author) {
            $author = trim(mb_substr((string) $author, 0, 200));
            if ($author !== '') {
                $authors[] = $author;
            }
        }
        $authors = array_slice($authors, 0, 50); // matches the form's max:50

        // A DOI longer than the column is worse than no DOI: truncating it
        // would produce a DOI that looks valid but points nowhere.
        $doi = $entry['doi'] ?? null;
        $doi = $doi !== null ? trim((string) $doi) : null;
        if ($doi === '' || $doi === null || mb_strlen($doi) > 255) {
            $doi = null;
        }

        return [
            'title'    => mb_substr(trim((string) ($entry['title'] ?? '')) ?: 'Untitled', 0, 500),
            'authors'  => $authors,
            'year'     => $year,
            'type'     => in_array($entry['type'] ?? '', ['journal', 'book', 'conference', 'thesis', 'web'], true)
                ? $entry['type']
                : 'web',
            'doi'      => $doi,
            'url'      => isset($entry['url']) && $entry['url'] !== ''
                ? mb_substr((string) $entry['url'], 0, 2048)
                : null,
            // matches the form's max:20000
            'notes'    => isset($entry['notes']) && $entry['notes'] !== ''
                ? mb_substr((string) $entry['notes'], 0, 20000)
                : null,
            'cite_key' => isset($entry['key']) && $entry['key'] !== ''
                ? mb_substr((string) $entry['key'], 0, 255)
                : null,
        ];
    }

    /**
     * Attach only tags that belong to the current user.
     *
     * Without this filter, a client could POST an arbitrary tag id and link
     * their reference to someone else's tag row — an isolation leak that
     * validation alone does not catch (the tag does exist, just not for you).
     */
    private function syncOwnedTags(Reference $reference, array $tagIds): void
    {
        if ($tagIds === []) {
            $reference->tags()->sync([]);

            return;
        }

        $owned = Tag::where('user_id', Auth::id())
            ->whereIn('id', $tagIds)
            ->pluck('id')
            ->all();

        $reference->tags()->sync($owned);
    }
}
