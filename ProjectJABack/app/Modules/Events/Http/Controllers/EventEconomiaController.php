<?php

namespace App\Modules\Events\Http\Controllers;

use App\Modules\Clubs\Models\Persona;
use App\Modules\Events\Models\Event;
use App\Modules\Events\Models\EventoInscripcion;
use App\Modules\Events\Models\EventoInscripcionComprobante;
use App\Modules\Events\Models\EventoInscripcionMovimiento;
use App\Modules\Events\Models\ProductoServicio;
use App\Modules\Events\Models\TipoSeguro;
use App\Modules\Events\Services\EventCompanionService;
use App\Modules\Events\Services\EventInscripcionService;
use App\Modules\Events\Services\ProductoServicioService;
use App\Modules\Events\Services\SeguroService;
use App\Modules\Shared\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

final class EventEconomiaController
{
    public function __construct(
        private readonly EventInscripcionService $inscripcionService,
        private readonly EventCompanionService $companionService,
        private readonly SeguroService $seguroService,
        private readonly ProductoServicioService $productoService,
    ) {}

    public function companionPersonas(Request $request, Event $event): JsonResponse
    {
        $personas = $this->companionService->search(
            $request->user(),
            $event,
            (string) $request->query('q', ''),
            (int) $request->integer('limit', 20),
        );
        $cobertura = $this->seguroService->coberturaBatch($personas->pluck('id')->all(), $event);

        return ApiResponse::success($personas->map(fn (Persona $persona) => $this->companionPersonaPayload(
            $persona,
            (bool) ($cobertura[(int) $persona->id]['cubierta'] ?? false),
        ))->values());
    }

    public function storeCompanionPersona(Request $request, Event $event): JsonResponse
    {
        $data = $request->validate([
            'tipo_identificacion' => ['required', 'string', 'max:30'],
            'identificacion' => [
                'required',
                'string',
                'max:50',
                Rule::unique('personas', 'identificacion')->whereNull('deleted_at'),
            ],
            'nombre1' => ['required', 'string', 'max:100'],
            'nombre2' => ['nullable', 'string', 'max:100'],
            'apellido1' => ['required', 'string', 'max:100'],
            'apellido2' => ['nullable', 'string', 'max:100'],
            'fecha_nacimiento' => ['nullable', 'date'],
            'sexo' => ['nullable', 'string', 'max:20'],
            'telefono' => ['nullable', 'string', 'max:40'],
            'correo' => ['nullable', 'email', 'max:255'],
        ]);
        $persona = $this->companionService->create($request->user(), $event, $data);

        return ApiResponse::success(
            $this->companionPersonaPayload($persona),
            'Persona acompañante creada',
            Response::HTTP_CREATED,
        );
    }

    public function tiposSeguro(): JsonResponse
    {
        $items = $this->seguroService->listTipos()->map(fn (TipoSeguro $t) => [
            'id' => $t->id,
            'nombre' => $t->nombre,
            'tipo' => $t->tipo,
            'descripcion' => $t->descripcion,
            'duracion_dias' => $t->duracion_dias,
            'requiere_evento' => $t->requiere_evento,
            'activo' => $t->activo,
        ]);

        return ApiResponse::success($items);
    }

    public function productos(Request $request): JsonResponse
    {
        $soloActivos = ! $request->boolean('all');
        $items = $this->productoService->listCatalog($soloActivos)->map(fn (ProductoServicio $p) => $this->productoPayload($p));

        return ApiResponse::success($items);
    }

    public function storeProducto(Request $request): JsonResponse
    {
        abort_unless($request->user()?->can('events.update') || $request->user()?->isPlatformAdmin(), 403);
        $data = $request->validate([
            'nombre' => ['required', 'string', 'max:255'],
            'tipo' => ['required', 'string', 'max:64'],
            'descripcion' => ['nullable', 'string'],
            'precio' => ['nullable', 'numeric', 'min:0'],
            'unidad' => ['nullable', 'string', 'max:32'],
            'activo' => ['sometimes', 'boolean'],
        ]);
        $p = $this->productoService->createProducto($data);

        return ApiResponse::success($this->productoPayload($p), 'Producto creado', Response::HTTP_CREATED);
    }

