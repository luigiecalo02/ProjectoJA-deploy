<?php

namespace App\Modules\Cabanas\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class AssignFromCupoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'inscripcion_persona_id' => ['required', 'integer', 'exists:evento_inscripcion_persona,id'],
            'evento_cabana_cama_id' => ['required', 'integer', 'exists:evento_cabana_camas,id'],
        ];
    }
}
