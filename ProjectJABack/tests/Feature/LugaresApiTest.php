<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Cabanas\Models\Cabana;
use App\Modules\Events\Models\Event;
use App\Modules\Lugares\Models\Lugar;
use App\Modules\Organizations\Models\Organizacion;
use App\Modules\Terrains\Models\ConfiguracionTerreno;
use App\Modules\Terrains\Models\Terreno;
use Database\Seeders\OrganizacionCatalogSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class LugaresApiTest extends TestCase
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
        $user = User::factory()->create(['email' => 'lugares-admin@test.local']);
        $user->forceFill(['is_admin' => true, 'is_super' => true])->save();
        $user->clearPermissionCache();

        return $user;
    }

    public function test_admin_can_crud_lugar_and_filter_catalogs(): void
    {
        Sanctum::actingAs($this->admin());

        $created = $this->postJson('/api/v1/lugares', [
            'nombre' => 'Finca El Roble',
            'descripcion' => 'Sede camporee',
            'latitud' => 4.71,
            'longitud' => -74.07,
            'nivel_zoom' => 15,
        ]);
        $created->assertCreated()->assertJsonPath('data.nombre', 'Finca El Roble');
        $lugarId = (int) $created->json('data.id');

        $other = $this->postJson('/api/v1/lugares', ['nombre' => 'Otro predio'])->assertCreated();
        $otherId = (int) $other->json('data.id');

        $this->postJson('/api/v1/terrenos', [
            'lugar_id' => $lugarId,
            'nombre' => 'Terreno norte',
        ])->assertCreated();

        $this->postJson('/api/v1/cabanas', [
            'lugar_id' => $lugarId,
            'nombre' => 'Cabaña 1',
        ])->assertCreated();

        $this->getJson('/api/v1/terrenos?lugar_id='.$lugarId)
            ->assertOk()
            ->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/terrenos?lugar_id='.$otherId)
            ->assertOk()
            ->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/cabanas?lugar_id='.$lugarId)
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->putJson("/api/v1/lugares/{$lugarId}", ['nombre' => 'Finca actualizada'])
            ->assertOk()
            ->assertJsonPath('data.nombre', 'Finca actualizada');
    }

    public function test_lugar_can_assign_and_reparent_catalogs(): void
    {
        Sanctum::actingAs($this->admin());

        $origen = Lugar::query()->create(['nombre' => 'Origen', 'estado' => 'activo']);
        $destino = Lugar::query()->create(['nombre' => 'Destino', 'estado' => 'activo']);
        $terreno = Terreno::query()->create([
            'lugar_id' => $origen->id,
            'nombre' => 'Lote A',
            'metros_por_persona' => 10,
            'estado' => Terreno::ESTADO_ACTIVO,
        ]);
        $cabana = Cabana::query()->create([
            'lugar_id' => $origen->id,
            'nombre' => 'Cabaña A',
            'estado' => 'activa',
        ]);

        $this->putJson("/api/v1/lugares/{$destino->id}", [
            'nombre' => 'Destino',
            'terreno_ids' => [$terreno->id],
            'cabana_ids' => [$cabana->id],
        ])->assertOk()
            ->assertJsonPath('data.terreno_ids.0', $terreno->id)
            ->assertJsonPath('data.cabana_ids.0', $cabana->id);

        $this->assertSame($destino->id, Terreno::query()->find($terreno->id)?->lugar_id);
        $this->assertSame($destino->id, Cabana::query()->find($cabana->id)?->lugar_id);

        $this->putJson("/api/v1/lugares/{$destino->id}", [
            'nombre' => 'Destino',
            'terreno_ids' => [],
            'cabana_ids' => [],
        ])->assertOk()
            ->assertJsonPath('data.terreno_ids', [])
            ->assertJsonPath('data.cabana_ids', []);

        $this->assertNull(Terreno::query()->find($terreno->id)?->lugar_id);
        $this->assertNull(Cabana::query()->find($cabana->id)?->lugar_id);
    }

    public function test_terreno_and_cabana_require_lugar(): void
    {
        Sanctum::actingAs($this->admin());

        $this->postJson('/api/v1/terrenos', ['nombre' => 'Sin lugar'])->assertUnprocessable();
        $this->postJson('/api/v1/cabanas', ['nombre' => 'Sin lugar'])->assertUnprocessable();
    }

    public function test_event_copies_lugar_name_and_validates_attach(): void
    {
        Sanctum::actingAs($this->admin());

        $lugar = Lugar::query()->create([
            'nombre' => 'Campo JA',
            'estado' => 'activo',
            'latitud' => 5.1,
            'longitud' => -75.5,
            'nivel_zoom' => 14,
        ]);
        $otro = Lugar::query()->create(['nombre' => 'Otro', 'estado' => 'activo']);

        $union = Organizacion::query()->create([
            'organizacion_padre_id' => null,
            'tipo_organizacion_id' => Organizacion::TIPO_UNION,
            'nombre' => 'Unión Lugares',
            'codigo' => 'UNLUG',
            'estado' => true,
        ]);

        $event = $this->postJson('/api/v1/events', [
            'name' => 'Camporee',
            'starts_at' => '2026-08-01 08:00:00',
            'ends_at' => '2026-08-03 18:00:00',
            'organizacion_id' => $union->id,
            'organizacion_ids' => [$union->id],
            'lugar_id' => $lugar->id,
            'usar_lotes' => true,
            'usar_cabanas' => true,
        ])->assertCreated();

        $this->assertSame('Campo JA', $event->json('data.lugar'));
        $this->assertTrue((bool) $event->json('data.usar_lotes'));
        $eventId = (int) $event->json('data.id');

        $terrenoOk = Terreno::query()->create([
            'lugar_id' => $lugar->id,
            'nombre' => 'Terreno ok',
            'metros_por_persona' => 10,
            'estado' => Terreno::ESTADO_ACTIVO,
        ]);
        $configOk = ConfiguracionTerreno::query()->create([
            'terreno_id' => $terrenoOk->id,
            'nombre' => 'Base',
            'es_default' => true,
            'orden' => 1,
            'estado' => 'activo',
        ]);
        $terrenoOtro = Terreno::query()->create([
            'lugar_id' => $otro->id,
            'nombre' => 'Terreno ajeno',
            'metros_por_persona' => 10,
            'estado' => Terreno::ESTADO_ACTIVO,
        ]);
        $configOtro = ConfiguracionTerreno::query()->create([
            'terreno_id' => $terrenoOtro->id,
            'nombre' => 'Base',
            'es_default' => true,
            'orden' => 1,
            'estado' => 'activo',
        ]);

        $this->postJson("/api/v1/events/{$eventId}/distribucion", [
            'terreno_id' => $terrenoOtro->id,
            'configuracion_terreno_id' => $configOtro->id,
        ])->assertUnprocessable();

        $this->postJson("/api/v1/events/{$eventId}/distribucion", [
            'terreno_id' => $terrenoOk->id,
            'configuracion_terreno_id' => $configOk->id,
        ])->assertCreated();

        $cabanaAjena = Cabana::query()->create([
            'lugar_id' => $otro->id,
            'nombre' => 'Cabaña ajena',
            'estado' => 'activa',
        ]);
        $cabanaOk = Cabana::query()->create([
            'lugar_id' => $lugar->id,
            'nombre' => 'Cabaña ok',
            'estado' => 'activa',
        ]);

        $this->postJson("/api/v1/events/{$eventId}/cabanas", ['cabana_id' => $cabanaAjena->id])
            ->assertUnprocessable();
        $this->postJson("/api/v1/events/{$eventId}/cabanas", ['cabana_id' => $cabanaOk->id])
            ->assertCreated();

        $this->putJson("/api/v1/events/{$eventId}", [
            'usar_cabanas' => false,
        ])->assertOk();

        Event::query()->where('id', $eventId)->update(['usar_cabanas' => false]);
        $otraCabana = Cabana::query()->create([
            'lugar_id' => $lugar->id,
            'nombre' => 'Cabaña extra',
            'estado' => 'activa',
        ]);
        $this->postJson("/api/v1/events/{$eventId}/cabanas", ['cabana_id' => $otraCabana->id])
            ->assertUnprocessable();
    }

    public function test_cannot_delete_lugar_with_catalog_items(): void
    {
        Sanctum::actingAs($this->admin());
        $lugar = Lugar::query()->create(['nombre' => 'Ocupado', 'estado' => 'activo']);
        Terreno::query()->create([
            'lugar_id' => $lugar->id,
            'nombre' => 'Ocupa',
            'metros_por_persona' => 10,
            'estado' => Terreno::ESTADO_ACTIVO,
        ]);

        $this->deleteJson("/api/v1/lugares/{$lugar->id}")->assertUnprocessable();
    }
}
