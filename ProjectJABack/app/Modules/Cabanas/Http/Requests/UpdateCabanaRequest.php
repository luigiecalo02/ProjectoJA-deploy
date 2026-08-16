<?php

namespace App\Modules\Cabanas\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateCabanaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('cabana')) ?? false;
    }

    public function rules(): array
    {
        return [
            'nombre' => ['sometimes', 'string', 'max:255'],
            'descripcion' => ['nullable', 'string'],
            'ancho' => ['sometimes', 'integer', 'min:100', 'max:10000'],
            'alto' => ['sometimes', 'integer', 'min:100', 'max:10000'],
            'estado' => ['sometimes', 'in:activa,inactiva'],
        ];
    }
}
