<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Parameters of the public commune endpoints: pagination, optional city slug and country code.
 */
class ListCommunesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Country code of the request (default country when absent).
     */
    public function country(): string
    {
        return strtoupper((string) ($this->validated('country') ?? config('digivitrine.default_country')));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
            'page' => ['nullable', 'integer', 'min:1'],
            'city' => ['nullable', 'string', 'max:120', 'regex:/^[a-z0-9]+(-[a-z0-9]+)*$/'],
            'country' => ['nullable', 'string', 'regex:/^[A-Za-z]{2}$/'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'per_page.integer' => "per_page doit \u{EA}tre un entier.",
            'per_page.min' => 'per_page doit valoir au moins 1.',
            'per_page.max' => "per_page ne peut pas d\u{E9}passer 50.",
            'page.integer' => "page doit \u{EA}tre un entier.",
            'page.min' => 'page doit valoir au moins 1.',
            'city.regex' => 'Identifiant de ville invalide.',
            'country.regex' => 'Le code pays doit comporter 2 lettres.',
        ];
    }
}
