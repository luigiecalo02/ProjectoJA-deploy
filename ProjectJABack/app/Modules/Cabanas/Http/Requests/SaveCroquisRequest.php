<?php

namespace App\Modules\Cabanas\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SaveCroquisRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('cabana')) ?? false;
    }

    public function rules(): array
    {
        return [
            'pisos' => ['required', 'array', 'max:100'],
            'pisos.*.nombre' => ['required', 'string', 'max:255'],
            'pisos.*.ancho' => ['required', 'integer', 'min:100', 'max:10000'],
            'pisos.*.alto' => ['required', 'integer', 'min:100', 'max:10000'],
            'pisos.*.orden' => ['nullable', 'integer', 'min:0'],
            'pisos.*.cuartos' => ['present', 'array'],
            'pisos.*.cuartos.*.nombre' => ['required', 'string', 'max:255'],
            'pisos.*.cuartos.*.codigo' => ['nullable', 'string', 'max:50'],
            'pisos.*.cuartos.*.x' => ['required', 'numeric', 'min:0'],
            'pisos.*.cuartos.*.y' => ['required', 'numeric', 'min:0'],
            'pisos.*.cuartos.*.ancho' => ['required', 'numeric', 'gt:0'],
            'pisos.*.cuartos.*.alto' => ['required', 'numeric', 'gt:0'],
            'pisos.*.cuartos.*.forma' => ['nullable', 'in:rect,circle,polygon'],
            'pisos.*.cuartos.*.vertices' => ['nullable', 'array'],
            'pisos.*.cuartos.*.vertices.*.x' => ['required_with:pisos.*.cuartos.*.vertices', 'numeric'],
            'pisos.*.cuartos.*.vertices.*.y' => ['required_with:pisos.*.cuartos.*.vertices', 'numeric'],
            'pisos.*.cuartos.*.puertas' => ['nullable', 'array'],
            'pisos.*.cuartos.*.puertas.*.x' => ['required_with:pisos.*.cuartos.*.puertas', 'numeric'],
            'pisos.*.cuartos.*.puertas.*.y' => ['required_with:pisos.*.cuartos.*.puertas', 'numeric'],
            'pisos.*.cuartos.*.puertas.*.ancho' => ['nullable', 'numeric', 'gt:0'],
            'pisos.*.cuartos.*.puertas.*.rotacion' => ['nullable', 'numeric', 'between:-360,360'],
            'pisos.*.cuartos.*.genero' => ['required', 'in:M,F,MIXTO'],
            'pisos.*.cuartos.*.capacidad' => ['required', 'integer', 'min:1', 'max:1000'],
            'pisos.*.cuartos.*.orden' => ['nullable', 'integer', 'min:0'],
            'pisos.*.cuartos.*.camas' => ['present', 'array'],
            'pisos.*.cuartos.*.camas.*.codigo' => ['required', 'string', 'max:50'],
            'pisos.*.cuartos.*.camas.*.nombre' => ['nullable', 'string', 'max:255'],
            'pisos.*.cuartos.*.camas.*.capacidad' => ['required', 'integer', 'min:1', 'max:20'],
            'pisos.*.cuartos.*.camas.*.x' => ['required', 'numeric', 'min:0'],
            'pisos.*.cuartos.*.camas.*.y' => ['required', 'numeric', 'min:0'],
            'pisos.*.cuartos.*.camas.*.ancho' => ['nullable', 'numeric', 'gt:0'],
            'pisos.*.cuartos.*.camas.*.alto' => ['nullable', 'numeric', 'gt:0'],
            'pisos.*.cuartos.*.camas.*.rotacion' => ['nullable', 'numeric', 'between:-360,360'],
            'pisos.*.cuartos.*.camas.*.estado' => ['nullable', 'in:disponible,no_disponible,mantenimiento'],
            'pisos.*.cuartos.*.camas.*.orden' => ['nullable', 'integer', 'min:0'],
        ];
    }
}
