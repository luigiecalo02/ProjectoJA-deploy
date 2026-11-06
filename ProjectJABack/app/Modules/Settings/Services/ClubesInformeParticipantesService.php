<?php

namespace App\Modules\Settings\Services;

use App\Models\User;
use App\Modules\Clubs\Models\Persona;
use App\Modules\Events\Models\Event;
use App\Modules\Events\Models\EventoParticipacionVenta;
use App\Modules\Events\Models\ProductoServicio;
use App\Modules\Events\Models\TipoEvento;
use App\Modules\Organizations\Models\Organizacion;
use App\Modules\Settings\Models\AppSetting;
use App\Modules\Settings\Models\ClubesCajaMovimiento;
use App\Modules\Settings\Models\ClubesInformeParticipantes;
use App\Modules\Shared\Services\PublicFileService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

final class ClubesInformeParticipantesService
{
    private const TTL_DAYS = 60;

    public function __construct(
        private readonly PublicFileService $publicFiles,
        private readonly EventoGananciaRegistro $ganancias,
        private readonly ClubesSettingsService $clubesSettings,
    ) {}

    /**
     * @return array{id: int, url: string, desde: string, hasta: string, expires_at: string|null, activo: bool}
     */
    public function publish(User $actor, string $desde, string $hasta, string $origin): array
    {
        $orgId = $this->assertCanPublish($actor);
        $from = Carbon::parse($desde)->startOfDay();
        $to = Carbon::parse($hasta)->endOfDay();
        abort_unless($to->gte($from), Response::HTTP_UNPROCESSABLE_ENTITY, 'El rango de fechas no es válido.');

        $token = Str::random(48);
        $informe = ClubesInformeParticipantes::query()->create([
            'organizacion_id' => $orgId,
            'desde' => $from->toDateString(),
            'hasta' => $to->toDateString(),
            'token_hash' => hash('sha256', $token),
            'token' => $token,
            'expires_at' => now()->addDays(self::TTL_DAYS),
            'created_by' => $actor->id,
        ]);

        return $this->presentLink($informe, $origin, $token);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listForOrg(User $actor, string $origin): array
    {
        $orgId = $this->assertCanPublish($actor);

        return ClubesInformeParticipantes::query()
            ->where('organizacion_id', $orgId)
            ->orderByDesc('id')
            ->limit(30)
            ->get()
            ->map(fn (ClubesInformeParticipantes $row) => $this->presentLink(
                $row,
                $origin,
                is_string($row->token) && $row->token !== '' ? $row->token : null,
            ))
            ->values()
            ->all();
    }

    /**
     * @return array{id: int, activo: bool}
     */
    public function revoke(User $actor, int $informeId): array
    {
        return $this->setActive($actor, $informeId, false);
    }

    /**
     * @return array{id: int, deleted: bool}
     */
    public function delete(User $actor, int $informeId): array
    {
        $orgId = $this->assertCanPublish($actor);
        $informe = ClubesInformeParticipantes::query()
            ->where('organizacion_id', $orgId)
            ->whereKey($informeId)
            ->firstOrFail();

        $id = (int) $informe->id;
        $informe->delete();

        return [
            'id' => $id,
            'deleted' => true,
        ];
    }

    /**
     * @return array{id: int, activo: bool}
     */
    public function setActive(User $actor, int $informeId, bool $activo): array
    {
        $orgId = $this->assertCanPublish($actor);
        $informe = ClubesInformeParticipantes::query()
            ->where('organizacion_id', $orgId)
            ->whereKey($informeId)
            ->firstOrFail();

        if ($activo) {
            $payload = ['revoked_at' => null];
            if ($informe->expires_at !== null && $informe->expires_at->isPast()) {
                $payload['expires_at'] = now()->addDays(self::TTL_DAYS);
            }
            $informe->forceFill($payload)->save();
        } elseif ($informe->revoked_at === null) {
            $informe->forceFill(['revoked_at' => now()])->save();
        }

        return [
            'id' => (int) $informe->id,
            'activo' => $informe->fresh()?->isActive() ?? false,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function publicSummary(string $token): array
    {
        $informe = $this->resolveActiveInforme($token);
        $orgId = (int) $informe->organizacion_id;
        $org = Organizacion::query()->findOrFail($orgId);
        $branding = $this->clubesSettings->publicBranding($orgId);
        $tree = $this->buildTree($orgId, $informe->desde, $informe->hasta);
        $participantes = array_map(static fn (array $row) => [
            'persona_id' => $row['persona_id'],
            'nombre' => $row['nombre'],
            'foto_url' => $row['foto_url'],
            'total' => $row['total'],
        ], $tree);

        return [
            'organizacion' => [
                'id' => (int) $org->id,
                'nombre' => $org->nombre,
                'logo_url' => $branding['logo_url'] ?? null,
                'banner_url' => $branding['banner_url'] ?? null,
                'background_url' => $branding['background_url'] ?? null,
                'scene_theme' => (($branding['clubes']['scene_theme'] ?? null) === 'day') ? 'day' : 'night',
                'color_principal' => $branding['clubes']['color_principal'] ?? null,
                'color_secundario' => $branding['clubes']['color_secundario'] ?? null,
            ],
            'desde' => $informe->desde?->toDateString(),
            'hasta' => $informe->hasta?->toDateString(),
            'expires_at' => $informe->expires_at?->toIso8601String(),
            'total' => round((float) collect($participantes)->sum('total'), 2),
            'participantes_count' => count($participantes),
            'participantes' => $participantes,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function publicPersona(string $token, int $personaId): array
    {
        $informe = $this->resolveActiveInforme($token);
        $tree = $this->buildTree((int) $informe->organizacion_id, $informe->desde, $informe->hasta);
        $persona = collect($tree)->firstWhere('persona_id', $personaId);
        abort_unless($persona, Response::HTTP_NOT_FOUND, 'Participante no encontrado en este informe.');

        return $persona;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function buildTree(int $orgId, Carbon $desde, Carbon $hasta): array
    {
        $from = $desde->copy()->startOfDay();
        $to = $hasta->copy()->endOfDay();

        $events = Event::query()
            ->with(['gananciaEclesiastica.distribucion'])
            ->where('organizacion_id', $orgId)
            ->whereNull('evento_padre_id')
            ->whereRaw('LOWER(estado) != ?', [Event::ESTADO_CANCELADO])
            ->whereHas('tipoEvento', fn ($tipo) => $tipo->where('slug', TipoEvento::SLUG_ACTIVIDAD_ECONOMICA))
            ->orderBy('starts_at')
            ->get();

        $lineas = $this->ganancias->lineasRecogidas($events, $from, $to);
        $eventMeta = $events->mapWithKeys(fn (Event $event) => [
            (int) $event->id => [
                'nombre' => (string) ($event->name ?? 'Actividad'),
                'banner_url' => $this->publicFiles->url($event->banner_url),
                'image_url' => $this->publicFiles->url($event->image_url ?? null),
            ],
        ]);

        $productoIds = collect($lineas)->pluck('producto_servicio_id')->unique()->filter()->values()->all();
        $productos = $productoIds === []
            ? collect()
            : ProductoServicio::query()
                ->whereIn('id', $productoIds)
                ->get(['id', 'nombre', 'image_path'])
                ->keyBy(fn (ProductoServicio $row) => (int) $row->id);

        $cantidades = $this->cantidadesPorLinea($events->map(fn (Event $e) => (int) $e->id)->all());

        $personas = [];
        foreach ($lineas as $linea) {
            $personaId = (int) ($linea['persona_id'] ?? 0);
            $eventoId = (int) ($linea['evento_id'] ?? 0);
            $productoId = (int) ($linea['producto_servicio_id'] ?? 0);
            $ganancia = round((float) ($linea['participantes'] ?? 0), 2);
            if ($personaId <= 0 || $eventoId <= 0 || $ganancia <= 0) {
                continue;
            }

            if (! isset($personas[$personaId])) {
                $personas[$personaId] = [
                    'persona_id' => $personaId,
                    'nombre' => 'Participante',
                    'foto_url' => null,
                    'total' => 0.0,
                    'actividades' => [],
                ];
            }
            if (! isset($personas[$personaId]['actividades'][$eventoId])) {
                $meta = $eventMeta[$eventoId] ?? ['nombre' => $linea['evento_nombre'] ?? 'Actividad', 'banner_url' => null, 'image_url' => null];
                $personas[$personaId]['actividades'][$eventoId] = [
                    'evento_id' => $eventoId,
                    'nombre' => $meta['nombre'],
                    'banner_url' => $meta['banner_url'] ?: $meta['image_url'],
                    'total' => 0.0,
                    'productos' => [],
                ];
            }

            $producto = $productos->get($productoId);
            $claveQty = $eventoId.'-'.$personaId.'-'.$productoId;
            $personas[$personaId]['actividades'][$eventoId]['productos'][] = [
                'producto_servicio_id' => $productoId,
                'nombre' => $producto?->nombre ?: 'Producto',
                'image_url' => $this->publicFiles->url($producto?->image_path),
                'cantidad' => (int) ($cantidades[$claveQty] ?? 0),
                'ganancia' => $ganancia,
            ];
            $personas[$personaId]['actividades'][$eventoId]['total'] = round(
                $personas[$personaId]['actividades'][$eventoId]['total'] + $ganancia,
                2,
            );
            $personas[$personaId]['total'] = round($personas[$personaId]['total'] + $ganancia, 2);
        }

        $this->aplicarAjustesCaja($personas, $orgId, $from, $to);
        $this->enriquecerPersonas($personas);

        return collect($personas)
            ->map(function (array $row) {
                $row['actividades'] = collect($row['actividades'])
                    ->sortByDesc('total')
                    ->values()
                    ->map(function (array $actividad) {
                        $actividad['productos'] = collect($actividad['productos'])
                            ->sortByDesc('ganancia')
                            ->values()
                            ->all();

                        return $actividad;
                    })
                    ->all();

                return $row;
            })
            ->sortByDesc('total')
            ->values()
            ->all();
    }

    /**
     * @param  list<int>  $eventoIds
     * @return array<string, int>
     */
    private function cantidadesPorLinea(array $eventoIds): array
    {
        if ($eventoIds === []) {
            return [];
        }

        $out = [];
        EventoParticipacionVenta::query()
            ->join(
                'evento_participacion',
                'evento_participacion.id',
                '=',
                'evento_participacion_venta.evento_participacion_id',
            )
            ->whereIn('evento_participacion.evento_id', $eventoIds)
            ->where('evento_participacion.participa', true)
            ->get([
                'evento_participacion.evento_id',
                'evento_participacion.persona_id',
                'evento_participacion_venta.producto_servicio_id',
                'evento_participacion_venta.cantidad',
                'evento_participacion_venta.cantidad_entregada',
            ])
            ->each(function (EventoParticipacionVenta $venta) use (&$out): void {
                $clave = ((int) $venta->evento_id).'-'.((int) $venta->persona_id).'-'.((int) $venta->producto_servicio_id);
                $out[$clave] = ($out[$clave] ?? 0) + $venta->cantidadContable();
            });

        return $out;
    }

    /**
     * @param  array<int, array<string, mixed>>  $personas
     */
    private function aplicarAjustesCaja(array &$personas, int $orgId, Carbon $from, Carbon $to): void
    {
        ClubesCajaMovimiento::query()
            ->with('evento:id,name,estado')
            ->where('organizacion_id', $orgId)
            ->where('destino', ClubesCajaMovimiento::DESTINO_PARTICIPANTES)
            ->whereNotNull('persona_id')
            ->whereBetween('occurred_at', [$from, $to])
            ->where(function ($query) {
                $query->whereNull('evento_id')
                    ->orWhereHas('evento', fn ($evento) => $evento->whereRaw('LOWER(estado) != ?', [Event::ESTADO_CANCELADO]));
            })
            ->get()
            ->each(function (ClubesCajaMovimiento $row) use (&$personas): void {
                $personaId = (int) $row->persona_id;
                if ($personaId <= 0) {
                    return;
                }
                $monto = round((float) $row->signedAmount(), 2);
                if (! isset($personas[$personaId])) {
                    $personas[$personaId] = [
                        'persona_id' => $personaId,
                        'nombre' => 'Participante',
                        'foto_url' => null,
                        'total' => 0.0,
                        'actividades' => [],
                    ];
                }
                $eventoId = (int) ($row->evento_id ?: 0);
                $clave = $eventoId > 0 ? $eventoId : 'ajustes';
                if (! isset($personas[$personaId]['actividades'][$clave])) {
                    $personas[$personaId]['actividades'][$clave] = [
                        'evento_id' => $eventoId > 0 ? $eventoId : null,
                        'nombre' => $eventoId > 0
                            ? ((string) ($row->evento?->name ?: 'Actividad'))
                            : 'Ajustes y abonos libres',
                        'banner_url' => null,
                        'total' => 0.0,
                        'productos' => [],
                    ];
                }
                $personas[$personaId]['actividades'][$clave]['productos'][] = [
                    'producto_servicio_id' => null,
                    'nombre' => $row->concepto ?: 'Movimiento',
                    'image_url' => null,
                    'cantidad' => null,
                    'ganancia' => $monto,
                ];
                $personas[$personaId]['actividades'][$clave]['total'] = round(
                    $personas[$personaId]['actividades'][$clave]['total'] + $monto,
                    2,
                );
                $personas[$personaId]['total'] = round($personas[$personaId]['total'] + $monto, 2);
            });
    }

    /**
     * @param  array<int, array<string, mixed>>  $personas
     */
    private function enriquecerPersonas(array &$personas): void
    {
        $ids = array_keys($personas);
        if ($ids === []) {
            return;
        }

        Persona::query()
            ->with('user')
            ->whereIn('id', $ids)
            ->get()
            ->each(function (Persona $persona) use (&$personas): void {
                $id = (int) $persona->id;
                if (! isset($personas[$id])) {
                    return;
                }
                $personas[$id]['nombre'] = $persona->full_name ?: 'Participante';
                $personas[$id]['foto_url'] = $this->publicFiles->url($persona->foto)
                    ?? $this->publicFiles->url($persona->user?->avatar_url);
            });
    }

    private function resolveActiveInforme(string $token): ClubesInformeParticipantes
    {
        $informe = ClubesInformeParticipantes::query()
            ->where('token_hash', hash('sha256', $token))
            ->first();
        abort_unless($informe, Response::HTTP_NOT_FOUND, 'El informe no existe o el enlace no es válido.');
        abort_unless($informe->isActive(), Response::HTTP_GONE, 'Este informe ya no está disponible.');

        return $informe;
    }

    /**
     * @return array{id: int, url: string|null, desde: string|null, hasta: string|null, expires_at: string|null, activo: bool, created_at: string|null}
     */
    private function presentLink(ClubesInformeParticipantes $informe, string $origin, ?string $token): array
    {
        $base = rtrim($origin, '/');

        return [
            'id' => (int) $informe->id,
            'url' => $token ? $base.'/informe-participantes/'.$token : null,
            'desde' => $informe->desde?->toDateString(),
            'hasta' => $informe->hasta?->toDateString(),
            'expires_at' => $informe->expires_at?->toIso8601String(),
            'activo' => $informe->isActive(),
            'created_at' => $informe->created_at?->toIso8601String(),
        ];
    }

    private function assertCanPublish(User $actor): int
    {
        $orgId = AppSetting::resolveOrganizacionId();
        abort_unless($orgId, Response::HTTP_FORBIDDEN, 'Debes tener una organización activa.');
        $manages = $actor->isSuperAdmin() || count(array_intersect($actor->roleNames(), [
            'director',
            'subdirector',
            'secretario',
            'tesorero',
        ])) > 0;
        abort_unless(
            $manages || $actor->hasPermission('finanzas.update') || $actor->hasPermission('finanzas.view'),
            Response::HTTP_FORBIDDEN,
            'No puedes publicar el informe de participantes.',
        );

        return (int) $orgId;
    }
}
