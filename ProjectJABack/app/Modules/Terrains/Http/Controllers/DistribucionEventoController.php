<?php

namespace App\Modules\Terrains\Http\Controllers;

use App\Modules\Clubs\Models\Club;
use App\Modules\Events\Models\Event;
use App\Modules\Events\Services\EventInscripcionService;
use App\Modules\Shared\Http\Responses\ApiResponse;
use App\Modules\Terrains\Events\DistribucionEventoChanged;
use App\Modules\Terrains\Models\AsignacionLote;
use App\Modules\Terrains\Models\ConfiguracionTerreno;
use App\Modules\Terrains\Models\EventoEstructura;
use App\Modules\Terrains\Models\EventoLote;
use App\Modules\Terrains\Models\EventoTerreno;
use App\Modules\Terrains\Models\EventoZona;
use App\Modules\Terrains\Models\Terreno;
use App\Modules\Terrains\Services\AsignacionLoteService;
use App\Modules\Terrains\Services\DistribucionEventoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class DistribucionEventoController
{
    public function __construct(
        private readonly DistribucionEventoService $distribucionService,
        private readonly AsignacionLoteService $asignacionService,
        private readonly EventInscripcionService $inscripcionService,
    ) {}

    public function show(Request $request, Event $event): JsonResponse
    {
        abort_unless(
            $request->user()->hasPermission('terrenos.view') || $request->user()->can('view', $event),
            Response::HTTP_FORBIDDEN
        );

        $distribucion = $this->distribucionService->getDistribucion($event);

        return ApiResponse::success($distribucion ? $this->payloadEventoTerreno($distribucion) : null);
    }

    public function attach(Request $request, Event $event): JsonResponse
    {
        abort_unless(
            $request->user()->hasPermission('terrenos.assign') || $request->user()->hasPermission('terrenos.update'),
            Response::HTTP_FORBIDDEN
        );

        $data = $request->validate([
            'terreno_id' => ['required', 'integer', 'exists:terrenos,id'],
            'configuracion_terreno_id' => ['required', 'integer', 'exists:configuraciones_terreno,id'],
            'descripcion' => ['nullable', 'string'],
        ]);

        $terreno = Terreno::query()->findOrFail($data['terreno_id']);
        $config = ConfiguracionTerreno::query()->findOrFail($data['configuracion_terreno_id']);
        $resultado = $this->distribucionService->attachTerreno(
            $event,
            $terreno,
            $config,
            $data['descripcion'] ?? null,
        );

        return ApiResponse::success($this->payloadEventoTerreno($resultado), 'Terreno asociado al evento', Response::HTTP_CREATED);
    }

    public function detach(Request $request, Event $event): JsonResponse
    {
        abort_unless(
            $request->user()->hasPermission('terrenos.assign') || $request->user()->hasPermission('terrenos.update'),
            Response::HTTP_FORBIDDEN
        );

        $this->distribucionService->detach($event);

        return ApiResponse::success(null, 'Terreno desasociado del evento');
    }

    public function storeZona(Request $request, EventoTerreno $eventoTerreno): JsonResponse
    {
        abort_unless($request->user()->hasPermission('terrenos.update'), Response::HTTP_FORBIDDEN);
        $data = $request->validate([
            'nombre' => ['required', 'string', 'max:255'],
            'geometria' => ['nullable', 'array'],
            'area' => ['nullable', 'numeric', 'min:0'],
            'perimetro' => ['nullable', 'numeric', 'min:0'],
            'capacidad' => ['nullable', 'integer', 'min:0'],
            'color' => ['nullable', 'string', 'max:20'],
            'orden' => ['nullable', 'integer', 'min:0'],
            'estado' => ['nullable', 'string', 'max:30'],
            'zona_terreno_id' => ['nullable', 'integer', 'exists:zonas_terreno,id'],
        ]);

        $zona = $this->distribucionService->createZona($eventoTerreno, $data);

        return ApiResponse::success($this->payloadEventoZona($zona, true), 'Zona de evento creada', Response::HTTP_CREATED);
    }

    public function updateZona(Request $request, EventoZona $eventoZona): JsonResponse
    {
        abort_unless($request->user()->hasPermission('terrenos.update'), Response::HTTP_FORBIDDEN);
        $data = $request->validate([
            'nombre' => ['sometimes', 'string', 'max:255'],
            'geometria' => ['nullable', 'array'],
            'area' => ['nullable', 'numeric', 'min:0'],
            'perimetro' => ['nullable', 'numeric', 'min:0'],
            'capacidad' => ['nullable', 'integer', 'min:0'],
            'color' => ['nullable', 'string', 'max:20'],
            'orden' => ['nullable', 'integer', 'min:0'],
            'estado' => ['nullable', 'string', 'max:30'],
        ]);

        $zona = $this->distribucionService->updateZona($eventoZona, $data);

        return ApiResponse::success($this->payloadEventoZona($zona, true), 'Zona de evento actualizada');
    }

    public function destroyZona(Request $request, EventoZona $eventoZona): JsonResponse
    {
        abort_unless($request->user()->hasPermission('terrenos.update'), Response::HTTP_FORBIDDEN);
        $this->distribucionService->deleteZona($eventoZona);

        return ApiResponse::success(null, 'Zona de evento eliminada');
    }

    public function storeLote(Request $request, EventoZona $eventoZona): JsonResponse
    {
        abort_unless($request->user()->hasPermission('terrenos.update'), Response::HTTP_FORBIDDEN);
        $data = $request->validate([
            'codigo' => ['required', 'string', 'max:50'],
            'nombre' => ['nullable', 'string', 'max:255'],
            'geometria' => ['nullable', 'array'],
            'area' => ['nullable', 'numeric', 'min:0'],
            'perimetro' => ['nullable', 'numeric', 'min:0'],
            'capacidad_maxima' => ['nullable', 'integer', 'min:0'],
            'tipo_capacidad' => ['nullable', 'in:calculada,manual'],
            'orden' => ['nullable', 'integer', 'min:0'],
            'estado' => ['nullable', 'string', 'max:30'],
            'lote_terreno_id' => ['nullable', 'integer', 'exists:lotes_terreno,id'],
        ]);

        $lote = $this->distribucionService->createLote($eventoZona, $data);

        return ApiResponse::success($this->payloadEventoLote($lote), 'Lote de evento creado', Response::HTTP_CREATED);
    }

    public function storeLoteOnTerreno(Request $request, EventoTerreno $eventoTerreno): JsonResponse
    {
        abort_unless($request->user()->hasPermission('terrenos.update'), Response::HTTP_FORBIDDEN);
        $data = $request->validate([
            'codigo' => ['required', 'string', 'max:50'],
            'nombre' => ['nullable', 'string', 'max:255'],
            'geometria' => ['nullable', 'array'],
            'area' => ['nullable', 'numeric', 'min:0'],
            'perimetro' => ['nullable', 'numeric', 'min:0'],
            'capacidad_maxima' => ['nullable', 'integer', 'min:0'],
            'tipo_capacidad' => ['nullable', 'in:calculada,manual'],
            'orden' => ['nullable', 'integer', 'min:0'],
            'estado' => ['nullable', 'string', 'max:30'],
            'lote_terreno_id' => ['nullable', 'integer', 'exists:lotes_terreno,id'],
        ]);

        $lote = $this->distribucionService->createLoteOnTerreno($eventoTerreno, $data);

        return ApiResponse::success($this->payloadEventoLote($lote), 'Lote de evento creado', Response::HTTP_CREATED);
    }

    public function updateLote(Request $request, EventoLote $eventoLote): JsonResponse
    {
        abort_unless($request->user()->hasPermission('terrenos.update'), Response::HTTP_FORBIDDEN);
        $data = $request->validate([
            'codigo' => ['sometimes', 'string', 'max:50'],
            'nombre' => ['nullable', 'string', 'max:255'],
            'geometria' => ['nullable', 'array'],
            'area' => ['nullable', 'numeric', 'min:0'],
            'perimetro' => ['nullable', 'numeric', 'min:0'],
            'capacidad_maxima' => ['nullable', 'integer', 'min:0'],
            'tipo_capacidad' => ['nullable', 'in:calculada,manual'],
            'orden' => ['nullable', 'integer', 'min:0'],
            'estado' => ['nullable', 'string', 'max:30'],
        ]);

        $lote = $this->distribucionService->updateLote($eventoLote, $data);
        $this->notifyDistributionChanged($lote, 'lot_updated');

        return ApiResponse::success($this->payloadEventoLote($lote), 'Lote de evento actualizado');
    }

    public function destroyLote(Request $request, EventoLote $eventoLote): JsonResponse
    {
        abort_unless($request->user()->hasPermission('terrenos.update'), Response::HTTP_FORBIDDEN);
        $this->distribucionService->deleteLote($eventoLote);

        return ApiResponse::success(null, 'Lote de evento eliminado');
    }

    public function assign(Request $request, EventoLote $eventoLote): JsonResponse
    {
        abort_unless(
            $request->user()->hasPermission('terrenos.assign') || $request->user()->hasPermission('terrenos.update'),
            Response::HTTP_FORBIDDEN
        );

        $data = $request->validate([
            'club_id' => ['required', 'integer', 'exists:clubes,id'],
            'cantidad_personas' => ['required', 'integer', 'min:0'],
            'observaciones' => ['nullable', 'string'],
        ]);

        $canOverride = $request->user()->can('overrideCapacity', Terreno::class);
        $asignacion = $this->asignacionService->assign($eventoLote, $data, $request->user(), $canOverride);
        $this->notifyDistributionChanged($eventoLote, 'assigned');

        return ApiResponse::success($this->payloadAsignacion($asignacion), 'Lote asignado', Response::HTTP_CREATED);
    }

    public function selfAssign(Request $request, EventoLote $eventoLote): JsonResponse
    {
        $data = $request->validate([
            'observaciones' => ['nullable', 'string', 'max:2000'],
        ]);
        $eventoLote->loadMissing('eventoTerreno.evento');
        $event = $eventoLote->eventoTerreno?->evento;
        abort_unless($event, Response::HTTP_UNPROCESSABLE_ENTITY, 'El lote no pertenece a un evento válido.');

        $inscripcion = $this->inscripcionService->assertInscripcionAprobadaParaLote(
            $request->user(),
            $event,
        );
        $club = Club::query()
            ->where('organizacion_id', $inscripcion->organizacion_id)
            ->firstOrFail();
        $cantidadPersonas = $inscripcion->personas()->count();

        $asignacion = $this->asignacionService->assignForDirector(
            $eventoLote,
            $club,
            $cantidadPersonas,
            $request->user(),
            $data['observaciones'] ?? null,
        );
        $this->notifyDistributionChanged($eventoLote, 'assigned');

        return ApiResponse::success(
            $this->payloadAsignacion($asignacion),
            'Lote elegido correctamente',
            Response::HTTP_CREATED,
        );
    }

    public function updateAsignacion(Request $request, AsignacionLote $asignacion): JsonResponse
    {
        abort_unless(
            $request->user()->hasPermission('terrenos.assign') || $request->user()->hasPermission('terrenos.update'),
            Response::HTTP_FORBIDDEN
        );

        $data = $request->validate([
            'club_id' => ['sometimes', 'integer', 'exists:clubes,id'],
            'cantidad_personas' => ['sometimes', 'integer', 'min:0'],
            'observaciones' => ['nullable', 'string'],
        ]);

        $canOverride = $request->user()->can('overrideCapacity', Terreno::class);
        $asignacion = $this->asignacionService->update($asignacion, $data, $canOverride);
        $this->notifyDistributionChanged($asignacion->eventoLote, 'assignment_updated');

        return ApiResponse::success($this->payloadAsignacion($asignacion), 'Asignación actualizada');
    }

    public function liberar(Request $request, AsignacionLote $asignacion): JsonResponse
    {
        abort_unless(
            $request->user()->hasPermission('terrenos.assign') || $request->user()->hasPermission('terrenos.update'),
            Response::HTTP_FORBIDDEN
        );

        $eventoLote = $asignacion->eventoLote;
        $asignacion = $this->asignacionService->liberar($asignacion);
        $this->notifyDistributionChanged($eventoLote, 'released');

        return ApiResponse::success($this->payloadAsignacion($asignacion), 'Asignación liberada');
    }

    private function payloadEventoTerreno(EventoTerreno $et): array
    {
        return [
            'id' => $et->id,
            'evento_id' => $et->evento_id,
            'terreno_id' => $et->terreno_id,
            'configuracion_terreno_id' => $et->configuracion_terreno_id,
            'descripcion' => $et->descripcion,
            'estado' => $et->estado,
            'configuracion' => $et->configuracion ? [
                'id' => $et->configuracion->id,
                'nombre' => $et->configuracion->nombre,
                'es_default' => (bool) $et->configuracion->es_default,
            ] : null,
            'terreno' => $et->terreno ? [
                'id' => $et->terreno->id,
                'nombre' => $et->terreno->nombre,
                'latitud' => $et->terreno->latitud,
                'longitud' => $et->terreno->longitud,
                'nivel_zoom' => $et->terreno->nivel_zoom,
                'geometria' => $et->terreno->geometria,
                'area_total' => $et->terreno->area_total,
                'metros_por_persona' => $et->terreno->metros_por_persona,
                'imagen_referencia' => $et->terreno->imagen_referencia,
            ] : null,
            'zonas' => ($et->zonas ?? collect())->map(fn (EventoZona $z) => $this->payloadEventoZona($z, true))->values(),
            'lotes' => ($et->lotesSinZona ?? collect())->map(fn (EventoLote $l) => $this->payloadEventoLote($l))->values(),
            'estructuras' => ($et->estructuras ?? collect())->map(fn (EventoEstructura $e) => $this->payloadEventoEstructura($e))->values(),
        ];
    }

    private function payloadEventoEstructura(EventoEstructura $estructura): array
    {
        return [
            'id' => $estructura->id,
            'evento_terreno_id' => $estructura->evento_terreno_id,
            'estructura_terreno_id' => $estructura->estructura_terreno_id,
            'nombre' => $estructura->nombre,
            'tipo' => $estructura->tipo,
            'geometria' => $estructura->geometria,
            'area' => $estructura->area,
            'perimetro' => $estructura->perimetro,
            'color' => $estructura->color,
            'orden' => $estructura->orden,
            'estado' => $estructura->estado,
        ];
    }

    private function payloadEventoZona(EventoZona $zona, bool $withLotes = false): array
    {
        $data = [
            'id' => $zona->id,
            'evento_terreno_id' => $zona->evento_terreno_id,
            'zona_terreno_id' => $zona->zona_terreno_id,
            'nombre' => $zona->nombre,
            'geometria' => $zona->geometria,
            'area' => $zona->area,
            'perimetro' => $zona->perimetro,
            'capacidad' => $zona->capacidad,
            'color' => $zona->color,
            'orden' => $zona->orden,
            'estado' => $zona->estado,
        ];

        if ($withLotes) {
            $data['lotes'] = ($zona->lotes ?? collect())->map(fn (EventoLote $l) => $this->payloadEventoLote($l))->values();
        }

        return $data;
    }

    private function payloadEventoLote(EventoLote $lote): array
    {
        $activa = ($lote->asignaciones ?? collect())->firstWhere('estado', 'activa')
            ?? ($lote->asignaciones ?? collect())->first();

        return [
            'id' => $lote->id,
            'evento_terreno_id' => $lote->evento_terreno_id,
            'evento_zona_id' => $lote->evento_zona_id,
            'lote_terreno_id' => $lote->lote_terreno_id,
            'codigo' => $lote->codigo,
            'nombre' => $lote->nombre,
            'geometria' => $lote->geometria,
            'area' => $lote->area,
            'perimetro' => $lote->perimetro,
            'capacidad_calculada' => $lote->capacidad_calculada,
            'capacidad_maxima' => $lote->capacidad_maxima,
            'tipo_capacidad' => $lote->tipo_capacidad,
            'orden' => $lote->orden,
            'estado' => $lote->estado,
            'asignacion' => $activa ? $this->payloadAsignacion($activa) : null,
        ];
    }

    private function payloadAsignacion(AsignacionLote $a): array
    {
        return [
            'id' => $a->id,
            'evento_lote_id' => $a->evento_lote_id,
            'club_id' => $a->club_id,
            'cantidad_personas' => $a->cantidad_personas,
            'observaciones' => $a->observaciones,
            'estado' => $a->estado,
            'asignado_por' => $a->asignado_por,
            'club' => $a->club ? [
                'id' => $a->club->id,
                'nombre' => $a->club->nombre,
                'nombre_corto' => $a->club->nombre_corto,
                'logo' => $a->club->logo,
                'organizacion_id' => $a->club->organizacion_id,
            ] : null,
        ];
    }

    private function notifyDistributionChanged(EventoLote $lote, string $action): void
    {
        $lote->loadMissing('eventoTerreno');
        $eventoId = $lote->eventoTerreno?->evento_id;
        if ($eventoId) {
            try {
                DistribucionEventoChanged::dispatch((int) $eventoId, (int) $lote->id, $action);
            } catch (\Throwable $exception) {
                report($exception);
            }
        }
    }
}
