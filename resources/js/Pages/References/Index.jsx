import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';

const TYPE_LABEL = {
    journal: 'Journal',
    book: 'Book',
    conference: 'Conference',
    thesis: 'Thesis',
    web: 'Web',
};

function formatAuthors(authors) {
    if (!authors || authors.length === 0) return 'No author';
    if (authors.length === 1) return authors[0];
    if (authors.length === 2) return authors.join(' & ');
    return `${authors[0]} et al.`;
}

export default function Index() {
    const { references, filters, tags, flash } = usePage().props;
    const [q, setQ] = useState(filters.q ?? '');
    const [activeTag, setActiveTag] = useState(null);

    const submitSearch = (e) => {
        e.preventDefault();
        router.get('/references', q ? { q } : {}, { preserveState: true, replace: true });
    };

    const rows = activeTag
        ? references.data.filter((r) => r.tags.some((t) => t.id === activeTag))
        : references.data;

    const remove = (ref) => {
        if (confirm(`Delete "${ref.title}"? This cannot be undone.`)) {
            router.delete(`/references/${ref.id}`);
        }
    };

    return (
        <AuthenticatedLayout
            header={
                <div className="flex items-center justify-between">
                    <h2 className="text-xl font-semibold leading-tight text-gray-800">
                        References
                    </h2>
                    <div className="flex items-center gap-2">
                        <a
                            href="/references/export"
                            className="rounded-md border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50"
                        >
                            Export .bib
                        </a>
                        <Link
                            href="/references/create"
                            className="rounded-md bg-gray-900 px-3 py-2 text-sm font-medium text-white hover:bg-gray-800"
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
                        <div className="mb-4 rounded-md border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800">
                            {flash.success}
                        </div>
                    )}

                    <form onSubmit={submitSearch} className="mb-4 flex gap-2">
                        <input
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
                                    onClick={() => setActiveTag(activeTag === t.id ? null : t.id)}
                                    className={
                                        'rounded-full border px-3 py-1 text-xs ' +
                                        (activeTag === t.id
                                            ? 'border-gray-900 bg-gray-900 text-white'
                                            : 'border-gray-300 bg-white text-gray-700 hover:bg-gray-50')
                                    }
                                >
                                    {t.name}
                                </button>
                            ))}
                        </div>
                    )}

                    {rows.length === 0 ? (
                        <div className="rounded-lg border border-dashed border-gray-300 bg-white px-6 py-16 text-center">
                            <p className="text-sm font-medium text-gray-900">
                                No references yet
                            </p>
                            <p className="mt-1 text-sm text-gray-500">
                                Add one manually, or import an existing .bib file.
                            </p>
                            <div className="mt-4 flex justify-center gap-2">
                                <Link
                                    href="/references/create"
                                    className="rounded-md bg-gray-900 px-3 py-2 text-sm font-medium text-white hover:bg-gray-800"
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
                        </div>
                    ) : (
                        <div className="overflow-hidden bg-white shadow-sm sm:rounded-lg">
                            <table className="min-w-full divide-y divide-gray-200">
                                <thead className="bg-gray-50">
                                    <tr>
                                        <th className="px-6 py-3 text-left text-xs font-medium uppercase tracking-wide text-gray-500">
                                            Title
                                        </th>
                                        <th className="px-6 py-3 text-left text-xs font-medium uppercase tracking-wide text-gray-500">
                                            Type
                                        </th>
                                        <th className="px-6 py-3 text-left text-xs font-medium uppercase tracking-wide text-gray-500">
                                            Year
                                        </th>
                                        <th className="px-6 py-3" />
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-gray-200 bg-white">
                                    {rows.map((ref) => (
                                        <tr key={ref.id}>
                                            <td className="px-6 py-4">
                                                <Link
                                                    href={`/references/${ref.id}`}
                                                    className="text-sm font-medium text-gray-900 hover:underline"
                                                >
                                                    {ref.title}
                                                </Link>
                                                <div className="text-xs text-gray-500">
                                                    {formatAuthors(ref.authors)}
                                                </div>
                                            </td>
                                            <td className="px-6 py-4 text-sm text-gray-600">
                                                {TYPE_LABEL[ref.type] ?? ref.type}
                                            </td>
                                            <td className="px-6 py-4 text-sm text-gray-600">
                                                {ref.year ?? '—'}
                                            </td>
                                            <td className="px-6 py-4 text-right text-sm">
                                                <Link
                                                    href={`/references/${ref.id}/edit`}
                                                    className="text-gray-600 hover:text-gray-900"
                                                >
                                                    Edit
                                                </Link>
                                                <button
                                                    type="button"
                                                    onClick={() => remove(ref)}
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
                    )}

                    {references.links && references.links.length > 3 && (
                        <div className="mt-4 flex flex-wrap gap-1">
                            {references.links.map((link, i) => (
                                <button
                                    key={i}
                                    type="button"
                                    disabled={!link.url}
                                    onClick={() => link.url && router.get(link.url)}
                                    dangerouslySetInnerHTML={{ __html: link.label }}
                                    className={
                                        'rounded border px-3 py-1 text-sm ' +
                                        (link.active
                                            ? 'border-gray-900 bg-gray-900 text-white'
                                            : link.url
                                              ? 'border-gray-300 bg-white text-gray-700 hover:bg-gray-50'
                                              : 'border-gray-200 bg-gray-50 text-gray-400')
                                    }
                                />
                            ))}
                        </div>
                    )}
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
