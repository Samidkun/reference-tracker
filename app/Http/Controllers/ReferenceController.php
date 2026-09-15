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

        $this->syncOwnedTags($reference, $request->validated('tags', []));

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

    public function import(ImportBibtexRequest $request): RedirectResponse
    {
        $entries = (new BibtexParser())->parse(
            (string) file_get_contents($request->file('file')->getRealPath())
        );

        $created = 0;

        foreach ($entries as $entry) {
            Reference::create([
                'user_id' => Auth::id(),
                'title'   => $entry['title'] ?: 'Untitled',
                'authors' => $entry['authors'] ?: [],
                'year'    => $entry['year'],
                'type'    => $entry['type'],
                'doi'     => $entry['doi'],
                'url'     => $entry['url'],
                'notes'   => $entry['notes'],
                'cite_key' => $entry['key'],
            ]);

            $created++;
        }

        return redirect()->route('references.index')
            ->with('success', "Imported {$created} reference(s).");
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
