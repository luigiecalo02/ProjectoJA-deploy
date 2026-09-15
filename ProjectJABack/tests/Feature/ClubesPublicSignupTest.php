<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Organizations\Models\Organizacion;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ClubesPublicSignupTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->ensureOrgTipos();
    }

    public function test_lists_children_under_env_root_and_registers_in_club(): void
    {
        $parent = $this->createOrg('Asociación Caribe', Organizacion::TIPO_ASOCIACION);
        $iglesia = $this->createOrg('Iglesia Central', Organizacion::TIPO_IGLESIA, $parent->id);
        $club = $this->createOrg('Club Halcones', Organizacion::TIPO_CLUB, $iglesia->id);
        $this->createOrg('Club Ajeno', Organizacion::TIPO_CLUB);

        $headers = [
            'X-Clubes-Client' => 'clubes',
            'X-Clubes-Root-Id' => (string) $parent->id,
        ];

        $this->getJson('/api/v1/settings/clubes/public/organizaciones', $headers)
            ->assertOk()
            ->assertJsonPath('data.path.0.id', $parent->id)
            ->assertJsonPath('data.children.0.id', $iglesia->id);

        $this->getJson('/api/v1/settings/clubes/public/organizaciones?padre_id='.$iglesia->id, $headers)
            ->assertOk()
            ->assertJsonPath('data.children.0.id', $club->id)
            ->assertJsonPath('data.children.0.is_club', true);

        $this->postJson('/api/v1/settings/clubes/public/register', [
            'organizacion_id' => $club->id,
            'nombre1' => 'Ana',
            'apellido1' => 'López',
            'correo' => 'ana@test.local',
            'password' => 'Password1!',
            'password_confirmation' => 'Password1!',
            'tipo_identificacion' => 'CC',
            'identificacion' => '1234567890',
        ], $headers)->assertCreated();

        $user = User::query()->where('email', 'ana@test.local')->first();
        $this->assertNotNull($user);
        $this->assertSame($club->id, (int) $user->active_organizacion_id);
        $this->assertFalse((bool) $user->is_active);
        $this->assertNull($user->email_verified_at);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'ana@test.local',
            'password' => 'Password1!',
        ], $headers)->assertStatus(422);

        $this->postJson('/api/v1/auth/email/verify', [
            'id' => $user->id,
            'hash' => sha1($user->email),
        ], $headers)->assertOk();

        $user->refresh();
        $this->assertTrue((bool) $user->is_active);
        $this->assertNotNull($user->email_verified_at);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'ana@test.local',
            'password' => 'Password1!',
        ], $headers)->assertOk();
    }

    public function test_cannot_register_in_organization_outside_tenant(): void
    {
        $parent = $this->createOrg('Asociación Caribe', Organizacion::TIPO_ASOCIACION);
        $other = $this->createOrg('Club Ajeno', Organizacion::TIPO_CLUB);

        $this->postJson('/api/v1/settings/clubes/public/register', [
            'organizacion_id' => $other->id,
            'nombre1' => 'Ana',
            'apellido1' => 'López',
            'correo' => 'ana@test.local',
            'password' => 'Password1!',
            'password_confirmation' => 'Password1!',
            'tipo_identificacion' => 'CC',
            'identificacion' => '1234567890',
        ], [
            'X-Clubes-Client' => 'clubes',
            'X-Clubes-Root-Id' => (string) $parent->id,
        ])->assertStatus(422);
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

    private function ensureOrgTipos(): void
    {
        foreach ([
            Organizacion::TIPO_ASOCIACION => 'Asociación',
            Organizacion::TIPO_IGLESIA => 'Iglesia',
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
