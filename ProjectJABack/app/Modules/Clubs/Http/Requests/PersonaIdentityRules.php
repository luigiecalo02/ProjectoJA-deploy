<?php

namespace App\Modules\Clubs\Http\Requests;

use App\Modules\Clubs\Models\Persona;
use Illuminate\Validation\Rule;

final class PersonaIdentityRules
{
    /**
     * @return list<mixed>
     */
    public static function identificacion(?int $ignorePersonaId = null): array
    {
        $unique = Rule::unique('personas', 'identificacion')->whereNull('deleted_at');
        if ($ignorePersonaId) {
            $unique = $unique->ignore($ignorePersonaId);
        }

        return [
            'required',
            'string',
            'min:5',
            'max:50',
            'regex:/^[A-Za-z0-9.\-]+$/',
            $unique,
        ];
    }

    /**
     * @return list<mixed>
     */
    public static function correo(?Persona $persona = null): array
    {
        $ignorePersonaId = $persona?->id;
        $ignoreUserId = $persona?->user?->id;

        $personaUnique = Rule::unique('personas', 'correo')
            ->whereNull('deleted_at')
            ->whereNotNull('correo');
        if ($ignorePersonaId) {
            $personaUnique = $personaUnique->ignore($ignorePersonaId);
        }

        $userUnique = Rule::unique('users', 'email');
        if ($ignoreUserId) {
            $userUnique = $userUnique->ignore($ignoreUserId);
        }

        return ['nullable', 'email', 'max:255', $personaUnique, $userUnique];
    }

    /**
     * @return array<string, string>
     */
    public static function messages(): array
    {
        return [
            'identificacion.required' => 'Escribe el número de identificación.',
            'identificacion.min' => 'La identificación debe tener al menos 5 caracteres.',
            'identificacion.regex' => 'La identificación solo admite letras, números, punto o guion.',
            'identificacion.unique' => 'Ya existe una persona con este número de identificación.',
            'correo.email' => 'El correo no es válido.',
            'correo.unique' => 'Ya existe una persona o un usuario con este correo.',
        ];
    }
}
