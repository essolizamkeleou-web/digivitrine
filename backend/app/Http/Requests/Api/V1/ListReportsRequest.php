<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\ReportReason;
use App\Enums\ReportStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListReportsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
            'status' => ['nullable', 'string', Rule::enum(ReportStatus::class)],
            'reason' => ['nullable', 'string', Rule::enum(ReportReason::class)],
            'artisan' => ['nullable', 'uuid'],
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
            'per_page.max' => "per_page ne peut pas d\u{E9}passer 100.",
            'page.integer' => "page doit \u{EA}tre un entier.",
            'page.min' => "page doit \u{EA}tre au moins 1.",
            'status.enum' => 'Statut inconnu (open, resolved ou dismissed).',
            'reason.enum' => 'Motif inconnu.',
            'artisan.uuid' => "L'identifiant du profil doit \u{EA}tre un uuid.",
        ];
    }
}
