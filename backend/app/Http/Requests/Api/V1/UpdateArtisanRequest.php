<?php

namespace App\Http\Requests\Api\V1;

use App\Models\Artisan;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * PUT replaces the profile (all identifying fields required); PATCH changes only the fields sent.
 */
class UpdateArtisanRequest extends ArtisanRequest
{
    /**
     * Authorization runs BEFORE validation: a stranger gets a 404 (non-public profile) or a 403,
     * never validation errors that would confirm the profile exists.
     */
    public function authorize(): bool
    {
        $artisan = $this->route('artisan');

        return $artisan instanceof Artisan && Gate::authorize('update', $artisan)->allowed();
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $put = $this->isMethod('PUT');
        $presence = $put ? 'required' : 'sometimes';
        $artisan = $this->route('artisan');

        return array_merge($this->commonRules(), [
            'business_name' => [$presence, 'string', 'min:2', 'max:150'],
            'phone' => [
                $presence, 'string', 'regex:/^\+228[0-9]{8}$/',
                Rule::unique('artisans', 'phone')->whereNull('deleted_at')->ignore($artisan instanceof Artisan ? $artisan->id : null),
            ],
            'category' => [$put ? 'required' : 'required_with:subcategory', ...$this->slugRules()],
            'city' => [$put ? 'required' : 'required_with:neighborhood', ...$this->slugRules()],
            'neighborhood' => [$put ? 'required' : 'required_with:city', ...$this->slugRules()],
        ]);
    }
}
