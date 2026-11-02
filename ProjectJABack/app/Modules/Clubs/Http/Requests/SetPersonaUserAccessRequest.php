<?php

namespace App\Modules\Clubs\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class SetPersonaUserAccessRequest extends FormRequest
{
    public function authorize(): bool
    {
        $persona = $this->route('persona');

        return $this->user()?->can('update', $persona) ?? false;
    }

    public function rules(): array
    {
        $activating = $this->boolean('is_active');
        $persona = $this->route('persona');
        $needsPassword = $activating && $persona && ! $persona->user()->exists();

        return [
            'is_active' => ['required', 'boolean'],
            'password' => [
                $needsPassword ? 'required' : ($activating ? 'nullable' : 'prohibited'),
                'confirmed',
                Password::defaults(),
            ],
            'password_confirmation' => [
                $activating && ($needsPassword || $this->filled('password')) ? 'required' : 'nullable',
                'string',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'is_active.required' => 'Indica si el acceso queda activo o no.',
            'password.confirmed' => 'Las contraseñas no coinciden.',
            'password.min' => 'La contraseña debe tener al menos 6 caracteres.',
            'password.regex' => 'La contraseña debe incluir al menos una letra mayúscula.',
            'password_confirmation.required_with' => 'Confirma la contraseña.',
        ];
    }
}
