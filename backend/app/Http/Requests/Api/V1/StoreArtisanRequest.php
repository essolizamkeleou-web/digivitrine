<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Validation\Rule;

class StoreArtisanRequest extends ArtisanRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return array_merge($this->commonRules(), [
            'business_name' => ['required', 'string', 'min:2', 'max:150'],
            'phone' => ['required', 'string', 'regex:/^\+228[0-9]{8}$/', Rule::unique('artisans', 'phone')->whereNull('deleted_at')],
            'category' => ['required', ...$this->slugRules()],
            'city' => ['required', ...$this->slugRules()],
            'neighborhood' => ['required', ...$this->slugRules()],
        ]);
    }
}
