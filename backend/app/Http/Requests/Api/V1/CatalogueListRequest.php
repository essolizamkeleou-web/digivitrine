<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class CatalogueListRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('country'))) {
            $this->merge(['country' => strtoupper($this->input('country'))]);
        }

        if (is_string($this->input('city'))) {
            $this->merge(['city' => strtolower($this->input('city'))]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
            'page' => ['nullable', 'integer', 'min:1'],
            'city' => ['nullable', 'string', 'max:120', 'regex:/^[a-z0-9]+(-[a-z0-9]+)*$/'],
            'country' => ['nullable', 'string', 'size:2', 'alpha'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'per_page.integer' => 'per_page doit \u{EA}tre un entier.',
            'per_page.min' => 'per_page doit \u{EA}tre au moins 1.',
            'per_page.max' => 'per_page ne peut pas d\u{E9}passer 200.',
            'page.integer' => 'page doit \u{EA}tre un entier.',
            'page.min' => 'page doit \u{EA}tre au moins 1.',
            'city.regex' => 'Identifiant de ville invalide (minuscules, chiffres et tirets).',
            'country.size' => 'Le pays doit \u{EA}tre un code \u{E0} 2 lettres (exemple : TG).',
            'country.alpha' => 'Le pays doit \u{EA}tre un code \u{E0} 2 lettres (exemple : TG).',
        ];
    }

    /**
     * Requested country, or the configured default.
     */
    public function country(): string
    {
        return $this->validated('country') ?? (string) config('digivitrine.default_country');
    }
}
