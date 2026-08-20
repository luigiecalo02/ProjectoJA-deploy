<?php

namespace App\Modules\Clubs\Http\Requests;

use App\Modules\Clubs\Models\Club;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class StoreClubInscripcionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'asociacion_id' => ['nullable', 'integer', 'exists:organizacion,id'],
            'distrito_id' => ['nullable', 'integer', 'exists:organizacion,id'],
            'iglesia_id' => ['nullable', 'integer', 'exists:organizacion,id'],

            'solicitud_asociacion' => ['nullable', 'array'],
            'solicitud_asociacion.union_id' => ['nullable', 'integer', 'exists:organizacion,id'],
            'solicitud_asociacion.nombre' => ['required_without_all:asociacion_id,iglesia_id', 'nullable', 'string', 'max:255'],
            'solicitud_asociacion.departamento_ids' => ['required_without_all:asociacion_id,iglesia_id', 'nullable', 'array', 'min:1'],
            'solicitud_asociacion.departamento_ids.*' => ['integer', 'exists:departamento,id'],

            'solicitud_distrito' => ['nullable', 'array'],
            'solicitud_distrito.nombre' => ['required_without_all:distrito_id,iglesia_id', 'nullable', 'string', 'max:255'],
            'solicitud_distrito.departamento_ids' => ['required_without_all:distrito_id,iglesia_id', 'nullable', 'array', 'min:1'],
            'solicitud_distrito.departamento_ids.*' => ['integer', 'exists:departamento,id'],
            'solicitud_distrito.ciudad_ids' => ['required_without_all:distrito_id,iglesia_id', 'nullable', 'array', 'min:1'],
            'solicitud_distrito.ciudad_ids.*' => ['integer', 'exists:ciudad,id'],

            'solicitud_iglesia' => ['nullable', 'array'],
            'solicitud_iglesia.nombre' => ['required_without:iglesia_id', 'nullable', 'string', 'max:255'],
            'solicitud_iglesia.direccion' => ['required_without:iglesia_id', 'nullable', 'string', 'max:255'],
            'solicitud_iglesia.departamento_id' => ['nullable', 'integer', 'exists:departamento,id'],
            'solicitud_iglesia.ciudad_id' => ['nullable', 'integer', 'exists:ciudad,id'],
            'solicitud_iglesia.telefono' => ['nullable', 'string', 'max:40'],
            'solicitud_iglesia.correo' => ['nullable', 'email', 'max:255'],

            'club_id' => ['nullable', 'integer', 'exists:clubes,id'],
            'club.nombre' => ['required_without:club_id', 'nullable', 'string', 'max:255'],
            'club.nombre_corto' => ['nullable', 'string', 'max:100'],
            'club.tipo' => ['required_without:club_id', 'nullable', 'string', Rule::in(Club::MINISTRIES)],

            'usuario.cargo' => ['required', 'string', Rule::in(Club::BOARD_POSITIONS)],
            'usuario.email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'usuario.password' => ['required', 'confirmed', Password::defaults()],
            'usuario.persona.tipo_identificacion' => ['required', 'string', 'max:30'],
            'usuario.persona.identificacion' => ['required', 'string', 'max:60', 'unique:personas,identificacion'],
            'usuario.persona.nombre1' => ['required', 'string', 'max:100'],
            'usuario.persona.nombre2' => ['nullable', 'string', 'max:100'],
            'usuario.persona.apellido1' => ['required', 'string', 'max:100'],
            'usuario.persona.apellido2' => ['nullable', 'string', 'max:100'],
            'usuario.persona.telefono' => ['nullable', 'string', 'max:40'],
        ];
    }

    public function messages(): array
    {
        return [
            'club.nombre.required' => 'El nombre del club es obligatorio.',
            'club.tipo.required' => 'Debes indicar el tipo de club.',
            'usuario.cargo.required' => 'Debes indicar el cargo que ocupas.',
            'usuario.email.unique' => 'Este correo ya está registrado.',
            'usuario.password.confirmed' => 'Las contraseñas no coinciden.',
            'usuario.password.min' => 'La contraseña debe tener al menos 6 caracteres, una mayúscula y un símbolo.',
            'usuario.persona.identificacion.unique' => 'Ya existe una persona con este número de identificación.',
            'club_id.exists' => 'El club seleccionado no es válido.',
            'solicitud_asociacion.nombre.required_without_all' => 'Selecciona una asociación o solicita su creación.',
            'solicitud_distrito.nombre.required_without_all' => 'Selecciona un distrito o solicita su creación.',
            'solicitud_iglesia.nombre.required_without' => 'Indica el nombre de la iglesia a solicitar.',
            'solicitud_iglesia.direccion.required_without' => 'La iglesia debe tener dirección.',
            'club.nombre.required_without' => 'El nombre del club es obligatorio.',
            'club.tipo.required_without' => 'Debes indicar el tipo de club.',
        ];
    }
}
