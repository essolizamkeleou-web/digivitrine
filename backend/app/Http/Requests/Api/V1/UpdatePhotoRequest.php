<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\UserRole;
use App\Models\Artisan;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class UpdatePhotoRequest extends FormRequest
{
    public function authorize(): bool
    {
        $artisan = $this->route('artisan');

        return $artisan instanceof Artisan && Gate::authorize('update', $artisan)->allowed();
    }

    /**
     * Only the staff may hide a photo (is_active). "is_primary" only accepts true: to change the
     * primary photo, choose another one.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $user = $this->user('sanctum');
        $staff = $user instanceof User && in_array($user->role, [UserRole::Admin, UserRole::Moderator], true);

        return [
            'is_primary' => ['sometimes', 'accepted'],
            'position' => ['sometimes', 'integer', 'min:0', 'max:100'],
            'is_active' => $staff ? ['sometimes', 'boolean'] : ['prohibited'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'is_primary.accepted' => "Seule la valeur true est accept\u{E9}e : choisissez une autre photo comme photo principale.",
            'position.integer' => "La position doit \u{EA}tre un entier.",
            'position.min' => "La position doit \u{EA}tre comprise entre 0 et 100.",
            'position.max' => "La position doit \u{EA}tre comprise entre 0 et 100.",
            'is_active.prohibited' => "Seul le personnel peut masquer ou r\u{E9}afficher une photo.",
            'is_active.boolean' => 'is_active doit valoir true ou false.',
        ];
    }
}
