<?php

namespace App\Modules\Settings\Services;

use App\Models\User;
use App\Modules\Events\Models\Event;
use App\Modules\Events\Models\EventoParticipacion;
use App\Modules\Events\Models\EventoParticipacionAbono;
use App\Modules\Events\Models\EventoParticipacionVenta;
use App\Modules\Events\Models\TipoEvento;
use App\Modules\Clubs\Models\Persona;
use App\Modules\Organizations\Models\PersonaOrganizacion;
use App\Modules\Settings\Models\AppSetting;
use App\Modules\Shared\Services\PublicFileService;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

final class ClubesAbonosService
{
    public function __construct(private readonly PublicFileService $publicFiles) {}

    /**
     * @return array<string, mixed>
     */
    public function board(User $actor, string $modo, ?int $personaId, ?int $eventoId): array
    {
        $orgId = $this->assertCanView($actor);
        $modo = $modo === 'actividad' ? 'actividad' : 'integrante';

        $participaciones = $this->participaciones($orgId);
        $abonos = $this->abonosPorClave($orgId);

        $opcionesIntegrantes = $this->opcionesIntegrantes($participaciones);
        $opcionesActividades = $this->opcionesActividades($participaciones);

        $filas = $participaciones
            ->when($personaId, fn (Collection $rows) => $rows->where('persona_id', $personaId))
            ->when($eventoId, fn (Collection $rows) => $rows->where('evento_id', $eventoId))
            ->map(function (EventoParticipacion $row) use ($abonos, $modo, $personaId, $eventoId) {
                $clave = $this->clave((int) $row->evento_id, (int) $row->persona_id);
                $lista = $abonos->get($clave, collect());
                $comprometido = $this->comprometido($row);
                $abonado = round((float) $lista->sum(fn (EventoParticipacionAbono $abono) => (float) $abono->monto), 2);
                $detalle = ($modo === 'integrante' && $personaId) || ($modo === 'actividad' && $eventoId);

                return [
                    'persona_id' => (int) $row->persona_id,
                    'full_name' => $row->persona?->full_name,
                    'foto_url' => $this->photoUrl($row->persona),
                    'evento_id' => (int) $row->evento_id,
                    'evento_name' => $row->evento?->name,
                    'image_url' => $row->evento?->image_url,
                    'banner_url' => $row->evento?->banner_url,
                    'starts_at' => $row->evento?->starts_at?->toIso8601String(),
                    'comprometido' => $comprometido,
                    'abonado' => $abonado,
                    'pendiente' => round($comprometido - $abonado, 2),
                    'items' => 1,
                    'pendientes' => ($comprometido - $abonado) > 0.009 ? 1 : 0,
                    'pedidos' => $detalle ? $this->pedidos($row) : [],
                    'abonos' => $detalle ? $lista->map(fn (EventoParticipacionAbono $abono) => [
                        'id' => (int) $abono->id,
                        'monto' => (float) $abono->monto,
                        'nota' => $abono->nota,
                        'created_at' => $abono->created_at?->toIso8601String(),
                    ])->values()->all() : [],
                ];
            })
            ->values();

        if ($modo === 'integrante' && ! $personaId) {
            $filas = $this->agruparPorPersona($filas);
        } elseif ($modo === 'actividad' && ! $eventoId) {
            $filas = $this->agruparPorEvento($filas);
        }

        return [
            'modo' => $modo,
            'persona_id' => $personaId,
            'evento_id' => $eventoId,
            'resumen' => $this->resumen($filas),
            'opciones_integrantes' => $opcionesIntegrantes,
            'opciones_actividades' => $opcionesActividades,
            'filas' => $filas->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function store(User $actor, int $eventoId, int $personaId, float $monto, ?string $nota, string $modo = 'integrante'): array
    {
        $orgId = $this->assertCanUpdate($actor);
        abort_unless($monto > 0, Response::HTTP_UNPROCESSABLE_ENTITY, 'El abono debe ser mayor a cero.');

        $participacion = EventoParticipacion::query()
            ->with(['evento.tipoEvento', 'ventas.producto'])
            ->where('evento_id', $eventoId)
            ->where('organizacion_id', $orgId)
            ->where('persona_id', $personaId)
            ->where('participa', true)
            ->first();

        if (! $participacion) {
            throw ValidationException::withMessages([
                'persona_id' => ['Esa persona no está marcada como participante de la actividad.'],
            ]);
        }

        $event = $participacion->evento;
        abort_unless($event instanceof Event, Response::HTTP_NOT_FOUND);
        abort_unless(
            $event->estado !== Event::ESTADO_CANCELADO && $event->isVisibleTo($actor) && $event->isActividadEconomica(),
            Response::HTTP_UNPROCESSABLE_ENTITY,
            'Solo se abona en actividades económicas vigentes.',
        );
        abort_unless(
            PersonaOrganizacion::query()
                ->where('organizacion_id', $orgId)
                ->where('persona_id', $personaId)
                ->where('estado', true)
                ->exists(),
            Response::HTTP_FORBIDDEN,
            'La persona no es integrante de este club.',
        );

        EventoParticipacionAbono::query()->create([
            'evento_id' => $eventoId,
            'organizacion_id' => $orgId,
            'persona_id' => $personaId,
            'monto' => round($monto, 2),
            'nota' => $nota !== null && $nota !== '' ? $nota : null,
            'registrado_por' => $actor->id,
        ]);

        return $this->board(
            $actor,
            $modo === 'actividad' ? 'actividad' : 'integrante',
            $modo === 'actividad' ? null : $personaId,
            $modo === 'actividad' ? $eventoId : null,
        );
    }

    /**
     * @return Collection<int, EventoParticipacion>
     */
    private function participaciones(int $orgId): Collection
    {
        return EventoParticipacion::query()
            ->with(['persona.user', 'evento.tipoEvento', 'ventas.producto'])
            ->where('organizacion_id', $orgId)
            ->where('participa', true)
            ->whereHas('evento', function ($query) {
                $query->whereNull('evento_padre_id')
                    ->where('estado', '!=', Event::ESTADO_CANCELADO)
                    ->whereHas(
                        'tipoEvento',
                        fn ($tipo) => $tipo->where('slug', TipoEvento::SLUG_ACTIVIDAD_ECONOMICA),
                    );
            })
            ->get();
    }

    /**
     * @return Collection<string, Collection<int, EventoParticipacionAbono>>
     */
    private function abonosPorClave(int $orgId): Collection
    {
        return EventoParticipacionAbono::query()
            ->where('organizacion_id', $orgId)
            ->orderByDesc('created_at')
            ->get()
            ->groupBy(fn (EventoParticipacionAbono $row) => $this->clave((int) $row->evento_id, (int) $row->persona_id));
    }

    private function comprometido(EventoParticipacion $row): float
    {
        return round((float) $row->ventas->sum(function (EventoParticipacionVenta $venta) {
            return (int) $venta->cantidad * (float) ($venta->producto?->precio ?? 0);
        }), 2);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function pedidos(EventoParticipacion $row): array
    {
        return $row->ventas
            ->filter(fn (EventoParticipacionVenta $venta) => (int) $venta->cantidad > 0)
            ->map(function (EventoParticipacionVenta $venta) {
                $cantidad = (int) $venta->cantidad;
                $precio = (float) ($venta->producto?->precio ?? 0);

                return [
                    'id' => (int) $venta->id,
                    'nombre' => $venta->producto?->nombre ?? 'Servicio',
                    'cantidad' => $cantidad,
                    'precio' => $precio,
                    'total' => round($cantidad * $precio, 2),
                    'icono' => $venta->producto?->icono,
                    'image_url' => $this->publicFiles->url($venta->producto?->image_path),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $filas
     * @return Collection<int, array<string, mixed>>
     */
    private function agruparPorPersona(Collection $filas): Collection
    {
        return $filas->groupBy('persona_id')->map(function (Collection $rows) {
            $first = $rows->first();
            $comprometido = round((float) $rows->sum('comprometido'), 2);
            $abonado = round((float) $rows->sum('abonado'), 2);

            return [
                'persona_id' => $first['persona_id'],
                'full_name' => $first['full_name'],
                'foto_url' => $first['foto_url'] ?? null,
                'evento_id' => null,
                'evento_name' => $rows->count().' '.($rows->count() === 1 ? 'actividad' : 'actividades'),
                'image_url' => null,
                'starts_at' => null,
                'comprometido' => $comprometido,
                'abonado' => $abonado,
                'pendiente' => round($comprometido - $abonado, 2),
                'items' => $rows->count(),
                'pendientes' => $rows->where('pendiente', '>', 0)->count(),
                'abonos' => [],
            ];
        })->sortBy('full_name', SORT_NATURAL | SORT_FLAG_CASE)->values();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $filas
     * @return Collection<int, array<string, mixed>>
     */
    private function agruparPorEvento(Collection $filas): Collection
    {
        return $filas->groupBy('evento_id')->map(function (Collection $rows) {
            $first = $rows->first();
            $comprometido = round((float) $rows->sum('comprometido'), 2);
            $abonado = round((float) $rows->sum('abonado'), 2);

            return [
                'persona_id' => null,
                'full_name' => $rows->count().' '.($rows->count() === 1 ? 'integrante' : 'integrantes'),
                'foto_url' => null,
                'evento_id' => $first['evento_id'],
                'evento_name' => $first['evento_name'],
                'image_url' => $first['image_url'] ?? null,
                'banner_url' => $first['banner_url'] ?? null,
                'starts_at' => $first['starts_at'],
                'comprometido' => $comprometido,
                'abonado' => $abonado,
                'pendiente' => round($comprometido - $abonado, 2),
                'items' => $rows->count(),
                'pendientes' => $rows->where('pendiente', '>', 0)->count(),
                'abonos' => [],
            ];
        })->sortByDesc('starts_at')->values();
    }

    /**
     * @param  Collection<int, EventoParticipacion>  $participaciones
     * @return list<array<string, mixed>>
     */
    private function opcionesIntegrantes(Collection $participaciones): array
    {
        return $participaciones
            ->unique('persona_id')
            ->map(fn (EventoParticipacion $row) => [
                'id' => (int) $row->persona_id,
                'nombre' => $row->persona?->full_name ?? 'Integrante',
                'foto_url' => $this->photoUrl($row->persona),
            ])
            ->sortBy('nombre', SORT_NATURAL | SORT_FLAG_CASE)
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, EventoParticipacion>  $participaciones
     * @return list<array<string, mixed>>
     */
    private function opcionesActividades(Collection $participaciones): array
    {
        return $participaciones
            ->unique('evento_id')
            ->map(fn (EventoParticipacion $row) => [
                'id' => (int) $row->evento_id,
                'nombre' => $row->evento?->name ?? 'Actividad',
                'starts_at' => $row->evento?->starts_at?->toIso8601String(),
                'image_url' => $row->evento?->image_url,
            ])
            ->sortByDesc('starts_at')
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $filas
     * @return array<string, float>
     */
    private function resumen(Collection $filas): array
    {
        $comprometido = round((float) $filas->sum('comprometido'), 2);
        $abonado = round((float) $filas->sum('abonado'), 2);

        return [
            'comprometido' => $comprometido,
            'abonado' => $abonado,
            'pendiente' => round($comprometido - $abonado, 2),
        ];
    }

    private function clave(int $eventoId, int $personaId): string
    {
        return $eventoId.':'.$personaId;
    }

    private function photoUrl(?Persona $persona): ?string
    {
        if (! $persona) {
            return null;
        }

        return $this->publicFiles->url($persona->foto)
            ?? $this->publicFiles->url($persona->user?->avatar_url);
    }

    private function assertCanView(User $actor): int
    {
        $orgId = AppSetting::resolveOrganizacionId();
        abort_unless($orgId, Response::HTTP_FORBIDDEN, 'Debes tener una organización activa.');
        abort_unless(
            $this->actorManagesAbonos($actor) || $actor->hasPermission('abonos.view'),
            Response::HTTP_FORBIDDEN,
            'No puedes ver los abonos del club.',
        );

        return $orgId;
    }

    private function assertCanUpdate(User $actor): int
    {
        $orgId = $this->assertCanView($actor);
        abort_unless(
            $this->actorManagesAbonos($actor) || $actor->hasPermission('abonos.update'),
            Response::HTTP_FORBIDDEN,
            'No puedes registrar abonos.',
        );

        return $orgId;
    }

    private function actorManagesAbonos(User $actor): bool
    {
        return count(array_intersect($actor->roleNames(), [
            'director',
            'subdirector',
            'secretario',
            'tesorero',
        ])) > 0;
    }
}
