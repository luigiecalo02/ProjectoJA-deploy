<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Events\Models\CategoriaSubevento;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CategoriaSubeventoApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    private function admin(): User
    {
        $user = User::factory()->create(['email' => 'cat-admin@test.local']);
        $user->forceFill(['is_admin' => true, 'is_super' => true])->save();
        $user->clearPermissionCache();

        return $user;
    }

    public function test_admin_can_crud_categoria_with_capability_flags(): void
    {
        Sanctum::actingAs($this->admin());

        $created = $this->postJson('/api/v1/events/categorias-subevento', [
            'nombre' => 'Solo puntos',
            'color' => '#111827',
            'icono' => 'pi pi-star',
            'orden' => 1,
            'maneja_puntos' => true,
            'maneja_fecha_inicio' => false,
            'maneja_fecha_fin' => false,
        ])->assertCreated()
            ->assertJsonPath('data.nombre', 'Solo puntos')
            ->assertJsonPath('data.maneja_puntos', true)
            ->assertJsonPath('data.maneja_fecha_inicio', false)
            ->assertJsonPath('data.maneja_fecha_fin', false);

        $id = (int) $created->json('data.id');

        $this->patchJson("/api/v1/events/categorias-subevento/{$id}", [
            'nombre' => 'Con fechas',
            'maneja_fecha_inicio' => true,
            'maneja_fecha_fin' => true,
        ])->assertOk()
            ->assertJsonPath('data.nombre', 'Con fechas')
            ->assertJsonPath('data.maneja_fecha_inicio', true)
            ->assertJsonPath('data.maneja_fecha_fin', true);

        $this->getJson('/api/v1/events/categorias-subevento')
            ->assertOk()
            ->assertJsonFragment(['id' => $id, 'nombre' => 'Con fechas']);

        $this->deleteJson("/api/v1/events/categorias-subevento/{$id}")->assertOk();

        $this->assertDatabaseMissing('categoria_subevento', ['id' => $id]);
    }

    public function test_list_can_include_inactive_categories(): void
    {
        Sanctum::actingAs($this->admin());

        $cat = CategoriaSubevento::query()->create([
            'nombre' => 'Inactiva',
            'slug' => 'inactiva',
            'orden' => 9,
            'estado' => false,
            'maneja_puntos' => true,
            'maneja_fecha_inicio' => false,
            'maneja_fecha_fin' => false,
        ]);

        $this->getJson('/api/v1/events/categorias-subevento')
            ->assertOk()
            ->assertJsonMissing(['id' => $cat->id]);

        $this->getJson('/api/v1/events/categorias-subevento?todos=1')
            ->assertOk()
            ->assertJsonFragment(['id' => $cat->id, 'estado' => false]);
    }

    public function test_cannot_delete_system_category(): void
    {
        Sanctum::actingAs($this->admin());

        $cat = CategoriaSubevento::query()->create([
            'nombre' => 'Especialidades',
            'slug' => 'especialidades-sistema',
            'orden' => 1,
            'estado' => true,
            'es_sistema' => true,
            'maneja_puntos' => true,
            'maneja_fecha_inicio' => false,
            'maneja_fecha_fin' => false,
        ]);

        $this->deleteJson("/api/v1/events/categorias-subevento/{$cat->id}")
            ->assertStatus(422);

        $this->assertDatabaseHas('categoria_subevento', ['id' => $cat->id]);
    }
}
