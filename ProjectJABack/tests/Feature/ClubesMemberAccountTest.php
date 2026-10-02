<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Clubs\Models\Club;
use App\Modules\Clubs\Models\Persona;
use App\Modules\Organizations\Models\Organizacion;
use App\Modules\Organizations\Models\PersonaOrganizacion;
use App\Modules\Organizations\Models\PersonaOrganizacionRol;
use App\Modules\Users\Models\Role;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ClubesMemberAccountTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->ensureClubTipo();
    }

    public function test_director_can_update_member_and_password_when_user_exists(): void
    {
        $org = $this->createClubOrg('Club Cuentas');
        $director = $this->boardUser('director', $org, 'dir-cuenta@test.local');
        $member = $this->memberWithUser($org, 'int-cuenta@test.local');

        Sanctum::actingAs($director);

        $this->putJson("/api/v1/personas/{$member->id}", [
            'telefono' => '3001112233',
            'correo' => 'int-cuenta@test.local',
        ])->assertOk()
            ->assertJsonPath('data.telefono', '3001112233')
            ->assertJsonPath('data.user_id', $member->user->id);

        $this->putJson("/api/v1/personas/{$member->id}/password", [
            'password' => 'NuevaClave1!',
            'password_confirmation' => 'NuevaClave1!',
        ])->assertOk();

        $this->assertTrue(Hash::check('NuevaClave1!', $member->user->fresh()->password));
    }

    public function test_director_cannot_change_password_without_user(): void
    {
        $org = $this->createClubOrg('Club Sin Usuario');
        $director = $this->boardUser('director', $org, 'dir-sin-user@test.local');
        $persona = $this->member($org, 'sin-user@test.local');

        Sanctum::actingAs($director);

        $this->putJson("/api/v1/personas/{$persona->id}/password", [
            'password' => 'NuevaClave1!',
            'password_confirmation' => 'NuevaClave1!',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['password']);
    }

    public function test_director_can_impersonate_club_member(): void
    {
        $org = $this->createClubOrg('Club Autologin');
        $director = $this->boardUser('director', $org, 'dir-auto@test.local');
        $member = $this->memberWithUser($org, 'int-auto@test.local');

        Sanctum::actingAs($director);
        $this->postJson("/api/v1/auth/impersonate/{$member->user->id}")
            ->assertOk()
            ->assertJsonPath('data.user.id', $member->user->id)
            ->assertJsonPath('data.user.impersonated', true)
            ->assertJsonPath('data.user.impersonator.id', $director->id);
    }

    public function test_director_can_impersonate_even_without_manage_members_permission(): void
    {
        $org = $this->createClubOrg('Club Autologin Rol');
        $director = $this->boardUser('director', $org, 'dir-auto-rol@test.local');
        $member = $this->memberWithUser($org, 'int-auto-rol@test.local');

        $role = Role::query()->where('name', 'director')->firstOrFail();
        $role->permissions()->detach(
            \App\Modules\Users\Models\Permission::query()
                ->whereIn('name', ['mi_club.manage_members', 'clubs.manage_members', 'users.view'])
                ->pluck('id'),
        );
        $director->clearPermissionCache();

        Sanctum::actingAs($director->fresh());
        $this->postJson("/api/v1/auth/impersonate/{$member->user->id}")
            ->assertOk()
            ->assertJsonPath('data.user.id', $member->user->id);
    }

    public function test_director_cannot_impersonate_member_of_other_club(): void
    {
        $mine = $this->createClubOrg('Club Propio');
        $other = $this->createClubOrg('Club Ajeno');
        $director = $this->boardUser('director', $mine, 'dir-propio-auto@test.local');
        $stranger = $this->memberWithUser($other, 'int-ajeno@test.local');

        Sanctum::actingAs($director);
        $this->postJson("/api/v1/auth/impersonate/{$stranger->user->id}")
            ->assertForbidden();
    }

    public function test_subdirector_cannot_impersonate(): void
    {
        $org = $this->createClubOrg('Club Autologin Sub');
        $this->boardUser('director', $org, 'dir-auto-sub@test.local');
        $subdirector = $this->boardUser('subdirector', $org, 'sub-auto@test.local');
        $member = $this->memberWithUser($org, 'int-auto-sub@test.local');

        Sanctum::actingAs($subdirector);
        $this->postJson("/api/v1/auth/impersonate/{$member->user->id}")
            ->assertForbidden();
    }

    public function test_tesorero_cannot_impersonate_or_change_password(): void
    {
        $org = $this->createClubOrg('Club Tesorería');
        $this->boardUser('director', $org, 'dir-tes-cuenta@test.local');
        $tesorero = $this->boardUser('tesorero', $org, 'tes-cuenta@test.local');
        $member = $this->memberWithUser($org, 'int-tes-cuenta@test.local');

        Sanctum::actingAs($tesorero);
        $this->putJson("/api/v1/personas/{$member->id}/password", [
            'password' => 'NuevaClave1!',
            'password_confirmation' => 'NuevaClave1!',
        ])->assertForbidden();

        $this->postJson("/api/v1/auth/impersonate/{$member->user->id}")
            ->assertForbidden();
    }

    public function test_director_can_assign_board_role(): void
    {
        [$org, $club] = $this->createClub('Club Directiva');
        $director = $this->boardUser('director', $org, 'dir-directiva@test.local');
        $member = $this->member($org, 'tes-directiva@test.local');

        Sanctum::actingAs($director);
        $this->putJson("/api/v1/clubs/{$club->id}/directors", [
            'directors' => [
                'tesorero' => [
                    'mode' => 'select',
                    'persona_id' => $member->id,
                ],
            ],
        ])->assertOk();
    }

    public function test_subdirector_cannot_assign_board_role(): void
    {
        [$org, $club] = $this->createClub('Club Directiva Sub');
        $this->boardUser('director', $org, 'dir-directiva-sub@test.local');
        $subdirector = $this->boardUser('subdirector', $org, 'sub-directiva@test.local');
        $member = $this->member($org, 'int-directiva-sub@test.local');

        Sanctum::actingAs($subdirector);
        $this->putJson("/api/v1/clubs/{$club->id}/directors", [
            'directors' => [
                'tesorero' => [
                    'mode' => 'select',
                    'persona_id' => $member->id,
                ],
            ],
        ])->assertForbidden();
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

    /**
     * @return array{0: Organizacion, 1: Club}
     */
    private function createClub(string $nombre): array
    {
        $org = $this->createClubOrg($nombre);
        $club = Club::query()->create([
            'organizacion_id' => $org->id,
            'nombre' => $nombre,
            'is_active' => true,
            'tipos' => ['conquistadores'],
        ]);

        return [$org, $club];
    }

    private function boardUser(string $roleName, Organizacion $org, string $email): User
    {
        $persona = $this->createPersona($email, ucfirst($roleName));
        $this->attachMember($persona, $org, $roleName);

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

    private function member(Organizacion $org, string $email): Persona
    {
        $persona = $this->createPersona($email, 'Integrante');
        $this->attachMember($persona, $org);

        return $persona;
    }

    private function memberWithUser(Organizacion $org, string $email): Persona
    {
        $persona = $this->member($org, $email);
        User::factory()->create([
            'email' => $email,
            'password' => 'Password1!',
            'persona_id' => $persona->id,
            'is_active' => true,
        ]);

        return $persona->fresh(['user']);
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