    public function updateProducto(Request $request, ProductoServicio $productoServicio): JsonResponse
    {
        abort_unless($request->user()?->can('events.update') || $request->user()?->isPlatformAdmin(), 403);
        $data = $request->validate([
            'nombre' => ['sometimes', 'string', 'max:255'],
            'tipo' => ['sometimes', 'string', 'max:64'],
            'descripcion' => ['nullable', 'string'],
            'precio' => ['nullable', 'numeric', 'min:0'],
            'unidad' => ['nullable', 'string', 'max:32'],
            'activo' => ['sometimes', 'boolean'],
        ]);
        $p = $this->productoService->updateProducto($productoServicio, $data);

        return ApiResponse::success($this->productoPayload($p), 'Producto actualizado');
    }

    public function ofertasEvento(Event $event): JsonResponse
    {
        $items = $this->productoService->listOfertasEvento($event)->map(fn ($o) => [
            'id' => $o->id,
            'evento_id' => $o->evento_id,
            'producto_servicio_id' => $o->producto_servicio_id,
            'precio' => $o->precio,
            'activo' => $o->activo,
            'producto' => $o->producto ? $this->productoPayload($o->producto) : null,
        ]);

        return ApiResponse::success($items);
    }

    public function syncOfertasEvento(Request $request, Event $event): JsonResponse
    {
        abort_unless($request->user()?->can('update', $event), 403);
        $data = $request->validate([
            'items' => ['required', 'array'],
            'items.*.producto_servicio_id' => ['required', 'integer', 'exists:productos_servicios,id'],
            'items.*.precio' => ['required', 'numeric', 'min:0'],
            'items.*.activo' => ['sometimes', 'boolean'],
        ]);
        $items = $this->productoService->syncOfertasEvento($event, $data['items'])->map(fn ($o) => [
            'id' => $o->id,
            'evento_id' => $o->evento_id,
            'producto_servicio_id' => $o->producto_servicio_id,
            'precio' => $o->precio,
            'activo' => $o->activo,
            'producto' => $o->producto ? $this->productoPayload($o->producto) : null,
        ]);

        return ApiResponse::success($items, 'Servicios del evento actualizados');
    }

    public function rosterCobertura(Request $request, Event $event): JsonResponse
    {
        return ApiResponse::success($this->inscripcionService->coberturaRoster($request->user(), $event));
    }

    public function enroll(Request $request, Event $event): JsonResponse
    {
        $data = $request->validate([
            'persona_ids' => ['sometimes', 'array'],
            'persona_ids.*' => ['integer', 'exists:personas,id'],
            'participantes' => ['required_without:persona_ids', 'array', 'min:1'],
            'participantes.*.ref' => ['required', 'string', 'max:100', 'distinct'],
            'participantes.*.persona_id' => ['nullable', 'integer', 'exists:personas,id', 'distinct'],
            'participantes.*.tipo' => ['required', Rule::in([
                'miembro', 'directiva', 'acompanante', 'acompanante_menor', 'visitante_pasadia',
            ])],
            'participantes.*.cargo_directiva' => ['nullable', Rule::in([
                'director', 'subdirector', 'secretario', 'tesorero',
            ])],
            'participantes.*.nombre' => ['required_without:participantes.*.persona_id', 'nullable', 'string', 'max:255'],
            'participantes.*.identificacion' => ['nullable', 'string', 'max:64'],
            'participantes.*.fecha_nacimiento' => ['nullable', 'date'],
            'participantes.*.parentesco' => ['nullable', 'string', 'max:100'],
            'participantes.*.descuento_codigo' => ['nullable', 'string', 'max:64'],
            'reservas' => ['sometimes', 'array'],
            'reservas.*.evento_producto_servicio_id' => ['required_with:reservas', 'integer'],
            'reservas.*.participante_ref' => ['required_with:reservas', 'string', 'max:100'],
            'reservas.*.fecha_inicio' => ['nullable', 'date'],
            'reservas.*.fecha_fin' => ['nullable', 'date'],
            'reservas.*.fecha' => ['nullable', 'date'],
            'reservas.*.cantidad' => ['nullable', 'integer', 'min:1'],
        ]);

        $inscripcion = $this->inscripcionService->enrollClubWithRoster($request->user(), $event, $data);

        return ApiResponse::success($this->inscripcionPayload($inscripcion), 'Inscripción registrada', Response::HTTP_CREATED);
    }

