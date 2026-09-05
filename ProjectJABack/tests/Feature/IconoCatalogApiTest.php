<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Events\Models\Icono;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class IconoCatalogApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    private function admin(): User
    {
        $user = User::factory()->create(['email' => 'icon-admin@test.local']);
        $user->forceFill(['is_admin' => true, 'is_super' => true])->save();
        $user->clearPermissionCache();

        return $user;
    }

    public function test_admin_can_crud_prime_icon(): void
    {
        Sanctum::actingAs($this->admin());

        $created = $this->postJson('/api/v1/events/iconos', [
            'nombre' => 'Bandera de honor',
            'categoria' => 'clubes',
            'etiquetas' => ['honor', 'bandera'],
            'tipo' => 'prime',
            'valor' => 'pi pi-flag',
        ])->assertCreated()
            ->assertJsonPath('data.nombre', 'Bandera de honor')
            ->assertJsonPath('data.categoria', 'clubes')
            ->assertJsonPath('data.valor', 'pi pi-flag');

        $id = (int) $created->json('data.id');

        $this->patchJson("/api/v1/events/iconos/{$id}", [
            'nombre' => 'Bandera club',
            'etiquetas' => 'club, bandera',
        ])->assertOk()
            ->assertJsonPath('data.nombre', 'Bandera club');

        $this->getJson('/api/v1/events/iconos?q=bandera')
            ->assertOk()
            ->assertJsonFragment(['id' => $id]);

        $this->deleteJson("/api/v1/events/iconos/{$id}")->assertOk();
        $this->assertDatabaseMissing('iconos', ['id' => $id]);
    }

    public function test_can_upload_gif_icon(): void
    {
        Storage::fake('public');
        Sanctum::actingAs($this->admin());

        $file = UploadedFile::fake()->create('fuego.gif', 20, 'image/gif');

        $this->post('/api/v1/events/iconos', [
            'nombre' => 'Fuego animado',
            'categoria' => 'personalizado',
            'etiquetas' => 'fogata,gif',
            'archivo' => $file,
        ], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('data.tipo', 'imagen')
            ->assertJsonPath('data.nombre', 'Fuego animado');

        $this->assertDatabaseHas('iconos', ['nombre' => 'Fuego animado', 'tipo' => 'imagen']);
    }

    public function test_cannot_delete_system_icon(): void
    {
        Sanctum::actingAs($this->admin());

        $icono = Icono::query()->create([
            'nombre' => 'Calendario',
            'slug' => 'eventos-calendario',
            'categoria' => 'eventos',
            'etiquetas' => ['evento'],
            'tipo' => 'prime',
            'valor' => 'pi pi-calendar',
            'es_sistema' => true,
            'estado' => true,
        ]);

        $this->deleteJson("/api/v1/events/iconos/{$icono->id}")->assertStatus(422);
        $this->assertDatabaseHas('iconos', ['id' => $icono->id]);
    }
}
