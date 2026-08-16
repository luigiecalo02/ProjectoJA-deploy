<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Cabanas\Models\AsignacionCama;
use App\Modules\Cabanas\Models\EventoCabanaCama;
use App\Modules\Clubs\Models\Persona;
use App\Modules\Events\Models\Event;
use App\Modules\Events\Models\EventoInscripcion;
use App\Modules\Events\Models\EventoInscripcionPersona;
use App\Modules\Events\Models\EventoPago;
use App\Modules\Events\Models\EventoProductoServicio;
use App\Modules\Events\Models\EventoServicioReserva;
use App\Modules\Events\Models\ProductoServicio;
use App\Modules\Users\Models\Role;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CabanasApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_guarda_croquis_y_crea_snapshot_independiente_por_evento(): void
    {
        Sanctum::actingAs($this->admin());
        $cabanaId = $this->createCabanaWithLayout()['cabana_id'];

        $event = $this->createEvent();
        $this->postJson("/api/v1/events/{$event->id}/cabanas", ['cabana_id' => $cabanaId])
            ->assertCreated();

        $this->assertDatabaseHas('evento_cabana_camas', [
            'codigo' => 'A-1', 'capacidad' => 2, 'rotacion' => 90,
        ]);

        $this->putJson("/api/v1/cabanas/{$cabanaId}/croquis", ['pisos' => [[
            'nombre' => 'Piso 1',
            'ancho' => 900,
            'alto' => 600,
            'cuartos' => [[
                'nombre' => 'Cuarto mixto', 'genero' => 'MIXTO',
                'x' => 0, 'y' => 0, 'ancho' => 400, 'alto' => 300, 'capacidad' => 1,
                'camas' => [[
                    'codigo' => 'NUEVA', 'capacidad' => 1, 'x' => 20, 'y' => 30,
                    'ancho' => 80, 'alto' => 190,
                ]],
            ]],
        ]]])->assertOk();

        $this->assertDatabaseHas('evento_cabana_camas', ['codigo' => 'A-1', 'capacidad' => 2]);
        $this->assertDatabaseMissing('evento_cabana_camas', ['codigo' => 'NUEVA']);
    }

    public function test_sync_vincula_y_retira_cabanas_del_evento(): void
    {
        Sanctum::actingAs($this->admin());
        $first = $this->createCabanaWithLayout('Norte');
        $second = $this->createCabanaWithLayout('Sur', 'B-1');
        $event = $this->createEvent();

        $this->putJson("/api/v1/events/{$event->id}/cabanas", [
            'items' => [
                ['cabana_id' => $first['cabana_id'], 'orden' => 1],
                ['cabana_id' => $second['cabana_id'], 'orden' => 2],
            ],
        ])->assertOk()->assertJsonCount(2, 'data.items');

        $this->putJson("/api/v1/events/{$event->id}/cabanas", [
            'items' => [
                ['cabana_id' => $second['cabana_id'], 'orden' => 1],
            ],
        ])->assertOk()->assertJsonCount(1, 'data.items');

        $this->assertDatabaseMissing('evento_cabanas', [
            'evento_id' => $event->id,
            'cabana_id' => $first['cabana_id'],
        ]);
    }

    public function test_invitado_no_puede_administrar_cabanas(): void
    {
        $guest = $this->guestUser($this->createPersona(['sexo' => 'M']));
        Sanctum::actingAs($guest);

        $this->postJson('/api/v1/cabanas', ['nombre' => 'Prohibida'])
            ->assertForbidden();
    }

    public function test_alojamiento_explica_cuando_falta_pago_o_perfil(): void
    {
        Sanctum::actingAs($this->admin());
        $setup = $this->createCabanaWithLayout();
        $event = $this->createEvent();
        $this->postJson("/api/v1/events/{$event->id}/cabanas", ['cabana_id' => $setup['cabana_id']])
            ->assertCreated();

        $persona = $this->createPersona(['sexo' => null]);
        $guest = $this->guestUser($persona);
        Sanctum::actingAs($guest);

        $this->getJson("/api/v1/events/{$event->id}/alojamiento")
            ->assertOk()
            ->assertJsonPath('data.puede_seleccionar', false)
            ->assertJsonPath('data.elegibilidad_codigo', 'sin_sexo');
    }

    public function test_autoasignacion_exige_inscripcion_aprobada_reserva_pagada_y_sexo(): void
    {
        $ready = $this->readyAssignmentContext();
        $camaId = $ready['beds'][0]->id;

        Sanctum::actingAs($this->guestUser($this->createPersona(['sexo' => 'M'])));
        $this->postJson("/api/v1/eventos-cabanas-camas/{$camaId}/autoasignacion")
            ->assertUnprocessable();

        Sanctum::actingAs($ready['user']);
        $this->postJson("/api/v1/eventos-cabanas-camas/{$camaId}/autoasignacion")
            ->assertCreated()
            ->assertJsonPath('data.evento_cabana_cama_id', $camaId);
    }

    public function test_rechaza_cama_de_genero_incompatible(): void
    {
        $ready = $this->readyAssignmentContext(roomGender: 'F', sexo: 'M');
        Sanctum::actingAs($ready['user']);

        $this->postJson("/api/v1/eventos-cabanas-camas/{$ready['beds'][0]->id}/autoasignacion")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['genero']);
    }

    public function test_permite_varias_personas_en_la_misma_cama_hasta_su_capacidad(): void
    {
        $first = $this->readyAssignmentContext(capacidad: 2);
        $second = $this->addEligibleGuest($first['event'], $first['oferta'], 'F');
        $camaId = $first['beds'][0]->id;

        Sanctum::actingAs($first['user']);
        $this->postJson("/api/v1/eventos-cabanas-camas/{$camaId}/autoasignacion")->assertCreated();

        Sanctum::actingAs($second['user']);
        $this->postJson("/api/v1/eventos-cabanas-camas/{$camaId}/autoasignacion")->assertCreated();

        $third = $this->addEligibleGuest($first['event'], $first['oferta'], 'M');
        Sanctum::actingAs($third['user']);
        $this->postJson("/api/v1/eventos-cabanas-camas/{$camaId}/autoasignacion")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['cama']);

        $this->assertSame(2, AsignacionCama::query()->where('evento_cabana_cama_id', $camaId)->where('estado', 'activa')->count());
    }

    public function test_cambiar_de_cama_libera_la_anterior_en_la_misma_transaccion(): void
    {
        $ready = $this->readyAssignmentContext(extraBed: true);
        [$firstBed, $secondBed] = $ready['beds'];
        Sanctum::actingAs($ready['user']);

        $this->postJson("/api/v1/eventos-cabanas-camas/{$firstBed->id}/autoasignacion")->assertCreated();
        $this->postJson("/api/v1/eventos-cabanas-camas/{$secondBed->id}/autoasignacion")->assertCreated();

        $this->assertSame(1, AsignacionCama::query()->where('inscripcion_persona_id', $ready['linea']->id)->where('estado', 'activa')->count());
        $this->assertDatabaseHas('asignaciones_cama', [
            'evento_cabana_cama_id' => $secondBed->id,
            'inscripcion_persona_id' => $ready['linea']->id,
            'estado' => 'activa',
        ]);
        $this->assertDatabaseHas('asignaciones_cama', [
            'evento_cabana_cama_id' => $firstBed->id,
            'estado' => 'liberada',
        ]);
    }

    public function test_cancelar_reserva_libera_la_cama(): void
    {
        $ready = $this->readyAssignmentContext();
        Sanctum::actingAs($ready['user']);
        $this->postJson("/api/v1/eventos-cabanas-camas/{$ready['beds'][0]->id}/autoasignacion")->assertCreated();

        $ready['reserva']->update(['estado' => EventoServicioReserva::ESTADO_CANCELADA]);

        $this->assertDatabaseHas('asignaciones_cama', [
            'evento_servicio_reserva_id' => $ready['reserva']->id,
            'estado' => 'liberada',
        ]);
    }

    public function test_alojamiento_no_expone_datos_de_otros_huespedes(): void
    {
        $ready = $this->readyAssignmentContext();
        Sanctum::actingAs($ready['user']);
        $this->postJson("/api/v1/eventos-cabanas-camas/{$ready['beds'][0]->id}/autoasignacion")->assertCreated();

        $payload = $this->getJson("/api/v1/events/{$ready['event']->id}/alojamiento")
            ->assertOk()
            ->json('data');

        $this->assertArrayNotHasKey('persona_id', $payload['asignacion'] ?? []);
        $encoded = json_encode($payload['cabanas']);
        $this->assertStringNotContainsString($ready['persona']->correo, (string) $encoded);
        $this->assertStringNotContainsString($ready['persona']->identificacion, (string) $encoded);
    }

    private function admin(): User
    {
        return User::factory()->create(['is_super' => true, 'is_admin' => true]);
    }

    private function guestUser(Persona $persona): User
    {
        $role = Role::query()->where('name', 'invitado')->firstOrFail();
        $user = User::factory()->create([
            'persona_id' => $persona->id,
            'is_super' => false,
            'is_admin' => false,
            'active_rol_id' => $role->id,
        ]);
        $user->clearPermissionCache();

        return $user;
    }

    private function createPersona(array $overrides = []): Persona
    {
        return Persona::query()->create(array_merge([
            'tipo_identificacion' => 'CC',
            'identificacion' => (string) random_int(1000000, 9999999),
            'nombre1' => 'Luis',
            'apellido1' => 'Prueba',
            'correo' => 'luis'.random_int(1, 999999).'@example.test',
            'telefono' => '+573001112233',
            'sexo' => 'M',
        ], $overrides));
    }

    private function createEvent(): Event
    {
        return Event::query()->create([
            'name' => 'Campamento',
            'starts_at' => now(),
            'ends_at' => now()->addDay(),
        ]);
    }

    /**
     * @return array{cabana_id: int}
     */
    private function createCabanaWithLayout(string $nombre = 'Cabaña Norte', string $codigo = 'A-1', string $genero = 'MIXTO', int $capacidad = 2, bool $extraBed = false): array
    {
        $cabanaId = $this->postJson('/api/v1/cabanas', [
            'nombre' => $nombre, 'ancho' => 900, 'alto' => 600,
        ])->assertCreated()->json('data.id');

        $camas = [[
            'codigo' => $codigo, 'capacidad' => $capacidad, 'x' => 20, 'y' => 30,
            'ancho' => 80, 'alto' => 190, 'rotacion' => 90,
        ]];
        if ($extraBed) {
            $camas[] = [
                'codigo' => $codigo.'-2', 'capacidad' => 1, 'x' => 120, 'y' => 30,
                'ancho' => 80, 'alto' => 190,
            ];
        }

        $this->putJson("/api/v1/cabanas/{$cabanaId}/croquis", ['pisos' => [[
            'nombre' => 'Piso 1',
            'ancho' => 900,
            'alto' => 600,
            'cuartos' => [[
                'nombre' => 'Cuarto '.$genero,
                'genero' => $genero,
                'x' => 0, 'y' => 0, 'ancho' => 400, 'alto' => 300, 'capacidad' => $capacidad,
                'camas' => $camas,
            ]],
        ]]])->assertOk();

        return ['cabana_id' => $cabanaId];
    }

    /**
     * @return array{
     *     event: Event,
     *     user: User,
     *     persona: Persona,
     *     linea: EventoInscripcionPersona,
     *     reserva: EventoServicioReserva,
     *     oferta: EventoProductoServicio,
     *     beds: \Illuminate\Support\Collection<int, EventoCabanaCama>
     * }
     */
    private function readyAssignmentContext(string $roomGender = 'MIXTO', string $sexo = 'M', int $capacidad = 2, bool $extraBed = false): array
    {
        Sanctum::actingAs($this->admin());
        $cabana = $this->createCabanaWithLayout('Norte', 'A-1', $roomGender, $capacidad, $extraBed);
        $event = $this->createEvent();
        $this->postJson("/api/v1/events/{$event->id}/cabanas", ['cabana_id' => $cabana['cabana_id']])
            ->assertCreated();

        $producto = ProductoServicio::query()->firstOrCreate(
            ['tipo' => 'CABANA', 'nombre' => 'Cabaña'],
            ['precio' => 100000, 'unidad' => 'DIA', 'activo' => true],
        );
        $oferta = EventoProductoServicio::query()->create([
            'evento_id' => $event->id,
            'producto_servicio_id' => $producto->id,
            'precio' => 100000,
            'activo' => true,
        ]);

        $guest = $this->addEligibleGuest($event, $oferta, $sexo);
        $beds = EventoCabanaCama::query()->orderBy('id')->get();

        return [
            'event' => $event,
            'oferta' => $oferta,
            'beds' => $beds,
            ...$guest,
        ];
    }

    /**
     * @return array{user: User, persona: Persona, linea: EventoInscripcionPersona, reserva: EventoServicioReserva}
     */
    private function addEligibleGuest(Event $event, EventoProductoServicio $oferta, string $sexo): array
    {
        $persona = $this->createPersona(['sexo' => $sexo]);
        $user = $this->guestUser($persona);
        $inscripcion = EventoInscripcion::query()->create([
            'evento_id' => $event->id,
            'tipo' => 'individual',
            'persona_id' => $persona->id,
            'estado' => EventoInscripcion::ESTADO_APROBADA,
            'total_declarado' => 100000,
            'inscrito_por' => $user->id,
        ]);
        $linea = EventoInscripcionPersona::query()->create([
            'inscripcion_id' => $inscripcion->id,
            'persona_id' => $persona->id,
            'tipo' => EventoInscripcionPersona::TIPO_MIEMBRO,
            'nombre_snapshot' => $persona->full_name,
            'identificacion_snapshot' => $persona->identificacion,
            'estado' => EventoInscripcionPersona::ESTADO_CONFIRMADA,
            'valor_inscripcion' => 0,
        ]);
        $reserva = EventoServicioReserva::query()->create([
            'evento_id' => $event->id,
            'evento_producto_servicio_id' => $oferta->id,
            'persona_id' => $persona->id,
            'inscripcion_persona_id' => $linea->id,
            'inscripcion_id' => $inscripcion->id,
            'precio_unitario' => 100000,
            'cantidad' => 1,
            'valor_total' => 100000,
            'estado' => EventoServicioReserva::ESTADO_CONFIRMADA,
        ]);
        EventoPago::query()->create([
            'inscripcion_id' => $inscripcion->id,
            'pagable_type' => EventoServicioReserva::class,
            'pagable_id' => $reserva->id,
            'monto' => 100000,
            'moneda' => 'COP',
            'estado' => EventoPago::ESTADO_PAGADO,
            'pagado_at' => now(),
        ]);

        return compact('user', 'persona', 'linea', 'reserva');
    }
}