    public function storeComprobante(Request $request, EventoInscripcion $eventoInscripcion): JsonResponse
    {
        $request->validate([
            'valor' => ['required', 'numeric', 'min:0'],
            'archivo' => ['required', 'file', 'max:10240', 'mimes:jpg,jpeg,png,webp,pdf'],
            'movimiento_id' => ['nullable', 'integer', 'exists:evento_inscripcion_movimiento,id'],
        ]);
        $c = $this->inscripcionService->addComprobante(
            $request->user(),
            $eventoInscripcion,
            $request->file('archivo'),
            (float) $request->input('valor'),
            $request->filled('movimiento_id') ? (int) $request->input('movimiento_id') : null,
        );

        return ApiResponse::success($this->comprobantePayload($c), 'Comprobante cargado', Response::HTTP_CREATED);
    }

    public function destroyComprobante(Request $request, EventoInscripcionComprobante $comprobante): JsonResponse
    {
        $this->inscripcionService->deleteComprobante($request->user(), $comprobante);

        return ApiResponse::success(null, 'Comprobante eliminado');
    }

    public function replaceComprobante(
        Request $request,
        EventoInscripcionComprobante $comprobante,
    ): JsonResponse {
        $request->validate([
            'valor' => ['required', 'numeric', 'min:0'],
            'archivo' => ['required', 'file', 'max:10240', 'mimes:jpg,jpeg,png,webp,pdf'],
        ]);
        $updated = $this->inscripcionService->replaceComprobante(
            $request->user(),
            $comprobante,
            $request->file('archivo'),
            (float) $request->input('valor'),
        );

        return ApiResponse::success(
            $this->comprobantePayload($updated),
            'Comprobante reemplazado',
        );
    }

    public function storeComprobanteComentario(
        Request $request,
        EventoInscripcionComprobante $comprobante,
    ): JsonResponse {
        $data = $request->validate([
            'mensaje' => ['required', 'string', 'max:3000'],
        ]);
        $comment = $this->inscripcionService->addComprobanteComentario(
            $request->user(),
            $comprobante,
            $data['mensaje'],
        );

        return ApiResponse::success([
            'id' => $comment->id,
            'comprobante_id' => $comment->comprobante_id,
            'autor_tipo' => $comment->autor_tipo,
            'autor_nombre' => $comment->autor?->name,
            'mensaje' => $comment->mensaje,
            'created_at' => $comment->created_at?->toIso8601String(),
        ], 'Comentario agregado', Response::HTTP_CREATED);
    }

    public function reviewComprobante(Request $request, EventoInscripcionComprobante $comprobante): JsonResponse
    {
        $data = $request->validate([
            'estado' => ['required', 'string', Rule::in(['pendiente', 'aprobado', 'rechazado'])],
            'observacion' => ['nullable', 'string', 'max:2000'],
        ]);
        $c = $this->inscripcionService->reviewComprobante(
            $request->user(),
            $comprobante,
            $data['estado'],
            $data['observacion'] ?? null,
        );

        return ApiResponse::success($this->comprobantePayload($c), 'Comprobante actualizado');
    }

    public function reviewInscripcion(Request $request, EventoInscripcion $eventoInscripcion): JsonResponse
    {
        $data = $request->validate([
            'estado' => ['required', 'string', Rule::in([
                'pendiente_revision', 'en_revision', 'aprobada', 'no_aprobada',
            ])],
            'observacion_revision' => ['nullable', 'string', 'max:5000'],
        ]);
        $ins = $this->inscripcionService->reviewInscripcion(
            $request->user(),
            $eventoInscripcion,
            $data['estado'],
            $data['observacion_revision'] ?? null,
        );

        return ApiResponse::success($this->inscripcionPayload($ins), 'Inscripción actualizada');
    }

    public function listRevision(Request $request, Event $event): JsonResponse
    {
        $items = collect($this->inscripcionService->listInscripcionesParaRevision($request->user(), $event))
            ->map(fn (EventoInscripcion $i) => $this->inscripcionPayload($i));

        return ApiResponse::success($items);
    }

