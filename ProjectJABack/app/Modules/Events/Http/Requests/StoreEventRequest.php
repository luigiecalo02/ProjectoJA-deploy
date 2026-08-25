<?php

namespace App\Modules\Events\Http\Requests;

use App\Modules\Events\Models\Event;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreEventRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Event::class) ?? false;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'descripcion' => ['nullable', 'string'],
            'lugar' => ['nullable', 'string', 'max:255'],
            'latitud' => ['nullable', 'numeric', 'between:-90,90'],
            'longitud' => ['nullable', 'numeric', 'between:-180,180'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after_or_equal:starts_at'],
            'is_active' => ['sometimes', 'boolean'],
            'estado' => ['sometimes', 'string', Rule::in([
                Event::ESTADO_BORRADOR,
                Event::ESTADO_PUBLICADO,
                Event::ESTADO_EN_PROCESO,
                Event::ESTADO_CERRADO,
                Event::ESTADO_CANCELADO,
            ])],
            'visibilidad' => ['sometimes', 'string', Rule::in([
                Event::VISIBILIDAD_PUBLICO,
                Event::VISIBILIDAD_PRIVADO,
                Event::VISIBILIDAD_ORGANIZACION,
            ])],
            'evento_padre_id' => ['nullable', 'integer', 'exists:events,id'],
            'orden' => ['nullable', 'integer', 'min:0'],
            'organizacion_id' => ['nullable', 'integer', 'exists:organizacion,id'],
            'tipo_evento_id' => ['nullable', 'integer', 'exists:tipo_evento,id'],
            'categoria_subevento_id' => ['nullable', 'integer', 'exists:categoria_subevento,id'],
            'juez_ids' => ['sometimes', 'array'],
            'juez_ids.*' => ['integer', 'exists:users,id'],
            'supervisor_ids' => ['sometimes', 'array'],
            'supervisor_ids.*' => ['integer', 'exists:users,id'],
            'organizacion_ids' => ['sometimes', 'array'],
            'organizacion_ids.*' => ['integer', 'exists:organizacion,id'],
            'tipo_organizacion_ids' => ['sometimes', 'array'],
            'tipo_organizacion_ids.*' => ['integer'],
            'audiencia' => ['sometimes', 'nullable', 'string', 'in:libre,conquistadores,aventureros,guias_mayores'],
            'es_en_sitio' => ['sometimes', 'boolean'],
            'es_calificable' => ['sometimes', 'boolean'],
            'puntaje_maximo' => ['nullable', 'numeric', 'min:0'],
            'puntaje_desde_hijos' => ['sometimes', 'boolean'],
            'puntaje_por_participar' => ['sometimes', 'boolean'],
            'tiempo_estimado_minutos' => ['nullable', 'integer', 'min:1'],
            'requiere_puesto_entrega' => ['sometimes', 'boolean'],
            'requiere_tiempo_entrega' => ['sometimes', 'boolean'],
            'resultado_esperado' => ['nullable', 'integer', 'min:1'],
            'participantes_min' => ['nullable', 'integer', 'min:1'],
            'participantes_max' => ['nullable', 'integer', 'min:1'],
            'permite_inscribir_no_participantes' => ['sometimes', 'boolean'],
            'participantes_genero' => ['nullable', 'string', 'in:mixto,M,F,cualquiera'],
            'participantes_min_m' => ['nullable', 'integer', 'min:0'],
            'participantes_max_m' => ['nullable', 'integer', 'min:0'],
            'participantes_min_f' => ['nullable', 'integer', 'min:0'],
            'participantes_max_f' => ['nullable', 'integer', 'min:0'],
            'equipos_org_min' => ['nullable', 'integer', 'min:1'],
            'equipos_org_max' => ['nullable', 'integer', 'min:1'],
            'es_conjunto' => ['sometimes', 'boolean'],
            'nivel_conjunto' => ['nullable', 'string', 'in:club,iglesia,distrito,asociacion'],
            'maneja_fecha_fin' => ['sometimes', 'boolean'],
            'maneja_penalizaciones' => ['sometimes', 'boolean'],
            'puntos_penalizacion' => ['nullable', 'numeric', 'min:0'],
            'reglas_penalizacion' => ['nullable', 'string'],
            'requiere_evidencia' => ['sometimes', 'boolean'],
            'tipos_evidencia' => ['nullable', 'array'],
            'tipos_evidencia.*' => ['string', 'in:link,pdf,imagen,audio,video'],
            'reglas' => ['nullable', 'string'],
            'requiere_pago' => ['sometimes', 'boolean'],
            'precio' => ['nullable', 'numeric', 'min:0'],
            'precio_fuera_tiempo' => ['nullable', 'numeric', 'min:0'],
            'precio_acompanante' => ['nullable', 'numeric', 'min:0'],
            'precio_acompanante_fuera_tiempo' => ['nullable', 'numeric', 'min:0'],
            'precio_acompanante_menor' => ['nullable', 'numeric', 'min:0'],
            'precio_acompanante_menor_fuera_tiempo' => ['nullable', 'numeric', 'min:0'],
            'precio_directiva' => ['nullable', 'numeric', 'min:0'],
            'precio_directiva_fuera_tiempo' => ['nullable', 'numeric', 'min:0'],
            'descuentos_directiva' => ['nullable', 'array'],
            'descuentos_directiva.*.codigo' => ['required_with:descuentos_directiva', 'string', 'max:64'],
            'descuentos_directiva.*.nombre' => ['required_with:descuentos_directiva', 'string', 'max:120'],
            'descuentos_directiva.*.porcentaje' => ['required_with:descuentos_directiva', 'numeric', 'min:0', 'max:100'],
            'fecha_limite_pago' => ['nullable', 'date'],
            'metodo_pago' => ['nullable', 'string', 'max:64'],
            'cuenta_bancaria_id' => ['nullable', 'integer', 'exists:cuentas_bancarias,id'],
            'requiere_seguro' => ['sometimes', 'boolean'],
            'tipo_seguro_id' => ['nullable', 'integer', 'exists:tipos_seguro,id'],
            'seguro_valor' => ['nullable', 'numeric', 'min:0'],
            'seguro_fecha_inicio' => ['nullable', 'date'],
            'seguro_fecha_fin' => ['nullable', 'date', 'after_or_equal:seguro_fecha_inicio'],
            'cupo_minimo' => ['nullable', 'integer', 'min:1'],
            'cupo_maximo' => ['nullable', 'integer', 'min:1'],
            'cupo_ilimitado' => ['sometimes', 'boolean'],
            'cupo_max_organizacion' => ['nullable', 'integer', 'min:1'],
            'cupo_max_club' => ['nullable', 'integer', 'min:1'],
            'cupo_max_iglesia' => ['nullable', 'integer', 'min:1'],
            'permite_inscripcion_individual' => ['sometimes', 'boolean'],
            'permite_inscripcion_organizacion' => ['sometimes', 'boolean'],
            'permite_inscripcion_club' => ['sometimes', 'boolean'],
            'permite_inscripcion_iglesia' => ['sometimes', 'boolean'],
            'fecha_limite_inscripcion' => ['nullable', 'date'],
            'puntos_inscripcion_a_tiempo' => ['nullable', 'numeric', 'min:0'],
            'puntos_inscripcion_fuera_tiempo' => ['nullable', 'numeric', 'min:0'],
            'criterios' => ['sometimes', 'array'],
            'criterios.*.id' => ['nullable', 'integer', 'exists:criterio_evaluacion,id'],
            'criterios.*.criterio_evaluacion_id' => ['nullable', 'integer', 'exists:criterio_evaluacion,id'],
            'criterios.*.puntos' => ['required_with:criterios', 'numeric', 'min:0'],
            'criterios.*.orden' => ['nullable', 'integer', 'min:0'],
            'image_url' => ['nullable', 'string', 'max:2048'],
            'banner_url' => ['nullable', 'string', 'max:2048'],
        ];
    }

    public function messages(): array
    {
        return [
            'ends_at.after_or_equal' => 'La fecha de finalización debe ser igual o posterior a la fecha de inicio.',
            'starts_at.required' => 'La fecha de inicio es obligatoria.',
            'ends_at.required' => 'La fecha de finalización es obligatoria.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $min = $this->input('cupo_minimo');
            $max = $this->input('cupo_maximo');
            $ilimitado = filter_var($this->input('cupo_ilimitado'), FILTER_VALIDATE_BOOLEAN);

            if ($min !== null && $max !== null && ! $ilimitado && (int) $min > (int) $max) {
                $validator->errors()->add(
                    'cupo_minimo',
                    'El cupo mínimo no puede ser mayor que el cupo máximo.'
                );
            }
        });
    }
}
