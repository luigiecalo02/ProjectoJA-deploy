<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Clubs\Models\Persona;
use App\Modules\Events\Models\EventoAsistencia;
use App\Modules\Organizations\Models\Organizacion;
use App\Modules\Organizations\Models\PersonaOrganizacion;
use App\Modules\Organizations\Models\PersonaOrganizacionRol;
use App\Modules\Users\Models\Role;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ClubesAttendanceApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->ensureClubTipo();
    }

    public function test_director_can_mark_member_attendance(): void
    {
        $org = $this->createClubOrg('Club Asistencia');
        $director = $this->boardUser('director', $org, 'dir-asis@test.local');
        $member = $this->member($org, 'int-asis@test.local');

        Sanctum::actingAs($director);
        $eventId = $this->postJson('/api/v1/settings/clubes/events', $this->eventPayload('Reunión'), [
            'X-Clubes-Client' => 'clubes',
        ])->assertCreated()->json('data.id');

        $this->getJson("/api/v1/settings/clubes/asistencia/{$eventId}", [
            'X-Clubes-Client' => 'clubes',
        ])->assertOk()
            ->assertJsonPath('data.resumen.total', 2);

        $this->putJson("/api/v1/settings/clubes/asistencia/{$eventId}", [
            'persona_ids' => [$member->id],
            'justificados' => [$director->persona_id],
        ], [
            'X-Clubes-Client' => 'clubes',
        ])->assertOk()
            ->assertJsonPath('data.resumen.presentes', 1)
            ->assertJsonPath('data.resumen.justificados', 1);

        $this->assertDatabaseHas('evento_asistencias', [
            'evento_id' => $eventId,
            'organizacion_id' => $org->id,
            'persona_id' => $member->id,
            'estado' => EventoAsistencia::ESTADO_PRESENTE,
        ]);
    }

    public function test_tesorero_cannot_view_attendance(): void
    {
        $org = $this->createClubOrg('Club Tesorería');
        $director = $this->boardUser('director', $org, 'dir-tes-asis@test.local');
        $tesorero = $this->boardUser('tesorero', $org, 'tes-asis@test.local');

        Sanctum::actingAs($director);
        $eventId = $this->postJson('/api/v1/settings/clubes/events', $this->eventPayload('Caja'), [
            'X-Clubes-Client' => 'clubes',
        ])->assertCreated()->json('data.id');

        Sanctum::actingAs($tesorero);
        $this->getJson("/api/v1/settings/clubes/asistencia/{$eventId}", [
            'X-Clubes-Client' => 'clubes',
        ])->assertForbidden();
    }

    public function test_other_club_cannot_mark_attendance(): void
    {
        $mine = $this->createClubOrg('Club Propio');
        $other = $this->createClubOrg('Club Ajeno');
        $director = $this->boardUser('director', $mine, 'dir-propio-asis@test.local');
        $otherDirector = $this->boardUser('director', $other, 'dir-ajeno-asis@test.local');
        $member = $this->member($mine, 'int-propio@test.local');

        Sanctum::actingAs($director);
        $eventId = $this->postJson('/api/v1/settings/clubes/events', $this->eventPayload('Interno'), [
            'X-Clubes-Client' => 'clubes',
        ])->assertCreated()->json('data.id');

        Sanctum::actingAs($otherDirector);
        $this->putJson("/api/v1/settings/clubes/asistencia/{$eventId}", [
            'persona_ids' => [$member->id],
        ], [
            'X-Clubes-Client' => 'clubes',
        ])->assertForbidden();
    }

    /**
     * @return array<string, string>
     */
    private function eventPayload(string $name): array
    {
        return [
            'name' => $name,
            'starts_at' => now()->addDay()->toDateTimeString(),
            'ends_at' => now()->addDays(2)->toDateTimeString(),
        ];
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
        return Organizacion::query()->create([
            'tipo_organizacion_id' => Organizacion::TIPO_CLUB,
            'nombre' => $nombre,
            'codigo' => strtoupper(substr(md5($nombre.microtime()), 0, 8)),
            'estado' => true,
        ]);
    }

    private function boardUser(string $roleName, Organizacion $org, string $email): User
    {
        $persona = $this->createPersona($email, ucfirst($roleName));
        $this->attachMember($persona, $org, $roleName);

        $roleId = (int) Role::query()->where('name', $roleName)->value('id');
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

    private function member(Organizacion $org, string $email): Persona
    {
        $persona = $this->createPersona($email, 'Integrante');
        $this->attachMember($persona, $org);

        return $persona;
    }

    private function createPersona(string $email, string $nombre): Persona
    {
        return Persona::query()->create([
            'tipo_identificacion' => 'CC',
            'identificacion' => 'ID'.random_int(100000, 999999).uniqid(),
            'nombre1' => $nombre,
            'apellido1' => 'Club',
            'correo' => $email,
        ]);
    }

    private function attachMember(Persona $persona, Organizacion $org, ?string $roleName = null): void
    {
        $membership = PersonaOrganizacion::query()->create([
            'persona_id' => $persona->id,
            'organizacion_id' => $org->id,
            'fecha_inicio' => now()->toDateString(),
            'estado' => true,
        ]);

        if (! $roleName) {
            return;
        }

        PersonaOrganizacionRol::query()->create([
            'persona_organizacion_id' => $membership->id,
            'rol_id' => (int) Role::query()->where('name', $roleName)->value('id'),
        ]);
    }
}