    public function showInscripcion(Request $request, EventoInscripcion $eventoInscripcion): JsonResponse
    {
        $eventoInscripcion->load([
            'personas.persona',
            'personas.reservas.oferta.producto',
            'personas.asignacionesCama' => fn ($query) => $query->where('estado', 'activa')
                ->with('cama.cuarto.piso.eventoCabana'),
            'comprobantes.archivo',
            'comprobantes.comentarios.autor',
            'seguros',
            'reservas',
            'organizacion.club',
            'movimientos.comprobantes.archivo',
            'movimientos.comprobantes.comentarios.autor',
        ]);

        return ApiResponse::success($this->inscripcionPayload($eventoInscripcion));
    }

    private function productoPayload(ProductoServicio $p): array
    {
        return [
            'id' => $p->id,
            'nombre' => $p->nombre,
            'tipo' => $p->tipo,
            'descripcion' => $p->descripcion,
            'precio' => $p->precio,
            'unidad' => $p->unidad,
            'activo' => $p->activo,
        ];
    }

    private function comprobantePayload(EventoInscripcionComprobante $c): array
    {
        $url = null;
        if ($c->archivo?->path) {
            $url = Storage::disk('public')->url($c->archivo->path);
        }

        return [
            'id' => $c->id,
            'inscripcion_id' => $c->inscripcion_id,
            'movimiento_id' => $c->movimiento_id,
            'movimiento_numero' => $c->movimiento?->numero,
            'valor' => $c->valor,
            'estado' => $c->estado,
            'observacion' => $c->observacion,
            'archivo_url' => $url,
            'archivo_nombre' => $c->archivo?->name,
            'mime_type' => $c->archivo?->mime_type,
            'comentarios' => ($c->comentarios ?? collect())->map(fn ($comment) => [
                'id' => $comment->id,
                'comprobante_id' => $comment->comprobante_id,
                'autor_tipo' => $comment->autor_tipo,
                'autor_nombre' => $comment->autor?->name,
                'mensaje' => $comment->mensaje,
                'created_at' => $comment->created_at?->toIso8601String(),
            ])->values()->all(),
            'revisado_at' => $c->revisado_at?->toIso8601String(),
            'created_at' => $c->created_at?->toIso8601String(),
        ];
    }

    private function movimientoPayload(EventoInscripcionMovimiento $movimiento): array
    {
        $resumen = $movimiento->resumenComprobantes();

        return [
            'id' => $movimiento->id,
            'numero' => $movimiento->numero,
            'tipo' => $movimiento->tipo,
            'total_anterior' => (float) $movimiento->total_anterior,
            'total_nuevo' => (float) $movimiento->total_nuevo,
            'valor_diferencia' => (float) $movimiento->valor_diferencia,
            'snapshot' => $movimiento->snapshot,
            'cambios' => $movimiento->cambios,
            'total_consignado' => $resumen['total_consignado'],
            'total_aprobado' => $resumen['total_aprobado'],
            'saldo_por_soportar' => $resumen['saldo_por_soportar'],
            'comprobantes' => $movimiento->comprobantes
                ->map(fn ($comprobante) => $this->comprobantePayload($comprobante))
                ->values()
                ->all(),
            'created_at' => $movimiento->created_at?->toIso8601String(),
        ];
    }

