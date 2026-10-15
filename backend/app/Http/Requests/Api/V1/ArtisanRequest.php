<?php

namespace App\Http\Requests\Api\V1;

use App\Support\TogoPhone;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Shared rules of the artisan write requests. Catalogue slugs are checked against the database
 * by CatalogueResolver; the status is never accepted from the client.
 */
abstract class ArtisanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $merge = [];

        foreach (['phone', 'whatsapp_phone'] as $field) {
            if (is_string($this->input($field))) {
                $merge[$field] = TogoPhone::normalize($this->input($field)) ?? $this->input($field);
            }
        }

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
     * @return list<string>
     */
    protected function slugRules(): array
    {
        return ['string', 'max:120', 'regex:/^[a-z0-9]+(-[a-z0-9]+)*$/'];
    }

    /**
     * @return array<string, mixed>
     */
    protected function commonRules(): array
    {
        return [
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'whatsapp_phone' => ['sometimes', 'nullable', 'string', 'regex:/^\+228[0-9]{8}$/'],
            'address' => ['sometimes', 'nullable', 'string', 'max:255'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90', 'required_with:longitude'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180', 'required_with:latitude'],
            'subcategory' => ['sometimes', 'nullable', ...$this->slugRules()],
            'country' => ['sometimes', 'nullable', 'string', 'size:2', 'alpha'],
            'status' => ['prohibited'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'business_name.required' => 'Le nom commercial est obligatoire.',
            'business_name.min' => "Le nom commercial doit contenir au moins 2 caract\u{E8}res.",
            'business_name.max' => "Le nom commercial est trop long (150 caract\u{E8}res maximum).",
            'description.max' => "La description est trop longue (2000 caract\u{E8}res maximum).",
            'phone.required' => "Le num\u{E9}ro de t\u{E9}l\u{E9}phone est obligatoire.",
            'phone.regex' => "Num\u{E9}ro invalide. Exemple : 90 12 34 56.",
            'phone.unique' => "Ce num\u{E9}ro de t\u{E9}l\u{E9}phone est d\u{E9}j\u{E0} utilis\u{E9} par un autre profil.",
            'whatsapp_phone.regex' => "Num\u{E9}ro WhatsApp invalide. Exemple : 90 12 34 56.",
            'address.max' => "L'adresse est trop longue (255 caract\u{E8}res maximum).",
            'latitude.between' => "La latitude doit \u{EA}tre comprise entre -90 et 90.",
            'latitude.numeric' => "La latitude doit \u{EA}tre un nombre.",
            'latitude.required_with' => "La latitude est obligatoire quand la longitude est renseign\u{E9}e.",
            'longitude.between' => "La longitude doit \u{EA}tre comprise entre -180 et 180.",
            'longitude.numeric' => "La longitude doit \u{EA}tre un nombre.",
            'longitude.required_with' => "La longitude est obligatoire quand la latitude est renseign\u{E9}e.",
            'category.required' => "La cat\u{E9}gorie est obligatoire.",
            'category.required_with' => "La cat\u{E9}gorie est obligatoire quand une sous-cat\u{E9}gorie est donn\u{E9}e.",
            'category.regex' => "Identifiant de cat\u{E9}gorie invalide.",
            'subcategory.regex' => "Identifiant de sous-cat\u{E9}gorie invalide.",
            'city.required' => 'La ville est obligatoire.',
            'city.required_with' => "La ville est obligatoire quand un quartier est donn\u{E9}.",
            'city.regex' => 'Identifiant de ville invalide.',
            'neighborhood.required' => 'Le quartier est obligatoire.',
            'neighborhood.required_with' => "Le quartier est obligatoire quand une ville est donn\u{E9}e.",
            'neighborhood.regex' => 'Identifiant de quartier invalide.',
            'status.prohibited' => "Le statut ne peut pas \u{EA}tre modifi\u{E9} ici : utilisez la soumission du profil.",
        ];
    }
}
