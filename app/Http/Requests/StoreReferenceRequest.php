<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreReferenceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // auth middleware already guarantees a logged-in user
    }

    /**
     * NOTE: 'user_id' is deliberately absent from these rules. Even if a
     * client posts one, it is not validated, not in $validated, and therefore
     * never reaches the model. Ownership is set server-side only.
     *
     * The title max must match the column width (500). A validation rule that
     * is looser than the schema turns a friendly error into an unhandled
     * database exception.
     */
    public function rules(): array
    {
        return [
            'title'   => ['required', 'string', 'max:500'],
            'authors' => ['required', 'array', 'min:1', 'max:50'],
            'authors.*' => ['required', 'string', 'max:200'],
            'year'    => ['nullable', 'integer', 'min:1000', 'max:' . (date('Y') + 1)],
            'type'    => ['required', Rule::in(['journal', 'book', 'conference', 'thesis', 'web'])],
            'doi'     => [
                'nullable', 'string', 'max:255',
                // A DOI is unique per user. Without this rule the second
                // insert hit the unique index and surfaced as a raw 500
                // instead of a field-level message.
                Rule::unique('references', 'doi')
                    ->where('user_id', $this->user()?->id)
                    ->ignore($this->route('reference')?->id),
            ],
            'url'     => ['nullable', 'url', 'max:2048'],
            'notes'   => ['nullable', 'string', 'max:20000'],
            'tags'    => ['nullable', 'array', 'max:20'],
            'tags.*'  => ['integer', 'exists:tags,id'],
        ];
    }

    public function messages(): array
    {
        return [
            'doi.unique' => 'You already have a reference with this DOI.',
        ];
    }
}