    private function inscripcionPayload(EventoInscripcion $i): array
    {
        $resumenComprobantes = $i->resumenComprobantes();
        $personas = ($i->personas ?? collect())->map(function ($p) {
            $asignacion = ($p->asignacionesCama ?? collect())->first();
            $cama = $asignacion?->cama;
            $cuarto = $cama?->cuarto;
            $piso = $cuarto?->piso;
            $cabana = $piso?->eventoCabana;

            return [
                'id' => $p->id,
                'persona_id' => $p->persona_id,
                'referencia_cliente' => $p->referencia_cliente,
                'tipo' => $p->tipo,
                'cargo_directiva' => $p->cargo_directiva,
                'identificacion' => $p->identificacion_snapshot,
                'fecha_nacimiento' => $p->fecha_nacimiento_snapshot?->toDateString(),
                'parentesco' => $p->parentesco,
                'descuento_codigo' => $p->descuento_codigo,
                'descuento_nombre' => $p->descuento_nombre,
                'descuento_porcentaje' => (float) $p->descuento_porcentaje,
                'valor_base' => (float) $p->valor_base,
                'valor_descuento' => (float) $p->valor_descuento,
                'valor_inscripcion' => $p->valor_inscripcion,
                'valor_seguro' => (float) $p->valor_seguro,
                'estado' => $p->estado,
                'nombre' => $p->nombre_snapshot ?: $p->persona?->full_name,
                'reservas' => ($p->reservas ?? collect())->map(fn ($r) => [
                    'id' => $r->id,
                    'evento_producto_servicio_id' => $r->evento_producto_servicio_id,
                    'producto' => $r->oferta?->producto?->nombre,
                    'tipo' => $r->oferta?->producto?->tipo,
                    'precio_unitario' => (float) $r->precio_unitario,
                    'cantidad' => $r->cantidad,
                    'valor_total' => (float) $r->valor_total,
                    'fecha_inicio' => $r->fecha_inicio?->toDateString(),
                    'fecha_fin' => $r->fecha_fin?->toDateString(),
                    'fecha' => $r->fecha?->toDateString(),
                ])->values()->all(),
                'asignacion_cama' => $asignacion ? [
                    'id' => $asignacion->id,
                    'evento_cabana_cama_id' => $asignacion->evento_cabana_cama_id,
                    'cama' => $cama ? ['id' => $cama->id, 'codigo' => $cama->codigo, 'nombre' => $cama->nombre] : null,
                    'cuarto' => $cuarto ? ['id' => $cuarto->id, 'nombre' => $cuarto->nombre, 'genero' => $cuarto->genero] : null,
                    'piso' => $piso ? ['id' => $piso->id, 'nombre' => $piso->nombre] : null,
                    'cabana' => $cabana ? ['id' => $cabana->id, 'nombre' => $cabana->nombre] : null,
                ] : null,
            ];
        })->values()->all();

        return [
            'id' => $i->id,
            'evento_id' => $i->evento_id,
            'tipo' => $i->tipo,
            'organizacion_id' => $i->organizacion_id,
            'organizacion' => $i->organizacion ? [
                'id' => $i->organizacion->id,
                'nombre' => $i->organizacion->nombre,
                'logo_url' => $i->organizacion->club?->logo,
            ] : null,
            'estado' => $i->estado,
            'total_declarado' => $i->total_declarado,
            'total_consignado' => $resumenComprobantes['total_consignado'],
            'total_consignado_aprobado' => $resumenComprobantes['total_aprobado'],
            'saldo_por_soportar' => $resumenComprobantes['saldo_por_soportar'],
            'observacion_revision' => $i->observacion_revision,
            'revisado_at' => $i->revisado_at?->toIso8601String(),
            'personas' => $personas,
            'comprobantes' => ($i->comprobantes ?? collect())->map(fn ($c) => $this->comprobantePayload($c))->values()->all(),
            'movimientos' => ($i->movimientos ?? collect())
                ->sortBy('numero')
                ->map(fn ($movimiento) => $this->movimientoPayload($movimiento))
                ->values()
                ->all(),
            'seguros_count' => ($i->seguros ?? collect())->count(),
            'reservas_count' => ($i->reservas ?? collect())->count(),
            'alojamiento' => [
                'asignadas' => collect($personas)->whereNotNull('asignacion_cama')->count(),
                'participantes' => count($personas),
            ],
            'created_at' => $i->created_at?->toIso8601String(),
        ];
    }

    private function companionPersonaPayload(Persona $persona, bool $cubierta = false): array
    {
        return [
            'id' => $persona->id,
            'tipo_identificacion' => $persona->tipo_identificacion,
            'identificacion' => $persona->identificacion,
            'nombre1' => $persona->nombre1,
            'nombre2' => $persona->nombre2,
            'apellido1' => $persona->apellido1,
            'apellido2' => $persona->apellido2,
            'fecha_nacimiento' => $persona->fecha_nacimiento?->toDateString(),
            'full_name' => $persona->full_name,
            'cubierta' => $cubierta,
        ];
    }
}
