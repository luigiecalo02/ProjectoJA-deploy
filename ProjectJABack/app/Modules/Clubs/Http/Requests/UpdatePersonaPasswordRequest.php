<?php

namespace App\Modules\Clubs\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class UpdatePersonaPasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        $persona = $this->route('persona');

        return $this->user()?->can('update', $persona) ?? false;
    }

    public function rules(): array
    {
        return [
            'password' => ['required', 'confirmed', Password::defaults()],
            'password_confirmation' => ['required', 'string'],
        ];
    }

    public function messages(): array
    {
        return [
            'password.required' => 'Escribe la nueva contraseña.',
            'password.confirmed' => 'Las contraseñas no coinciden.',
            'password.min' => 'La contraseña debe tener al menos 6 caracteres.',
            'password.regex' => 'La contraseña debe incluir al menos una letra mayúscula.',
            'password_confirmation.required' => 'Confirma la contraseña.',
        ];
    }
}
