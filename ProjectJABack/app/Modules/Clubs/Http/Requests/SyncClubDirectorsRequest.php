<?php

namespace App\Modules\Clubs\Http\Requests;

use App\Modules\Clubs\Models\Club;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class SyncClubDirectorsRequest extends FormRequest
{
    public function authorize(): bool
    {
        $club = $this->route('club');

        return $this->user()?->can('manageDirectors', $club) ?? false;
    }

    public function rules(): array
    {
        $positionRules = [];
        foreach (Club::BOARD_POSITIONS as $position) {
            $positionRules["directors.{$position}"] = ['nullable', 'array'];
            $positionRules["directors.{$position}.clear"] = ['sometimes', 'boolean'];
            $positionRules["directors.{$position}.mode"] = ['nullable', Rule::in(['select', 'create'])];
            $positionRules["directors.{$position}.persona_id"] = ['nullable', 'integer', 'exists:personas,id'];
            $positionRules["directors.{$position}.user_id"] = ['nullable', 'integer', 'exists:users,id'];
            $positionRules["directors.{$position}.user"] = ['nullable', 'array'];
            $positionRules["directors.{$position}.user.name"] = ['nullable', 'string', 'max:255'];
            $positionRules["directors.{$position}.user.email"] = [
                'nullable',
                'email',
                'max:255',
                "required_if:directors.{$position}.mode,create",
            ];
            $positionRules["directors.{$position}.user.password"] = [
                "required_if:directors.{$position}.mode,create",
                'nullable',
                'string',
                Password::defaults(),
            ];
            $positionRules["directors.{$position}.persona"] = ['nullable', 'array'];
            $positionRules["directors.{$position}.persona.tipo_identificacion"] = ['nullable', 'string', 'max:30'];
            $positionRules["directors.{$position}.persona.identificacion"] = ["required_if:directors.{$position}.mode,create", 'nullable', 'string', 'max:60'];
            $positionRules["directors.{$position}.persona.nombre1"] = ["required_if:directors.{$position}.mode,create", 'nullable', 'string', 'max:100'];
            $positionRules["directors.{$position}.persona.nombre2"] = ['nullable', 'string', 'max:100'];
            $positionRules["directors.{$position}.persona.apellido1"] = ["required_if:directors.{$position}.mode,create", 'nullable', 'string', 'max:100'];
            $positionRules["directors.{$position}.persona.apellido2"] = ['nullable', 'string', 'max:100'];
            $positionRules["directors.{$position}.persona.telefono"] = ['nullable', 'string', 'max:40'];
            $positionRules["directors.{$position}.persona.sexo"] = ['nullable', 'string', 'max:20'];
        }

        return array_merge([
            'directors' => ['required', 'array'],
        ], $positionRules);
    }
}
