import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import ConfirmDialog from '@/Components/ConfirmDialog';
import TableSkeleton from '@/Components/TableSkeleton';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';

const TYPE_LABEL = {
    journal: 'Journal',
    book: 'Book',
    conference: 'Conference',
    thesis: 'Thesis',
    web: 'Web',
};

/** Laravel pagination labels arrive HTML-encoded; decode instead of injecting HTML. */
function decodeLabel(label) {
    return String(label)
        .replace(/&laquo;/g, '\u00ab')
        .replace(/&raquo;/g, '\u00bb')
        .replace(/&amp;/g, '&')
        .replace(/&lt;/g, '<')
        .replace(/&gt;/g, '>');
}

function formatAuthors(authors) {
    if (!authors || authors.length === 0) return 'No author';
    if (authors.length === 1) return authors[0];
    if (authors.length === 2) return authors.join(' & ');
    return `${authors[0]} et al.`;
}

export default function Index() {
    const { references, filters, tags, flash } = usePage().props;

    const [q, setQ] = useState(filters.q ?? '');
    const [pendingDelete, setPendingDelete] = useState(null);
    const [loading, setLoading] = useState(false);

    // Keep the input in sync when the server returns a different query
    // (back button, shared link).
    useEffect(() => setQ(filters.q ?? ''), [filters.q]);

    // Inertia's progress bar is 3px and easy to miss; the contract asks for a
    // visible loading state, so track the router's own events.
    useEffect(() => {
        const offStart = router.on('start', () => setLoading(true));
        const offFinish = router.on('finish', () => setLoading(false));
        return () => {
            offStart();
            offFinish();
        };
    }, []);

    const activeTag = filters.tag ?? null;
    const isFiltered = Boolean(filters.q) || Boolean(activeTag);

    const buildQuery = (overrides = {}) => {
        const next = { q: q || undefined, tag: activeTag || undefined, ...overrides };
        return Object.fromEntries(Object.entries(next).filter(([, v]) => v !== undefined && v !== ''));
    };

    const submitSearch = (e) => {
        e.preventDefault();
        router.get('/references', buildQuery(), { preserveState: true, replace: true });
    };

    const selectTag = (id) => {
        // the tag lives in the URL, so the filter survives pagination,
        // refresh, and sharing - and pagination links carry it
        router.get(
            '/references',
            buildQuery({ tag: activeTag === id ? undefined : id }),
            { preserveState: true, replace: true },
        );
    };

    const clearFilters = () => {
        setQ('');
        router.get('/references', {}, { preserveState: true, replace: true });
    };

    const confirmDelete = () => {
        if (!pendingDelete) return;
        router.delete(`/references/${pendingDelete.id}`, {
            onFinish: () => setPendingDelete(null),
        });
    };

    const rows = references.data;

    return (
        <AuthenticatedLayout
            header={
                <div className="flex items-center justify-between gap-4">
                    <h1 className="text-xl font-semibold leading-tight text-gray-800">
                        References
                    </h1>
                    <div className="flex items-center gap-2">
                        <a
                            href="/references/export"
                            className="rounded-md border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50"
                        >
                            Export .bib
                        </a>
                        <Link
                            href="/references/create"
                            className="rounded-md bg-indigo-600 px-3 py-2 text-sm font-medium text-white hover:bg-indigo-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2"
                        >
                            Add reference
                        </Link>
                    </div>
                </div>
            }
        >
            <Head title="References" />

            <div className="py-8">
                <div className="mx-auto max-w-7xl sm:px-6 lg:px-8">
                    {flash?.success && (
                        <div
                            role="status"
                            aria-live="polite"
                            className="mb-4 rounded-md border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800"
                        >
                            {flash.success}
                        </div>
                    )}

                    <form onSubmit={submitSearch} className="mb-4 flex gap-2" role="search">
                        <label htmlFor="q" className="sr-only">
                            Search references
                        </label>
                        <input
                            id="q"
                            type="search"
                            value={q}
                            onChange={(e) => setQ(e.target.value)}
                            placeholder="Search title, author, year, or DOI"
                            className="w-full rounded-md border-gray-300 shadow-sm focus:border-gray-500 focus:ring-gray-500"
                        />
                        <button
                            type="submit"
                            className="rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50"
                        >
                            Search
                        </button>
                    </form>

                    {tags.length > 0 && (
                        <div className="mb-4 flex flex-wrap items-center gap-2">
                            <span className="text-xs uppercase tracking-wide text-gray-500">
                                Tags
                            </span>
                            {tags.map((t) => (
                                <button
                                    key={t.id}
                                    type="button"
                                    aria-pressed={activeTag === t.id}
                                    onClick={() => selectTag(t.id)}
                                    className={
                                        'rounded-full border px-3 py-1 text-xs ' +
                                        (activeTag === t.id
                                            ? 'border-indigo-600 bg-indigo-600 text-white'
                                            : 'border-gray-300 bg-white text-gray-700 hover:bg-gray-50')
                                    }
                                >
                                    {t.name}
                                </button>
                            ))}
                        </div>
                    )}

                    {loading ? (
                        <TableSkeleton />
                    ) : rows.length === 0 ? (
                        <div className="rounded-lg border border-dashed border-gray-300 bg-white px-6 py-16 text-center">
                            {isFiltered ? (
                                <>
                                    <p className="text-sm font-medium text-gray-900">
                                        No references match
                                        {filters.q ? ` “${filters.q}”` : ''}
                                        {activeTag ? ' this tag' : ''}
                                    </p>
                                    <p className="mt-1 text-sm text-gray-500">
                                        Your library may still contain references — try a
                                        different search, or clear the filters.
                                    </p>
                                    <button
                                        type="button"
                                        onClick={clearFilters}
                                        className="mt-4 rounded-md border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50"
                                    >
                                        Clear filters
                                    </button>
                                </>
                            ) : (
                                <>
                                    <p className="text-sm font-medium text-gray-900">
                                        No references yet
                                    </p>
                                    <p className="mt-1 text-sm text-gray-500">
                                        Add one manually, or import an existing .bib file.
                                    </p>
                                    <div className="mt-4 flex justify-center gap-2">
                                        <Link
                                            href="/references/create"
                                            className="rounded-md bg-indigo-600 px-3 py-2 text-sm font-medium text-white hover:bg-indigo-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2"
                                        >
                                            Add reference
                                        </Link>
                                        <Link
                                            href="/references/create#import"
                                            className="rounded-md border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50"
                                        >
                                            Import .bib
                                        </Link>
                                    </div>
                                </>
                            )}
                        </div>
                    ) : (
                        <>
                        <div className="hidden overflow-hidden bg-white shadow-sm sm:block sm:rounded-lg">
                            <table className="min-w-full divide-y divide-gray-200">
                                <thead className="bg-gray-50">
                                    <tr>
                                        <th scope="col" className="px-6 py-3 text-left text-xs font-medium uppercase tracking-wide text-gray-500">
                                            Title
                                        </th>
                                        <th scope="col" className="hidden px-6 py-3 text-left text-xs font-medium uppercase tracking-wide text-gray-500 sm:table-cell">
                                            Type
                                        </th>
                                        <th scope="col" className="hidden px-6 py-3 text-left text-xs font-medium uppercase tracking-wide text-gray-500 sm:table-cell">
                                            Year
                                        </th>
                                        <th scope="col" className="px-6 py-3">
                                            <span className="sr-only">Actions</span>
                                        </th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-gray-200 bg-white">
                                    {rows.map((ref) => (
                                        <tr key={ref.id}>
                                            <td className="px-6 py-4">
                                                <Link
                                                    href={`/references/${ref.id}`}
                                                    className="block break-words text-sm font-medium text-gray-900 hover:underline"
                                                >
                                                    {ref.title}
                                                </Link>
                                                <div className="break-words text-xs text-gray-500">
                                                    {formatAuthors(ref.authors)}
                                                </div>
                                            </td>
                                            <td className="hidden px-6 py-4 text-sm text-gray-600 sm:table-cell">
                                                {TYPE_LABEL[ref.type] ?? ref.type}
                                            </td>
                                            <td className="hidden px-6 py-4 text-sm text-gray-600 sm:table-cell">
                                                {ref.year ?? '—'}
                                            </td>
                                            <td className="whitespace-nowrap px-6 py-4 text-right text-sm">
                                                <Link
                                                    href={`/references/${ref.id}/edit`}
                                                    className="text-gray-600 hover:text-gray-900"
                                                >
                                                    Edit
                                                </Link>
                                                <button
                                                    type="button"
                                                    onClick={() => setPendingDelete(ref)}
                                                    className="ml-4 text-red-600 hover:text-red-800"
                                                >
                                                    Delete
                                                </button>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>

                        {/* Cards below sm. The contract asks for a stacked
                            card layout on mobile; a squeezed table is not the
                            specified treatment. Same data, same actions. */}
                        <ul className="space-y-3 sm:hidden">
                            {rows.map((ref) => (
                                <li
                                    key={ref.id}
                                    className="rounded-lg bg-white p-4 shadow-sm"
                                >
                                    <Link
                                        href={`/references/${ref.id}`}
                                        className="block break-words text-sm font-medium text-gray-900 hover:underline"
                                    >
                                        {ref.title}
                                    </Link>
                                    <p className="mt-1 break-words text-xs text-gray-500">
                                        {formatAuthors(ref.authors)}
                                    </p>
                                    <p className="mt-1 text-xs text-gray-500">
                                        {TYPE_LABEL[ref.type] ?? ref.type}
                                        {ref.year ? ` · ${ref.year}` : ''}
                                    </p>
                                    <div className="mt-3 flex items-center gap-4 text-sm">
                                        <Link
                                            href={`/references/${ref.id}/edit`}
                                            className="text-gray-600 hover:text-gray-900"
                                        >
                                            Edit
                                        </Link>
                                        <button
                                            type="button"
                                            onClick={() => setPendingDelete(ref)}
                                            className="text-red-600 hover:text-red-800"
                                        >
                                            Delete
                                        </button>
                                    </div>
                                </li>
                            ))}
                        </ul>
                        </>
                    )}

                    {!loading && references.links && references.links.length > 3 && (
                        <nav className="mt-4 flex flex-wrap gap-1" aria-label="Pagination">
                            {references.links.map((link, i) =>
                                link.url ? (
                                    <Link
                                        key={i}
                                        href={link.url}
                                        preserveScroll
                                        aria-current={link.active ? 'page' : undefined}
                                        className={
                                            'rounded border px-3 py-1 text-sm ' +
                                            (link.active
                                                ? 'border-indigo-600 bg-indigo-600 text-white'
                                                : 'border-gray-300 bg-white text-gray-700 hover:bg-gray-50')
                                        }
                                    >
                                        {decodeLabel(link.label)}
                                    </Link>
                                ) : (
                                    <span
                                        key={i}
                                        aria-disabled="true"
                                        className="rounded border border-gray-200 bg-gray-50 px-3 py-1 text-sm text-gray-500"
                                    >
                                        {decodeLabel(link.label)}
                                    </span>
                                ),
                            )}
                        </nav>
                    )}
                </div>
            </div>

            <ConfirmDialog
                show={Boolean(pendingDelete)}
                title="Delete this reference?"
                description={
                    pendingDelete
                        ? `“${pendingDelete.title}” will be permanently removed. This cannot be undone.`
                        : ''
                }
                onConfirm={confirmDelete}
                onCancel={() => setPendingDelete(null)}
            />
        </AuthenticatedLayout>
    );
}
