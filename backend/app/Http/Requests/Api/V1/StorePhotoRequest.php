<?php

namespace App\Http\Requests\Api\V1;

use App\Models\Artisan;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class StorePhotoRequest extends FormRequest
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
     * The type is checked on the content (image + mimes use the real MIME type), never on the file name.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'photo' => [
                'required',
                'file',
                'image',
                'mimes:jpeg,png,webp',
                'max:'.(int) config('digivitrine.photos_max_kb'),
                'dimensions:min_width=200,min_height=200,max_width=5000,max_height=5000',
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'photo.required' => "Aucune photo n'a \u{E9}t\u{E9} envoy\u{E9}e (champ photo).",
            'photo.file' => "L'envoi de la photo a \u{E9}chou\u{E9}.",
            'photo.uploaded' => "L'envoi de la photo a \u{E9}chou\u{E9} (fichier trop volumineux pour le serveur ?).",
            'photo.image' => "Le fichier doit \u{EA}tre une image.",
            'photo.mimes' => "Formats accept\u{E9}s : JPEG, PNG et WebP.",
            'photo.max' => 'La photo est trop lourde (5 Mo maximum).',
            'photo.dimensions' => "La photo doit mesurer entre 200 et 5000 pixels de c\u{F4}t\u{E9}.",
        ];
    }
}
