<?php

namespace App\Modules\Terrains\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateTerrenoRequest extends FormRequest
{
    public function authorize(): bool
    {
        $terreno = $this->route('terreno');

        return $this->user()?->can('update', $terreno) ?? false;
    }

    public function rules(): array
    {
        return [
            'lugar_id' => ['sometimes', 'required', 'integer', 'exists:lugares,id'],
            'nombre' => ['sometimes', 'string', 'max:255'],
            'descripcion' => ['nullable', 'string'],
            'latitud' => ['nullable', 'numeric', 'between:-90,90'],
            'longitud' => ['nullable', 'numeric', 'between:-180,180'],
            'nivel_zoom' => ['nullable', 'integer', 'between:1,22'],
            'geometria' => ['nullable', 'array'],
            'area_total' => ['nullable', 'numeric', 'min:0'],
            'perimetro' => ['nullable', 'numeric', 'min:0'],
            'metros_por_persona' => ['nullable', 'numeric', 'min:0.1'],
            'imagen_referencia' => ['nullable', 'string', 'max:2048'],
            'estado' => ['nullable', 'string', Rule::in(['activo', 'inactivo'])],
        ];
    }
}
