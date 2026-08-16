<?php

namespace App\Modules\Events\Services;

use App\Models\User;
use App\Modules\Clubs\Models\Persona;
use App\Modules\Events\Models\Seguro;
use App\Modules\Organizations\Services\OrganizationAccessService;
use Carbon\Carbon;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

final class SeguroConsultaService
{
    public function __construct(
        private readonly OrganizationAccessService $organizationAccess,
    ) {}

    /** @return LengthAwarePaginator<int, array<string, mixed>> */
    public function search(User $actor, string $term, int $perPage = 9): LengthAwarePaginator
    {
        $term = trim($term);
        $organizationIds = $this->organizationAccess->personaListOrganizationIds($actor);

        $query = Persona::query()
            ->when($organizationIds === [], fn ($query) => $query->whereRaw('1 = 0'))
            ->when($organizationIds !== null, function ($query) use ($organizationIds) {
                $query->whereHas('organizaciones', fn ($organizations) => $organizations
                    ->where('estado', true)
                    ->whereIn('organizacion_id', $organizationIds));
            })
            ->where(function ($query) use ($term) {
                $query->where('identificacion', 'like', "%{$term}%")
                    ->orWhere('nombre1', 'like', "%{$term}%")
                    ->orWhere('nombre2', 'like', "%{$term}%")
                    ->orWhere('apellido1', 'like', "%{$term}%")
                    ->orWhere('apellido2', 'like', "%{$term}%");
            })
            ->orderBy('apellido1')
            ->orderBy('nombre1');

        $paginator = $query->paginate(max(1, min($perPage, 50)));
        $personas = collect($paginator->items());

        $segurosPorPersona = Seguro::query()
            ->with(['tipoSeguro:id,nombre,tipo', 'evento:id,name'])
            ->whereIn('persona_id', $personas->pluck('id'))
            ->orderByDesc('fecha_fin')
            ->orderByDesc('id')
            ->get()
            ->groupBy('persona_id');

        $paginator->setCollection(
            $personas->map(fn (Persona $persona) => $this->payload(
                $persona,
                $segurosPorPersona->get($persona->id, collect()),
            )),
        );

        return $paginator;
    }

    /**
     * @param  Collection<int, Seguro>  $seguros
     * @return array<string, mixed>
     */
    private function payload(Persona $persona, Collection $seguros): array
    {
        $hoy = Carbon::today();
        $vigente = $seguros->first(function (Seguro $seguro) use ($hoy) {
            return $seguro->estado === Seguro::ESTADO_ACTIVO
                && $seguro->fecha_inicio?->copy()->startOfDay()->lte($hoy)
                && $seguro->fecha_fin?->copy()->startOfDay()->gte($hoy);
        });
        $seguro = $vigente ?? $seguros->first();
        $fechaFin = $seguro?->fecha_fin?->copy()->startOfDay();

        return [
            'persona_id' => (int) $persona->id,
            'nombre' => $persona->full_name,
            'tipo_identificacion' => $persona->tipo_identificacion,
            'identificacion' => $persona->identificacion,
            'vigente' => $vigente !== null,
            'estado' => $vigente !== null ? 'vigente' : ($seguro ? 'no_vigente' : 'sin_seguro'),
            'dias_restantes' => $vigente && $fechaFin
                ? max(0, (int) $hoy->diffInDays($fechaFin, false))
                : 0,
            'seguro' => $seguro ? [
                'id' => (int) $seguro->id,
                'estado' => $seguro->estado,
                'tipo' => $seguro->tipoSeguro?->nombre,
                'fecha_inicio' => $seguro->fecha_inicio?->toDateString(),
                'fecha_fin' => $seguro->fecha_fin?->toDateString(),
                'evento' => $seguro->evento?->name,
            ] : null,
        ];
    }
}
