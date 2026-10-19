<?php

namespace App\Http\Requests\Api\V1;

use App\Models\Artisan;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * Reason required to reject or suspend a profile. Authorization runs BEFORE validation.
 */
class ModerationReasonRequest extends FormRequest
{
    public function authorize(): bool
    {
        $artisan = $this->route('artisan');

        return $artisan instanceof Artisan && Gate::authorize('moderate', $artisan)->allowed();
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'reason.required' => 'Le motif est obligatoire.',
            'reason.string' => "Le motif doit \u{EA}tre un texte.",
            'reason.min' => "Le motif doit contenir au moins 3 caract\u{E8}res.",
            'reason.max' => "Le motif est trop long (500 caract\u{E8}res maximum).",
        ];
    }
}
