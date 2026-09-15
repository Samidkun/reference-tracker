import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import ConfirmDialog from '@/Components/ConfirmDialog';
import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';

const TYPE_LABEL = {
    journal: 'Journal article',
    book: 'Book',
    conference: 'Conference paper',
    thesis: 'Thesis',
    web: 'Website',
};

function Row({ label, children }) {
    return (
        <div className="grid grid-cols-1 gap-1 border-b border-gray-100 py-3 sm:grid-cols-4">
            <dt className="text-sm font-medium text-gray-500">{label}</dt>
            <dd className="text-sm text-gray-900 sm:col-span-3">{children}</dd>
        </div>
    );
}

export default function Show({ reference }) {
    const [confirming, setConfirming] = useState(false);

    const remove = () => {
        router.delete(`/references/${reference.id}`, {
            onFinish: () => setConfirming(false),
        });
    };

    return (
        <AuthenticatedLayout
            header={
                <div className="flex items-start justify-between gap-4">
                    {/* min-w-0 + break-words: a 500-char unbroken title (a
                        realistic paste from Zotero) otherwise stretched the
                        page to ~6,600px and pushed these buttons off-screen. */}
                    <h1 className="min-w-0 break-words text-xl font-semibold leading-tight text-gray-800">
                        {reference.title}
                    </h1>
                    <div className="flex shrink-0 items-center gap-2">
                        <Link
                            href={`/references/${reference.id}/edit`}
                            className="rounded-md border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50"
                        >
                            Edit
                        </Link>
                        <button
                            type="button"
                            onClick={() => setConfirming(true)}
                            className="rounded-md border border-red-300 bg-white px-3 py-2 text-sm font-medium text-red-700 hover:bg-red-50"
                        >
                            Delete
                        </button>
                    </div>
                </div>
            }
        >
            <Head title={reference.title} />

            <div className="py-8">
                <div className="mx-auto max-w-3xl sm:px-6 lg:px-8">
                    <dl className="bg-white p-6 shadow-sm sm:rounded-lg">
                        <Row label="Authors">
                            {reference.authors?.length ? (
                                <ul className="space-y-0.5">
                                    {reference.authors.map((a, i) => (
                                        <li key={i}>{a}</li>
                                    ))}
                                </ul>
                            ) : (
                                <span className="text-gray-400">Not recorded</span>
                            )}
                        </Row>

                        <Row label="Year">{reference.year ?? '—'}</Row>
                        <Row label="Type">
                            {TYPE_LABEL[reference.type] ?? reference.type}
                        </Row>

                        <Row label="DOI">
                            {reference.doi ? (
                                <a
                                    href={`https://doi.org/${reference.doi}`}
                                    target="_blank"
                                    rel="noreferrer"
                                    className="text-blue-700 hover:underline"
                                >
                                    {reference.doi}
                                </a>
                            ) : (
                                <span className="text-gray-400">Not recorded</span>
                            )}
                        </Row>

                        <Row label="URL">
                            {reference.url ? (
                                <a
                                    href={reference.url}
                                    target="_blank"
                                    rel="noreferrer"
                                    className="break-all text-blue-700 hover:underline"
                                >
                                    {reference.url}
                                </a>
                            ) : (
                                <span className="text-gray-400">Not recorded</span>
                            )}
                        </Row>

                        <Row label="Tags">
                            {reference.tags?.length ? (
                                <div className="flex flex-wrap gap-2">
                                    {reference.tags.map((t) => (
                                        <span
                                            key={t.id}
                                            className="rounded-full border border-gray-300 bg-gray-50 px-3 py-1 text-xs text-gray-700"
                                        >
                                            {t.name}
                                        </span>
                                    ))}
                                </div>
                            ) : (
                                <span className="text-gray-400">None</span>
                            )}
                        </Row>

                        <div className="grid grid-cols-1 gap-1 py-3 sm:grid-cols-4">
                            <dt className="text-sm font-medium text-gray-500">Notes</dt>
                            <dd className="whitespace-pre-wrap break-words text-sm text-gray-900 sm:col-span-3">
                                {reference.notes || (
                                    <span className="text-gray-400">No notes</span>
                                )}
                            </dd>
                        </div>
                    </dl>

                    <div className="mt-4">
                        <Link
                            href="/references"
                            className="text-sm text-gray-600 hover:text-gray-900"
                        >
                            ← Back to references
                        </Link>
                    </div>
                </div>
            </div>

            <ConfirmDialog
                show={confirming}
                title="Delete this reference?"
                description={`“${reference.title}” will be permanently removed. This cannot be undone.`}
                onConfirm={remove}
                onCancel={() => setConfirming(false)}
            />
        </AuthenticatedLayout>
    );
}
