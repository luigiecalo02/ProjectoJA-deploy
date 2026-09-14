<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Clubs\Models\Persona;
use App\Modules\Events\Models\Event;
use App\Modules\Organizations\Models\Organizacion;
use App\Modules\Organizations\Models\PersonaOrganizacion;
use App\Modules\Organizations\Models\PersonaOrganizacionRol;
use App\Modules\Users\Models\Role;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ClubesEventsApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->ensureClubTipo();
    }

    public function test_board_member_can_create_club_event_from_clubes_front(): void
    {
        $org = $this->createClubOrg('Club Directiva');
        $director = $this->boardUser('director', $org, 'dir-club@test.local');
        $tesorero = $this->boardUser('tesorero', $org, 'tes-club@test.local');

        Sanctum::actingAs($director);
        $first = $this->postJson('/api/v1/settings/clubes/events', $this->eventPayload('Reunión semanal'), [
            'X-Clubes-Client' => 'clubes',
        ]);
        $first->assertCreated()
            ->assertJsonPath('data.visibilidad', Event::VISIBILIDAD_ORGANIZACION)
            ->assertJsonPath('data.es_calificable', false)
            ->assertJsonPath('data.permite_inscripcion_club', true)
            ->assertJsonPath('data.permite_inscripcion_organizacion', true)
            ->assertJsonPath('data.permite_inscribir_no_participantes', true)
            ->assertJsonPath('data.organizacion.id', $org->id);

        Sanctum::actingAs($tesorero);
        $this->postJson('/api/v1/settings/clubes/events', $this->eventPayload('Tesorería'), [
            'X-Clubes-Client' => 'clubes',
        ])->assertCreated()
            ->assertJsonPath('data.visibilidad', Event::VISIBILIDAD_ORGANIZACION);
    }

    public function test_board_member_cannot_create_club_event_without_clubes_header(): void
    {
        $org = $this->createClubOrg('Club Sin Header');
        $director = $this->boardUser('director', $org, 'dir-no-header@test.local');

        Sanctum::actingAs($director);
        $this->postJson('/api/v1/settings/clubes/events', $this->eventPayload('Intento'))
            ->assertForbidden();
    }

    public function test_board_member_creates_org_event_from_main_api(): void
    {
        $org = $this->createClubOrg('Club Main API');
        $director = $this->boardUser('director', $org, 'dir-main@test.local');

        Sanctum::actingAs($director);
        $this->postJson('/api/v1/events', [
            'name' => 'Reunión de club',
            'starts_at' => now()->addDay()->toDateTimeString(),
            'ends_at' => now()->addDays(2)->toDateTimeString(),
            'visibilidad' => Event::VISIBILIDAD_PUBLICO,
        ])->assertCreated()
            ->assertJsonPath('data.visibilidad', Event::VISIBILIDAD_ORGANIZACION)
            ->assertJsonPath('data.organizacion.id', $org->id);
    }

    public function test_other_organization_cannot_see_club_event(): void
    {
        $mine = $this->createClubOrg('Club Visible');
        $other = $this->createClubOrg('Club Ajeno');
        $director = $this->boardUser('director', $mine, 'dir-visible@test.local');
        $otherDirector = $this->boardUser('director', $other, 'dir-ajeno@test.local');

        Sanctum::actingAs($director);
        $eventId = $this->postJson('/api/v1/settings/clubes/events', $this->eventPayload('Solo mi club'), [
            'X-Clubes-Client' => 'clubes',
        ])->assertCreated()->json('data.id');

        Sanctum::actingAs($otherDirector);
        $ids = collect($this->getJson('/api/v1/events')->assertOk()->json('data'))
            ->pluck('id')
            ->all();

        $this->assertNotContains($eventId, $ids);
    }

    public function test_director_can_create_event_with_logo_and_banner(): void
    {
        Storage::fake('public');
        $org = $this->createClubOrg('Club Imágenes');
        $director = $this->boardUser('director', $org, 'dir-img@test.local');

        Sanctum::actingAs($director);
        $this->post('/api/v1/settings/clubes/events', [
            ...$this->eventPayload('Campamento'),
            'descripcion' => 'Salida al campo',
            'lugar' => 'Finca El Roble',
            'logo' => UploadedFile::fake()->image('logo.png', 200, 200),
            'banner' => UploadedFile::fake()->image('banner.jpg', 800, 320),
        ], [
            'X-Clubes-Client' => 'clubes',
        ])->assertCreated()
            ->assertJsonPath('data.lugar', 'Finca El Roble')
            ->assertJsonPath('data.descripcion', 'Salida al campo');

        $this->assertNotNull(
            Event::query()->where('name', 'Campamento')->value('image_url')
        );
        $this->assertNotNull(
            Event::query()->where('name', 'Campamento')->value('banner_url')
        );
    }

    public function test_board_member_can_update_club_event_from_clubes_front(): void
    {
        $org = $this->createClubOrg('Club Editar');
        $director = $this->boardUser('director', $org, 'dir-edit@test.local');

        Sanctum::actingAs($director);
        $eventId = $this->postJson('/api/v1/settings/clubes/events', $this->eventPayload('Dia Mundial'), [
            'X-Clubes-Client' => 'clubes',
        ])->assertCreated()->json('data.id');

        $this->postJson("/api/v1/settings/clubes/events/{$eventId}", [
            'name' => 'Dia Mundial editado',
            'descripcion' => 'Actividad del club',
            'lugar' => 'Sede R.I.C',
            'starts_at' => now()->addDays(3)->toDateTimeString(),
            'ends_at' => now()->addDays(3)->endOfDay()->toDateTimeString(),
        ], [
            'X-Clubes-Client' => 'clubes',
        ])->assertOk()
            ->assertJsonPath('data.name', 'Dia Mundial editado')
            ->assertJsonPath('data.lugar', 'Sede R.I.C')
            ->assertJsonPath('data.descripcion', 'Actividad del club');
    }

    public function test_other_organization_cannot_update_club_event(): void
    {
        $mine = $this->createClubOrg('Club Propio');
        $other = $this->createClubOrg('Club Extraño');
        $director = $this->boardUser('director', $mine, 'dir-propio@test.local');
        $otherDirector = $this->boardUser('director', $other, 'dir-extrano@test.local');

        Sanctum::actingAs($director);
        $eventId = $this->postJson('/api/v1/settings/clubes/events', $this->eventPayload('Privado'), [
            'X-Clubes-Client' => 'clubes',
        ])->assertCreated()->json('data.id');

        Sanctum::actingAs($otherDirector);
        $this->postJson("/api/v1/settings/clubes/events/{$eventId}", [
            ...$this->eventPayload('Intento ajeno'),
            'name' => 'Intento ajeno',
        ], [
            'X-Clubes-Client' => 'clubes',
        ])->assertForbidden();
    }

    /**
     * @return array<string, string>
     */
    private function eventPayload(string $name): array
    {
        return [
            'name' => $name,
            'starts_at' => now()->addDay()->toDateTimeString(),
            'ends_at' => now()->addDays(2)->toDateTimeString(),
        ];
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
