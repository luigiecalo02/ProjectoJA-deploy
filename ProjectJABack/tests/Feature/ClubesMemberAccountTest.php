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
