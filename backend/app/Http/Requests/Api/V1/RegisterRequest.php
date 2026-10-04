<?php

namespace App\Http\Requests\Api\V1;

use App\Support\TogoPhone;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Normalises the phone number (RG-01) and the e-mail before validation,
     * so that uniqueness is checked on the stored form.
     */
    protected function prepareForValidation(): void
    {
        $email = $this->input('email');

        $this->merge([
            'phone' => TogoPhone::normalize($this->input('phone')) ?? $this->input('phone'),
            'email' => is_string($email) ? mb_strtolower(trim($email)) : $email,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'phone' => ['required', 'string', 'regex:/^\+228[0-9]{8}$/', Rule::unique('users', 'phone')],
            'email' => ['nullable', 'string', 'email:rfc', 'max:255', Rule::unique('users', 'email')],
            'password' => ['required', 'string', 'confirmed', 'min:8', 'max:72'],
            // Only "artisan" can be requested: staff roles are never self-assigned.
            'role' => ['nullable', 'string', Rule::in(['artisan'])],
            'device_name' => ['nullable', 'string', 'max:100'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'Le nom est obligatoire.',
            'name.min' => "Le nom doit contenir au moins 2 caract\u{E8}res.",
            'name.max' => 'Le nom est trop long.',
            'phone.required' => "Le num\u{E9}ro de t\u{E9}l\u{E9}phone est obligatoire.",
            'phone.regex' => "Num\u{E9}ro invalide. Exemple : 90 12 34 56.",
            'phone.unique' => "Ce num\u{E9}ro de t\u{E9}l\u{E9}phone est d\u{E9}j\u{E0} utilis\u{E9}.",
            'email.email' => 'Cette adresse e-mail est invalide.',
            'email.max' => 'Cette adresse e-mail est trop longue.',
            'email.unique' => "Cette adresse e-mail est d\u{E9}j\u{E0} utilis\u{E9}e.",
            'password.required' => 'Le mot de passe est obligatoire.',
            'password.min' => "Le mot de passe doit contenir au moins 8 caract\u{E8}res.",
            'password.max' => "Le mot de passe est trop long (72 caract\u{E8}res maximum).",
            'password.confirmed' => 'La confirmation du mot de passe ne correspond pas.',
            'role.in' => "Ce r\u{F4}le ne peut pas \u{EA}tre choisi \u{E0} l'inscription.",
            'device_name.max' => "Le nom de l'appareil est trop long.",
        ];
    }
}
