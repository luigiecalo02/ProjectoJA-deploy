<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Clubs\Models\Persona;
use App\Modules\Events\Models\Event;
use App\Modules\Events\Models\EventoInscripcionPersona;
use App\Modules\Events\Models\TipoEvento;
use App\Modules\Organizations\Models\Organizacion;
use App\Modules\Organizations\Models\PersonaOrganizacion;
use App\Modules\Organizations\Models\PersonaOrganizacionRol;
use App\Modules\Users\Models\Role;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ClubesInscripcionesApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->ensureClubTipo();
        $this->ensureTipo(TipoEvento::SLUG_CAMPAMENTO, 'Campamento');
        $this->ensureTipo(TipoEvento::SLUG_ESPECIALIDAD_LEGACY, 'Especialidad');
        $this->ensureTipo(TipoEvento::SLUG_INVESTIDURA, 'Investidura');
        $this->ensureTipo(TipoEvento::SLUG_ACTIVIDAD_ECONOMICA, 'Actividad Económica');
    }

    public function test_member_can_join_published_campamento_and_board_can_sync(): void
    {
        $org = $this->createClubOrg('Club Inscripciones');
        $director = $this->boardUser('director', $org, 'dir-insc@test.local');
        $member = $this->boardUser('miembro', $org, 'int-insc@test.local');

        Sanctum::actingAs($director);
        $eventId = $this->createEvent($director, 'Campamento publicado', TipoEvento::SLUG_CAMPAMENTO);

        Sanctum::actingAs($member);
        $this->putJson("/api/v1/settings/clubes/inscripciones/{$eventId}/yo", [
            'inscrito' => true,
        ], [
            'X-Clubes-Client' => 'clubes',
        ])->assertOk()
            ->assertJsonPath('data.inscrito', true)
            ->assertJsonPath('data.evento.inscritos_count', 1);

        $this->assertDatabaseHas('evento_inscripcion_persona', [
            'persona_id' => $member->persona_id,
            'estado' => EventoInscripcionPersona::ESTADO_CONFIRMADA,
        ]);

        Sanctum::actingAs($director);
        $this->putJson("/api/v1/settings/clubes/inscripciones/{$eventId}", [
            'persona_ids' => [$director->persona_id, $member->persona_id],
        ], [
            'X-Clubes-Client' => 'clubes',
        ])->assertOk()
            ->assertJsonPath('data.resumen.inscritos', 2);

        $this->getJson("/api/v1/settings/clubes/inscripciones/{$eventId}/yo", [
            'X-Clubes-Client' => 'clubes',
        ])->assertOk()->assertJsonPath('data.inscrito', true);
    }

    public function test_rejects_unpublished_economic_and_member_cannot_sync(): void
    {
        $org = $this->createClubOrg('Club Inscripciones Cerrado');
        $director = $this->boardUser('director', $org, 'dir-insc-cerr@test.local');
        $member = $this->boardUser('miembro', $org, 'int-insc-cerr@test.local');

        Sanctum::actingAs($director);
        $draftId = $this->createEvent($director, 'Campamento borrador', TipoEvento::SLUG_CAMPAMENTO, Event::ESTADO_BORRADOR);
        $economicId = $this->createEvent($director, 'Venta', TipoEvento::SLUG_ACTIVIDAD_ECONOMICA);

        Sanctum::actingAs($member);
        $this->putJson("/api/v1/settings/clubes/inscripciones/{$draftId}/yo", [
            'inscrito' => true,
        ], [
            'X-Clubes-Client' => 'clubes',
        ])->assertStatus(422);

        $this->putJson("/api/v1/settings/clubes/inscripciones/{$economicId}/yo", [
            'inscrito' => true,
        ], [
            'X-Clubes-Client' => 'clubes',
        ])->assertStatus(422);

        $this->putJson("/api/v1/settings/clubes/inscripciones/{$draftId}", [
            'persona_ids' => [$member->persona_id],
        ], [
            'X-Clubes-Client' => 'clubes',
        ])->assertForbidden();
    }

    public function test_especialidad_and_investidura_accept_self_join(): void
    {
        $org = $this->createClubOrg('Club Especialidades');
        $director = $this->boardUser('director', $org, 'dir-esp@test.local');
        $member = $this->boardUser('miembro', $org, 'int-esp@test.local');

        Sanctum::actingAs($director);
        $especialidadId = $this->createEvent($director, 'Nudos', TipoEvento::SLUG_ESPECIALIDAD_LEGACY);
        $investiduraId = $this->createEvent($director, 'Ceremonial', TipoEvento::SLUG_INVESTIDURA);

        Sanctum::actingAs($member);
        $this->putJson("/api/v1/settings/clubes/inscripciones/{$especialidadId}/yo", [
            'inscrito' => true,
        ], [
            'X-Clubes-Client' => 'clubes',
        ])->assertOk()->assertJsonPath('data.inscrito', true);

        $this->putJson("/api/v1/settings/clubes/inscripciones/{$investiduraId}/yo", [
            'inscrito' => true,
        ], [
            'X-Clubes-Client' => 'clubes',
        ])->assertOk()->assertJsonPath('data.inscrito', true);
    }

    public function test_board_can_inscribe_people_from_parent_or_sibling_org(): void
    {
        $this->ensureTipoOrg(Organizacion::TIPO_IGLESIA, 'Iglesia');
        $iglesia = $this->createOrg(Organizacion::TIPO_IGLESIA, 'Iglesia Central');
        $club = $this->createOrg(Organizacion::TIPO_CLUB, 'Club Avion', $iglesia->id);
        $hermano = $this->createOrg(Organizacion::TIPO_CLUB, 'Club Aventureros', $iglesia->id);
        $ajeno = $this->createOrg(Organizacion::TIPO_CLUB, 'Club Otro Distrito');

        $director = $this->boardUser('director', $club, 'dir-ext@test.local');
        $iglesiaPersona = $this->orgPersona($iglesia, 'iglesia-ext@test.local', 'Ana', 'Iglesia');
        $hermanoPersona = $this->orgPersona($hermano, 'hermano-ext@test.local', 'Luis', 'Hermano');
        $ajenoPersona = $this->orgPersona($ajeno, 'ajeno-ext@test.local', 'Pedro', 'Ajeno');

        Sanctum::actingAs($director);
        $eventId = $this->createEvent($director, 'Campamento con externos', TipoEvento::SLUG_CAMPAMENTO);

        $this->getJson("/api/v1/settings/clubes/inscripciones/{$eventId}", [
            'X-Clubes-Client' => 'clubes',
        ])->assertOk()
            ->assertJsonPath('data.resumen.externos', 0)
            ->assertJsonFragment(['persona_id' => $iglesiaPersona->id])
            ->assertJsonFragment(['persona_id' => $hermanoPersona->id])
            ->assertJsonMissing(['persona_id' => $ajenoPersona->id]);

        $this->putJson("/api/v1/settings/clubes/inscripciones/{$eventId}", [
            'persona_ids' => [$iglesiaPersona->id, $hermanoPersona->id, $ajenoPersona->id],
        ], [
            'X-Clubes-Client' => 'clubes',
        ])->assertStatus(422);

        $this->putJson("/api/v1/settings/clubes/inscripciones/{$eventId}", [
            'persona_ids' => [$iglesiaPersona->id, $hermanoPersona->id],
        ], [
            'X-Clubes-Client' => 'clubes',
        ])->assertOk()
            ->assertJsonPath('data.resumen.inscritos', 2)
            ->assertJsonPath('data.resumen.externos', 2);

        $this->assertDatabaseHas('evento_inscripcion_persona', [
            'persona_id' => $iglesiaPersona->id,
            'tipo' => EventoInscripcionPersona::TIPO_ACOMPANANTE,
            'estado' => EventoInscripcionPersona::ESTADO_CONFIRMADA,
        ]);
    }

    private function createEvent(User $actor, string $name, string $slug, string $estado = Event::ESTADO_PUBLICADO): int
    {
        Sanctum::actingAs($actor);

        return (int) $this->postJson('/api/v1/settings/clubes/events', [
            'name' => $name,
            'starts_at' => now()->addDay()->toDateTimeString(),
            'ends_at' => now()->addDays(2)->toDateTimeString(),
            'tipo_evento_id' => $this->tipoId($slug),
            'estado' => $estado,
        ], [
            'X-Clubes-Client' => 'clubes',
        ])->assertCreated()->json('data.id');
    }

    private function tipoId(string $slug): int
    {
        return (int) DB::table('tipo_evento')->where('slug', $slug)->value('id');
    }

    private function ensureTipo(string $slug, string $nombre): void
    {
        if (DB::table('tipo_evento')->where('slug', $slug)->exists()) {
            return;
        }

        DB::table('tipo_evento')->insert([
            'nombre' => $nombre,
            'slug' => $slug,
            'descripcion' => $nombre,
            'color' => '#15803d',
            'icono' => 'pi pi-flag',
            'orden' => 10,
            'estado' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function ensureClubTipo(): void
    {
        if (DB::table('tipo_organizacion')->where('id', Organizacion::TIPO_CLUB)->exists()) {
            return;
        }

        DB::table('tipo_organizacion')->insert([
            'id' => Organizacion::TIPO_CLUB,
            'tipo_organizacion_padre_id' => null,
            'nombre' => 'Club',
            'descripcion' => 'Club',
            'estado' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createClubOrg(string $nombre): Organizacion
    {
        return $this->createOrg(Organizacion::TIPO_CLUB, $nombre);
    }

    private function createOrg(int $tipoId, string $nombre, ?int $padreId = null): Organizacion
    {
        return Organizacion::query()->create([
            'tipo_organizacion_id' => $tipoId,
            'organizacion_padre_id' => $padreId,
            'nombre' => $nombre,
            'codigo' => strtoupper(substr(md5($nombre.microtime()), 0, 8)),
            'estado' => true,
        ]);
    }

    private function ensureTipoOrg(int $id, string $nombre): void
    {
        if (DB::table('tipo_organizacion')->where('id', $id)->exists()) {
            return;
        }

        DB::table('tipo_organizacion')->insert([
            'id' => $id,
            'tipo_organizacion_padre_id' => null,
            'nombre' => $nombre,
            'descripcion' => $nombre,
            'estado' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function orgPersona(Organizacion $org, string $email, string $nombre, string $apellido): Persona
    {
        $persona = Persona::query()->create([
            'tipo_identificacion' => 'CC',
            'identificacion' => 'ID'.random_int(100000, 999999).uniqid(),
            'nombre1' => $nombre,
            'apellido1' => $apellido,
            'correo' => $email,
        ]);
        PersonaOrganizacion::query()->create([
            'persona_id' => $persona->id,
            'organizacion_id' => $org->id,
            'fecha_inicio' => now()->toDateString(),
            'estado' => true,
        ]);

        return $persona;
    }

    private function boardUser(string $roleName, Organizacion $org, string $email): User
    {
        $persona = Persona::query()->create([
            'tipo_identificacion' => 'CC',
            'identificacion' => 'ID'.random_int(100000, 999999).uniqid(),
            'nombre1' => ucfirst($roleName),
            'apellido1' => 'Club',
            'correo' => $email,
        ]);
        $membership = PersonaOrganizacion::query()->create([
            'persona_id' => $persona->id,
            'organizacion_id' => $org->id,
            'fecha_inicio' => now()->toDateString(),
            'estado' => true,
        ]);
        $roleId = (int) Role::query()->where('name', $roleName)->value('id');
        PersonaOrganizacionRol::query()->create([
            'persona_organizacion_id' => $membership->id,
            'rol_id' => $roleId,
        ]);

        $user = User::factory()->create([
            'email' => $email,
            'persona_id' => $persona->id,
        ]);
        $user->forceFill([
            'active_organizacion_id' => $org->id,
            'active_rol_id' => $roleId,
        ])->save();
        $user->clearPermissionCache();

        return $user->fresh();
    }
}
