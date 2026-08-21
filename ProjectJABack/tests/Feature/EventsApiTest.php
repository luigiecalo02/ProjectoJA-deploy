<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Clubs\Models\Club;
use App\Modules\Clubs\Models\Persona;
use App\Modules\Events\Models\Event;
use App\Modules\Events\Models\EventoInscripcion;
use App\Modules\Events\Models\TipoEvento;
use App\Modules\Organizations\Models\Organizacion;
use App\Modules\Organizations\Models\PersonaOrganizacion;
use App\Modules\Organizations\Models\TipoOrganizacion;
use Database\Seeders\OrganizacionCatalogSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\TipoEventoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class EventsApiTest extends TestCase
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
        $user = User::factory()->create(['email' => 'events-admin@test.local']);
        $user->forceFill(['is_admin' => true, 'is_super' => true])->save();
        $user->clearPermissionCache();

        return $user;
    }

    private function createOrg(string $nombre, ?int $padreId = null, int $tipoId = Organizacion::TIPO_UNION): Organizacion
    {
        return Organizacion::query()->create([
            'organizacion_padre_id' => $padreId,
            'tipo_organizacion_id' => $tipoId,
            'nombre' => $nombre,
            'codigo' => strtoupper(substr(md5($nombre.microtime()), 0, 8)),
            'estado' => true,
        ]);
    }

    public function test_admin_can_create_event_with_organizations(): void
    {
        Sanctum::actingAs($this->admin());
        $union = $this->createOrg('Unión Test');

        $response = $this->postJson('/api/v1/events', [
            'name' => 'Camporee 2026',
            'starts_at' => '2026-08-01 08:00:00',
            'ends_at' => '2026-08-03 18:00:00',
            'organizacion_id' => $union->id,
            'organizacion_ids' => [$union->id],
            'estado' => 'publicado',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.name', 'Camporee 2026')
            ->assertJsonPath('data.organizacion_id', $union->id)
            ->assertJsonPath('data.organizacion_ids.0', $union->id);

        $this->assertDatabaseHas('evento_organizacion', [
            'evento_id' => $response->json('data.id'),
            'organizacion_id' => $union->id,
        ]);
    }

    public function test_admin_can_upload_event_image(): void
    {
        Storage::fake('public');
        $admin = $this->admin();
        Sanctum::actingAs($admin);

        $event = Event::query()->create([
            'name' => 'Con imagen',
            'starts_at' => now()->addDay(),
            'ends_at' => now()->addDays(2),
            'created_by' => $admin->id,
            'is_active' => true,
            'estado' => Event::ESTADO_BORRADOR,
        ]);

        $file = UploadedFile::fake()->image('evento.jpg', 640, 480);

        $this->post("/api/v1/events/{$event->id}/image", [
            'image' => $file,
        ], [
            'Accept' => 'application/json',
        ])->assertOk()
            ->assertJsonPath('success', true);

        $this->assertNotNull($event->fresh()->image_url);
    }

    public function test_admin_can_upload_event_banner(): void
    {
        Storage::fake('public');
        $admin = $this->admin();
        Sanctum::actingAs($admin);

        $event = Event::query()->create([
            'name' => 'Con banner',
            'starts_at' => now()->addDay(),
            'ends_at' => now()->addDays(2),
            'created_by' => $admin->id,
            'is_active' => true,
            'estado' => Event::ESTADO_BORRADOR,
        ]);

        $file = UploadedFile::fake()->image('banner.jpg', 1920, 600);

        $this->post("/api/v1/events/{$event->id}/banner", [
            'image' => $file,
        ], [
            'Accept' => 'application/json',
        ])->assertOk()
            ->assertJsonPath('success', true);

        $this->assertNotNull($event->fresh()->banner_url);
    }

    public function test_child_event_must_be_within_parent_when_es_en_sitio(): void
    {
        Sanctum::actingAs($this->admin());
        $union = $this->createOrg('Unión Padre');

        $parent = $this->postJson('/api/v1/events', [
            'name' => 'Camporee',
            'starts_at' => '2026-07-20 08:00:00',
            'ends_at' => '2026-07-24 18:00:00',
            'organizacion_id' => $union->id,
            'organizacion_ids' => [$union->id],
            'es_en_sitio' => true,
        ])->assertCreated()->json('data');

        $this->postJson('/api/v1/events', [
            'name' => 'Concurso fuera de rango',
            'starts_at' => '2026-07-10 08:00:00',
            'ends_at' => '2026-07-10 12:00:00',
            'evento_padre_id' => $parent['id'],
            'organizacion_id' => $union->id,
            'es_en_sitio' => true,
        ])->assertStatus(422);

        $this->postJson('/api/v1/events', [
            'name' => 'Concurso OK',
            'starts_at' => '2026-07-21 08:00:00',
            'ends_at' => '2026-07-21 12:00:00',
            'evento_padre_id' => $parent['id'],
            'organizacion_id' => $union->id,
        ])->assertCreated()
            ->assertJsonPath('data.evento_padre_id', $parent['id']);
    }

    public function test_child_can_be_outside_parent_when_not_es_en_sitio(): void
    {
        Sanctum::actingAs($this->admin());
        $union = $this->createOrg('Unión Virtual');

        $parent = $this->postJson('/api/v1/events', [
            'name' => 'Camporee',
            'starts_at' => '2026-07-20 08:00:00',
            'ends_at' => '2026-07-24 18:00:00',
            'organizacion_id' => $union->id,
            'es_en_sitio' => false,
        ])->assertCreated()->json('data');

        $this->postJson('/api/v1/events', [
            'name' => 'Inscripción virtual',
            'starts_at' => '2026-07-10 08:00:00',
            'ends_at' => '2026-07-10 12:00:00',
            'evento_padre_id' => $parent['id'],
            'organizacion_id' => $union->id,
        ])->assertCreated();
    }

    public function test_event_accepts_tipo_organizacion_ids(): void
    {
        Sanctum::actingAs($this->admin());
        $union = $this->createOrg('Unión Tipos');
        $tipoClub = TipoOrganizacion::query()->where('id', Organizacion::TIPO_CLUB)->value('id')
            ?? TipoOrganizacion::query()->value('id');

        $this->postJson('/api/v1/events', [
            'name' => 'Solo clubes',
            'starts_at' => '2026-08-01 08:00:00',
            'ends_at' => '2026-08-02 18:00:00',
            'organizacion_id' => $union->id,
            'tipo_organizacion_ids' => [$tipoClub],
        ])->assertCreated()
            ->assertJsonPath('data.tipo_organizacion_ids.0', $tipoClub);
    }

    public function test_event_accepts_tipo_evento_and_cupo_minimo(): void
    {
        Sanctum::actingAs($this->admin());
        $this->seed(TipoEventoSeeder::class);
        $union = $this->createOrg('Unión Tipos Evento');
        $tipoEventoId = TipoEvento::query()
            ->where('slug', 'eventos-biblicos')
            ->value('id');
        $this->assertNotNull($tipoEventoId);

        $this->postJson('/api/v1/events', [
            'name' => 'Concurso Bíblico',
            'starts_at' => '2026-08-01 08:00:00',
            'ends_at' => '2026-08-02 18:00:00',
            'organizacion_id' => $union->id,
            'tipo_evento_id' => (int) $tipoEventoId,
            'cupo_ilimitado' => false,
            'cupo_minimo' => 10,
            'cupo_maximo' => 100,
        ])->assertCreated()
            ->assertJsonPath('data.tipo_evento_id', (int) $tipoEventoId)
            ->assertJsonPath('data.cupo_minimo', 10)
            ->assertJsonPath('data.cupo_maximo', 100);

        $this->getJson('/api/v1/events/tipos')
            ->assertOk()
            ->assertJsonFragment(['slug' => 'eventos-deportivos']);
    }

    public function test_cupo_minimo_cannot_exceed_maximo(): void
    {
        Sanctum::actingAs($this->admin());
        $union = $this->createOrg('Unión Cupos');

        $this->postJson('/api/v1/events', [
            'name' => 'Cupos inválidos',
            'starts_at' => '2026-08-01 08:00:00',
            'ends_at' => '2026-08-02 18:00:00',
            'organizacion_id' => $union->id,
            'cupo_ilimitado' => false,
            'cupo_minimo' => 50,
            'cupo_maximo' => 10,
        ])->assertStatus(422);
    }

    public function test_club_audience_hides_other_ministry_clubs(): void
    {
        $asociacion = $this->createOrg('Asociación Norte', null, Organizacion::TIPO_ASOCIACION);
        $iglesia = $this->createOrg('Iglesia Central', $asociacion->id, Organizacion::TIPO_IGLESIA);
        $clubAventOrg = $this->createOrg('Club Aventureros', $iglesia->id, Organizacion::TIPO_CLUB);
        $clubConqOrg = $this->createOrg('Club Conquistadores', $iglesia->id, Organizacion::TIPO_CLUB);

        Club::query()->create([
            'organizacion_id' => $clubAventOrg->id,
            'nombre' => 'Aventureros Central',
            'tipos' => ['aventureros'],
            'is_active' => true,
        ]);
        Club::query()->create([
            'organizacion_id' => $clubConqOrg->id,
            'nombre' => 'Conquistadores Central',
            'tipos' => ['conquistadores'],
            'is_active' => true,
        ]);

        Sanctum::actingAs($this->admin());
        $tipoAventureros = TipoOrganizacion::query()
            ->where('nombre', 'like', '%Aventurer%')
            ->value('id');
        $this->assertNotNull($tipoAventureros);

        $eventId = $this->postJson('/api/v1/events', [
            'name' => 'Solo Aventureros',
            'starts_at' => '2026-09-01 08:00:00',
            'ends_at' => '2026-09-02 18:00:00',
            'organizacion_id' => $asociacion->id,
            'organizacion_ids' => [$asociacion->id],
            'tipo_organizacion_ids' => [(int) $tipoAventureros],
            'estado' => 'publicado',
            'is_active' => true,
        ])->assertCreated()->json('data.id');

        $event = Event::query()->with('tiposOrganizacion')->findOrFail($eventId);

        $aventUser = $this->userInOrg($clubAventOrg->id);
        $conqUser = $this->userInOrg($clubConqOrg->id);

        $this->assertTrue($event->isVisibleTo($aventUser));
        $this->assertFalse($event->isVisibleTo($conqUser));
    }

    public function test_changing_audience_hides_event_but_keeps_inscriptions(): void
    {
        $asociacion = $this->createOrg('Asociación Audiencia', null, Organizacion::TIPO_ASOCIACION);
        $iglesia = $this->createOrg('Iglesia Audiencia', $asociacion->id, Organizacion::TIPO_IGLESIA);
        $clubAventOrg = $this->createOrg('Club Aventureros Audiencia', $iglesia->id, Organizacion::TIPO_CLUB);
        $clubConqOrg = $this->createOrg('Club Conquistadores Audiencia', $iglesia->id, Organizacion::TIPO_CLUB);

        Club::query()->create([
            'organizacion_id' => $clubAventOrg->id,
            'nombre' => 'Aventureros Audiencia',
            'tipos' => ['aventureros'],
            'is_active' => true,
        ]);
        Club::query()->create([
            'organizacion_id' => $clubConqOrg->id,
            'nombre' => 'Conquistadores Audiencia',
            'tipos' => ['conquistadores'],
            'is_active' => true,
        ]);

        $tipoAventureros = TipoOrganizacion::query()
            ->where('nombre', 'like', '%Aventurer%')
            ->value('id');
        $tipoConquistadores = TipoOrganizacion::query()
            ->where('nombre', 'like', '%Conquistador%')
            ->value('id');
        $this->assertNotNull($tipoAventureros);
        $this->assertNotNull($tipoConquistadores);

        Sanctum::actingAs($this->admin());
        $eventId = $this->postJson('/api/v1/events', [
            'name' => 'Cambio de audiencia',
            'starts_at' => '2026-09-01 08:00:00',
            'ends_at' => '2026-09-02 18:00:00',
            'organizacion_id' => $asociacion->id,
            'organizacion_ids' => [$asociacion->id],
            'tipo_organizacion_ids' => [(int) $tipoConquistadores],
            'visibilidad' => Event::VISIBILIDAD_PUBLICO,
            'estado' => 'publicado',
            'is_active' => true,
        ])->assertCreated()->json('data.id');

        $inscripcion = EventoInscripcion::query()->create([
            'evento_id' => $eventId,
            'tipo' => 'club',
            'organizacion_id' => $clubConqOrg->id,
            'estado' => EventoInscripcion::ESTADO_APROBADA,
        ]);

        $this->putJson('/api/v1/events/'.$eventId, [
            'tipo_organizacion_ids' => [(int) $tipoAventureros],
        ])->assertOk()
            ->assertJsonPath('data.tipo_organizacion_ids.0', (int) $tipoAventureros);

        $this->assertDatabaseHas('evento_inscripcion', [
            'id' => $inscripcion->id,
            'evento_id' => $eventId,
            'organizacion_id' => $clubConqOrg->id,
            'estado' => EventoInscripcion::ESTADO_APROBADA,
        ]);

        $event = Event::query()->with('tiposOrganizacion')->findOrFail($eventId);
        $aventUser = $this->userInOrg($clubAventOrg->id);
        $conqUser = $this->userInOrg($clubConqOrg->id);

        $this->assertTrue($event->isVisibleTo($aventUser));
        $this->assertFalse($event->isVisibleTo($conqUser));
    }

    private function userInOrg(int $organizacionId): User
    {
        $user = User::factory()->create();
        $persona = Persona::query()->create([
            'tipo_identificacion' => 'CC',
            'identificacion' => 'ID'.random_int(100000, 999999).uniqid(),
            'nombre1' => 'Test',
            'apellido1' => 'User',
            'correo' => $user->email,
        ]);
        $user->forceFill(['persona_id' => $persona->id])->save();

        PersonaOrganizacion::query()->create([
            'persona_id' => $persona->id,
            'organizacion_id' => $organizacionId,
            'estado' => true,
        ]);

        return $user->fresh();
    }
}
