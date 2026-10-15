<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class ListArtisansRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $merge = [];

        foreach (['category', 'subcategory', 'city', 'neighborhood'] as $field) {
            if (is_string($this->input($field))) {
                $merge[$field] = strtolower($this->input($field));
            }
        }

        if (is_string($this->input('country'))) {
            $merge['country'] = strtoupper($this->input('country'));
        }

        $this->merge($merge);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $slug = ['nullable', 'string', 'max:120', 'regex:/^[a-z0-9]+(-[a-z0-9]+)*$/'];

        return [
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
            'page' => ['nullable', 'integer', 'min:1'],
            'category' => $slug,
            'subcategory' => $slug,
            'city' => $slug,
            'neighborhood' => $slug,
            'country' => ['nullable', 'string', 'size:2', 'alpha'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'per_page.integer' => "per_page doit \u{EA}tre un entier.",
            'per_page.min' => "per_page doit \u{EA}tre au moins 1.",
            'per_page.max' => "per_page ne peut pas d\u{E9}passer 50.",
            'page.integer' => "page doit \u{EA}tre un entier.",
            'page.min' => "page doit \u{EA}tre au moins 1.",
            'category.regex' => "Identifiant de cat\u{E9}gorie invalide.",
            'subcategory.regex' => "Identifiant de sous-cat\u{E9}gorie invalide.",
            'city.regex' => 'Identifiant de ville invalide.',
            'neighborhood.regex' => 'Identifiant de quartier invalide.',
            'country.size' => "Le pays doit \u{EA}tre un code \u{E0} 2 lettres (exemple : TG).",
            'country.alpha' => "Le pays doit \u{EA}tre un code \u{E0} 2 lettres (exemple : TG).",
        ];
    }
}
