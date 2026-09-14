<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Users\Models\Page;
use App\Modules\Users\Models\Permission;
use App\Modules\Users\Models\Role;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PagesMenuApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_clubes_page_is_hidden_from_project_menu(): void
    {
        $admin = $this->admin();
        Sanctum::actingAs($admin);

        $created = $this->postJson('/api/v1/pages', [
            'key' => 'asistencia_club',
            'name' => 'Asistencia',
            'route_name' => 'asistencia',
            'front' => Page::FRONT_CLUBES,
        ])->assertCreated()
            ->assertJsonPath('data.front', Page::FRONT_CLUBES)
            ->json('data');

        $this->getJson('/api/v1/roles/pages')
            ->assertOk()
            ->assertJsonFragment(['key' => 'asistencia_club', 'front' => Page::FRONT_CLUBES]);

        $projectIds = collect($this->getJson('/api/v1/menu')->assertOk()->json('data'))->pluck('id');
        $this->assertNotContains($created['id'], $projectIds->all());

        $clubesIds = collect($this->getJson('/api/v1/menu', [
            'X-Clubes-Client' => 'clubes',
        ])->assertOk()->json('data'))->pluck('id');
        $this->assertContains($created['id'], $clubesIds->all());

        $queryIds = collect($this->getJson('/api/v1/menu?front=clubes')->assertOk()->json('data'))->pluck('id');
        $this->assertContains($created['id'], $queryIds->all());
    }

    public function test_patch_front_moves_page_between_menus(): void
    {
        $admin = $this->admin();
        Sanctum::actingAs($admin);

        $pageId = $this->postJson('/api/v1/pages', [
            'key' => 'reunion_club',
            'name' => 'Reunión',
            'route_name' => 'reunion',
            'front' => Page::FRONT_PROJECT,
        ])->assertCreated()->json('data.id');

        $this->assertContains($pageId, collect($this->getJson('/api/v1/menu')->json('data'))->pluck('id'));

        $this->patchJson("/api/v1/pages/{$pageId}", ['front' => Page::FRONT_CLUBES])
            ->assertOk()
            ->assertJsonPath('data.front', Page::FRONT_CLUBES);

        $this->assertNotContains($pageId, collect($this->getJson('/api/v1/menu')->json('data'))->pluck('id'));
        $this->assertContains(
            $pageId,
            collect($this->getJson('/api/v1/menu', ['X-Clubes-Client' => 'clubes'])->json('data'))->pluck('id')
        );
    }

    public function test_director_cannot_create_pages(): void
    {
        $director = User::factory()->create(['email' => 'dir-pages@test.local']);
        $roleId = (int) Role::query()->where('name', 'director')->value('id');
        $director->forceFill(['active_rol_id' => $roleId])->save();
        $director->clearPermissionCache();

        Sanctum::actingAs($director->fresh());
        $this->postJson('/api/v1/pages', [
            'key' => 'no_permitido',
            'name' => 'No',
            'front' => Page::FRONT_CLUBES,
        ])->assertForbidden();
    }

    public function test_cannot_delete_system_page(): void
    {
        Sanctum::actingAs($this->admin());
        $dashboard = Page::query()->where('key', 'dashboard')->firstOrFail();

        $this->deleteJson("/api/v1/pages/{$dashboard->id}")->assertStatus(422);
        $this->assertDatabaseHas('pages', ['id' => $dashboard->id]);
    }

    public function test_created_page_gets_view_permission(): void
    {
        Sanctum::actingAs($this->admin());
        $this->postJson('/api/v1/pages', [
            'key' => 'lista_asistencia',
            'name' => 'Lista de asistencia',
            'front' => Page::FRONT_CLUBES,
        ])->assertCreated();

        $this->assertDatabaseHas('permissions', [
            'name' => 'lista_asistencia.view',
        ]);
        $this->assertTrue(Permission::query()->where('name', 'lista_asistencia.view')->exists());
    }

    private function admin(): User
    {
        $user = User::factory()->create(['email' => 'admin-pages@test.local']);
        $user->forceFill(['is_admin' => true, 'is_super' => true])->save();
        $user->clearPermissionCache();

        return $user;
    }
}
