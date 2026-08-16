<?php

namespace App\Modules\Auth\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class StartParticipantRegistrationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'tipo_identificacion' => ['required', 'string', 'max:30'],
            'identificacion' => ['required', 'string', 'max:80'],
        ];
    }
}
