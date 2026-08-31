<?php

namespace App\Modules\Cabanas\Http\Requests;

use App\Modules\Cabanas\Models\Cabana;
use Illuminate\Foundation\Http\FormRequest;

class StoreCabanaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Cabana::class) ?? false;
    }

    public function rules(): array
    {
        return [
            'lugar_id' => ['required', 'integer', 'exists:lugares,id'],
            'nombre' => ['required', 'string', 'max:255'],
            'descripcion' => ['nullable', 'string'],
            'ancho' => ['sometimes', 'integer', 'min:100', 'max:10000'],
            'alto' => ['sometimes', 'integer', 'min:100', 'max:10000'],
            'estado' => ['sometimes', 'in:activa,inactiva'],
        ];
    }
}
