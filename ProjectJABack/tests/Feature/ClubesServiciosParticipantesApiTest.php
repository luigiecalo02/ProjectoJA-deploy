<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Clubs\Models\Persona;
use App\Modules\Events\Models\Event;
use App\Modules\Events\Models\ProductoServicio;
use App\Modules\Events\Models\TipoEvento;
use App\Modules\Organizations\Models\Organizacion;
use App\Modules\Organizations\Models\PersonaOrganizacion;
use App\Modules\Organizations\Models\PersonaOrganizacionRol;
use App\Modules\Users\Models\Role;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ClubesServiciosParticipantesApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->ensureClubTipo();
        $this->ensureEconomicTipo();
    }

    public function test_director_manages_club_services_scoped_to_organization(): void
    {
        $mine = $this->createClubOrg('Club Servicios');
        $other = $this->createClubOrg('Club Ajeno Servicios');
        $director = $this->boardUser('director', $mine, 'dir-serv@test.local');
        $otherDirector = $this->boardUser('director', $other, 'dir-serv-ajeno@test.local');

        Sanctum::actingAs($director);
        $created = $this->postJson('/api/v1/settings/clubes/servicios', [
            'nombre' => 'Venta de empanadas',
            'descripcion' => 'Unidad',
            'precio' => 3500,
            'activo' => true,
        ], [
            'X-Clubes-Client' => 'clubes',
        ])->assertCreated()->json('data');

        $this->assertSame($mine->id, $created['organizacion_id']);
        $this->assertDatabaseHas('productos_servicios', [
            'id' => $created['id'],
            'organizacion_id' => $mine->id,
            'nombre' => 'Venta de empanadas',
        ]);

        $listed = $this->getJson('/api/v1/settings/clubes/servicios', [
            'X-Clubes-Client' => 'clubes',
        ])->assertOk()->json('data');
        $this->assertCount(1, $listed);
        $this->assertSame($created['id'], $listed[0]['id']);

        Sanctum::actingAs($director);
        $iconos = $this->getJson('/api/v1/settings/clubes/servicios/iconos', [
            'X-Clubes-Client' => 'clubes',
        ])->assertOk()->json('data');
        $this->assertNotEmpty($iconos);
        $this->assertArrayHasKey('nombre', $iconos[0]);
        $this->assertArrayHasKey('valor', $iconos[0]);

        Sanctum::actingAs($otherDirector);
        $foreign = $this->getJson('/api/v1/settings/clubes/servicios', [
            'X-Clubes-Client' => 'clubes',
        ])->assertOk()->json('data');
        $this->assertSame([], $foreign);

        $this->putJson('/api/v1/settings/clubes/servicios/'.$created['id'], [
            'nombre' => 'Intento ajeno',
        ], [
            'X-Clubes-Client' => 'clubes',
        ])->assertNotFound();
    }

    public function test_economic_event_can_sync_own_services_only(): void
    {
        $org = $this->createClubOrg('Club Económico');
        $other = $this->createClubOrg('Club Otro Catálogo');
        $director = $this->boardUser('director', $org, 'dir-eco@test.local');
        $this->boardUser('director', $other, 'dir-eco-otro@test.local');

        $own = ProductoServicio::query()->create([
            'organizacion_id' => $org->id,
            'nombre' => 'Arepas',
            'tipo' => ProductoServicio::TIPO_SERVICIO,
            'precio' => 2000,
            'activo' => true,
        ]);
        $foreign = ProductoServicio::query()->create([
            'organizacion_id' => $other->id,
            'nombre' => 'Ajeno',
            'tipo' => ProductoServicio::TIPO_SERVICIO,
            'precio' => 1000,
            'activo' => true,
        ]);

        Sanctum::actingAs($director);
        $regularId = $this->postJson('/api/v1/settings/clubes/events', $this->eventPayload('Reunión'), [
            'X-Clubes-Client' => 'clubes',
        ])->assertCreated()->json('data.id');

        $this->putJson("/api/v1/settings/clubes/events/{$regularId}/servicios", [
            'producto_servicio_ids' => [$own->id],
        ], [
            'X-Clubes-Client' => 'clubes',
        ])->assertUnprocessable();

        $economicId = $this->postJson('/api/v1/settings/clubes/events', $this->eventPayload('Venta', $this->economicTipoId()), [
            'X-Clubes-Client' => 'clubes',
        ])->assertCreated()->json('data.id');

        $this->putJson("/api/v1/settings/clubes/events/{$economicId}/servicios", [
            'producto_servicio_ids' => [$foreign->id],
        ], [
            'X-Clubes-Client' => 'clubes',
        ])->assertUnprocessable();

        $this->putJson("/api/v1/settings/clubes/events/{$economicId}/servicios", [
            'producto_servicio_ids' => [$own->id],
        ], [
            'X-Clubes-Client' => 'clubes',
        ])->assertOk()
            ->assertJsonPath('data.ofertas.0.producto_servicio_id', $own->id);

        $this->assertDatabaseHas('evento_producto_servicio', [
            'evento_id' => $economicId,
            'producto_servicio_id' => $own->id,
            'activo' => 1,
        ]);
    }

    public function test_director_marks_participants_only_on_economic_events(): void
    {
        $org = $this->createClubOrg('Club Participantes');
        $director = $this->boardUser('director', $org, 'dir-part@test.local');
        $member = $this->member($org, 'int-part@test.local');

        Sanctum::actingAs($director);
        $regularId = $this->postJson('/api/v1/settings/clubes/events', $this->eventPayload('Clase'), [
            'X-Clubes-Client' => 'clubes',
        ])->assertCreated()->json('data.id');

        $this->getJson("/api/v1/settings/clubes/events/{$regularId}/participantes", [
            'X-Clubes-Client' => 'clubes',
        ])->assertUnprocessable();

        $economicId = $this->postJson('/api/v1/settings/clubes/events', $this->eventPayload('Bazar', $this->economicTipoId()), [
            'X-Clubes-Client' => 'clubes',
        ])->assertCreated()->json('data.id');

        $this->getJson("/api/v1/settings/clubes/events/{$economicId}/participantes", [
            'X-Clubes-Client' => 'clubes',
        ])->assertOk()
            ->assertJsonPath('data.resumen.total', 2);

        $this->putJson("/api/v1/settings/clubes/events/{$economicId}/participantes", [
            'persona_ids' => [$member->id],
        ], [
            'X-Clubes-Client' => 'clubes',
        ])->assertOk()
            ->assertJsonPath('data.resumen.participan', 1)
            ->assertJsonPath('data.resumen.no_participan', 1);

        $this->assertDatabaseHas('evento_participacion', [
            'evento_id' => $economicId,
            'organizacion_id' => $org->id,
            'persona_id' => $member->id,
            'participa' => 1,
        ]);
        $this->assertDatabaseHas('evento_participacion', [
            'evento_id' => $economicId,
            'persona_id' => $director->persona_id,
            'participa' => 0,
        ]);

        $servicio = ProductoServicio::query()->create([
            'organizacion_id' => $org->id,
            'nombre' => 'Empanadas',
            'tipo' => ProductoServicio::TIPO_SERVICIO,
            'precio' => 2500,
            'activo' => true,
        ]);
        $this->putJson("/api/v1/settings/clubes/events/{$economicId}/servicios", [
            'producto_servicio_ids' => [$servicio->id],
        ], [
            'X-Clubes-Client' => 'clubes',
        ])->assertOk();

        $this->putJson("/api/v1/settings/clubes/events/{$economicId}/participantes", [
            'participantes' => [
                [
                    'persona_id' => $member->id,
                    'participa' => true,
                    'ventas' => [
                        ['producto_servicio_id' => $servicio->id, 'cantidad' => 12],
                    ],
                ],
                ['persona_id' => $director->persona_id, 'participa' => false, 'ventas' => []],
            ],
        ], [
            'X-Clubes-Client' => 'clubes',
        ])->assertOk()
            ->assertJsonPath('data.resumen.unidades', 12)
            ->assertJsonFragment([
                'persona_id' => $member->id,
                'participa' => true,
            ]);

        $this->assertDatabaseHas('evento_participacion_venta', [
            'producto_servicio_id' => $servicio->id,
            'cantidad' => 12,
        ]);

        $attendanceEvents = $this->getJson('/api/v1/settings/clubes/asistencia/eventos', [
            'X-Clubes-Client' => 'clubes',
        ])->assertOk()->json('data');
        $this->assertFalse(collect($attendanceEvents)->contains(fn ($row) => (int) $row['id'] === (int) $economicId));
        $this->assertTrue(collect($attendanceEvents)->contains(fn ($row) => (int) $row['id'] === (int) $regularId));

        $this->putJson("/api/v1/settings/clubes/asistencia/{$economicId}", [
            'persona_ids' => [$member->id],
        ], [
            'X-Clubes-Client' => 'clubes',
        ])->assertUnprocessable();
    }

    public function test_member_joins_economic_event_with_own_quantities(): void
    {
        $org = $this->createClubOrg('Club Autoinscripción');
        $director = $this->boardUser('director', $org, 'dir-join@test.local');
        $memberUser = $this->memberUser($org, 'mem-join@test.local');
        $other = $this->member($org, 'otro-join@test.local');

        Sanctum::actingAs($director);
        $economicId = $this->postJson('/api/v1/settings/clubes/events', $this->eventPayload('Venta de pescado', $this->economicTipoId()), [
            'X-Clubes-Client' => 'clubes',
        ])->assertCreated()->json('data.id');

        $servicio = ProductoServicio::query()->create([
            'organizacion_id' => $org->id,
            'nombre' => 'Pescado',
            'tipo' => ProductoServicio::TIPO_SERVICIO,
            'precio' => 8000,
            'activo' => true,
        ]);
        $this->putJson("/api/v1/settings/clubes/events/{$economicId}/servicios", [
            'producto_servicio_ids' => [$servicio->id],
        ], [
            'X-Clubes-Client' => 'clubes',
        ])->assertOk();

        Sanctum::actingAs($memberUser);
        $this->putJson("/api/v1/settings/clubes/events/{$economicId}/participantes", [
            'participantes' => [
                ['persona_id' => $other->id, 'participa' => true, 'ventas' => []],
            ],
        ], [
            'X-Clubes-Client' => 'clubes',
        ])->assertForbidden();

        $this->putJson("/api/v1/settings/clubes/events/{$economicId}/participantes/yo", [
            'participa' => true,
            'ventas' => [
                ['producto_servicio_id' => $servicio->id, 'cantidad' => 7],
            ],
        ], [
            'X-Clubes-Client' => 'clubes',
        ])->assertOk()
            ->assertJsonPath('data.integrante.persona_id', $memberUser->persona_id)
            ->assertJsonPath('data.integrante.participa', true)
            ->assertJsonPath('data.integrante.ventas.0.cantidad', 7);

        $this->assertDatabaseHas('evento_participacion', [
            'evento_id' => $economicId,
            'organizacion_id' => $org->id,
            'persona_id' => $memberUser->persona_id,
            'participa' => 1,
        ]);
        $this->assertDatabaseHas('evento_participacion_venta', [
            'producto_servicio_id' => $servicio->id,
            'cantidad' => 7,
        ]);
        $this->assertDatabaseMissing('evento_participacion', [
            'evento_id' => $economicId,
            'persona_id' => $other->id,
        ]);

        Sanctum::actingAs($director);
        $this->getJson("/api/v1/settings/clubes/events/{$economicId}/participantes", [
            'X-Clubes-Client' => 'clubes',
        ])->assertOk()
            ->assertJsonPath('data.resumen.participan', 1)
            ->assertJsonPath('data.resumen.unidades', 7);

        $this->postJson('/api/v1/settings/clubes/abonos', [
            'evento_id' => $economicId,
            'persona_id' => $memberUser->persona_id,
            'monto' => 20000,
            'nota' => 'Primera entrega',
            'modo' => 'integrante',
        ], [
            'X-Clubes-Client' => 'clubes',
        ])->assertOk()
            ->assertJsonPath('data.resumen.abonado', 20000)
            ->assertJsonPath('data.filas.0.pendiente', 36000);

        $this->assertDatabaseHas('evento_participacion_abono', [
            'evento_id' => $economicId,
            'persona_id' => $memberUser->persona_id,
            'monto' => 20000,
        ]);

        $this->getJson('/api/v1/settings/clubes/abonos?modo=actividad&evento_id='.$economicId, [
            'X-Clubes-Client' => 'clubes',
        ])->assertOk()
            ->assertJsonPath('data.filas.0.abonado', 20000)
            ->assertJsonPath('data.filas.0.pedidos.0.nombre', 'Pescado')
            ->assertJsonPath('data.filas.0.pedidos.0.cantidad', 7);
    }

    public function test_nobody_joins_economic_event_once_in_progress(): void
    {
        $org = $this->createClubOrg('Club En Curso');
        $director = $this->boardUser('director', $org, 'dir-curso@test.local');
        $memberUser = $this->memberUser($org, 'mem-curso@test.local');

        Sanctum::actingAs($director);
        $economicId = $this->postJson('/api/v1/settings/clubes/events', $this->eventPayload('Venta en curso', $this->economicTipoId()), [
            'X-Clubes-Client' => 'clubes',
        ])->assertCreated()->json('data.id');

        Event::query()->whereKey($economicId)->update(['estado' => Event::ESTADO_EN_PROCESO]);

        Sanctum::actingAs($memberUser);
        $this->putJson("/api/v1/settings/clubes/events/{$economicId}/participantes/yo", [
            'participa' => true,
            'ventas' => [],
        ], [
            'X-Clubes-Client' => 'clubes',
        ])->assertUnprocessable();

        Sanctum::actingAs($director);
        $this->putJson("/api/v1/settings/clubes/events/{$economicId}/participantes", [
            'participantes' => [
                ['persona_id' => $memberUser->persona_id, 'participa' => true, 'ventas' => []],
            ],
        ], [
            'X-Clubes-Client' => 'clubes',
        ])->assertUnprocessable();

        $this->assertDatabaseMissing('evento_participacion', [
            'evento_id' => $economicId,
            'persona_id' => $memberUser->persona_id,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function eventPayload(string $name, ?int $tipoEventoId = null): array
    {
        $payload = [
            'name' => $name,
            'starts_at' => now()->addDay()->toDateTimeString(),
            'ends_at' => now()->addDays(2)->toDateTimeString(),
        ];
        if ($tipoEventoId) {
            $payload['tipo_evento_id'] = $tipoEventoId;
        }

        return $payload;
    }

    private function economicTipoId(): int
    {
        return (int) DB::table('tipo_evento')->where('slug', TipoEvento::SLUG_ACTIVIDAD_ECONOMICA)->value('id');
    }

    private function ensureEconomicTipo(): void
    {
        if (DB::table('tipo_evento')->where('slug', TipoEvento::SLUG_ACTIVIDAD_ECONOMICA)->exists()) {
            return;
        }

        DB::table('tipo_evento')->insert([
            'nombre' => 'Actividad Económica',
            'slug' => TipoEvento::SLUG_ACTIVIDAD_ECONOMICA,
            'descripcion' => 'Recaudo',
            'color' => '#b45309',
            'icono' => 'pi pi-wallet',
            'orden' => 10,
            'estado' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
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

    private function boardUser(string $roleName, Organizacion $org, string $email): User
    {
        $persona = $this->createPersona($email, ucfirst($roleName));
        $this->attachMember($persona, $org, $roleName);

        $roleId = (int) Role::query()->where('name', $roleName)->value('id');
        $user = User::factory()->create([
            'email' => $email,
            'persona_id' => $persona->id,
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

    private function memberUser(Organizacion $org, string $email): User
    {
        $persona = $this->createPersona($email, 'Integrante');
        $this->attachMember($persona, $org, 'miembro');

        $roleId = (int) Role::query()->where('name', 'miembro')->value('id');
        $user = User::factory()->create([
            'email' => $email,
            'persona_id' => $persona->id,
        ]);
        $user->forceFill([
            'active_organizacion_id' => $org->id,
            'active_rol_id' => $roleId,
        ])->save();
        $user->clearPermissionCache();

        return $user->fresh();
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
