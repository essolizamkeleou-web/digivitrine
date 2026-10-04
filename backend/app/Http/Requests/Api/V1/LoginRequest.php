<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * "login" is a phone number or an e-mail address.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'login' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'max:255'],
            'device_name' => ['nullable', 'string', 'max:100'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'login.required' => "Saisissez votre num\u{E9}ro de t\u{E9}l\u{E9}phone ou votre e-mail.",
            'login.string' => "Saisissez votre num\u{E9}ro de t\u{E9}l\u{E9}phone ou votre e-mail.",
            'login.max' => 'Identifiant trop long.',
            'password.required' => 'Le mot de passe est obligatoire.',
            'password.string' => 'Le mot de passe est invalide.',
            'password.max' => 'Le mot de passe est trop long.',
            'device_name.max' => "Le nom de l'appareil est trop long.",
        ];
    }
}
