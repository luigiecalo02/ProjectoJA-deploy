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

class ClubesTenantHandoffTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->ensureOrgTipos();
    }

    public function test_menu_options_include_role_from_the_other_club_host(): void
    {
        [$aventureros, $guias, $user] = $this->twoClubUser();
        $this->mapHosts($aventureros, $guias);

        Sanctum::actingAs($user);

        $payload = $this->getJson('/api/v1/auth/context-options', $this->clubesHeaders($aventureros, 'https://aventureros.test'))
            ->assertOk()
            ->json('data');

        $this->assertCount(1, $payload['options']);
        $this->assertSame($aventureros->id, $payload['options'][0]['organizacion_id']);

        $remote = collect($payload['menu_options'])->firstWhere('organizacion_id', $guias->id);
        $this->assertIsArray($remote);
        $this->assertFalse($remote['current_tenant']);
        $this->assertSame('https://guias.test', $remote['origin']);
        $this->assertSame('tesorero', $remote['rol_name']);
    }

    public function test_handoff_opens_session_on_the_destination_club(): void
    {
        [$aventureros, $guias, $user] = $this->twoClubUser();
        $this->mapHosts($aventureros, $guias);
        $tesoreroId = (int) Role::query()->where('name', 'tesorero')->value('id');

        Sanctum::actingAs($user);

        $issued = $this->postJson('/api/v1/auth/handoff', [
            'organizacion_id' => $guias->id,
            'rol_id' => $tesoreroId,
        ], $this->clubesHeaders($aventureros, 'https://aventureros.test'))
            ->assertOk()
            ->assertJsonPath('data.origin', 'https://guias.test')
            ->json('data');

        $this->postJson('/api/v1/auth/handoff/consume', [
            'code' => $issued['code'],
        ], $this->clubesHeaders($guias, 'https://guias.test'))
            ->assertOk()
            ->assertJsonPath('data.user.contexto.organizacion_id', $guias->id)
            ->assertJsonPath('data.user.contexto.rol_id', $tesoreroId)
            ->assertJsonPath('data.token_type', 'Bearer');

        $this->postJson('/api/v1/auth/handoff/consume', [
            'code' => $issued['code'],
        ], $this->clubesHeaders($guias, 'https://guias.test'))
            ->assertOk()
            ->assertJsonPath('data.user.contexto.organizacion_id', $guias->id);

        $this->postJson('/api/v1/auth/handoff/consume', [
            'code' => 'codigo-inexistente-de-salto-entre-clubes',
        ], $this->clubesHeaders($guias, 'https://guias.test'))
            ->assertStatus(422);
    }

    public function test_handoff_is_blocked_during_impersonation(): void
    {
        [$aventureros, $guias, $user] = $this->twoClubUser();
        $this->mapHosts($aventureros, $guias);
        $tesoreroId = (int) Role::query()->where('name', 'tesorero')->value('id');

        $token = $user->createToken('impersonation', ['impersonator:99'])->plainTextToken;

        $this->withToken($token)
            ->postJson('/api/v1/auth/handoff', [
                'organizacion_id' => $guias->id,
                'rol_id' => $tesoreroId,
            ], $this->clubesHeaders($aventureros, 'https://aventureros.test'))
            ->assertStatus(422);
    }

    /**
     * @return array{0: Organizacion, 1: Organizacion, 2: User}
     */
    private function twoClubUser(): array
    {
        $aventureros = $this->createOrg('Club Aventureros', Organizacion::TIPO_CLUB);
        $guias = $this->createOrg('Club Guías', Organizacion::TIPO_CLUB);
        $directorId = (int) Role::query()->where('name', 'director')->value('id');
        $tesoreroId = (int) Role::query()->where('name', 'tesorero')->value('id');

        $persona = Persona::query()->create([
            'tipo_identificacion' => 'CC',
            'identificacion' => 'ID'.random_int(100000, 999999).uniqid(),
            'nombre1' => 'Luisa',
            'apellido1' => 'Club',
            'correo' => 'luisa@test.local',
        ]);

        $this->assignRole($persona->id, $aventureros->id, $directorId);
        $this->assignRole($persona->id, $guias->id, $tesoreroId);

        $user = User::factory()->create([
            'email' => 'luisa@test.local',
            'password' => 'Password1!',
            'is_active' => true,
            'persona_id' => $persona->id,
        ]);
        $user->forceFill([
            'active_organizacion_id' => $aventureros->id,
            'active_rol_id' => $directorId,
        ])->save();

        return [$aventureros, $guias, $user];
    }

    private function assignRole(int $personaId, int $organizacionId, int $rolId): void
    {
        $membership = PersonaOrganizacion::query()->create([
            'persona_id' => $personaId,
            'organizacion_id' => $organizacionId,
            'fecha_inicio' => now()->toDateString(),
            'estado' => true,
        ]);

        PersonaOrganizacionRol::query()->create([
            'persona_organizacion_id' => $membership->id,
            'rol_id' => $rolId,
        ]);
    }

    private function mapHosts(Organizacion $aventureros, Organizacion $guias): void
    {
        config([
            'clubes.hosts' => sprintf(
                'aventureros.test:%d,guias.test:%d',
                $aventureros->id,
                $guias->id,
            ),
        ]);
    }

    /**
     * @return array<string, int|string>
     */
    private function clubesHeaders(Organizacion $root, string $origin): array
    {
        return [
            'Origin' => $origin,
            'X-Clubes-Client' => 'clubes',
            'X-Clubes-Root-Id' => (string) $root->id,
        ];
    }

    private function createOrg(string $nombre, int $tipoId): Organizacion
    {
        return Organizacion::query()->create([
            'tipo_organizacion_id' => $tipoId,
            'organizacion_padre_id' => null,
            'nombre' => $nombre,
            'codigo' => strtoupper(substr(md5($nombre.microtime()), 0, 8)),
            'estado' => true,
        ]);
    }

    private function ensureOrgTipos(): void
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
}
