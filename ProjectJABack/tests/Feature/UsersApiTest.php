<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Users\Models\Role;
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
}
