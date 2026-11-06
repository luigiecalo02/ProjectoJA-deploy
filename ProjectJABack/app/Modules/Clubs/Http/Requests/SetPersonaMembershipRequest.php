<?php

namespace App\Modules\Clubs\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SetPersonaMembershipRequest extends FormRequest
{
    public function authorize(): bool
    {
        $persona = $this->route('persona');

        return $this->user()?->can('update', $persona) ?? false;
    }

    public function rules(): array
    {
        return [
            'estado' => ['required', 'boolean'],
            'organizacion_id' => ['nullable', 'integer', 'exists:organizaciones,id'],
        ];
    }

    public function messages(): array
    {
        return [
            'estado.required' => 'Indica si el integrante queda activo o no en el club.',
        ];
    }
}
