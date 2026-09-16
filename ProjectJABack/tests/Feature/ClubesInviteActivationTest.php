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
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ClubesInviteActivationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->ensureOrgTipos();
    }

    public function test_director_link_activates_existing_persona_as_miembro(): void
    {
        $club = $this->createOrg('Club Halcones', Organizacion::TIPO_CLUB);
        $director = $this->director($club);
        $persona = Persona::query()->create([
            'tipo_identificacion' => 'CC',
            'identificacion' => '1098765432',
            'nombre1' => 'Luis',
            'apellido1' => 'Mora',
            'correo' => null,
        ]);
        PersonaOrganizacion::query()->create([
            'persona_id' => $persona->id,
            'organizacion_id' => $club->id,
            'fecha_inicio' => now()->toDateString(),
            'estado' => true,
        ]);

        $headers = [
            'X-Clubes-Client' => 'clubes',
            'X-Clubes-Root-Id' => (string) $club->id,
            'Origin' => 'http://localhost:5173',
        ];

        Sanctum::actingAs($director);
        $url = $this->postJson('/api/v1/settings/clubes/invite-link', [], $headers)
            ->assertOk()
            ->json('data.url');

        $this->assertStringContainsString('/activar?token=', $url);
        parse_str(parse_url($url, PHP_URL_QUERY) ?: '', $query);
        $token = $query['token'] ?? '';

        $this->postJson('/api/v1/settings/clubes/public/activate/lookup', [
            'token' => $token,
            'identificacion' => '1098765432',
        ], $headers)
            ->assertOk()
            ->assertJsonPath('data.missing.0', 'correo');

        $this->postJson('/api/v1/settings/clubes/public/activate', [
            'token' => $token,
            'identificacion' => '1098765432',
            'nombre1' => 'Luis',
            'apellido1' => 'Mora',
            'correo' => 'luis@test.local',
            'password' => 'Password1!',
            'password_confirmation' => 'Password1!',
        ], $headers)
            ->assertCreated()
            ->assertJsonPath('success', true);

        $user = User::query()->where('email', 'luis@test.local')->first();
        $this->assertNotNull($user);
        $this->assertSame($club->id, (int) $user->active_organizacion_id);
        $this->assertSame(
            (int) Role::query()->where('name', 'miembro')->value('id'),
            (int) $user->active_rol_id,
        );
    }

    public function test_lookup_allows_persona_with_existing_user_and_updates_data(): void
    {
        $club = $this->createOrg('Club Halcones', Organizacion::TIPO_CLUB);
        $director = $this->director($club);
        $persona = Persona::query()->create([
            'tipo_identificacion' => 'CC',
            'identificacion' => '1098765433',
            'nombre1' => 'Ana',
            'apellido1' => 'Ruiz',
            'correo' => 'ana@test.local',
            'telefono' => '3001112233',
        ]);
        PersonaOrganizacion::query()->create([
            'persona_id' => $persona->id,
            'organizacion_id' => $club->id,
            'fecha_inicio' => now()->toDateString(),
            'estado' => true,
        ]);
        User::factory()->create([
            'email' => 'ana@test.local',
            'password' => 'Password1!',
            'is_active' => true,
            'persona_id' => $persona->id,
            'active_organizacion_id' => $club->id,
        ]);

        $headers = [
            'X-Clubes-Client' => 'clubes',
            'X-Clubes-Root-Id' => (string) $club->id,
            'Origin' => 'http://localhost:5173',
        ];

        Sanctum::actingAs($director);
        $url = $this->postJson('/api/v1/settings/clubes/invite-link', [], $headers)
            ->assertOk()
            ->json('data.url');
        parse_str(parse_url($url, PHP_URL_QUERY) ?: '', $query);
        $token = $query['token'] ?? '';

        $this->postJson('/api/v1/settings/clubes/public/activate/lookup', [
            'token' => $token,
            'identificacion' => '1098765433',
        ], $headers)
            ->assertOk()
            ->assertJsonPath('data.has_user', true)
            ->assertJsonPath('data.persona.nombre1', 'Ana');

        $this->postJson('/api/v1/settings/clubes/public/activate', [
            'token' => $token,
            'identificacion' => '1098765433',
            'nombre1' => 'Ana María',
            'apellido1' => 'Ruiz',
            'correo' => 'ana.nueva@test.local',
            'telefono' => '3009998877',
        ], $headers)
            ->assertCreated()
            ->assertJsonPath('success', true);

        $persona->refresh();
        $this->assertSame('Ana María', $persona->nombre1);
        $this->assertSame('ana.nueva@test.local', $persona->correo);
        $this->assertSame('3009998877', $persona->telefono);
        $this->assertTrue(
            User::query()->where('persona_id', $persona->id)->where('email', 'ana.nueva@test.local')->exists()
        );
    }

    private function director(Organizacion $org): User
    {
        $persona = Persona::query()->create([
            'tipo_identificacion' => 'CC',
            'identificacion' => 'DIR'.random_int(10000, 99999),
            'nombre1' => 'Director',
            'apellido1' => 'Club',
            'correo' => 'dir@test.local',
        ]);
        $membership = PersonaOrganizacion::query()->create([
            'persona_id' => $persona->id,
            'organizacion_id' => $org->id,
            'fecha_inicio' => now()->toDateString(),
            'estado' => true,
        ]);
        $roleId = (int) Role::query()->where('name', 'director')->value('id');
        PersonaOrganizacionRol::query()->create([
            'persona_organizacion_id' => $membership->id,
            'rol_id' => $roleId,
            'created_at' => now(),
        ]);

        $user = User::factory()->create([
            'email' => 'dir@test.local',
            'password' => 'Password1!',
            'is_active' => true,
            'persona_id' => $persona->id,
            'active_organizacion_id' => $org->id,
            'active_rol_id' => $roleId,
        ]);

        return $user;
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
