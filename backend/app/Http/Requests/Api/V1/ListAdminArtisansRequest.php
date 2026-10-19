<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\ArtisanStatus;
use Illuminate\Validation\Rule;

/**
 * Staff list of profiles: the public list filters plus the status and a name search.
 */
class ListAdminArtisansRequest extends ListArtisansRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return array_merge(parent::rules(), [
            'status' => ['nullable', 'string', Rule::enum(ArtisanStatus::class)],
            'q' => ['nullable', 'string', 'max:100'],
        ]);
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return array_merge(parent::messages(), [
            'status.enum' => 'Statut inconnu (draft, pending, validated, rejected ou suspended).',
            'q.string' => "La recherche doit \u{EA}tre un texte.",
            'q.max' => "La recherche est trop longue (100 caract\u{E8}res maximum).",
        ]);
    }
}
