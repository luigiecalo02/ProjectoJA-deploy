<?php

namespace App\Modules\Users\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class StoreUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', User::class) ?? false;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('email')) {
            $this->merge([
                'email' => strtolower(trim((string) $this->input('email'))),
            ]);
        }
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required',
                'email',
                'max:255',
                Rule::unique('users', 'email'),
            ],
            'password' => ['required', 'confirmed', Password::defaults()],
            'is_active' => ['sometimes', 'boolean'],
            'role_ids' => ['sometimes', 'array'],
            'role_ids.*' => ['integer', 'exists:roles,id'],
            'club_ids' => ['sometimes', 'array'],
            'club_ids.*' => ['integer', 'exists:clubes,id'],
            'avatar_url' => ['nullable', 'url', 'max:2048'],
            'organizaciones' => ['nullable', 'array'],
            'organizaciones.*.organizacion_id' => ['required', 'integer', 'exists:organizacion,id', 'distinct'],
            'organizaciones.*.rol_ids' => ['required', 'array', 'min:1'],
            'organizaciones.*.rol_ids.*' => ['integer', 'exists:roles,id'],
            'organizaciones.*.fecha_inicio' => ['nullable', 'date'],
            'organizaciones.*.fecha_fin' => ['nullable', 'date', 'after_or_equal:organizaciones.*.fecha_inicio'],
            'organizaciones.*.estado' => ['nullable', 'boolean'],
            'organizacion_id' => ['nullable', 'integer', 'exists:organizacion,id'],
            'organizacion_rol_id' => ['nullable', 'integer', 'exists:roles,id', 'required_with:organizacion_id'],
            'persona_id' => [
                'nullable',
                'integer',
                'exists:personas,id',
                Rule::requiredIf(fn () => ! $this->filled('persona.identificacion')),
            ],
            'persona' => ['nullable', 'array'],
            'persona.tipo_identificacion' => ['nullable', 'string', 'max:30'],
            'persona.identificacion' => [
                Rule::requiredIf(fn () => ! $this->filled('persona_id')),
                'nullable',
                'string',
                'max:50',
                Rule::unique('personas', 'identificacion')->whereNull('deleted_at'),
            ],
            'persona.nombre1' => [
                Rule::requiredIf(fn () => ! $this->filled('persona_id')),
                'nullable',
                'string',
                'max:100',
            ],
            'persona.nombre2' => ['nullable', 'string', 'max:100'],
            'persona.apellido1' => [
                Rule::requiredIf(fn () => ! $this->filled('persona_id')),
                'nullable',
                'string',
                'max:100',
            ],
            'persona.apellido2' => ['nullable', 'string', 'max:100'],
            'persona.telefono' => ['nullable', 'string', 'max:40'],
            'persona.correo' => ['nullable', 'email', 'max:255'],
            'persona.direccion_actual' => ['nullable', 'string', 'max:500'],
            'persona.fecha_nacimiento' => ['nullable', 'date'],
            'persona.sexo' => ['nullable', 'string', 'max:20'],
        ];
    }

    public function messages(): array
    {
        return [
            'email.unique' => 'Ya existe un usuario registrado con este correo.',
            'email.required' => 'El correo es obligatorio.',
            'email.email' => 'El correo no es válido.',
            'persona_id.required' => 'Debes asociar una persona existente o registrar una nueva.',
            'persona.identificacion.required' => 'La identificación de la persona es obligatoria.',
            'persona.identificacion.unique' => 'Ya existe una persona con este número de identificación.',
            'persona.nombre1.required' => 'El primer nombre de la persona es obligatorio.',
            'persona.apellido1.required' => 'El primer apellido de la persona es obligatorio.',
            'organizaciones.*.organizacion_id.distinct' => 'No puedes repetir la misma organización.',
            'organizaciones.*.rol_ids.required' => 'Cada organización debe tener al menos un rol.',
            'organizaciones.*.rol_ids.min' => 'Cada organización debe tener al menos un rol.',
            'password.regex' => 'La contraseña debe incluir al menos una letra mayúscula.',
            'password.max' => 'La contraseña no puede superar 64 caracteres.',
        ];
    }
}
