<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Events\Models\Event;
use App\Modules\Organizations\Models\Organizacion;
use App\Modules\Settings\Models\CuentaBancaria;
use Database\Seeders\OrganizacionCatalogSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CuentasBancariasApiTest extends TestCase
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
        $user = User::factory()->create(['email' => 'cuentas-admin@test.local']);
        $user->forceFill(['is_admin' => true, 'is_super' => true])->save();
        $user->clearPermissionCache();

        return $user;
    }

    public function test_admin_can_create_and_assign_bank_account_to_event(): void
    {
        Storage::fake('public');
        Sanctum::actingAs($this->admin());

        $created = $this->postJson('/api/v1/settings/cuentas-bancarias', [
            'nombre' => 'Cuenta camporee',
            'banco' => 'Bancolombia',
            'tipo_cuenta' => 'ahorros',
            'numero_cuenta' => '123456789',
            'titular' => 'Asociación JA',
            'identificacion_titular' => '900123456',
        ]);

        $created->assertCreated()
            ->assertJsonPath('data.nombre', 'Cuenta camporee')
            ->assertJsonPath('data.tipo_cuenta', 'ahorros')
            ->assertJsonPath('data.numero_cuenta', '123456789');

        $cuentaId = (int) $created->json('data.id');
        $qr = UploadedFile::fake()->image('qr.jpg', 400, 400);
        $this->post("/api/v1/settings/cuentas-bancarias/{$cuentaId}/qr", [
            'qr' => $qr,
        ], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('data.id', $cuentaId);

        $this->assertNotNull($created->json('data.id'));
        $this->assertNotNull(CuentaBancaria::query()->find($cuentaId)?->qr_file_id);

        $union = Organizacion::query()->create([
            'organizacion_padre_id' => null,
            'tipo_organizacion_id' => Organizacion::TIPO_UNION,
            'nombre' => 'Unión Cuentas',
            'codigo' => 'UNCTAS',
            'estado' => true,
        ]);

        $event = $this->postJson('/api/v1/events', [
            'name' => 'Evento con pago',
            'starts_at' => '2026-08-01 08:00:00',
            'ends_at' => '2026-08-03 18:00:00',
            'organizacion_id' => $union->id,
            'organizacion_ids' => [$union->id],
            'requiere_pago' => true,
            'cuenta_bancaria_id' => $cuentaId,
        ]);

        $event->assertCreated()
            ->assertJsonPath('data.cuenta_bancaria_id', $cuentaId)
            ->assertJsonPath('data.cuenta_bancaria.numero_cuenta', '123456789');

        $this->assertDatabaseHas('events', [
            'id' => $event->json('data.id'),
            'cuenta_bancaria_id' => $cuentaId,
        ]);
        $this->assertInstanceOf(Event::class, Event::query()->find($event->json('data.id')));
    }
}
