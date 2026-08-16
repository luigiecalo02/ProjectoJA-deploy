<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Clubs\Models\Club;
use App\Modules\Clubs\Models\Persona;
use App\Modules\Organizations\Models\Organizacion;
use App\Modules\Organizations\Models\PersonaOrganizacion;
use App\Modules\Organizations\Models\PersonaOrganizacionRol;
use App\Modules\Users\Models\Role;
use Database\Seeders\OrganizacionCatalogSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PersonasApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(OrganizacionCatalogSeeder::class);
    }

    private function admin(): User
    {
        $user = User::factory()->create(['email' => 'personas-admin@test.local']);
        $user->forceFill(['is_admin' => true])->save();
        $user->clearPermissionCache();

        return $user;
    }

    private function createClubOrg(string $nombre): Organizacion
    {
        return Organizacion::query()->create([
            'tipo_organizacion_id' => Organizacion::TIPO_CLUB,
            'nombre' => $nombre,
            'codigo' => 'CLB-'.uniqid(),
            'estado' => true,
        ]);
    }

    public function test_identificacion_must_be_globally_unique(): void
    {
        Sanctum::actingAs($this->admin());
        $org = $this->createClubOrg('Club Unique');

        $this->postJson('/api/v1/personas', [
            'tipo_identificacion' => 'CC',
            'identificacion' => '999888777',
            'nombre1' => 'Ana',
            'apellido1' => 'Uno',
            'organizacion_ids' => [$org->id],
        ])->assertCreated();

        $this->postJson('/api/v1/personas', [
            'tipo_identificacion' => 'TI',
            'identificacion' => '999888777',
            'nombre1' => 'Luis',
            'apellido1' => 'Dos',
            'organizacion_ids' => [$org->id],
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['identificacion']);
    }

    public function test_pastor_only_lists_personas_from_owned_clubs(): void
    {
        $orgMine = $this->createClubOrg('Mi Club Org');
        $orgOther = $this->createClubOrg('Otro Club Org');

        $mine = Club::query()->create([
            'organizacion_id' => $orgMine->id,
            'nombre' => 'Mi Club',
            'is_active' => true,
            'tipos' => ['conquistadores'],
        ]);
        $other = Club::query()->create([
            'organizacion_id' => $orgOther->id,
            'nombre' => 'Otro Club',
            'is_active' => true,
            'tipos' => ['aventureros'],
        ]);

        $personaMine = Persona::query()->create([
            'tipo_identificacion' => 'CC',
            'identificacion' => '111',
            'nombre1' => 'Mia',
            'apellido1' => 'Persona',
        ]);
        $personaOther = Persona::query()->create([
            'tipo_identificacion' => 'CC',
            'identificacion' => '222',
            'nombre1' => 'Otra',
            'apellido1' => 'Persona',
        ]);

        PersonaOrganizacion::query()->create([
            'persona_id' => $personaMine->id,
            'organizacion_id' => $orgMine->id,
            'fecha_inicio' => now()->toDateString(),
            'estado' => true,
        ]);
        PersonaOrganizacion::query()->create([
            'persona_id' => $personaOther->id,
            'organizacion_id' => $orgOther->id,
            'fecha_inicio' => now()->toDateString(),
            'estado' => true,
        ]);

        $user = User::factory()->create([
            'email' => 'pastor@test.local',
            'persona_id' => $personaMine->id,
        ]);
        $pastorRoleId = Role::query()->where('name', 'pastor')->value('id');
        $po = PersonaOrganizacion::query()
            ->where('persona_id', $personaMine->id)
            ->where('organizacion_id', $orgMine->id)
            ->first();
        PersonaOrganizacionRol::query()->create([
            'persona_organizacion_id' => $po->id,
            'rol_id' => $pastorRoleId,
        ]);
        $user->forceFill([
            'active_organizacion_id' => $orgMine->id,
            'active_rol_id' => $pastorRoleId,
        ])->save();
        $user->clearPermissionCache();

        Sanctum::actingAs($user);

        $ids = collect($this->getJson('/api/v1/personas')->assertOk()->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($personaMine->id));
        $this->assertFalse($ids->contains($personaOther->id));
    }

    public function test_persona_payload_includes_clubs(): void
    {
        Sanctum::actingAs($this->admin());

        $org = $this->createClubOrg('Club Norte Org');
        $club = Club::query()->create([
            'organizacion_id' => $org->id,
            'nombre' => 'Club Norte',
            'is_active' => true,
            'tipos' => ['conquistadores'],
        ]);

        $persona = $this->postJson('/api/v1/personas', [
            'tipo_identificacion' => 'CC',
            'identificacion' => '555444333',
            'nombre1' => 'Carla',
            'apellido1' => 'Rios',
            'organizacion_ids' => [$org->id],
            'club_ids' => [$club->id],
        ])->assertCreated()
            ->assertJsonPath('data.clubs.0.nombre', 'Club Norte')
            ->json('data');

        $this->assertContains($club->id, $persona['club_ids']);
    }

    public function test_persona_can_only_link_one_user(): void
    {
        $first = Persona::query()->create([
            'tipo_identificacion' => 'CC',
            'identificacion' => 'AAA111',
            'nombre1' => 'Primera',
            'apellido1' => 'Persona',
        ]);

        User::factory()->create([
            'email' => 'one-persona@test.local',
            'persona_id' => $first->id,
        ]);

        $this->expectException(QueryException::class);

        User::factory()->create([
            'email' => 'dup-persona@test.local',
            'persona_id' => $first->id,
        ]);
    }
}
