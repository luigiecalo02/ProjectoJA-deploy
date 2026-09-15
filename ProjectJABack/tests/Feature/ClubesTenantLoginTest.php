<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Clubs\Models\Persona;
use App\Modules\Organizations\Models\Organizacion;
use App\Modules\Organizations\Models\PersonaOrganizacion;
use App\Modules\Organizations\Models\PersonaOrganizacionRol;
use App\Modules\Users\Models\Role;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ClubesTenantLoginTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->ensureOrgTipos();
    }

    public function test_child_organization_can_login_to_clubes_tenant(): void
    {
        [$parent, $child] = $this->parentAndChild();
        $user = $this->orgUser($child, 'hija@test.local');

        $this->postJson('/api/v1/auth/login', [
            'email' => 'hija@test.local',
            'password' => 'Password1!',
        ], $this->clubesHeaders($parent->id))
            ->assertOk()
            ->assertJsonPath('success', true);
    }

    public function test_outside_organization_cannot_login_to_clubes_tenant(): void
    {
        [$parent] = $this->parentAndChild();
        $other = $this->createOrg('Club Ajeno', Organizacion::TIPO_CLUB);
        $this->orgUser($other, 'ajena@test.local');

        $this->postJson('/api/v1/auth/login', [
            'email' => 'ajena@test.local',
            'password' => 'Password1!',
        ], $this->clubesHeaders($parent->id))
            ->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    public function test_superadmin_can_login_to_clubes_tenant_without_membership(): void
    {
        [$parent] = $this->parentAndChild();
        $user = User::factory()->create([
            'email' => 'super@test.local',
            'password' => 'Password1!',
            'is_active' => true,
        ]);
        $user->forceFill(['is_super' => true, 'is_admin' => true])->save();

        $this->postJson('/api/v1/auth/login', [
            'email' => 'super@test.local',
            'password' => 'Password1!',
        ], $this->clubesHeaders($parent->id))
            ->assertOk()
            ->assertJsonPath('data.user.is_super', true);
    }

    /**
     * @return array{0: Organizacion, 1: Organizacion}
     */
    private function parentAndChild(): array
    {
        $parent = $this->createOrg('Asociación Caribe', Organizacion::TIPO_ASOCIACION);
        $child = $this->createOrg('Club Hijo', Organizacion::TIPO_CLUB, $parent->id);

        return [$parent, $child];
    }

    /**
     * @return array<string, int|string>
     */
    private function clubesHeaders(int $rootId): array
    {
        return [
            'X-Clubes-Client' => 'clubes',
            'X-Clubes-Root-Id' => (string) $rootId,
        ];
    }

    private function createOrg(string $nombre, int $tipoId, ?int $padreId = null): Organizacion
    {
        return Organizacion::query()->create([
            'tipo_organizacion_id' => $tipoId,
            'organizacion_padre_id' => $padreId,
            'nombre' => $nombre,
            'codigo' => strtoupper(substr(md5($nombre.microtime()), 0, 8)),
            'estado' => true,
        ]);
    }

    private function orgUser(Organizacion $org, string $email): User
    {
        $persona = Persona::query()->create([
            'tipo_identificacion' => 'CC',
            'identificacion' => 'ID'.random_int(100000, 999999).uniqid(),
            'nombre1' => 'Integrante',
            'apellido1' => 'Club',
            'correo' => $email,
        ]);

        $membership = PersonaOrganizacion::query()->create([
            'persona_id' => $persona->id,
            'organizacion_id' => $org->id,
            'fecha_inicio' => now()->toDateString(),
            'estado' => true,
        ]);

        PersonaOrganizacionRol::query()->create([
            'persona_organizacion_id' => $membership->id,
            'rol_id' => (int) Role::query()->where('name', 'director')->value('id'),
        ]);

        $user = User::factory()->create([
            'email' => $email,
            'password' => 'Password1!',
            'is_active' => true,
            'persona_id' => $persona->id,
        ]);
        $user->forceFill([
            'active_organizacion_id' => $org->id,
            'active_rol_id' => (int) Role::query()->where('name', 'director')->value('id'),
        ])->save();

        return $user;
    }

    private function ensureOrgTipos(): void
    {
        foreach ([
            Organizacion::TIPO_ASOCIACION => 'Asociación',
            Organizacion::TIPO_CLUB => 'Club',
        ] as $id => $nombre) {
            if (DB::table('tipo_organizacion')->where('id', $id)->exists()) {
                continue;
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
    }
}
