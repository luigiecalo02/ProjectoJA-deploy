<?php

namespace App\Modules\Clubs\Http\Requests;

use App\Modules\Clubs\Models\Club;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateClubRequest extends FormRequest
{
    public function authorize(): bool
    {
        $club = $this->route('club');

        return $this->user()?->can('update', $club) ?? false;
    }

    public function rules(): array
    {
        return [
            'organizacion_id' => [
                'sometimes',
                'required',
                'integer',
                'exists:organizacion,id',
            ],
            'iglesia_organizacion_id' => [
                'sometimes',
                'required',
                'integer',
                'exists:organizacion,id',
            ],
            'nombre' => ['sometimes', 'required', 'string', 'max:255'],
            'nombre_corto' => ['nullable', 'string', 'max:100'],
            'lema' => ['nullable', 'string', 'max:255'],
            'logo' => ['nullable', 'string', 'max:2048'],
            'fecha_fundacion' => ['nullable', 'date'],
            'descripcion' => ['nullable', 'string'],
            'color_principal' => ['nullable', 'string', 'max:20'],
            'color_secundario' => ['nullable', 'string', 'max:20'],
            'sitio_web' => ['nullable', 'string', 'max:255'],
            'distrito' => ['nullable', 'string', 'max:255'],
            'ciudad' => ['nullable', 'string', 'max:255'],
            'tipos' => ['sometimes', 'required', 'array', 'size:1'],
            'tipos.*' => ['string', Rule::in(Club::MINISTRIES)],
            'is_active' => ['sometimes', 'boolean'],
            'persona_ids' => ['sometimes', 'array'],
            'persona_ids.*' => ['integer', 'exists:personas,id'],
        ];
    }

    public function messages(): array
    {
        return [
            'organizacion_id.required' => 'Debes asociar el club a una iglesia.',
            'iglesia_organizacion_id.required' => 'Debes asociar el club a una iglesia.',
        ];
    }
}
