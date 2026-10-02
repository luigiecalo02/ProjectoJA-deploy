<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Clubs\Models\Persona;
use App\Modules\Events\Models\EventoInscripcion;
use App\Modules\Events\Models\EventoInscripcionPersona;
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

class ClubesPresupuestoApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->ensureClubTipo();
        $this->ensureTipo(TipoEvento::SLUG_CAMPAMENTO, 'Campamento');
        $this->ensureTipo(TipoEvento::SLUG_ACTIVIDAD_ECONOMICA, 'Actividad Económica');
    }

    public function test_director_can_save_campamento_budget_and_calculate_totals(): void
    {
        $org = $this->createClubOrg('Club Presupuesto');
        $director = $this->boardUser('director', $org, 'dir-pres@test.local');

        Sanctum::actingAs($director);
        $campamentoId = $this->createEvent($director, 'Campamento de verano', $this->campamentoTipoId());
        $this->inscribirPersonas($campamentoId, $org->id, EventoInscripcionPersona::TIPO_MIEMBRO, 12);

        $listed = $this->getJson('/api/v1/settings/clubes/presupuesto/eventos', [
            'X-Clubes-Client' => 'clubes',
        ])->assertOk()->json('data');
        $this->assertSame($campamentoId, $listed[0]['id']);
        $this->assertFalse($listed[0]['tiene_presupuesto']);
        $this->assertSame(12, $listed[0]['miembros_count']);

        $saved = $this->putJson("/api/v1/settings/clubes/presupuesto/{$campamentoId}", [
            'acompanantes_count' => 4,
            'items' => [
                [
                    'concepto' => 'Inscripción',
                    'tipo' => 'individual',
                    'monto' => 85000,
                    'destinatario' => 'miembros',
                ],
                [
                    'concepto' => 'Transporte',
                    'tipo' => 'grupal',
                    'monto' => 800000,
                    'destinatario' => 'miembros',
                ],
                [
                    'concepto' => 'Alimentación',
                    'tipo' => 'individual',
                    'monto' => 40000,
                    'destinatario' => 'acompanantes',
                ],
            ],
        ], [
            'X-Clubes-Client' => 'clubes',
        ])->assertOk()->json('data');

        $this->assertSame(12, $saved['miembros_count']);
        $this->assertSame(4, $saved['cantidades']['acompanantes']);
        $this->assertEquals(1020000, $saved['bloques']['miembros']['items'][0]['monto_total']);
        $this->assertEquals(85000, $saved['bloques']['miembros']['items'][0]['por_persona']);
        $this->assertEquals(800000, $saved['bloques']['miembros']['items'][1]['monto_total']);
        $this->assertEquals(66666.67, $saved['bloques']['miembros']['items'][1]['por_persona']);
        $this->assertEquals(151666.67, $saved['bloques']['miembros']['total_por_persona']);
        $this->assertEquals(1820000, $saved['bloques']['miembros']['total']);
        $this->assertEquals(160000, $saved['bloques']['acompanantes']['total']);
        $this->assertEquals(40000, $saved['bloques']['acompanantes']['total_por_persona']);

        $this->assertDatabaseHas('clubes_evento_presupuestos', [
            'evento_id' => $campamentoId,
            'organizacion_id' => $org->id,
            'acompanantes_count' => 4,
        ]);
        $this->assertTrue($saved['activo']);
        $this->assertCount(1, $saved['presupuestos']);
        $this->assertDatabaseCount('clubes_evento_presupuesto_items', 3);
    }

    public function test_event_can_have_many_budgets_but_only_one_active(): void
    {
        $org = $this->createClubOrg('Club Varios Presupuestos');
        $director = $this->boardUser('director', $org, 'dir-varios@test.local');

        Sanctum::actingAs($director);
        $campamentoId = $this->createEvent($director, 'Campamento opciones', $this->campamentoTipoId());

        $primero = $this->putJson("/api/v1/settings/clubes/presupuesto/{$campamentoId}", [
            'nombre' => 'Opción económica',
            'acompanantes_count' => 0,
            'items' => [
                ['concepto' => 'Inscripción', 'tipo' => 'individual', 'monto' => 50000, 'destinatario' => 'miembros'],
            ],
        ], ['X-Clubes-Client' => 'clubes'])->assertOk()->json('data');
        $this->assertTrue($primero['activo']);
        $idA = (int) $primero['presupuesto_id'];

        $segundo = $this->putJson("/api/v1/settings/clubes/presupuesto/{$campamentoId}", [
            'nombre' => 'Opción completa',
            'acompanantes_count' => 2,
            'items' => [
                ['concepto' => 'Inscripción', 'tipo' => 'individual', 'monto' => 90000, 'destinatario' => 'miembros'],
            ],
        ], ['X-Clubes-Client' => 'clubes'])->assertOk()->json('data');
        $this->assertFalse($segundo['activo']);
        $this->assertSame('Opción completa', $segundo['nombre']);
        $idB = (int) $segundo['presupuesto_id'];
        $this->assertNotSame($idA, $idB);
        $this->assertCount(2, $segundo['presupuestos']);

        $this->getJson("/api/v1/settings/clubes/presupuesto/{$campamentoId}", [
            'X-Clubes-Client' => 'clubes',
        ])->assertOk()
            ->assertJsonPath('data.presupuesto_id', $idA)
            ->assertJsonPath('data.activo', true);

        $this->getJson("/api/v1/settings/clubes/presupuesto/{$campamentoId}?presupuesto_id={$idB}", [
            'X-Clubes-Client' => 'clubes',
        ])->assertOk()
            ->assertJsonPath('data.presupuesto_id', $idB)
            ->assertJsonPath('data.activo', false)
            ->assertJsonPath('data.bloques.miembros.items.0.monto', 90000);

        $this->putJson("/api/v1/settings/clubes/presupuesto/{$campamentoId}", [
            'presupuesto_id' => $idB,
            'nombre' => 'Opción completa',
            'activo' => true,
            'acompanantes_count' => 2,
            'items' => [
                ['concepto' => 'Inscripción', 'tipo' => 'individual', 'monto' => 90000, 'destinatario' => 'miembros'],
            ],
        ], ['X-Clubes-Client' => 'clubes'])->assertOk()
            ->assertJsonPath('data.activo', true)
            ->assertJsonPath('data.presupuesto_id', $idB);

        $this->assertEquals(1, DB::table('clubes_evento_presupuestos')->where('evento_id', $campamentoId)->where('activo', true)->count());
        $this->assertDatabaseHas('clubes_evento_presupuestos', ['id' => $idB, 'activo' => true]);
        $this->assertDatabaseHas('clubes_evento_presupuestos', ['id' => $idA, 'activo' => false]);
    }

    public function test_rejects_economic_activity_and_isolates_clubs(): void
    {
        $mine = $this->createClubOrg('Club Propio Presupuesto');
        $other = $this->createClubOrg('Club Ajeno Presupuesto');
        $director = $this->boardUser('director', $mine, 'dir-propio-pres@test.local');
        $otherDirector = $this->boardUser('director', $other, 'dir-ajeno-pres@test.local');

        Sanctum::actingAs($director);
        $campamentoId = $this->createEvent($director, 'Campamento propio', $this->campamentoTipoId());
        $economicaId = $this->createEvent($director, 'Venta de empanadas', $this->economicTipoId());

        $this->putJson("/api/v1/settings/clubes/presupuesto/{$economicaId}", [
            'acompanantes_count' => 0,
            'items' => [
                [
                    'concepto' => 'No aplica',
                    'tipo' => 'individual',
                    'monto' => 1000,
                    'destinatario' => 'miembros',
                ],
            ],
        ], [
            'X-Clubes-Client' => 'clubes',
        ])->assertStatus(422);

        $this->getJson("/api/v1/settings/clubes/presupuesto/{$economicaId}", [
            'X-Clubes-Client' => 'clubes',
        ])->assertNotFound();

        $ids = collect($this->getJson('/api/v1/settings/clubes/presupuesto/eventos', [
            'X-Clubes-Client' => 'clubes',
        ])->assertOk()->json('data'))->pluck('id')->all();
        $this->assertContains($campamentoId, $ids);
        $this->assertNotContains($economicaId, $ids);

        $this->putJson("/api/v1/settings/clubes/presupuesto/{$campamentoId}", [
            'acompanantes_count' => 0,
            'items' => [
                [
                    'concepto' => 'Inscripción',
                    'tipo' => 'individual',
                    'monto' => 10000,
                    'destinatario' => 'miembros',
                ],
            ],
        ], [
            'X-Clubes-Client' => 'clubes',
        ])->assertOk();

        Sanctum::actingAs($otherDirector);
        $this->getJson("/api/v1/settings/clubes/presupuesto/{$campamentoId}", [
            'X-Clubes-Client' => 'clubes',
        ])->assertForbidden();
        $this->putJson("/api/v1/settings/clubes/presupuesto/{$campamentoId}", [
            'acompanantes_count' => 2,
            'items' => [],
        ], [
            'X-Clubes-Client' => 'clubes',
        ])->assertForbidden();
        $otherIds = collect($this->getJson('/api/v1/settings/clubes/presupuesto/eventos', [
            'X-Clubes-Client' => 'clubes',
        ])->assertOk()->json('data'))->pluck('id')->all();
        $this->assertNotContains($campamentoId, $otherIds);
    }

    public function test_member_cannot_write_budget(): void
    {
        $org = $this->createClubOrg('Club Miembro Presupuesto');
        $director = $this->boardUser('director', $org, 'dir-miembro-pres@test.local');
        $member = $this->boardUser('miembro', $org, 'int-pres@test.local');

        Sanctum::actingAs($director);
        $campamentoId = $this->createEvent($director, 'Campamento interno', $this->campamentoTipoId());

        Sanctum::actingAs($member);
        $this->putJson("/api/v1/settings/clubes/presupuesto/{$campamentoId}", [
            'acompanantes_count' => 1,
            'items' => [
                [
                    'concepto' => 'Intento',
                    'tipo' => 'individual',
                    'monto' => 5000,
                    'destinatario' => 'miembros',
                ],
            ],
        ], [
            'X-Clubes-Client' => 'clubes',
        ])->assertForbidden();

        $this->assertDatabaseMissing('clubes_evento_presupuestos', [
            'evento_id' => $campamentoId,
        ]);
    }

    public function test_uses_inscribed_companions_over_planned_count(): void
    {
        $org = $this->createClubOrg('Club Acompañantes');
        $director = $this->boardUser('director', $org, 'dir-acomp@test.local');

        Sanctum::actingAs($director);
        $campamentoId = $this->createEvent($director, 'Campamento familias', $this->campamentoTipoId());
        $this->putJson("/api/v1/settings/clubes/presupuesto/{$campamentoId}", [
            'acompanantes_count' => 8,
            'items' => [
                [
                    'concepto' => 'Transporte',
                    'tipo' => 'grupal',
                    'monto' => 80000,
                    'destinatario' => 'acompanantes',
                ],
            ],
        ], [
            'X-Clubes-Client' => 'clubes',
        ])->assertOk()
            ->assertJsonPath('data.cantidades.acompanantes', 8)
            ->assertJsonPath('data.bloques.acompanantes.items.0.por_persona', 10000);

        $this->inscribirPersonas($campamentoId, $org->id, EventoInscripcionPersona::TIPO_ACOMPANANTE, 2);
        $this->getJson("/api/v1/settings/clubes/presupuesto/{$campamentoId}", [
            'X-Clubes-Client' => 'clubes',
        ])->assertOk()
            ->assertJsonPath('data.acompanantes_inscritos', 2)
            ->assertJsonPath('data.cantidades.acompanantes', 2)
            ->assertJsonPath('data.bloques.acompanantes.items.0.por_persona', 40000);
    }

    public function test_ambos_item_applies_to_members_and_companions(): void
    {
        $org = $this->createClubOrg('Club Ambos');
        $director = $this->boardUser('director', $org, 'dir-ambos@test.local');

        Sanctum::actingAs($director);
        $campamentoId = $this->createEvent($director, 'Campamento mixto', $this->campamentoTipoId());
        $this->inscribirPersonas($campamentoId, $org->id, EventoInscripcionPersona::TIPO_MIEMBRO, 2);

        $saved = $this->putJson("/api/v1/settings/clubes/presupuesto/{$campamentoId}", [
            'acompanantes_count' => 4,
            'items' => [
                [
                    'concepto' => 'Alimentación',
                    'tipo' => 'individual',
                    'monto' => 20000,
                    'destinatario' => 'ambos',
                ],
            ],
        ], [
            'X-Clubes-Client' => 'clubes',
        ])->assertOk()->json('data');

        $this->assertSame('ambos', $saved['bloques']['miembros']['items'][0]['destinatario']);
        $this->assertSame('ambos', $saved['bloques']['acompanantes']['items'][0]['destinatario']);
        $this->assertEquals(40000, $saved['bloques']['miembros']['total']);
        $this->assertEquals(80000, $saved['bloques']['acompanantes']['total']);
        $this->assertDatabaseCount('clubes_evento_presupuesto_items', 1);
    }

    private function createEvent(User $actor, string $name, int $tipoEventoId): int
    {
        Sanctum::actingAs($actor);

        return (int) $this->postJson('/api/v1/settings/clubes/events', [
            'name' => $name,
            'starts_at' => now()->addDay()->toDateTimeString(),
            'ends_at' => now()->addDays(2)->toDateTimeString(),
            'tipo_evento_id' => $tipoEventoId,
        ], [
            'X-Clubes-Client' => 'clubes',
        ])->assertCreated()->json('data.id');
    }

    private function inscribirPersonas(int $eventoId, int $orgId, string $tipo, int $cantidad): void
    {
        $inscripcion = EventoInscripcion::query()->create([
            'evento_id' => $eventoId,
            'tipo' => EventoInscripcion::TIPO_CLUB,
            'organizacion_id' => $orgId,
            'estado' => EventoInscripcion::ESTADO_APROBADA,
        ]);

        for ($i = 0; $i < $cantidad; $i++) {
            EventoInscripcionPersona::query()->create([
                'inscripcion_id' => $inscripcion->id,
                'tipo' => $tipo,
                'nombre_snapshot' => $tipo.' '.$i,
                'estado' => EventoInscripcionPersona::ESTADO_CONFIRMADA,
            ]);
        }
    }

    private function campamentoTipoId(): int
    {
        return (int) DB::table('tipo_evento')->where('slug', TipoEvento::SLUG_CAMPAMENTO)->value('id');
    }

    private function economicTipoId(): int
    {
        return (int) DB::table('tipo_evento')->where('slug', TipoEvento::SLUG_ACTIVIDAD_ECONOMICA)->value('id');
    }

    private function ensureTipo(string $slug, string $nombre): void
    {
        if (DB::table('tipo_evento')->where('slug', $slug)->exists()) {
            return;
        }

        DB::table('tipo_evento')->insert([
            'nombre' => $nombre,
            'slug' => $slug,
            'descripcion' => $nombre,
            'color' => '#b45309',
            'icono' => 'pi pi-flag',
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
        $persona = Persona::query()->create([
            'tipo_identificacion' => 'CC',
            'identificacion' => 'ID'.random_int(100000, 999999).uniqid(),
            'nombre1' => ucfirst($roleName),
            'apellido1' => 'Club',
            'correo' => $email,
        ]);
        $membership = PersonaOrganizacion::query()->create([
            'persona_id' => $persona->id,
            'organizacion_id' => $org->id,
            'fecha_inicio' => now()->toDateString(),
            'estado' => true,
        ]);
        $roleId = (int) Role::query()->where('name', $roleName)->value('id');
        PersonaOrganizacionRol::query()->create([
            'persona_organizacion_id' => $membership->id,
            'rol_id' => $roleId,
        ]);

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
}
