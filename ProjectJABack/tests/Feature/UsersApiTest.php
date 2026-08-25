<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Clubs\Models\Persona;
use App\Modules\Organizations\Models\Organizacion;
use App\Modules\Organizations\Models\PersonaOrganizacion;
use App\Modules\Organizations\Models\TipoOrganizacion;
use App\Modules\Users\Models\Role;
use Database\Seeders\OrganizacionCatalogSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class UsersApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    private function admin(): User
    {
        $user = User::factory()->create(['email' => 'admin@test.local']);
        $user->forceFill(['is_admin' => true])->save();
        $user->clearPermissionCache();

        return $user;
    }

    public function test_admin_can_list_users(): void
    {
        Sanctum::actingAs($this->admin());
        User::factory()->count(2)->create();

        $this->getJson('/api/v1/users')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['pagination']);
    }

    public function test_user_without_permission_cannot_list(): void
    {
        $user = User::factory()->create();
        // pastor via role_ids / POR; no role_user
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/users')->assertForbidden();
    }

    public function test_admin_can_create_user(): void
    {
        Sanctum::actingAs($this->admin());
        $roleId = Role::query()->where('name', 'pastor')->value('id');

        $this->postJson('/api/v1/users', [
            'name' => 'Nuevo',
            'email' => 'nuevo@projectja.local',
            'password' => 'Password1!',
            'password_confirmation' => 'Password1!',
            'role_ids' => [$roleId],
        ])->assertCreated()->assertJsonPath('data.email', 'nuevo@projectja.local');
    }

    public function test_cannot_create_user_with_duplicate_email(): void
    {
        Sanctum::actingAs($this->admin());
        User::factory()->create(['email' => 'duplicado@projectja.local']);
        $roleId = Role::query()->where('name', 'pastor')->value('id');

        $this->postJson('/api/v1/users', [
            'name' => 'Otro',
            'email' => 'Duplicado@projectja.local',
            'password' => 'Password1!',
            'password_confirmation' => 'Password1!',
            'role_ids' => [$roleId],
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['email']);
    }

    public function test_scoped_admin_only_lists_users_from_own_organization_downward(): void
    {
        $this->seed(OrganizacionCatalogSeeder::class);

        $distrito = Organizacion::query()->create([
            'tipo_organizacion_id' => Organizacion::TIPO_DISTRITO,
            'nombre' => 'Distrito Norte',
            'codigo' => 'DIS-N-'.uniqid(),
            'estado' => true,
        ]);
        $iglesiaLocal = Organizacion::query()->create([
            'organizacion_padre_id' => $distrito->id,
            'tipo_organizacion_id' => Organizacion::TIPO_IGLESIA,
            'nombre' => 'Iglesia Norte',
            'codigo' => 'IGL-N-'.uniqid(),
            'estado' => true,
        ]);
        $iglesiaAjena = Organizacion::query()->create([
            'tipo_organizacion_id' => Organizacion::TIPO_IGLESIA,
            'nombre' => 'Iglesia Sur',
            'codigo' => 'IGL-S-'.uniqid(),
            'estado' => true,
        ]);

        $localUser = $this->userInOrganization($iglesiaLocal->id, 'local@test.local');
        $foreignUser = $this->userInOrganization($iglesiaAjena->id, 'ajeno@test.local');
        $admin = $this->scopedAdmin($distrito->id);

        Sanctum::actingAs($admin);

        $ids = collect($this->getJson('/api/v1/users')->assertOk()->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($localUser->id));
        $this->assertFalse($ids->contains($foreignUser->id));

        $filtered = collect($this->getJson('/api/v1/users?organizacion_id='.$iglesiaLocal->id)
            ->assertOk()
            ->json('data'))->pluck('id');
        $this->assertTrue($filtered->contains($localUser->id));
        $this->assertFalse($filtered->contains($foreignUser->id));

        $ignored = collect($this->getJson('/api/v1/users?organizacion_id='.$iglesiaAjena->id)
            ->assertOk()
            ->json('data'))->pluck('id');
        $this->assertTrue($ignored->contains($localUser->id));
        $this->assertFalse($ignored->contains($foreignUser->id));
    }

    public function test_admin_can_filter_users_by_club_ministry(): void
    {
        $this->seed(OrganizacionCatalogSeeder::class);

        $tipoAventureros = (int) TipoOrganizacion::query()
            ->where('nombre', 'like', '%Aventurer%')
            ->value('id');
        $tipoConquistadores = (int) TipoOrganizacion::query()
            ->where('nombre', 'like', '%Conquistador%')
            ->value('id');

        $clubAve = Organizacion::query()->create([
            'tipo_organizacion_id' => $tipoAventureros,
            'nombre' => 'Club Aventureros Norte',
            'codigo' => 'AVE-N-'.uniqid(),
            'estado' => true,
        ]);
        $clubConq = Organizacion::query()->create([
            'tipo_organizacion_id' => $tipoConquistadores,
            'nombre' => 'Club Conquistadores Norte',
            'codigo' => 'CON-N-'.uniqid(),
            'estado' => true,
        ]);

        $aveUser = $this->userInOrganization($clubAve->id, 'ave@test.local');
        $conqUser = $this->userInOrganization($clubConq->id, 'conq@test.local');

        Sanctum::actingAs($this->admin());

        $aventureros = collect($this->getJson('/api/v1/users?tipo_club=aventureros')
            ->assertOk()
            ->json('data'))->pluck('id');
        $this->assertTrue($aventureros->contains($aveUser->id));
        $this->assertFalse($aventureros->contains($conqUser->id));

        $conquistadores = collect($this->getJson('/api/v1/users?tipo_club=conquistadores')
            ->assertOk()
            ->json('data'))->pluck('id');
        $this->assertTrue($conquistadores->contains($conqUser->id));
        $this->assertFalse($conquistadores->contains($aveUser->id));
    }

    private function scopedAdmin(int $organizacionId): User
    {
        $user = User::factory()->create(['email' => 'district-admin@test.local']);
        $persona = Persona::query()->create([
            'tipo_identificacion' => 'CC',
            'identificacion' => 'ADM-'.uniqid(),
            'nombre1' => 'Admin',
            'apellido1' => 'Distrital',
            'correo' => $user->email,
        ]);
        PersonaOrganizacion::query()->create([
            'persona_id' => $persona->id,
            'organizacion_id' => $organizacionId,
            'estado' => true,
        ]);
        $user->forceFill([
            'is_admin' => true,
            'persona_id' => $persona->id,
            'active_organizacion_id' => $organizacionId,
        ])->save();
        $user->clearPermissionCache();

        return $user->fresh();
    }

    private function userInOrganization(int $organizacionId, string $email): User
    {
        $user = User::factory()->create(['email' => $email]);
        $persona = Persona::query()->create([
            'tipo_identificacion' => 'CC',
            'identificacion' => 'USR-'.uniqid(),
            'nombre1' => 'Usuario',
            'apellido1' => 'Org',
            'correo' => $email,
        ]);
        PersonaOrganizacion::query()->create([
            'persona_id' => $persona->id,
            'organizacion_id' => $organizacionId,
            'estado' => true,
        ]);
        $user->forceFill(['persona_id' => $persona->id])->save();

        return $user->fresh();
    }
}
