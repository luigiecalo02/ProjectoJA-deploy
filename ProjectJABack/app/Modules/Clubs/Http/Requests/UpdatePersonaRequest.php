<?php

namespace App\Modules\Clubs\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePersonaRequest extends FormRequest
{
    public function authorize(): bool
    {
        $persona = $this->route('persona');

        return $this->user()?->can('update', $persona) ?? false;
    }

    public function rules(): array
    {
        $personaId = $this->route('persona')?->id;

        return [
            'tipo_identificacion' => ['sometimes', 'required', 'string', 'max:30'],
            'identificacion' => [
                'sometimes',
                'required',
                'string',
                'max:50',
                Rule::unique('personas', 'identificacion')
                    ->ignore($personaId)
                    ->whereNull('deleted_at'),
            ],
            'nombre1' => ['sometimes', 'required', 'string', 'max:100'],
            'nombre2' => ['nullable', 'string', 'max:100'],
            'apellido1' => ['sometimes', 'required', 'string', 'max:100'],
            'apellido2' => ['nullable', 'string', 'max:100'],
            'fecha_nacimiento' => ['nullable', 'date'],
            'sexo' => ['nullable', 'string', 'max:20'],
            'telefono' => ['nullable', 'string', 'max:40'],
            'correo' => ['nullable', 'email', 'max:255'],
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
            'identificacion.unique' => 'Ya existe una persona con este número de identificación.',
            'organizacion_ids.*.distinct' => 'No puedes repetir la misma organización.',
        ];
    }
}
