<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Clubs\Models\Persona;
use App\Modules\Organizations\Models\Organizacion;
use App\Modules\Organizations\Models\PersonaOrganizacion;
use App\Modules\Organizations\Models\PersonaOrganizacionRol;
use App\Modules\Settings\Models\ClubesGananciaDistribucion;
use App\Modules\Users\Models\Role;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ClubesGananciasApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->ensureClubTipo();
    }

    public function test_director_can_create_and_list_distribucion(): void
    {
        $org = $this->createClubOrg('Club Ganancias');
        $director = $this->boardUser('director', $org, 'dir-gan@test.local');

        Sanctum::actingAs($director);
        $created = $this->postJson('/api/v1/settings/clubes/ganancias', [
            'nombre' => 'Distribución normal',
            'club' => 50,
            'miembros' => 30,
            'extras' => 20,
            'es_predeterminada' => true,
        ], $this->clubesHeaders())->assertCreated()->json('data');

        $this->assertSame('Distribución normal', $created['nombre']);
        $this->assertEquals(50, $created['club']);
        $this->assertTrue($created['es_predeterminada']);

        $listed = $this->getJson('/api/v1/settings/clubes/ganancias', $this->clubesHeaders())
            ->assertOk()
            ->json('data');
        $this->assertCount(1, $listed);
        $this->assertSame($created['id'], $listed[0]['id']);
    }

    public function test_distribucion_must_sum_100(): void
    {
        $org = $this->createClubOrg('Club Suma');
        $director = $this->boardUser('director', $org, 'dir-suma@test.local');

        Sanctum::actingAs($director);
        $this->postJson('/api/v1/settings/clubes/ganancias', [
            'nombre' => 'Incompleta',
            'club' => 50,
            'miembros' => 30,
            'extras' => 10,
        ], $this->clubesHeaders())->assertStatus(422);
    }

    public function test_director_cannot_see_other_club_distribucion(): void
    {
        $mine = $this->createClubOrg('Club Propio Gan');
        $other = $this->createClubOrg('Club Ajeno Gan');
        $director = $this->boardUser('director', $mine, 'dir-propio-gan@test.local');
        $this->boardUser('director', $other, 'dir-ajeno-gan@test.local');

        $foreign = ClubesGananciaDistribucion::query()->create([
            'organizacion_id' => $other->id,
            'nombre' => 'Ajena',
            'club' => 100,
            'miembros' => 0,
            'extras' => 0,
            'es_predeterminada' => false,
        ]);

        Sanctum::actingAs($director);
        $this->getJson('/api/v1/settings/clubes/ganancias', $this->clubesHeaders())
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->putJson("/api/v1/settings/clubes/ganancias/{$foreign->id}", [
            'nombre' => 'Hack',
            'club' => 100,
            'miembros' => 0,
            'extras' => 0,
        ], $this->clubesHeaders())->assertNotFound();
    }

    public function test_subdirector_cannot_write_distribucion(): void
    {
        $org = $this->createClubOrg('Club Sub Gan');
        $this->boardUser('director', $org, 'dir-sub-gan@test.local');
        $subdirector = $this->boardUser('subdirector', $org, 'sub-gan@test.local');

        Sanctum::actingAs($subdirector);
        $this->postJson('/api/v1/settings/clubes/ganancias', [
            'nombre' => 'No debe',
            'club' => 100,
            'miembros' => 0,
            'extras' => 0,
        ], $this->clubesHeaders())->assertForbidden();
    }

    public function test_director_can_create_eclesiastica_and_cannot_delete_used_distribucion(): void
    {
        $org = $this->createClubOrg('Club Iglesia');
        $director = $this->boardUser('director', $org, 'dir-igl@test.local');

        Sanctum::actingAs($director);
        $distId = $this->postJson('/api/v1/settings/clubes/ganancias', [
            'nombre' => 'Distribución normal',
            'club' => 50,
            'miembros' => 30,
            'extras' => 20,
        ], $this->clubesHeaders())->assertCreated()->json('data.id');

        $created = $this->postJson('/api/v1/settings/clubes/ganancias/eclesiasticas', [
            'nombre' => 'Eventos especiales',
            'diezmo' => 10,
            'ofrenda' => 10,
            'distribucion_id' => $distId,
            'es_predeterminada' => true,
        ], $this->clubesHeaders())->assertCreated()->json('data');

        $this->assertEquals(80, $created['resto']);
        $this->assertSame($distId, $created['distribucion_id']);
        $this->assertSame('Distribución normal', $created['distribucion']['nombre']);

        $this->deleteJson("/api/v1/settings/clubes/ganancias/{$distId}", [], $this->clubesHeaders())
            ->assertStatus(422);

        $this->deleteJson("/api/v1/settings/clubes/ganancias/eclesiasticas/{$created['id']}", [], $this->clubesHeaders())
            ->assertOk();
        $this->deleteJson("/api/v1/settings/clubes/ganancias/{$distId}", [], $this->clubesHeaders())
            ->assertOk();
    }

    public function test_eclesiastica_rejects_diezmo_plus_ofrenda_over_100(): void
    {
        $org = $this->createClubOrg('Club Cap');
        $director = $this->boardUser('director', $org, 'dir-cap@test.local');

        Sanctum::actingAs($director);
        $distId = $this->postJson('/api/v1/settings/clubes/ganancias', [
            'nombre' => 'Base',
            'club' => 100,
            'miembros' => 0,
            'extras' => 0,
        ], $this->clubesHeaders())->assertCreated()->json('data.id');

        $this->postJson('/api/v1/settings/clubes/ganancias/eclesiasticas', [
            'nombre' => 'Exceso',
            'diezmo' => 60,
            'ofrenda' => 50,
            'distribucion_id' => $distId,
        ], $this->clubesHeaders())->assertStatus(422);
    }

    /**
     * @return array<string, string>
     */
    private function clubesHeaders(): array
    {
        return ['X-Clubes-Client' => 'clubes'];
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
        $membership = PersonaOrganizacion::query()->create([
            'persona_id' => $persona->id,
            'organizacion_id' => $org->id,
            'fecha_inicio' => now()->toDateString(),
            'estado' => true,
        ]);
        PersonaOrganizacionRol::query()->create([
            'persona_organizacion_id' => $membership->id,
            'rol_id' => (int) Role::query()->where('name', $roleName)->value('id'),
        ]);

        $roleId = (int) Role::query()->where('name', $roleName)->value('id');
        $user = User::factory()->create([
            'email' => $email,
            'persona_id' => $persona->id,
            'is_active' => true,
        ]);
        $user->forceFill([
            'active_organizacion_id' => $org->id,
            'active_rol_id' => $roleId,
        ])->save();
        $user->clearPermissionCache();

        return $user->fresh();
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
}
