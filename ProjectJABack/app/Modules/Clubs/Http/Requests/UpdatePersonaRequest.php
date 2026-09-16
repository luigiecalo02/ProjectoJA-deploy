<?php

namespace App\Modules\Clubs\Http\Requests;

use App\Modules\Clubs\Models\Persona;
use Illuminate\Foundation\Http\FormRequest;

class UpdatePersonaRequest extends FormRequest
{
    public function authorize(): bool
    {
        $persona = $this->route('persona');

        return $this->user()?->can('update', $persona) ?? false;
    }

    protected function prepareForValidation(): void
    {
        $merge = [];
        if ($this->has('identificacion')) {
            $merge['identificacion'] = trim((string) $this->input('identificacion', ''));
        }
        if ($this->exists('correo')) {
            $merge['correo'] = filled($this->input('correo'))
                ? strtolower(trim((string) $this->input('correo')))
                : null;
        }
        if ($merge !== []) {
            $this->merge($merge);
        }
    }

    public function rules(): array
    {
        $persona = $this->route('persona');
        $personaId = $persona instanceof Persona ? $persona->id : null;

        return [
            'tipo_identificacion' => ['sometimes', 'required', 'string', 'max:30'],
            'identificacion' => ['sometimes', ...PersonaIdentityRules::identificacion($personaId)],
            'nombre1' => ['sometimes', 'required', 'string', 'max:100'],
            'nombre2' => ['nullable', 'string', 'max:100'],
            'apellido1' => ['sometimes', 'required', 'string', 'max:100'],
            'apellido2' => ['nullable', 'string', 'max:100'],
            'fecha_nacimiento' => ['nullable', 'date'],
            'sexo' => ['nullable', 'string', 'max:20'],
            'telefono' => ['nullable', 'string', 'max:40'],
            'correo' => PersonaIdentityRules::correo($persona instanceof Persona ? $persona : null),
            'direccion_actual' => ['nullable', 'string', 'max:500'],
            'club_ids' => ['nullable', 'array'],
            'club_ids.*' => ['integer', 'exists:clubes,id'],
            'organizacion_ids' => ['nullable', 'array'],
            'organizacion_ids.*' => ['integer', 'exists:organizacion,id', 'distinct'],
            'solo_tipo_club' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            ...PersonaIdentityRules::messages(),
            'organizacion_ids.*.distinct' => 'No puedes repetir la misma organización.',
        ];
    }
}
