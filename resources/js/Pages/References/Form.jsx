import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import TextInput from '@/Components/TextInput';
import { Head, Link, useForm } from '@inertiajs/react';
import { useRef, useState } from 'react';

const TYPES = [
    ['journal', 'Journal article'],
    ['book', 'Book'],
    ['conference', 'Conference paper'],
    ['thesis', 'Thesis'],
    ['web', 'Website'],
];

export default function Form({ reference, tags }) {
    const isEdit = Boolean(reference);
    const fileRef = useRef(null);
    const [importError, setImportError] = useState('');
    const [importBusy, setImportBusy] = useState(false);
    const [doiBusy, setDoiBusy] = useState(false);
    const [doiError, setDoiError] = useState('');

    const { data, setData, post, put, processing, errors } = useForm({
        title: reference?.title ?? '',
        authors: (reference?.authors ?? ['']).join('\n'),
        year: reference?.year ?? '',
        type: reference?.type ?? 'journal',
        doi: reference?.doi ?? '',
        url: reference?.url ?? '',
        notes: reference?.notes ?? '',
        tags: (reference?.tags ?? []).map((t) => t.id),
    });

    const submit = (e) => {
        e.preventDefault();

        const payload = {
            ...data,
            authors: data.authors
                .split('\n')
                .map((a) => a.trim())
                .filter(Boolean),
            year: data.year === '' ? null : Number(data.year),
        };

        if (isEdit) {
            put(`/references/${reference.id}`, { data: payload });
        } else {
            post('/references', { data: payload });
        }
    };

    const lookupDoi = async () => {
        if (!data.doi.trim()) return;
        setDoiBusy(true);
        setDoiError('');

        try {
            const res = await fetch('/references/doi', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document
                        .querySelector('meta[name="csrf-token"]')
                        ?.getAttribute('content'),
                    Accept: 'application/json',
                },
                body: JSON.stringify({ doi: data.doi }),
            });

            if (!res.ok) {
                setDoiError('Could not find that DOI. Fill the fields in manually.');
                return;
            }

            const meta = await res.json();
            setData((prev) => ({
                ...prev,
                title: meta.title ?? prev.title,
                authors: (meta.authors ?? []).join('\n') || prev.authors,
                year: meta.year ?? prev.year,
                type: meta.type ?? prev.type,
                url: meta.url ?? prev.url,
            }));
        } catch {
            setDoiError('Network error while looking up the DOI.');
        } finally {
            setDoiBusy(false);
        }
    };

    const importFile = (e) => {
        e.preventDefault();
        if (!fileRef.current?.files?.[0]) {
            setImportError('Choose a .bib file first.');
            return;
        }

        const body = new FormData();
        body.append('file', fileRef.current.files[0]);

        setImportBusy(true);
        setImportError('');

        fetch('/references/import', {
            method: 'POST',
            headers: {
                'X-CSRF-TOKEN': document
                    .querySelector('meta[name="csrf-token"]')
                    ?.getAttribute('content'),
                Accept: 'application/json',
            },
            body,
        })
            .then(async (res) => {
                if (res.ok) {
                    window.location.href = '/references';
                    return;
                }
                const json = await res.json().catch(() => ({}));
                setImportError(
                    json.message || json.errors?.file?.[0] || 'Import failed.',
                );
            })
            .catch(() => setImportError('Network error during import.'))
            .finally(() => setImportBusy(false));
    };

    return (
        <AuthenticatedLayout
            header={
                <h2 className="text-xl font-semibold leading-tight text-gray-800">
                    {isEdit ? 'Edit reference' : 'Add reference'}
                </h2>
            }
        >
            <Head title={isEdit ? 'Edit reference' : 'Add reference'} />

            <div className="py-8">
                <div className="mx-auto max-w-3xl sm:px-6 lg:px-8">
                    <form
                        onSubmit={submit}
                        className="space-y-6 bg-white p-6 shadow-sm sm:rounded-lg"
                    >
                        <div>
                            <InputLabel htmlFor="title" value="Title" />
                            <TextInput
                                id="title"
                                className="mt-1 block w-full"
                                value={data.title}
                                onChange={(e) => setData('title', e.target.value)}
                                required
                            />
                            <InputError className="mt-1" message={errors.title} />
                        </div>

                        <div>
                            <InputLabel htmlFor="authors" value="Authors (one per line)" />
                            <textarea
                                id="authors"
                                rows={3}
                                value={data.authors}
                                onChange={(e) => setData('authors', e.target.value)}
                                className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-gray-500 focus:ring-gray-500"
                                placeholder="Surname, Given&#10;Surname, Given"
                            />
                            <InputError className="mt-1" message={errors.authors} />
                        </div>

                        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                            <div>
                                <InputLabel htmlFor="year" value="Year" />
                                <TextInput
                                    id="year"
                                    type="number"
                                    className="mt-1 block w-full"
                                    value={data.year}
                                    onChange={(e) => setData('year', e.target.value)}
                                />
                                <InputError className="mt-1" message={errors.year} />
                            </div>

                            <div>
                                <InputLabel htmlFor="type" value="Type" />
                                <select
                                    id="type"
                                    value={data.type}
                                    onChange={(e) => setData('type', e.target.value)}
                                    className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-gray-500 focus:ring-gray-500"
                                >
                                    {TYPES.map(([value, label]) => (
                                        <option key={value} value={value}>
                                            {label}
                                        </option>
                                    ))}
                                </select>
                                <InputError className="mt-1" message={errors.type} />
                            </div>
                        </div>

                        <div>
                            <InputLabel htmlFor="doi" value="DOI" />
                            <div className="mt-1 flex gap-2">
                                <TextInput
                                    id="doi"
                                    className="block w-full"
                                    value={data.doi}
                                    onChange={(e) => setData('doi', e.target.value)}
                                    placeholder="10.48550/arXiv.1706.03762"
                                />
                                <button
                                    type="button"
                                    onClick={lookupDoi}
                                    disabled={doiBusy}
                                    className="whitespace-nowrap rounded-md border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 disabled:opacity-50"
                                >
                                    {doiBusy ? 'Looking up…' : 'Fetch metadata'}
                                </button>
                            </div>
                            {doiError && (
                                <p className="mt-1 text-sm text-amber-700">{doiError}</p>
                            )}
                            <InputError className="mt-1" message={errors.doi} />
                        </div>

                        <div>
                            <InputLabel htmlFor="url" value="URL" />
                            <TextInput
                                id="url"
                                type="url"
                                className="mt-1 block w-full"
                                value={data.url}
                                onChange={(e) => setData('url', e.target.value)}
                            />
                            <InputError className="mt-1" message={errors.url} />
                        </div>

                        <div>
                            <InputLabel htmlFor="notes" value="Notes" />
                            <textarea
                                id="notes"
                                rows={3}
                                value={data.notes}
                                onChange={(e) => setData('notes', e.target.value)}
                                className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-gray-500 focus:ring-gray-500"
                            />
                            <InputError className="mt-1" message={errors.notes} />
                        </div>

                        {tags.length > 0 && (
                            <div>
                                <InputLabel value="Tags" />
                                <div className="mt-2 flex flex-wrap gap-2">
                                    {tags.map((t) => {
                                        const checked = data.tags.includes(t.id);
                                        return (
                                            <label
                                                key={t.id}
                                                className={
                                                    'cursor-pointer rounded-full border px-3 py-1 text-xs ' +
                                                    (checked
                                                        ? 'border-gray-900 bg-gray-900 text-white'
                                                        : 'border-gray-300 bg-white text-gray-700')
                                                }
                                            >
                                                <input
                                                    type="checkbox"
                                                    className="sr-only"
                                                    checked={checked}
                                                    onChange={() =>
                                                        setData(
                                                            'tags',
                                                            checked
                                                                ? data.tags.filter((id) => id !== t.id)
                                                                : [...data.tags, t.id],
                                                        )
                                                    }
                                                />
                                                {t.name}
                                            </label>
                                        );
                                    })}
                                </div>
                            </div>
                        )}

                        <div className="flex items-center gap-3 border-t border-gray-100 pt-4">
                            <PrimaryButton disabled={processing}>
                                {isEdit ? 'Save changes' : 'Add reference'}
                            </PrimaryButton>
                            <Link href="/references">
                                <SecondaryButton type="button">Cancel</SecondaryButton>
                            </Link>
                        </div>
                    </form>

                    {!isEdit && (
                        <form
                            onSubmit={importFile}
                            id="import"
                            className="mt-6 bg-white p-6 shadow-sm sm:rounded-lg"
                        >
                            <h3 className="text-sm font-semibold text-gray-900">
                                Import from BibTeX
                            </h3>
                            <p className="mt-1 text-sm text-gray-500">
                                Upload a .bib file. Duplicate entries are added as separate
                                references.
                            </p>
                            <div className="mt-3 flex items-center gap-3">
                                <input
                                    ref={fileRef}
                                    type="file"
                                    accept=".bib,text/plain"
                                    className="block text-sm text-gray-700"
                                />
                                <SecondaryButton type="submit" disabled={importBusy}>
                                    {importBusy ? 'Importing…' : 'Import'}
                                </SecondaryButton>
                            </div>
                            {importError && (
                                <p className="mt-2 text-sm text-red-600">{importError}</p>
                            )}
                        </form>
                    )}
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
