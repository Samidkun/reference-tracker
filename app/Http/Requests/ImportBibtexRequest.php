<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ImportBibtexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'max:5120'], // 5 MB
        ];
    }

    /**
     * Content check on top of the extension check.
     *
     * A .bib upload is user-supplied text that we parse. We reject anything
     * that does not look like BibTeX at all (e.g. a PHP payload renamed to
     * .bib) before the parser ever sees it.
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($v) {
            $file = $this->file('file');

            if (! $file || ! $file->isValid()) {
                return;
            }

            $head = (string) file_get_contents($file->getRealPath(), false, null, 0, 4096);

            if (! preg_match('/@\s*[a-zA-Z]+\s*\{/', $head)) {
                $v->errors()->add('file', 'This file does not look like a BibTeX file.');

                return;
            }

            // Cap the ENTRY count, not just the file size. A 5 MB file can hold
            // tens of thousands of entries, each an INSERT inside one
            // transaction - a 30,000-row import completed in ~6s, which is a
            // trivial resource-exhaustion lever for any signed-in user.
            // Rejecting loudly beats silently importing a partial library.
            $count = preg_match_all('/@\s*[a-zA-Z]+\s*\{/', (string) file_get_contents($file->getRealPath()));

            if ($count > 2000) {
                $v->errors()->add(
                    'file',
                    "This file contains {$count} entries. The limit is 2000 per import — split it into smaller files."
                );
            }
        });
    }
}
