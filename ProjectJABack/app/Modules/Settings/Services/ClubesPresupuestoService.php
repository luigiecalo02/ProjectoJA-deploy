<?php

namespace App\Modules\Settings\Services;

use App\Models\User;
use App\Modules\Events\Models\Event;
use App\Modules\Events\Models\EventoInscripcion;
use App\Modules\Events\Models\EventoInscripcionPersona;
use App\Modules\Events\Models\TipoEvento;
use App\Modules\Settings\Models\AppSetting;
use App\Modules\Settings\Models\ClubesEventoPresupuesto;
use App\Modules\Settings\Models\ClubesEventoPresupuestoItem;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

final class ClubesPresupuestoService
{
    /**
     * @return list<array<string, mixed>>
     */
    public function listEventos(User $actor): array
    {
        $orgId = $this->assertCanView($actor);
        $events = $this->campamentos($actor, $orgId);
        $eventIds = $events->pluck('id')->all();
        $presupuestos = ClubesEventoPresupuesto::query()
            ->where('organizacion_id', $orgId)
            ->whereIn('evento_id', $eventIds ?: [0])
            ->orderByDesc('activo')
            ->orderBy('id')
            ->get()
            ->groupBy(fn (ClubesEventoPresupuesto $row) => (int) $row->evento_id);
        $conteos = $this->conteosPorEvento($eventIds);

        return $events->map(function (Event $event) use ($presupuestos, $conteos) {
            $grupo = $presupuestos->get($event->id, collect());
            $presupuesto = $grupo->firstWhere('activo', true) ?? $grupo->first();
            $inscritos = $conteos[$event->id] ?? ['miembros' => 0, 'acompanantes' => 0];
            $acompanantesPrevistos = (int) ($presupuesto?->acompanantes_count ?? 0);

            return [
                ...$this->eventoResumen($event),
                'tiene_presupuesto' => $grupo->isNotEmpty(),
                'presupuestos_count' => $grupo->count(),
                'miembros_count' => $inscritos['miembros'],
                'acompanantes_count' => $this->cantidadAcompanantes($inscritos['acompanantes'], $acompanantesPrevistos),
            ];
        })->values()->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function show(User $actor, Event $event, ?int $presupuestoId = null): array
    {
        $orgId = $this->assertCanView($actor);
        $this->assertEventoDelClub($actor, $event, $orgId);
        abort_unless($event->isCampamento(), Response::HTTP_NOT_FOUND);

        return $this->payload($event, $orgId, $presupuestoId);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function save(User $actor, Event $event, array $data): array
    {
        $orgId = $this->assertCanUpdate($actor);
        $this->assertEventoDelClub($actor, $event, $orgId);
        abort_unless(
            $event->isCampamento(),
            Response::HTTP_UNPROCESSABLE_ENTITY,
            'El presupuesto solo aplica a eventos tipo Campamento.',
        );

        $presupuestoId = null;
        DB::transaction(function () use ($event, $orgId, $data, &$presupuestoId) {
            $presupuesto = $this->resolveForWrite($event->id, $orgId, $data);
            $activar = array_key_exists('activo', $data)
                ? (bool) $data['activo']
                : (bool) $presupuesto->activo;
            if (! ClubesEventoPresupuesto::query()->where('evento_id', $event->id)->where('id', '!=', $presupuesto->id)->exists()) {
                $activar = true;
            }
            $presupuesto->fill([
                'nombre' => $this->nombre($data['nombre'] ?? $presupuesto->nombre, $event->id, $orgId, (int) $presupuesto->id),
                'acompanantes_count' => (int) ($data['acompanantes_count'] ?? $presupuesto->acompanantes_count),
                'activo' => $activar,
            ])->save();
            if ($activar) {
                $this->activarSolo($event->id, $orgId, (int) $presupuesto->id);
            }

            $presupuesto->items()->delete();
            foreach (array_values($data['items'] ?? []) as $index => $item) {
                $presupuesto->items()->create([
                    'concepto' => trim((string) $item['concepto']),
                    'tipo' => $item['tipo'],
                    'monto' => round((float) $item['monto'], 2),
                    'destinatario' => $item['destinatario'],
                    'orden' => isset($item['orden']) ? (int) $item['orden'] : $index,
                ]);
            }
            $presupuestoId = (int) $presupuesto->id;
        });

        return $this->payload($event, $orgId, $presupuestoId);
    }

    public function destroy(User $actor, Event $event, int $presupuestoId): array
    {
        $orgId = $this->assertCanUpdate($actor);
        $this->assertEventoDelClub($actor, $event, $orgId);
        abort_unless($event->isCampamento(), Response::HTTP_UNPROCESSABLE_ENTITY, 'El presupuesto solo aplica a eventos tipo Campamento.');

        DB::transaction(function () use ($event, $orgId, $presupuestoId) {
            $presupuesto = $this->presupuestoDelEvento($event->id, $orgId, $presupuestoId);
            abort_unless($presupuesto, Response::HTTP_NOT_FOUND);
            $eraActivo = (bool) $presupuesto->activo;
            $presupuesto->delete();
            if ($eraActivo) {
                $siguiente = ClubesEventoPresupuesto::query()
                    ->where('evento_id', $event->id)
                    ->where('organizacion_id', $orgId)
                    ->orderBy('id')
                    ->first();
                if ($siguiente) {
                    $this->activarSolo($event->id, $orgId, (int) $siguiente->id);
                }
            }
        });

        return $this->payload($event, $orgId);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Event $event, int $orgId, ?int $presupuestoId = null): array
    {
        $event->loadMissing('tipoEvento');
        $lista = ClubesEventoPresupuesto::query()
            ->where('evento_id', $event->id)
            ->where('organizacion_id', $orgId)
            ->orderByDesc('activo')
            ->orderBy('id')
            ->get();
        $presupuesto = $presupuestoId
            ? $lista->firstWhere('id', $presupuestoId)
            : ($lista->firstWhere('activo', true) ?? $lista->first());
        if ($presupuesto) {
            $presupuesto->load('items');
        }

        $inscritos = $this->conteosPorEvento([$event->id])[$event->id] ?? [
            'miembros' => 0,
            'acompanantes' => 0,
        ];
        $acompanantesPrevistos = (int) ($presupuesto?->acompanantes_count ?? 0);
        $cantidades = [
            ClubesEventoPresupuestoItem::DEST_MIEMBROS => $inscritos['miembros'],
            ClubesEventoPresupuestoItem::DEST_ACOMPANANTES => $this->cantidadAcompanantes(
                $inscritos['acompanantes'],
                $acompanantesPrevistos,
            ),
        ];

        abort_unless($presupuestoId === null || $presupuesto, Response::HTTP_NOT_FOUND);
        $items = $presupuesto?->items ?? collect();

        return [
            'evento' => $this->eventoResumen($event),
            'presupuesto_id' => $presupuesto?->id,
            'nombre' => $presupuesto?->nombre,
            'activo' => (bool) ($presupuesto?->activo ?? false),
            'presupuestos' => $lista->map(fn (ClubesEventoPresupuesto $row) => [
                'id' => (int) $row->id,
                'nombre' => $row->nombre,
                'activo' => (bool) $row->activo,
            ])->values()->all(),
            'acompanantes_count' => $acompanantesPrevistos,
            'acompanantes_inscritos' => $inscritos['acompanantes'],
            'miembros_count' => $inscritos['miembros'],
            'cantidades' => $cantidades,
            'bloques' => [
                ClubesEventoPresupuestoItem::DEST_MIEMBROS => $this->bloque(
                    $this->itemsDelBloque(
                        $items,
                        ClubesEventoPresupuestoItem::DEST_MIEMBROS,
                        $cantidades[ClubesEventoPresupuestoItem::DEST_MIEMBROS],
                    ),
                ),
                ClubesEventoPresupuestoItem::DEST_ACOMPANANTES => $this->bloque(
                    $this->itemsDelBloque(
                        $items,
                        ClubesEventoPresupuestoItem::DEST_ACOMPANANTES,
                        $cantidades[ClubesEventoPresupuestoItem::DEST_ACOMPANANTES],
                    ),
                ),
            ],
        ];
    }

    /**
     * @param  \Illuminate\Support\Collection<int, ClubesEventoPresupuestoItem>  $items
     * @return list<array<string, mixed>>
     */
    private function itemsDelBloque($items, string $destinatario, int $cantidad): array
    {
        return $items
            ->filter(fn (ClubesEventoPresupuestoItem $item) => $this->aplicaAlBloque((string) $item->destinatario, $destinatario))
            ->map(fn (ClubesEventoPresupuestoItem $item) => $this->itemPayload($item, $cantidad))
            ->values()
            ->all();
    }

    private function aplicaAlBloque(string $destinatario, string $bloque): bool
    {
        return $destinatario === $bloque || $destinatario === ClubesEventoPresupuestoItem::DEST_AMBOS;
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return array<string, mixed>
     */
    private function bloque(array $items): array
    {
        $totalPorPersona = 0.0;
        $total = 0.0;
        foreach ($items as $item) {
            $total += (float) $item['monto_total'];
            $totalPorPersona += (float) ($item['por_persona'] ?? 0);
        }

        return [
            'items' => $items,
            'total_por_persona' => round($totalPorPersona, 2),
            'total' => round($total, 2),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function itemPayload(ClubesEventoPresupuestoItem $item, int $cantidad): array
    {
        $monto = round((float) $item->monto, 2);
        $individual = $item->tipo === ClubesEventoPresupuestoItem::TIPO_INDIVIDUAL;
        $personas = $cantidad > 0 ? $cantidad : 1;
        $montoTotal = $individual ? round($monto * $cantidad, 2) : $monto;
        $porPersona = $individual ? $monto : round($monto / $personas, 2);

        return [
            'id' => (int) $item->id,
            'concepto' => $item->concepto,
            'tipo' => $item->tipo,
            'monto' => $monto,
            'destinatario' => $item->destinatario,
            'orden' => (int) $item->orden,
            'monto_total' => $montoTotal,
            'por_persona' => $porPersona,
        ];
    }

    /**
     * @return \Illuminate\Support\Collection<int, Event>
     */
    private function campamentos(User $actor, int $orgId)
    {
        return Event::query()
            ->visibleTo($actor)
            ->with('tipoEvento:id,nombre,slug,color,icono')
            ->where('organizacion_id', $orgId)
            ->whereNull('evento_padre_id')
            ->where('estado', '!=', Event::ESTADO_CANCELADO)
            ->whereHas(
                'tipoEvento',
                fn ($tipo) => $tipo->where('slug', TipoEvento::SLUG_CAMPAMENTO),
            )
            ->orderByDesc('starts_at')
            ->get();
    }

    /**
     * @param  list<int>  $eventIds
     * @return array<int, array{miembros: int, acompanantes: int}>
     */
    private function conteosPorEvento(array $eventIds): array
    {
        $ids = array_values(array_unique(array_map('intval', $eventIds)));
        $empty = [];
        foreach ($ids as $id) {
            $empty[$id] = ['miembros' => 0, 'acompanantes' => 0];
        }
        if ($ids === []) {
            return $empty;
        }

        $rows = EventoInscripcionPersona::query()
            ->selectRaw('evento_inscripcion.evento_id as evento_id, evento_inscripcion_persona.tipo as tipo, COUNT(*) as total')
            ->join('evento_inscripcion', 'evento_inscripcion.id', '=', 'evento_inscripcion_persona.inscripcion_id')
            ->whereIn('evento_inscripcion.evento_id', $ids)
            ->whereNotIn('evento_inscripcion.estado', [
                EventoInscripcion::ESTADO_NO_APROBADA,
                EventoInscripcion::ESTADO_BORRADOR,
            ])
            ->where('evento_inscripcion_persona.estado', '!=', EventoInscripcionPersona::ESTADO_CANCELADA)
            ->groupBy('evento_inscripcion.evento_id', 'evento_inscripcion_persona.tipo')
            ->get();

        foreach ($rows as $row) {
            $eventoId = (int) $row->evento_id;
            $tipo = (string) $row->tipo;
            $total = (int) $row->total;
            if (in_array($tipo, [
                EventoInscripcionPersona::TIPO_MIEMBRO,
                EventoInscripcionPersona::TIPO_DIRECTIVA,
            ], true)) {
                $empty[$eventoId]['miembros'] += $total;
            }
            if (in_array($tipo, [
                EventoInscripcionPersona::TIPO_ACOMPANANTE,
                EventoInscripcionPersona::TIPO_ACOMPANANTE_MENOR,
            ], true)) {
                $empty[$eventoId]['acompanantes'] += $total;
            }
        }

        return $empty;
    }

    private function cantidadAcompanantes(int $inscritos, int $previstos): int
    {
        return $inscritos > 0 ? $inscritos : $previstos;
    }

    /**
     * @return array<string, mixed>
     */
    /**
     * @param  array<string, mixed>  $data
     */
    private function resolveForWrite(int $eventoId, int $orgId, array $data): ClubesEventoPresupuesto
    {
        $id = isset($data['presupuesto_id']) ? (int) $data['presupuesto_id'] : null;
        if ($id) {
            $presupuesto = $this->presupuestoDelEvento($eventoId, $orgId, $id);
            abort_unless($presupuesto, Response::HTTP_NOT_FOUND);

            return $presupuesto;
        }

        return ClubesEventoPresupuesto::query()->create([
            'evento_id' => $eventoId,
            'organizacion_id' => $orgId,
            'nombre' => $this->nombre($data['nombre'] ?? null, $eventoId, $orgId, null),
            'activo' => false,
            'acompanantes_count' => (int) ($data['acompanantes_count'] ?? 0),
        ]);
    }

    private function presupuestoDelEvento(int $eventoId, int $orgId, int $presupuestoId): ?ClubesEventoPresupuesto
    {
        return ClubesEventoPresupuesto::query()
            ->where('id', $presupuestoId)
            ->where('evento_id', $eventoId)
            ->where('organizacion_id', $orgId)
            ->first();
    }

    private function activarSolo(int $eventoId, int $orgId, int $presupuestoId): void
    {
        ClubesEventoPresupuesto::query()
            ->where('evento_id', $eventoId)
            ->where('organizacion_id', $orgId)
            ->update(['activo' => false]);
        ClubesEventoPresupuesto::query()
            ->where('id', $presupuestoId)
            ->update(['activo' => true]);
    }

    private function nombre(?string $nombre, int $eventoId, int $orgId, ?int $ignoreId): string
    {
        $trimmed = trim((string) $nombre);
        if ($trimmed !== '') {
            return $trimmed;
        }
        $count = ClubesEventoPresupuesto::query()
            ->where('evento_id', $eventoId)
            ->where('organizacion_id', $orgId)
            ->when($ignoreId, fn ($query) => $query->where('id', '!=', $ignoreId))
            ->count();

        return 'Presupuesto '.($count + 1);
    }

    /**
     * @return array<string, mixed>
     */
    private function eventoResumen(Event $event): array
    {
        return [
            'id' => (int) $event->id,
            'name' => $event->name,
            'starts_at' => $event->starts_at?->toIso8601String(),
            'lugar' => $event->lugar,
            'image_url' => $event->image_url,
            'banner_url' => $event->banner_url,
        ];
    }

    private function assertEventoDelClub(User $actor, Event $event, int $orgId): void
    {
        abort_unless(
            (int) $event->organizacion_id === $orgId
                && $event->estado !== Event::ESTADO_CANCELADO
                && $event->isVisibleTo($actor),
            Response::HTTP_FORBIDDEN,
            'Este evento no pertenece a tu club.',
        );
    }

    private function assertCanView(User $actor): int
    {
        $orgId = AppSetting::resolveOrganizacionId();
        abort_unless($orgId, Response::HTTP_FORBIDDEN, 'Debes tener una organización activa.');
        abort_unless(
            $this->actorManagesPresupuesto($actor) || $actor->hasPermission('presupuesto.view'),
            Response::HTTP_FORBIDDEN,
            'No puedes ver el presupuesto del club.',
        );

        return $orgId;
    }

    private function assertCanUpdate(User $actor): int
    {
        $orgId = $this->assertCanView($actor);
        abort_unless(
            $this->actorManagesPresupuesto($actor) || $actor->hasPermission('presupuesto.update'),
            Response::HTTP_FORBIDDEN,
            'No puedes editar el presupuesto del club.',
        );

        return $orgId;
    }

    private function actorManagesPresupuesto(User $actor): bool
    {
        return count(array_intersect($actor->roleNames(), [
            'director',
            'subdirector',
            'secretario',
            'tesorero',
        ])) > 0;
    }
}
