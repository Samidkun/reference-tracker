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
     */
    public function rules(): array
    {
        return [
            'title'   => ['required', 'string', 'max:500'],
            'authors' => ['required', 'array', 'min:1', 'max:50'],
            'authors.*' => ['required', 'string', 'max:200'],
            'year'    => ['nullable', 'integer', 'min:1000', 'max:' . (date('Y') + 1)],
            'type'    => ['required', Rule::in(['journal', 'book', 'conference', 'thesis', 'web'])],
            'doi'     => ['nullable', 'string', 'max:255'],
            'url'     => ['nullable', 'url', 'max:2048'],
            'notes'   => ['nullable', 'string', 'max:20000'],
            'tags'    => ['nullable', 'array', 'max:20'],
            'tags.*'  => ['integer', 'exists:tags,id'],
        ];
    }
}
