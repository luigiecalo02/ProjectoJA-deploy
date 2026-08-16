<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Users\Models\Permission;
use App\Modules\Users\Models\Role;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RolesApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    private function admin(): User
    {
        $user = User::factory()->create(['email' => 'admin-roles@test.local']);
        $user->forceFill(['is_admin' => true])->save();
        $user->clearPermissionCache();

        return $user;
    }

    private function superAdmin(): User
    {
        $user = User::factory()->create(['email' => 'super@test.local']);
        $user->forceFill(['is_super' => true])->save();
        $user->clearPermissionCache();

        return $user;
    }

    public function test_admin_can_list_roles_and_pages(): void
    {
        Sanctum::actingAs($this->admin());

        $this->getJson('/api/v1/roles')
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->getJson('/api/v1/roles/pages')
            ->assertOk()
            ->assertJsonStructure(['data' => [['key', 'permissions']]]);
    }

    public function test_admin_can_create_role_with_permissions(): void
    {
        Sanctum::actingAs($this->admin());
        $permissionIds = Permission::query()->whereIn('name', ['dashboard.view', 'users.view'])->pluck('id')->all();

        $this->postJson('/api/v1/roles', [
            'display_name' => 'Editor Club',
            'description' => 'Solo lectura de usuarios',
            'permission_ids' => $permissionIds,
        ])
            ->assertCreated()
            ->assertJsonPath('data.display_name', 'Editor Club')
            ->assertJsonCount(2, 'data.permission_ids');
    }

    public function test_cannot_update_super_admin_role(): void
    {
        Sanctum::actingAs($this->admin());
        $super = Role::query()->where('name', 'super_admin')->firstOrFail();

        $this->putJson("/api/v1/roles/{$super->id}", [
            'display_name' => 'Hack',
        ])->assertStatus(422);
    }

    public function test_super_admin_bypasses_all_permissions(): void
    {
        $super = $this->superAdmin();
        Sanctum::actingAs($super);

        $this->getJson('/api/v1/roles')->assertOk();
        $this->getJson('/api/v1/users')->assertOk();
        $this->assertTrue($super->fresh()->hasPermission('roles.assign_permissions'));
    }

    public function test_pastor_cannot_manage_roles(): void
    {
        $user = User::factory()->create();
        // pastor via role_ids / POR; no role_user
        $user->clearPermissionCache();
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/roles')->assertForbidden();
    }
}
