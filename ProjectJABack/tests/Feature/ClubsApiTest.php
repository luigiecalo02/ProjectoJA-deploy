<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Clubs\Models\Club;
use App\Modules\Organizations\Models\Ciudad;
use App\Modules\Organizations\Models\Departamento;
use App\Modules\Organizations\Models\Organizacion;
use App\Modules\Organizations\Models\Pais;
use App\Modules\Organizations\Models\PersonaOrganizacion;
use App\Modules\Users\Models\Role;
use Database\Seeders\OrganizacionCatalogSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\UbicacionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ClubsApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(OrganizacionCatalogSeeder::class);
        $this->seed(UbicacionSeeder::class);
    }

    private function admin(): User
    {
        $user = User::factory()->create(['email' => 'clubs-admin@test.local']);
        $user->forceFill(['is_admin' => true])->save();
        $user->clearPermissionCache();

        return $user;
    }

    private function createIglesia(): Organizacion
    {
        $paisId = Pais::query()->value('id');
        $departamentoId = Departamento::query()
            ->where('pais_id', $paisId)
            ->value('id');
        $ciudadId = Ciudad::query()
            ->where('departamento_id', $departamentoId)
            ->value('id');

        $union = Organizacion::query()->create([
            'tipo_organizacion_id' => Organizacion::TIPO_UNION,
            'nombre' => 'Unión Test',
            'codigo' => 'UNI-T-'.uniqid(),
            'pais_id' => $paisId,
            'estado' => true,
        ]);
        $aso = Organizacion::query()->create([
            'organizacion_padre_id' => $union->id,
            'tipo_organizacion_id' => Organizacion::TIPO_ASOCIACION,
            'nombre' => 'Asociación Test',
            'codigo' => 'ASO-T-'.uniqid(),
            'pais_id' => $paisId,
            'estado' => true,
        ]);
        $dis = Organizacion::query()->create([
            'organizacion_padre_id' => $aso->id,
            'tipo_organizacion_id' => Organizacion::TIPO_DISTRITO,
            'nombre' => 'Distrito Test',
            'codigo' => 'DIS-T-'.uniqid(),
            'pais_id' => $paisId,
            'departamento_id' => $departamentoId,
            'ciudad_id' => $ciudadId,
            'estado' => true,
        ]);

        return Organizacion::query()->create([
            'organizacion_padre_id' => $dis->id,
            'tipo_organizacion_id' => Organizacion::TIPO_IGLESIA,
            'nombre' => 'Iglesia Test',
            'codigo' => 'IGL-T-'.uniqid(),
            'pais_id' => $paisId,
            'departamento_id' => $departamentoId,
            'ciudad_id' => $ciudadId,
            'direccion' => 'Calle 1',
            'estado' => true,
        ]);
    }

    public function test_admin_can_create_club_with_persona(): void
    {
        Sanctum::actingAs($this->admin());
        $iglesia = $this->createIglesia();

        $response = $this->postJson('/api/v1/clubs', [
            'organizacion_id' => $iglesia->id,
            'nombre' => 'Club Esperanza',
            'distrito' => 'Centro',
            'ciudad' => 'Bogotá',
            'tipos' => ['conquistadores'],
        ])->assertCreated()
            ->assertJsonPath('data.nombre', 'Club Esperanza')
            ->assertJsonPath('data.tipos.0', 'conquistadores');

        $clubId = $response->json('data.id');
        $clubOrgId = $response->json('data.organizacion_id');
        $this->assertNotNull($clubOrgId);
        $this->assertDatabaseHas('organizacion', [
            'id' => $clubOrgId,
            'tipo_organizacion_id' => Organizacion::TIPO_CLUB,
            'organizacion_padre_id' => $iglesia->id,
        ]);

        $persona = $this->postJson('/api/v1/personas', [
            'tipo_identificacion' => 'CC',
            'identificacion' => '100200300',
            'nombre1' => 'Ana',
            'apellido1' => 'López',
            'correo' => 'ana@example.com',
            'sexo' => 'F',
            'organizacion_ids' => [$clubOrgId],
            'club_ids' => [$clubId],
        ])->assertCreated()->json('data');

        $this->getJson("/api/v1/clubs/{$clubId}")
            ->assertOk()
            ->assertJsonPath('data.personas.0.id', $persona['id']);
    }

    public function test_can_create_director_and_assign_role(): void
    {
        Sanctum::actingAs($this->admin());
        $iglesia = $this->createIglesia();

        $clubOrg = Organizacion::query()->create([
            'organizacion_padre_id' => $iglesia->id,
            'tipo_organizacion_id' => Organizacion::TIPO_CLUB,
            'nombre' => 'Club Norte Org',
            'codigo' => 'CLB-N',
            'estado' => true,
        ]);

        $club = Club::query()->create([
            'organizacion_id' => $clubOrg->id,
            'nombre' => 'Club Norte',
            'distrito' => 'Norte',
            'ciudad' => 'Medellín',
            'is_active' => true,
            'tipos' => ['conquistadores'],
        ]);

        $this->putJson("/api/v1/clubs/{$club->id}/directors", [
            'directors' => [
                'director' => [
                    'mode' => 'create',
                    'user' => [
                        'name' => 'Dir Conquistadores',
                        'email' => 'dir.conq@test.local',
                        'password' => 'Password1!',
                    ],
                    'persona' => [
                        'tipo_identificacion' => 'CC',
                        'identificacion' => '900100200',
                        'nombre1' => 'Dir',
                        'apellido1' => 'Conquistadores',
                    ],
                ],
            ],
        ])->assertOk();

        $director = User::query()->where('email', 'dir.conq@test.local')->first();
        $this->assertNotNull($director);
        $this->assertNotNull($director->persona_id);
        $this->assertTrue($director->hasRole('director'));
        $this->assertDatabaseHas('persona_organizacion', [
            'persona_id' => $director->persona_id,
            'organizacion_id' => $clubOrg->id,
            'estado' => true,
        ]);
        $directorRoleId = Role::query()->where('name', 'director')->value('id');
        $poId = PersonaOrganizacion::query()
            ->where('persona_id', $director->persona_id)
            ->where('organizacion_id', $clubOrg->id)
            ->value('id');
        $this->assertDatabaseHas('persona_organizacion_rol', [
            'persona_organizacion_id' => $poId,
            'rol_id' => $directorRoleId,
        ]);
    }

    public function test_select_director_assigns_role(): void
    {
        Sanctum::actingAs($this->admin());
        $iglesia = $this->createIglesia();
        $clubOrg = Organizacion::query()->create([
            'organizacion_padre_id' => $iglesia->id,
            'tipo_organizacion_id' => Organizacion::TIPO_CLUB,
            'nombre' => 'Club Sur Org',
            'codigo' => 'CLB-S',
            'estado' => true,
        ]);
        $club = Club::query()->create([
            'organizacion_id' => $clubOrg->id,
            'nombre' => 'Club Sur',
            'is_active' => true,
            'tipos' => ['aventureros'],
        ]);

        $plain = User::factory()->create(['email' => 'plain@test.local']);

        $this->putJson("/api/v1/clubs/{$club->id}/directors", [
            'directors' => [
                'director' => [
                    'mode' => 'select',
                    'user_id' => $plain->id,
                ],
            ],
        ])->assertOk();

        $plain->refresh();
        $this->assertNotNull($plain->persona_id);
        $this->assertTrue($plain->hasRole('director'));
        $this->assertDatabaseHas('persona_organizacion', [
            'persona_id' => $plain->persona_id,
            'organizacion_id' => $clubOrg->id,
            'estado' => true,
        ]);
    }

    public function test_create_director_rejects_existing_email(): void
    {
        Sanctum::actingAs($this->admin());
        $iglesia = $this->createIglesia();
        $clubOrg = Organizacion::query()->create([
            'organizacion_padre_id' => $iglesia->id,
            'tipo_organizacion_id' => Organizacion::TIPO_CLUB,
            'nombre' => 'Club Este Org',
            'codigo' => 'CLB-E',
            'estado' => true,
        ]);
        $club = Club::query()->create([
            'organizacion_id' => $clubOrg->id,
            'nombre' => 'Club Este',
            'is_active' => true,
            'tipos' => ['conquistadores'],
        ]);

        $existing = User::factory()->create([
            'name' => 'Usuario Original',
            'email' => 'ya.existe@test.local',
        ]);

        $this->putJson("/api/v1/clubs/{$club->id}/directors", [
            'directors' => [
                'director' => [
                    'mode' => 'create',
                    'user' => [
                        'name' => 'Otro Nombre',
                        'email' => 'ya.existe@test.local',
                        'password' => 'Password1!',
                    ],
                    'persona' => [
                        'tipo_identificacion' => 'CC',
                        'identificacion' => '800700600',
                        'nombre1' => 'Otro',
                        'apellido1' => 'Nombre',
                    ],
                ],
            ],
        ])->assertStatus(422);

        $existing->refresh();
        $this->assertSame('Usuario Original', $existing->name);
        $this->assertFalse($existing->hasRole('director'));
    }

    public function test_club_can_belong_to_only_one_account(): void
    {
        Sanctum::actingAs($this->admin());
        $clubRoleId = Role::query()->where('name', 'pastor')->value('id');

        $club = Club::query()->create([
            'nombre' => 'Club Único',
            'is_active' => true,
            'tipos' => ['conquistadores'],
        ]);

        $owner = User::factory()->create(['email' => 'owner-club@test.local']);
        $owner->clubs()->sync([$club->id]);

        $other = User::factory()->create(['email' => 'other-club@test.local']);

        $this->patchJson("/api/v1/users/{$other->id}", [
            'name' => $other->name,
            'email' => $other->email,
            'role_ids' => [$clubRoleId],
            'club_ids' => [$club->id],
            'persona' => [
                'tipo_identificacion' => 'CC',
                'identificacion' => '555444333',
                'nombre1' => 'Other',
                'apellido1' => 'User',
            ],
        ])->assertStatus(422);

        $available = $this->getJson('/api/v1/clubs/available-for-account?user_id='.$other->id)
            ->assertOk()
            ->json('data');

        $this->assertFalse(collect($available)->contains('id', $club->id));

        $availableOwner = $this->getJson('/api/v1/clubs/available-for-account?user_id='.$owner->id)
            ->assertOk()
            ->json('data');

        $this->assertTrue(collect($availableOwner)->contains('id', $club->id));
    }
}
