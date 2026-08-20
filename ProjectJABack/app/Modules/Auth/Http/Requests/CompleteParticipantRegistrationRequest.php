<?php

namespace App\Modules\Auth\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

final class CompleteParticipantRegistrationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'verification_token' => ['required', 'string', 'size:64'],
            'correo' => ['nullable', 'email:rfc', 'max:255'],
            'telefono' => ['nullable', 'string', 'max:40'],
            'sexo' => ['nullable', 'in:M,F'],
            'nombre1' => ['nullable', 'string', 'max:120'],
            'apellido1' => ['nullable', 'string', 'max:120'],
            'password' => ['required', 'confirmed', Password::defaults()],
        ];
    }

    public function messages(): array
    {
        return [
            'password.regex' => 'La contraseña debe incluir al menos una letra mayúscula.',
            'password.max' => 'La contraseña no puede superar 64 caracteres.',
        ];
    }
}
