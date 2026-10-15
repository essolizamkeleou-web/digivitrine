<?php

namespace App\Http\Requests\Api\V1;

/**
 * Search parameters: the list filters (slugs, pagination) plus free text and "open now".
 */
class SearchArtisansRequest extends ListArtisansRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return array_merge(parent::rules(), [
            'q' => ['nullable', 'string', 'max:100'],
            'available' => ['nullable', 'boolean'],
        ]);
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return array_merge(parent::messages(), [
            'q.string' => "La recherche doit \u{EA}tre un texte.",
            'q.max' => "La recherche est trop longue (100 caract\u{E8}res maximum).",
            'available.boolean' => "Le param\u{E8}tre available doit valoir 1 ou 0.",
        ]);
    }
}
