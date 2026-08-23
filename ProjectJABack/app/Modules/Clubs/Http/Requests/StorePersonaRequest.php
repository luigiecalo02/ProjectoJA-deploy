<?php

namespace App\Modules\Clubs\Http\Requests;

use App\Modules\Clubs\Models\Persona;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePersonaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Persona::class) ?? false;
    }

    public function rules(): array
    {
        return [
            'tipo_identificacion' => ['required', 'string', 'max:30'],
            'identificacion' => [
                'required',
                'string',
                'max:50',
                Rule::unique('personas', 'identificacion')->whereNull('deleted_at'),
            ],
            'nombre1' => ['required', 'string', 'max:100'],
            'nombre2' => ['nullable', 'string', 'max:100'],
            'apellido1' => ['required', 'string', 'max:100'],
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
