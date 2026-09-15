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
            }
        });
    }
}
