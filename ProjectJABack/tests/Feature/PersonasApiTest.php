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

    private function createClubOrg(string $nombre, ?int $parentId = null): Organizacion
    {
        return Organizacion::query()->create([
            'organizacion_padre_id' => $parentId,
            'tipo_organizacion_id' => Organizacion::TIPO_CLUB,
            'nombre' => $nombre,
            'codigo' => 'CLB-'.uniqid(),
            'estado' => true,
        ]);
    }

    private function createIglesia(string $nombre = 'Iglesia Padre'): Organizacion
    {
        return Organizacion::query()->create([
            'tipo_organizacion_id' => Organizacion::TIPO_IGLESIA,
            'nombre' => $nombre,
            'codigo' => 'IGL-'.uniqid(),
            'estado' => true,
        ]);
    }

    /**
     * @return array{user: User, iglesia: Organizacion, mine: Organizacion, sibling: Organizacion}
     */
    private function clubActorWithFamily(): array
    {
        $iglesia = $this->createIglesia();
        $orgMine = $this->createClubOrg('Mi Club Org', $iglesia->id);
        $orgSibling = $this->createClubOrg('Club Hermano Org', $iglesia->id);

        $actorPersona = Persona::query()->create([
            'tipo_identificacion' => 'CC',
            'identificacion' => 'ACTOR-'.uniqid(),
            'nombre1' => 'Director',
            'apellido1' => 'Prueba',
        ]);
        PersonaOrganizacion::query()->create([
            'persona_id' => $actorPersona->id,
            'organizacion_id' => $orgMine->id,
            'fecha_inicio' => now()->toDateString(),
            'estado' => true,
        ]);

        $user = User::factory()->create([
            'email' => 'director-personas@test.local',
            'persona_id' => $actorPersona->id,
        ]);
        $roleId = Role::query()->where('name', 'director')->value('id');
        $po = PersonaOrganizacion::query()
            ->where('persona_id', $actorPersona->id)
            ->where('organizacion_id', $orgMine->id)
            ->first();
        PersonaOrganizacionRol::query()->create([
            'persona_organizacion_id' => $po->id,
            'rol_id' => $roleId,
        ]);
        $user->forceFill([
            'active_organizacion_id' => $orgMine->id,
            'active_rol_id' => $roleId,
        ])->save();
        $user->clearPermissionCache();

        return [
            'user' => $user,
            'iglesia' => $iglesia,
            'mine' => $orgMine,
            'sibling' => $orgSibling,
        ];
    }

    private function attachPersonaToOrg(Persona $persona, int $organizacionId): void
    {
        PersonaOrganizacion::query()->create([
            'persona_id' => $persona->id,
            'organizacion_id' => $organizacionId,
            'fecha_inicio' => now()->toDateString(),
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

    public function test_club_context_lists_only_parent_and_sibling_personas(): void
    {
        $ctx = $this->clubActorWithFamily();
        $unrelated = $this->createClubOrg('Club Ajeno');

        $inChurch = Persona::query()->create([
            'tipo_identificacion' => 'CC',
            'identificacion' => '111',
            'nombre1' => 'Iglesia',
            'apellido1' => 'Persona',
        ]);
        $inSibling = Persona::query()->create([
            'tipo_identificacion' => 'CC',
            'identificacion' => '222',
            'nombre1' => 'Hermano',
            'apellido1' => 'Persona',
        ]);
        $inMine = Persona::query()->create([
            'tipo_identificacion' => 'CC',
            'identificacion' => '333',
            'nombre1' => 'Integrante',
            'apellido1' => 'Local',
        ]);
        $outside = Persona::query()->create([
            'tipo_identificacion' => 'CC',
            'identificacion' => '444',
            'nombre1' => 'Ajena',
            'apellido1' => 'Persona',
        ]);

        $this->attachPersonaToOrg($inChurch, $ctx['iglesia']->id);
        $this->attachPersonaToOrg($inSibling, $ctx['sibling']->id);
        $this->attachPersonaToOrg($inMine, $ctx['mine']->id);
        $this->attachPersonaToOrg($outside, $unrelated->id);

        Sanctum::actingAs($ctx['user']);

        $ids = collect($this->getJson('/api/v1/personas')->assertOk()->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($inChurch->id));
        $this->assertTrue($ids->contains($inSibling->id));
        $this->assertFalse($ids->contains($inMine->id));
        $this->assertFalse($ids->contains($outside->id));

        $memberIds = collect(
            $this->getJson('/api/v1/personas?solo_tipo_club=1')->assertOk()->json('data')
        )->pluck('id');
        $this->assertTrue($memberIds->contains($inMine->id));
        $this->assertFalse($memberIds->contains($inChurch->id));
        $this->assertFalse($memberIds->contains($inSibling->id));
    }

    public function test_club_context_creates_persona_in_parent_or_sibling_not_current_club(): void
    {
        $ctx = $this->clubActorWithFamily();
        Sanctum::actingAs($ctx['user']);

        $created = $this->postJson('/api/v1/personas', [
            'tipo_identificacion' => 'CC',
            'identificacion' => '555666777',
            'nombre1' => 'Nueva',
            'apellido1' => 'Persona',
            'organizacion_ids' => [$ctx['iglesia']->id],
        ])->assertCreated()->json('data');

        $this->assertContains($ctx['iglesia']->id, $created['organizacion_ids']);
        $this->assertNotContains($ctx['mine']->id, $created['organizacion_ids']);

        $this->postJson('/api/v1/personas', [
            'tipo_identificacion' => 'CC',
            'identificacion' => '555666778',
            'nombre1' => 'Forzada',
            'apellido1' => 'Club',
            'organizacion_ids' => [$ctx['mine']->id],
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['organizacion_ids']);

        $sibling = $this->postJson('/api/v1/personas', [
            'tipo_identificacion' => 'CC',
            'identificacion' => '555666779',
            'nombre1' => 'Hermana',
            'apellido1' => 'Nueva',
            'organizacion_ids' => [$ctx['sibling']->id],
        ])->assertCreated()->json('data');
        $this->assertContains($ctx['sibling']->id, $sibling['organizacion_ids']);
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
