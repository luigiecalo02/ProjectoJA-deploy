<?php

namespace App\Modules\Events\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class StorePublicEventoInscripcionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $crearUsuario = filter_var($this->input('crear_usuario'), FILTER_VALIDATE_BOOLEAN);

        return [
            'tipo_identificacion' => ['required', 'string', 'max:30'],
            'identificacion' => ['required', 'string', 'max:60'],
            'nombre1' => ['required', 'string', 'max:100'],
            'nombre2' => ['nullable', 'string', 'max:100'],
            'apellido1' => ['required', 'string', 'max:100'],
            'apellido2' => ['nullable', 'string', 'max:100'],
            'fecha_nacimiento' => ['nullable', 'date'],
            'sexo' => ['nullable', 'string', Rule::in(['M', 'F', 'Otro'])],
            'telefono' => ['nullable', 'string', 'max:40'],
            'correo' => ['required', 'email', 'max:255'],
            'evento_lote_id' => ['nullable', 'integer', 'exists:eventos_lotes,id'],
            'evento_cabana_id' => ['nullable', 'integer', 'exists:evento_cabanas,id'],
            'crear_usuario' => ['sometimes'],
            'password' => [$crearUsuario ? 'required' : 'nullable', 'confirmed', Password::defaults()],
            'comprobante' => ['nullable', 'file', 'max:10240', 'mimes:jpg,jpeg,png,webp,pdf'],
            'comprobante_valor' => ['nullable', 'numeric', 'min:0'],
        ];
    }

    public function messages(): array
    {
        return [
            'correo.required' => 'El correo es obligatorio para enviarte el seguimiento de la inscripción.',
            'password.required' => 'Escribe una contraseña para crear tu usuario.',
            'password.confirmed' => 'Las contraseñas no coinciden.',
        ];
    }
}
