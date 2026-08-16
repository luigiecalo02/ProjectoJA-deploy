<?php

namespace App\Modules\Terrains\Http\Requests;

use App\Modules\Terrains\Models\Terreno;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTerrenoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Terreno::class) ?? false;
    }

    public function rules(): array
    {
        return [
            'nombre' => ['required', 'string', 'max:255'],
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
