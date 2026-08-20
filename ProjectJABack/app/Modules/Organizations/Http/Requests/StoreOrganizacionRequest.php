<?php

namespace App\Modules\Organizations\Http\Requests;

use App\Modules\Organizations\Models\Organizacion;
use Illuminate\Foundation\Http\FormRequest;

class StoreOrganizacionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Organizacion::class) ?? false;
    }

    public function rules(): array
    {
        return [
            'organizacion_padre_id' => ['nullable', 'integer', 'exists:organizacion,id'],
            'tipo_organizacion_id' => ['required', 'integer', 'exists:tipo_organizacion,id'],
            'pais_id' => ['nullable', 'integer', 'exists:pais,id'],
            'pais_nombre' => ['nullable', 'string', 'max:255'],
            'departamento_id' => ['nullable', 'integer', 'exists:departamento,id'],
            'departamento_nombre' => ['nullable', 'string', 'max:255'],
            'departamento_ids' => ['nullable', 'array'],
            'departamento_ids.*' => ['integer', 'exists:departamento,id'],
            'departamento_nombres' => ['nullable', 'array'],
            'departamento_nombres.*' => ['string', 'max:255'],
            'ciudad_id' => ['nullable', 'integer', 'exists:ciudad,id'],
            'ciudad_ids' => ['nullable', 'array'],
            'ciudad_ids.*' => ['integer', 'exists:ciudad,id'],
            'ciudad_nombre' => ['nullable', 'string', 'max:255'],
            'nombre' => ['required', 'string', 'max:255'],
            'direccion' => ['nullable', 'string', 'max:500'],
            'telefono' => ['nullable', 'string', 'max:40'],
            'correo' => ['nullable', 'email', 'max:255'],
            'estado' => ['sometimes', 'boolean'],
        ];
    }
}
