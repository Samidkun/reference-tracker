import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import TextInput from '@/Components/TextInput';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';

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

    // useForm.submit() ignores any `data` option passed to post()/put(): it
    // ALWAYS sends transform.current(dataRef.current) — verified in the
    // library source. transform() is the only supported way to reshape the
    // payload. Without it, `authors` went out as a raw newline string and the
    // server rejected the request with "The authors field must be an array."
    const { data, setData, post, put, processing, errors, transform } = useForm({
        title: reference?.title ?? '',
        authors: (reference?.authors ?? ['']).join('\n'),
        year: reference?.year ?? '',
        type: reference?.type ?? 'journal',
        doi: reference?.doi ?? '',
        url: reference?.url ?? '',
        notes: reference?.notes ?? '',
        tags: (reference?.tags ?? []).map((t) => t.id),
    });

    transform((data) => ({
        ...data,
        authors: data.authors
            .split('\n')
            .map((a) => a.trim())
            .filter(Boolean),
        year: data.year === '' || data.year === null ? null : Number(data.year),
    }));

    const [submitError, setSubmitError] = useState('');
    const [dirty, setDirty] = useState(false);

    // Snapshot the initial form state once, then compare on every change.
    // Wrapping every setData call would work too, but it is easy to forget
    // one - a snapshot cannot miss a field.
    const initial = useRef(null);
    if (initial.current === null) {
        initial.current = JSON.stringify({
            title: reference?.title ?? '',
            authors: (reference?.authors ?? ['']).join('\n'),
            year: reference?.year ?? '',
            type: reference?.type ?? 'journal',
            doi: reference?.doi ?? '',
            url: reference?.url ?? '',
            notes: reference?.notes ?? '',
            tags: (reference?.tags ?? []).map((x) => x.id),
        });
    }

    useEffect(() => {
        setDirty(JSON.stringify(data) !== initial.current);
    }, [data]);

    // Warn before navigating away with unsaved edits.
    //
    // The FIRST version of this blocked every Inertia visit while the form was
    // dirty - including the form's own submit - so saving silently did nothing.
    // Two guards fix that:
    //   1. only intercept visits that LEAVE the form, not the one we started
    //   2. stop guarding the moment a submit begins
    const [submitting, setSubmitting] = useState(false);

    useEffect(() => {
        const beforeUnload = (e) => {
            if (!dirty || submitting) return undefined;
            e.preventDefault();
            e.returnValue = '';
            return undefined;
        };
        window.addEventListener('beforeunload', beforeUnload);
        return () => window.removeEventListener('beforeunload', beforeUnload);
    }, [dirty, submitting]);

    useEffect(() => {
        if (!dirty || submitting) return undefined;

        const off = router.on('before', (event) => {
            const target = String(event.detail.visit.url ?? '');

            // Our own submit navigates to /references - never block that.
            if (target.includes('/references') && !target.includes('/create')) {
                return;
            }

            // Leaving the form with unsaved edits: ask, and only block if the
            // user declines. window.confirm is acceptable here (it is a
            // navigation guard, not a destructive-action confirmation), but it
            // must not fire for the submit path above.
            if (!window.confirm('Discard your unsaved changes?')) {
                event.preventDefault();
            }
        });

        return () => off();
    }, [dirty, submitting]);

    const submit = (e) => {
        e.preventDefault();
        setSubmitError('');

        setSubmitting(true);
        setDirty(false);

        const options = {
            // Without these a 500 or a dropped connection leaves the form
            // silent and the user with no idea anything happened.
            onError: () => {
                setSubmitting(false);
                setSubmitError('Could not save. Check the fields and try again.');
            },
            onNetworkError: () => {
                setSubmitting(false);
                setSubmitError('Network problem — your changes were not saved. Try again.');
            },
            onFinish: () => setSubmitting(false),
        };

        if (isEdit) {
            put(`/references/${reference.id}`, options);
        } else {
            post('/references', options);
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
                                invalid={Boolean(errors.title)}
                                value={data.title}
                                onChange={(e) => setData('title', e.target.value)}
                                aria-describedby={errors.title ? 'title-error' : undefined}
                                required
                            />
                            <InputError id="title-error" className="mt-1" message={errors.title} />
                        </div>

                        <div>
                            <InputLabel htmlFor="authors" value="Authors (one per line)" />
                            <textarea
                                id="authors"
                                rows={3}
                                value={data.authors}
                                onChange={(e) => setData('authors', e.target.value)}
                                aria-invalid={errors.authors ? 'true' : undefined}
                                aria-describedby={errors.authors ? 'authors-error' : undefined}
                                className={
                                    'mt-1 block w-full rounded-md shadow-sm focus:ring-2 ' +
                                    (errors.authors
                                        ? 'border-red-500 focus:border-red-500 focus:ring-red-500'
                                        : 'border-gray-300 focus:border-indigo-500 focus:ring-indigo-500')
                                }
                                placeholder="Surname, Given&#10;Surname, Given"
                            />
                            <InputError id="authors-error" className="mt-1" message={errors.authors} />
                        </div>

                        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                            <div>
                                <InputLabel htmlFor="year" value="Year" />
                                <TextInput
                                    id="year"
                                    type="number"
                                    className="mt-1 block w-full"
                                    invalid={Boolean(errors.year)}
                                    value={data.year}
                                    onChange={(e) => setData('year', e.target.value)}
                                    aria-describedby={errors.year ? 'year-error' : undefined}
                                />
                                <InputError id="year-error" className="mt-1" message={errors.year} />
                            </div>

                            <div>
                                <InputLabel htmlFor="type" value="Type" />
                                <select
                                    id="type"
                                    value={data.type}
                                    onChange={(e) => setData('type', e.target.value)}
                                    aria-invalid={errors.type ? 'true' : undefined}
                                    aria-describedby={errors.type ? 'type-error' : undefined}
                                    className={
                                        'mt-1 block w-full rounded-md shadow-sm focus:ring-2 ' +
                                        (errors.type
                                            ? 'border-red-500 focus:border-red-500 focus:ring-red-500'
                                            : 'border-gray-300 focus:border-indigo-500 focus:ring-indigo-500')
                                    }
                                >
                                    {TYPES.map(([value, label]) => (
                                        <option key={value} value={value}>
                                            {label}
                                        </option>
                                    ))}
                                </select>
                                <InputError id="type-error" className="mt-1" message={errors.type} />
                            </div>
                        </div>

                        <div>
                            <InputLabel htmlFor="doi" value="DOI" />
                            <div className="mt-1 flex gap-2">
                                <TextInput
                                    id="doi"
                                    className="block w-full"
                                    invalid={Boolean(errors.doi)}
                                    value={data.doi}
                                    onChange={(e) => setData('doi', e.target.value)}
                                    aria-describedby={errors.doi ? 'doi-error' : undefined}
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
                            <InputError id="doi-error" className="mt-1" message={errors.doi} />
                        </div>

                        <div>
                            <InputLabel htmlFor="url" value="URL" />
                            <TextInput
                                id="url"
                                type="url"
                                className="mt-1 block w-full"
                                invalid={Boolean(errors.url)}
                                value={data.url}
                                onChange={(e) => setData('url', e.target.value)}
                                aria-describedby={errors.url ? 'url-error' : undefined}
                            />
                            <InputError id="url-error" className="mt-1" message={errors.url} />
                        </div>

                        <div>
                            <InputLabel htmlFor="notes" value="Notes" />
                            <textarea
                                id="notes"
                                rows={3}
                                value={data.notes}
                                onChange={(e) => setData('notes', e.target.value)}
                                aria-invalid={errors.notes ? 'true' : undefined}
                                aria-describedby={errors.notes ? 'notes-error' : undefined}
                                className={
                                    'mt-1 block w-full rounded-md shadow-sm focus:ring-2 ' +
                                    (errors.notes
                                        ? 'border-red-500 focus:border-red-500 focus:ring-red-500'
                                        : 'border-gray-300 focus:border-indigo-500 focus:ring-indigo-500')
                                }
                            />
                            <InputError id="notes-error" className="mt-1" message={errors.notes} />
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
                                                        ? 'border-indigo-600 bg-indigo-600 text-white'
                                                        : 'border-gray-300 bg-white text-gray-700 hover:bg-gray-50')
                                                }
                                            >
                                                <input
                                                    type="checkbox"
                                                    className="sr-only"
                                                    checked={checked}
                                                    aria-label={t.name}
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

                        {submitError && (
                            <div
                                role="alert"
                                className="rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800"
                            >
                                {submitError}
                            </div>
                        )}

                        <div className="flex items-center gap-3 border-t border-gray-100 pt-4">
                            <PrimaryButton disabled={processing}>
                                {isEdit ? 'Save changes' : 'Add reference'}
                            </PrimaryButton>
                            <Link
                                href="/references"
                                className="inline-flex items-center rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 transition hover:bg-gray-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2"
                            >
                                Cancel
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
