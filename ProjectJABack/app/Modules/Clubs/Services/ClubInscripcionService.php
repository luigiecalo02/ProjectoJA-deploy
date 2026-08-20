<?php

namespace App\Modules\Clubs\Services;

use App\Models\User;
use App\Modules\Clubs\Models\Club;
use App\Modules\Clubs\Models\Persona;
use App\Modules\Organizations\Models\Organizacion;
use App\Modules\Organizations\Services\OrganizacionService;
use App\Modules\Organizations\Services\UbicacionService;
use App\Modules\Settings\Services\PublicFormSettingsService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ClubInscripcionService
{
    public function __construct(
        private readonly ClubService $clubService,
        private readonly OrganizacionService $organizacionService,
        private readonly UbicacionService $ubicacionService,
        private readonly PublicFormSettingsService $publicForm,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function catalog(int $tipoId, ?int $padreId = null): array
    {
        $query = Organizacion::query()
            ->where('tipo_organizacion_id', $tipoId)
            ->where('estado', true)
            ->where('estado_aprobacion', Organizacion::APROBACION_APROBADA)
            ->orderBy('nombre');

        if ($padreId) {
            $query->where('organizacion_padre_id', $padreId);
        }

        if (in_array($tipoId, [Organizacion::TIPO_ASOCIACION, Organizacion::TIPO_DISTRITO], true)) {
            $relations = ['departamentos:id,nombre,pais_id'];
            if ($tipoId === Organizacion::TIPO_DISTRITO) {
                $relations[] = 'ciudades:id,nombre,departamento_id';
            }
            $query->with($relations);
        }

        return $query
            ->get(['id', 'nombre', 'codigo', 'tipo_organizacion_id', 'organizacion_padre_id', 'pais_id', 'departamento_id', 'ciudad_id'])
            ->map(function (Organizacion $org) {
                $departamentoIds = $org->relationLoaded('departamentos')
                    ? $org->departamentos->pluck('id')->map(fn ($id) => (int) $id)->values()->all()
                    : [];
                if ($departamentoIds === [] && $org->departamento_id) {
                    $departamentoIds = [(int) $org->departamento_id];
                }
                $ciudadIds = $org->relationLoaded('ciudades')
                    ? $org->ciudades->pluck('id')->map(fn ($id) => (int) $id)->values()->all()
                    : [];
                if ($ciudadIds === [] && $org->ciudad_id) {
                    $ciudadIds = [(int) $org->ciudad_id];
                }

                return [
                    'id' => (int) $org->id,
                    'nombre' => $org->nombre,
                    'codigo' => $org->codigo,
                    'tipo_organizacion_id' => (int) $org->tipo_organizacion_id,
                    'organizacion_padre_id' => $org->organizacion_padre_id ? (int) $org->organizacion_padre_id : null,
                    'pais_id' => $org->pais_id ? (int) $org->pais_id : null,
                    'departamento_id' => $org->departamento_id ? (int) $org->departamento_id : null,
                    'departamento_ids' => $departamentoIds,
                    'ciudad_id' => $org->ciudad_id ? (int) $org->ciudad_id : null,
                    'ciudad_ids' => $ciudadIds,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function catalogClubes(?int $iglesiaId): array
    {
        $orgQuery = Organizacion::query()
            ->where('tipo_organizacion_id', Organizacion::TIPO_CLUB)
            ->where('estado', true)
            ->where('estado_aprobacion', Organizacion::APROBACION_APROBADA);

        if ($iglesiaId) {
            $orgQuery->where('organizacion_padre_id', $iglesiaId);
        }

        $orgIds = $orgQuery->pluck('id');
        if ($orgIds->isEmpty()) {
            return [];
        }

        return Club::query()
            ->whereIn('organizacion_id', $orgIds)
            ->where('is_active', true)
            ->orderBy('nombre')
            ->get(['id', 'organizacion_id', 'nombre', 'nombre_corto'])
            ->map(function (Club $club) {
                $ocupados = collect($this->clubService->boardAssignments($club))
                    ->map(function (array $assignment) {
                        $cargo = $assignment['ministry'] ?? null;
                        if (! is_string($cargo) || $cargo === '') {
                            return null;
                        }
                        $nombre = trim((string) ($assignment['persona']['full_name'] ?? $assignment['user']['name'] ?? ''));

                        return [
                            'cargo' => $cargo,
                            'nombre' => $nombre !== '' ? $nombre : null,
                        ];
                    })
                    ->filter()
                    ->values()
                    ->all();

                return [
                    'id' => (int) $club->id,
                    'organizacion_id' => (int) $club->organizacion_id,
                    'nombre' => $club->nombre,
                    'nombre_corto' => $club->nombre_corto,
                    'cargos_ocupados' => $ocupados,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return list<array{id: int, codigo: string|null, nombre: string, label: string}>
     */
    public function paises(): array
    {
        return array_map(fn ($pais) => [
            'id' => (int) $pais->id,
            'codigo' => $pais->codigo,
            'nombre' => $pais->nombre,
            'label' => $pais->codigo ? "{$pais->codigo} — {$pais->nombre}" : $pais->nombre,
        ], $this->ubicacionService->paises());
    }

    /**
     * @return list<array{id: int, pais_id: int, nombre: string, label: string}>
     */
    public function departamentos(?int $paisId): array
    {
        return array_map(fn ($dep) => [
            'id' => (int) $dep->id,
            'pais_id' => (int) $dep->pais_id,
            'nombre' => $dep->nombre,
            'label' => $dep->nombre,
        ], $this->ubicacionService->departamentos($paisId));
    }

    /**
     * @param  list<int>|null  $departamentoIds
     * @return list<array{id: int, departamento_id: int, nombre: string, label: string}>
     */
    public function ciudades(?int $departamentoId, ?array $departamentoIds = null): array
    {
        return array_map(fn ($ciudad) => [
            'id' => (int) $ciudad->id,
            'departamento_id' => (int) $ciudad->departamento_id,
            'nombre' => $ciudad->nombre,
            'label' => $ciudad->nombre,
        ], $this->ubicacionService->ciudades($departamentoId, $departamentoIds));
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{organizacion_id: int, club_id: int}
     */
    public function register(array $data): array
    {
        $this->assertPublicFlags($data);
        $this->assertUniqueAccount($data);

        return DB::transaction(function () use ($data) {
            $iglesiaId = $this->resolveIglesiaId($data);
            $joiningExisting = ! empty($data['club_id']);

            if ($joiningExisting) {
                $club = $this->existingApprovedClub((int) $data['club_id'], $iglesiaId);
                $this->assertCargoLibre($club, (string) $data['usuario']['cargo']);
            } else {
                $club = $this->clubService->createFromIglesia($iglesiaId, [
                    'nombre' => trim((string) $data['club']['nombre']),
                    'nombre_corto' => isset($data['club']['nombre_corto']) ? trim((string) $data['club']['nombre_corto']) : null,
                    'tipos' => [$data['club']['tipo']],
                    'estado_aprobacion' => Organizacion::APROBACION_PENDIENTE,
                    'is_active' => false,
                ]);
            }

            $position = (string) $data['usuario']['cargo'];
            $persona = $data['usuario']['persona'] ?? [];
            $name = trim(collect([
                $persona['nombre1'] ?? null,
                $persona['nombre2'] ?? null,
                $persona['apellido1'] ?? null,
                $persona['apellido2'] ?? null,
            ])->filter()->implode(' '));

            $this->clubService->syncDirectors($club, [
                $position => [
                    'mode' => 'create',
                    'user' => [
                        'name' => $name,
                        'email' => $data['usuario']['email'],
                        'password' => $data['usuario']['password'],
                    ],
                    'persona' => $persona,
                ],
            ], new User());

            $user = User::query()->where('email', $data['usuario']['email'])->firstOrFail();
            $user->forceFill([
                'is_active' => false,
                'email_verified_at' => null,
            ])->save();

            if (! $joiningExisting) {
                $club->update(['created_by' => $user->id, 'is_active' => false]);
            }

            return [
                'organizacion_id' => (int) $club->organizacion_id,
                'club_id' => (int) $club->id,
            ];
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function assertUniqueAccount(array $data): void
    {
        $email = strtolower(trim((string) ($data['usuario']['email'] ?? '')));
        $identificacion = trim((string) ($data['usuario']['persona']['identificacion'] ?? ''));

        if ($email !== '' && User::query()->whereRaw('LOWER(email) = ?', [$email])->exists()) {
            throw ValidationException::withMessages([
                'usuario.email' => ['Ya existe una cuenta con este correo.'],
            ]);
        }

        if ($identificacion !== '' && Persona::query()->where('identificacion', $identificacion)->exists()) {
            throw ValidationException::withMessages([
                'usuario.persona.identificacion' => ['Ya existe una cuenta con esta identificación.'],
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function assertPublicFlags(array $data): void
    {
        if (! $this->publicForm->allows('enabled')) {
            throw ValidationException::withMessages([
                'formulario' => ['Actualmente esta función está desactivada.'],
            ]);
        }

        if (! empty($data['solicitud_asociacion']) && ! $this->publicForm->allows('allow_request_asociacion')) {
            throw ValidationException::withMessages([
                'asociacion_id' => ['No está permitido solicitar una asociación nueva.'],
            ]);
        }
        if (! empty($data['solicitud_distrito']) && ! $this->publicForm->allows('allow_request_distrito')) {
            throw ValidationException::withMessages([
                'distrito_id' => ['No está permitido solicitar un distrito nuevo.'],
            ]);
        }
        if (! empty($data['solicitud_iglesia']) && ! $this->publicForm->allows('allow_request_iglesia')) {
            throw ValidationException::withMessages([
                'iglesia_id' => ['No está permitido solicitar una iglesia nueva.'],
            ]);
        }
        if (empty($data['club_id']) && ! $this->publicForm->allows('allow_request_club')) {
            throw ValidationException::withMessages([
                'club_id' => ['Debes seleccionar un club existente.'],
            ]);
        }
    }

    private function existingApprovedClub(int $clubId, int $iglesiaId): Club
    {
        $club = Club::query()
            ->where('id', $clubId)
            ->where('is_active', true)
            ->whereHas('organizacion', function ($query) use ($iglesiaId) {
                $query->where('organizacion_padre_id', $iglesiaId)
                    ->where('estado_aprobacion', Organizacion::APROBACION_APROBADA);
            })
            ->first();

        if (! $club) {
            throw ValidationException::withMessages([
                'club_id' => ['El club seleccionado no es válido para esa iglesia.'],
            ]);
        }

        return $club;
    }

    private function assertCargoLibre(Club $club, string $cargo): void
    {
        $ocupados = collect($this->clubService->boardAssignments($club))
            ->pluck('ministry')
            ->all();

        if (! in_array($cargo, $ocupados, true)) {
            return;
        }

        $labels = [
            Club::BOARD_DIRECTOR => 'director',
            Club::BOARD_SUBDIRECTOR => 'subdirector',
            Club::BOARD_SECRETARIA => 'secretario',
            Club::BOARD_TESORERO => 'tesorero',
        ];

        throw ValidationException::withMessages([
            'usuario.cargo' => ['Este club ya tiene un '.$labels[$cargo].' asignado.'],
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function resolveIglesiaId(array $data): int
    {
        if (! empty($data['iglesia_id'])) {
            $iglesia = Organizacion::query()
                ->where('id', (int) $data['iglesia_id'])
                ->where('tipo_organizacion_id', Organizacion::TIPO_IGLESIA)
                ->where('estado', true)
                ->where('estado_aprobacion', Organizacion::APROBACION_APROBADA)
                ->first();
            if (! $iglesia) {
                throw ValidationException::withMessages([
                    'iglesia_id' => ['La iglesia seleccionada no es válida.'],
                ]);
            }

            return (int) $iglesia->id;
        }

        $asociacionId = $this->resolveAsociacionId($data);
        $distritoId = $this->resolveDistritoId($data, $asociacionId);

        $solicitud = $data['solicitud_iglesia'] ?? null;
        if (! is_array($solicitud) || trim((string) ($solicitud['nombre'] ?? '')) === '') {
            throw ValidationException::withMessages([
                'iglesia_id' => ['Selecciona una iglesia o solicita su creación.'],
            ]);
        }

        $iglesia = $this->organizacionService->create([
            'tipo_organizacion_id' => Organizacion::TIPO_IGLESIA,
            'organizacion_padre_id' => $distritoId,
            'nombre' => trim((string) $solicitud['nombre']),
            'direccion' => trim((string) ($solicitud['direccion'] ?? '')),
            'departamento_id' => $solicitud['departamento_id'] ?? null,
            'ciudad_id' => $solicitud['ciudad_id'] ?? null,
            'telefono' => $solicitud['telefono'] ?? null,
            'correo' => $solicitud['correo'] ?? null,
            'estado' => true,
            'estado_aprobacion' => Organizacion::APROBACION_PENDIENTE,
        ]);

        return (int) $iglesia->id;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function resolveAsociacionId(array $data): int
    {
        if (! empty($data['asociacion_id'])) {
            $asociacion = Organizacion::query()
                ->where('id', (int) $data['asociacion_id'])
                ->where('tipo_organizacion_id', Organizacion::TIPO_ASOCIACION)
                ->where('estado', true)
                ->where('estado_aprobacion', Organizacion::APROBACION_APROBADA)
                ->first();
            if (! $asociacion) {
                throw ValidationException::withMessages([
                    'asociacion_id' => ['La asociación seleccionada no es válida.'],
                ]);
            }

            return (int) $asociacion->id;
        }

        $solicitud = $data['solicitud_asociacion'] ?? null;
        if (! is_array($solicitud) || trim((string) ($solicitud['nombre'] ?? '')) === '') {
            throw ValidationException::withMessages([
                'asociacion_id' => ['Selecciona una asociación o solicita su creación.'],
            ]);
        }

        $unionId = isset($solicitud['union_id']) ? (int) $solicitud['union_id'] : $this->defaultUnionId();
        $asociacion = $this->organizacionService->create([
            'tipo_organizacion_id' => Organizacion::TIPO_ASOCIACION,
            'organizacion_padre_id' => $unionId,
            'nombre' => trim((string) $solicitud['nombre']),
            'departamento_ids' => $solicitud['departamento_ids'] ?? [],
            'estado' => true,
            'estado_aprobacion' => Organizacion::APROBACION_PENDIENTE,
        ]);

        return (int) $asociacion->id;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function resolveDistritoId(array $data, int $asociacionId): int
    {
        if (! empty($data['distrito_id'])) {
            $distrito = Organizacion::query()
                ->where('id', (int) $data['distrito_id'])
                ->where('tipo_organizacion_id', Organizacion::TIPO_DISTRITO)
                ->where('organizacion_padre_id', $asociacionId)
                ->where('estado', true)
                ->where('estado_aprobacion', Organizacion::APROBACION_APROBADA)
                ->first();
            if (! $distrito) {
                throw ValidationException::withMessages([
                    'distrito_id' => ['El distrito seleccionado no pertenece a la asociación indicada.'],
                ]);
            }

            return (int) $distrito->id;
        }

        $solicitud = $data['solicitud_distrito'] ?? null;
        if (! is_array($solicitud) || trim((string) ($solicitud['nombre'] ?? '')) === '') {
            throw ValidationException::withMessages([
                'distrito_id' => ['Selecciona un distrito o solicita su creación.'],
            ]);
        }

        $distrito = $this->organizacionService->create([
            'tipo_organizacion_id' => Organizacion::TIPO_DISTRITO,
            'organizacion_padre_id' => $asociacionId,
            'nombre' => trim((string) $solicitud['nombre']),
            'departamento_ids' => $solicitud['departamento_ids'] ?? [],
            'ciudad_ids' => $solicitud['ciudad_ids'] ?? [],
            'estado' => true,
            'estado_aprobacion' => Organizacion::APROBACION_PENDIENTE,
        ]);

        return (int) $distrito->id;
    }

    private function defaultUnionId(): int
    {
        $unionId = Organizacion::query()
            ->where('tipo_organizacion_id', Organizacion::TIPO_UNION)
            ->where('estado', true)
            ->orderBy('id')
            ->value('id');

        if (! $unionId) {
            throw ValidationException::withMessages([
                'solicitud_asociacion.union_id' => ['No hay una Unión disponible para crear la asociación.'],
            ]);
        }

        return (int) $unionId;
    }
}
