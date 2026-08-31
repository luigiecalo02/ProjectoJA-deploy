<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Clubs\Models\Persona;
use App\Modules\Events\Models\Event;
use App\Modules\Events\Models\EventoInscripcion;
use App\Modules\Organizations\Models\TipoOrganizacion;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PublicEventosApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_lists_only_public_free_individual_events(): void
    {
        $visible = $this->publicEvent('Camporee libre');
        $this->hiddenPrivateEvent();
        $this->clubAudienceEvent();

        $response = $this->getJson('/api/v1/public/eventos');

        $response->assertOk()
            ->assertJsonPath('success', true);
        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertContains($visible->id, $ids);
        $this->assertCount(1, $ids);
    }

    public function test_guest_can_enroll_without_creating_user(): void
    {
        $event = $this->publicEvent('Inscripción abierta');

        $response = $this->post("/api/v1/public/eventos/{$event->id}/inscribir", [
            'tipo_identificacion' => 'CC',
            'identificacion' => '1098765432',
            'nombre1' => 'Ana',
            'apellido1' => 'López',
            'correo' => 'ana.publica@test.local',
            'crear_usuario' => '0',
        ], [
            'Accept' => 'application/json',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.usuario_creado', false);

        $this->assertDatabaseHas('personas', [
            'identificacion' => '1098765432',
            'correo' => 'ana.publica@test.local',
        ]);
        $this->assertDatabaseHas('evento_inscripcion', [
            'evento_id' => $event->id,
            'tipo' => EventoInscripcion::TIPO_INDIVIDUAL,
            'estado' => EventoInscripcion::ESTADO_PENDIENTE_REVISION,
        ]);
        $this->assertDatabaseMissing('users', [
            'email' => 'ana.publica@test.local',
        ]);
    }

    public function test_guest_can_enroll_creating_user_and_paying(): void
    {
        Storage::fake('public');
        $event = $this->publicEvent('Con pago');
        $event->update([
            'requiere_pago' => true,
            'precio' => 50000,
        ]);

        $response = $this->post("/api/v1/public/eventos/{$event->id}/inscribir", [
            'tipo_identificacion' => 'CC',
            'identificacion' => '100200300',
            'nombre1' => 'Luis',
            'apellido1' => 'Rojas',
            'correo' => 'luis.public@test.local',
            'crear_usuario' => '1',
            'password' => 'Clave#2026',
            'password_confirmation' => 'Clave#2026',
            'comprobante' => UploadedFile::fake()->image('pago.jpg', 400, 300),
        ], [
            'Accept' => 'application/json',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.usuario_creado', true)
            ->assertJsonPath('data.total', 50000);

        $this->assertDatabaseHas('users', [
            'email' => 'luis.public@test.local',
            'is_active' => 0,
        ]);
        $persona = Persona::query()->where('identificacion', '100200300')->first();
        $this->assertNotNull($persona);
        $this->assertSame($persona->id, User::query()->where('email', 'luis.public@test.local')->value('persona_id'));
    }

    public function test_rejects_duplicate_active_inscription(): void
    {
        $event = $this->publicEvent('Duplicado');
        $this->post("/api/v1/public/eventos/{$event->id}/inscribir", [
            'tipo_identificacion' => 'CC',
            'identificacion' => '555111222',
            'nombre1' => 'Eva',
            'apellido1' => 'Díaz',
            'correo' => 'eva.dup@test.local',
        ], ['Accept' => 'application/json'])->assertCreated();

        $this->post("/api/v1/public/eventos/{$event->id}/inscribir", [
            'tipo_identificacion' => 'CC',
            'identificacion' => '555111222',
            'nombre1' => 'Eva',
            'apellido1' => 'Díaz',
            'correo' => 'eva.dup@test.local',
        ], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['identificacion']);
    }

    public function test_hidden_event_is_not_shown(): void
    {
        $event = $this->hiddenPrivateEvent();

        $this->getJson("/api/v1/public/eventos/{$event->id}")->assertNotFound();
    }

    private function publicEvent(string $name): Event
    {
        return Event::query()->create([
            'name' => $name,
            'starts_at' => now()->addDays(10),
            'ends_at' => now()->addDays(12),
            'is_active' => true,
            'estado' => Event::ESTADO_PUBLICADO,
            'visibilidad' => Event::VISIBILIDAD_PUBLICO,
            'permite_inscripcion_individual' => true,
            'requiere_pago' => false,
        ]);
    }

    private function hiddenPrivateEvent(): Event
    {
        return Event::query()->create([
            'name' => 'Privado',
            'starts_at' => now()->addDays(10),
            'ends_at' => now()->addDays(12),
            'is_active' => true,
            'estado' => Event::ESTADO_PUBLICADO,
            'visibilidad' => Event::VISIBILIDAD_PRIVADO,
            'permite_inscripcion_individual' => true,
            'requiere_pago' => false,
        ]);
    }

    private function clubAudienceEvent(): Event
    {
        $event = Event::query()->create([
            'name' => 'Solo clubes',
            'starts_at' => now()->addDays(10),
            'ends_at' => now()->addDays(12),
            'is_active' => true,
            'estado' => Event::ESTADO_PUBLICADO,
            'visibilidad' => Event::VISIBILIDAD_PUBLICO,
            'permite_inscripcion_individual' => true,
            'requiere_pago' => false,
        ]);
        $tipo = TipoOrganizacion::query()->create([
            'nombre' => 'Conquistadores',
            'estado' => true,
        ]);
        $event->tiposOrganizacion()->attach($tipo->id);

        return $event;
    }
}
