<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Clubs\Models\Persona;
use App\Modules\Events\Models\Event;
use App\Modules\Events\Models\EventoAsistencia;
use App\Modules\Events\Models\EventoParticipacionAbono;
use App\Modules\Events\Models\TipoEvento;
use App\Modules\Notifications\Enums\ClubNotificationType;
use App\Modules\Notifications\Models\OrganizacionNotificacionTipo;
use App\Modules\Notifications\Notifications\AbonoAprobadoNotification;
use App\Modules\Notifications\Notifications\AbonoComprobanteSubidoNotification;
use App\Modules\Notifications\Notifications\AsistenciaMarcadaNotification;
use App\Modules\Notifications\Services\ReadNotificationPruner;
use App\Modules\Organizations\Models\Organizacion;
use App\Modules\Organizations\Models\PersonaOrganizacion;
use App\Modules\Organizations\Models\PersonaOrganizacionRol;
use App\Modules\Users\Models\Role;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ClubNotificationsApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->ensureClubTipo();
        config([
            'webpush.vapid.public_key' => 'BBpo8UBJgga8vGxOM-8hN3xhNQV6WDU0GzkW-WJmojupeHH8wXAHR12NQ7Qag7ksnXetAgyHAvducT1rC8N2oKA',
            'webpush.vapid.private_key' => 'wcRpIstrcaYj61pQukDvN_Z3N50d-O6ZkZK3az10tvA',
            'webpush.vapid.subject' => 'mailto:admin@clubes.local',
        ]);
    }

    public function test_attendance_sync_notifies_only_changed_members(): void
    {
        Notification::fake();

        $org = $this->createClubOrg('Club Notif Asistencia');
        $director = $this->boardUser('director', $org, 'dir-notif-asis@test.local');
        $memberUser = $this->memberUser($org, 'mem-notif-asis@test.local');
        $otherUser = $this->memberUser($org, 'mem-notif-asis-2@test.local');

        Sanctum::actingAs($director);
        $eventId = $this->postJson('/api/v1/settings/clubes/events', $this->eventPayload('Reunión notif'), [
            'X-Clubes-Client' => 'clubes',
        ])->assertCreated()->json('data.id');

        $this->putJson("/api/v1/settings/clubes/asistencia/{$eventId}", [
            'persona_ids' => [$memberUser->persona_id],
            'justificados' => [$director->persona_id],
        ], [
            'X-Clubes-Client' => 'clubes',
        ])->assertOk();

        Notification::assertSentTo($memberUser, AsistenciaMarcadaNotification::class);
        // El ausente automático del resto del roster no notifica.
        Notification::assertNotSentTo($otherUser, AsistenciaMarcadaNotification::class);
        Notification::assertNotSentTo($director, AsistenciaMarcadaNotification::class);

        Notification::fake();

        $this->putJson("/api/v1/settings/clubes/asistencia/{$eventId}", [
            'persona_ids' => [$memberUser->persona_id],
            'justificados' => [$director->persona_id],
        ], [
            'X-Clubes-Client' => 'clubes',
        ])->assertOk();

        Notification::assertNothingSent();
    }

    public function test_approving_pending_abono_notifies_member_and_reject_does_not(): void
    {
        Notification::fake();
        Storage::fake('public');

        $org = $this->createClubOrg('Club Notif Abono');
        $director = $this->boardUser('director', $org, 'dir-notif-abono@test.local');
        $memberUser = $this->memberUser($org, 'mem-notif-abono@test.local');

        Sanctum::actingAs($director);
        $economicId = $this->postJson('/api/v1/settings/clubes/events', $this->eventPayload('Venta notif', $this->economicTipoId()), [
            'X-Clubes-Client' => 'clubes',
        ])->assertCreated()->json('data.id');

        Sanctum::actingAs($memberUser);
        $this->putJson("/api/v1/settings/clubes/events/{$economicId}/participantes/yo", [
            'participa' => true,
            'ventas' => [],
        ], [
            'X-Clubes-Client' => 'clubes',
        ])->assertOk();

        Event::query()->whereKey($economicId)->update(['estado' => Event::ESTADO_EN_PROCESO]);

        $pending = $this->post('/api/v1/settings/clubes/abonos/comprobante', [
            'evento_id' => $economicId,
            'comprobante' => UploadedFile::fake()->image('pago.jpg', 800, 1200),
        ], [
            'X-Clubes-Client' => 'clubes',
            'Accept' => 'application/json',
        ])->assertOk();

        $abonoId = (int) $pending->json('data.abonos.0.id');
        Notification::assertSentTo($director, AbonoComprobanteSubidoNotification::class);
        Notification::assertNotSentTo($memberUser, AbonoComprobanteSubidoNotification::class);

        Notification::fake();
        Sanctum::actingAs($director);
        $this->patchJson("/api/v1/settings/clubes/abonos/{$abonoId}/revision", [
            'estado' => 'aprobado',
            'monto' => 12000,
        ], [
            'X-Clubes-Client' => 'clubes',
        ])->assertOk();

        Notification::assertSentTo($memberUser, AbonoAprobadoNotification::class);
        Notification::assertNotSentTo($director, AbonoAprobadoNotification::class);

        Notification::fake();
        $pendingReject = EventoParticipacionAbono::query()->create([
            'organizacion_id' => $org->id,
            'evento_id' => $economicId,
            'persona_id' => $memberUser->persona_id,
            'monto' => 0,
            'estado' => EventoParticipacionAbono::ESTADO_PENDIENTE,
            'registrado_por' => $memberUser->id,
        ]);

        $this->patchJson("/api/v1/settings/clubes/abonos/{$pendingReject->id}/revision", [
            'estado' => 'rechazado',
        ], [
            'X-Clubes-Client' => 'clubes',
        ])->assertOk();

        Notification::assertNothingSent();
    }

    public function test_disabled_type_does_not_notify(): void
    {
        Notification::fake();

        $org = $this->createClubOrg('Club Notif Off');
        $director = $this->boardUser('director', $org, 'dir-notif-off@test.local');
        $memberUser = $this->memberUser($org, 'mem-notif-off@test.local');

        OrganizacionNotificacionTipo::query()->create([
            'organizacion_id' => $org->id,
            'tipo' => ClubNotificationType::ASISTENCIA_MARCADA->value,
            'activo' => false,
        ]);

        Sanctum::actingAs($director);
        $eventId = $this->postJson('/api/v1/settings/clubes/events', $this->eventPayload('Reunión off'), [
            'X-Clubes-Client' => 'clubes',
        ])->assertCreated()->json('data.id');

        $this->putJson("/api/v1/settings/clubes/asistencia/{$eventId}", [
            'persona_ids' => [$memberUser->persona_id],
        ], [
            'X-Clubes-Client' => 'clubes',
        ])->assertOk();

        Notification::assertNothingSent();
        $this->assertDatabaseHas('evento_asistencias', [
            'evento_id' => $eventId,
            'persona_id' => $memberUser->persona_id,
            'estado' => EventoAsistencia::ESTADO_PRESENTE,
        ]);
    }

    public function test_member_comprobante_notifies_all_board(): void
    {
        Notification::fake();
        Storage::fake('public');

        $org = $this->createClubOrg('Club Notif Comprobante');
        $director = $this->boardUser('director', $org, 'dir-notif-comp@test.local');
        $tesorero = $this->boardUser('tesorero', $org, 'tes-notif-comp@test.local');
        $memberUser = $this->memberUser($org, 'mem-notif-comp@test.local');
        $otherMember = $this->memberUser($org, 'mem-notif-comp-2@test.local');

        Sanctum::actingAs($director);
        $economicId = $this->postJson('/api/v1/settings/clubes/events', $this->eventPayload('Venta comprobante', $this->economicTipoId()), [
            'X-Clubes-Client' => 'clubes',
        ])->assertCreated()->json('data.id');

        Sanctum::actingAs($memberUser);
        $this->putJson("/api/v1/settings/clubes/events/{$economicId}/participantes/yo", [
            'participa' => true,
            'ventas' => [],
        ], [
            'X-Clubes-Client' => 'clubes',
        ])->assertOk();

        Event::query()->whereKey($economicId)->update(['estado' => Event::ESTADO_EN_PROCESO]);

        $this->post('/api/v1/settings/clubes/abonos/comprobante', [
            'evento_id' => $economicId,
            'comprobante' => UploadedFile::fake()->image('pago.jpg', 800, 1200),
        ], [
            'X-Clubes-Client' => 'clubes',
            'Accept' => 'application/json',
        ])->assertOk();

        Notification::assertSentTo($director, AbonoComprobanteSubidoNotification::class);
        Notification::assertSentTo($tesorero, AbonoComprobanteSubidoNotification::class);
        Notification::assertNotSentTo($memberUser, AbonoComprobanteSubidoNotification::class);
        Notification::assertNotSentTo($otherMember, AbonoComprobanteSubidoNotification::class);
    }

    public function test_disabled_comprobante_type_does_not_notify_board(): void
    {
        Notification::fake();
        Storage::fake('public');

        $org = $this->createClubOrg('Club Notif Comp Off');
        $director = $this->boardUser('director', $org, 'dir-notif-comp-off@test.local');
        $memberUser = $this->memberUser($org, 'mem-notif-comp-off@test.local');

        OrganizacionNotificacionTipo::query()->create([
            'organizacion_id' => $org->id,
            'tipo' => ClubNotificationType::ABONO_COMPROBANTE_SUBIDO->value,
            'activo' => false,
        ]);

        Sanctum::actingAs($director);
        $economicId = $this->postJson('/api/v1/settings/clubes/events', $this->eventPayload('Venta off', $this->economicTipoId()), [
            'X-Clubes-Client' => 'clubes',
        ])->assertCreated()->json('data.id');

        Sanctum::actingAs($memberUser);
        $this->putJson("/api/v1/settings/clubes/events/{$economicId}/participantes/yo", [
            'participa' => true,
            'ventas' => [],
        ], [
            'X-Clubes-Client' => 'clubes',
        ])->assertOk();

        Event::query()->whereKey($economicId)->update(['estado' => Event::ESTADO_EN_PROCESO]);

        $this->post('/api/v1/settings/clubes/abonos/comprobante', [
            'evento_id' => $economicId,
            'comprobante' => UploadedFile::fake()->image('pago.jpg', 800, 1200),
        ], [
            'X-Clubes-Client' => 'clubes',
            'Accept' => 'application/json',
        ])->assertOk();

        Notification::assertNothingSent();
    }

    public function test_prunes_old_read_notifications_and_keeps_unread(): void
    {
        $org = $this->createClubOrg('Club Notif Prune');
        $user = $this->memberUser($org, 'mem-notif-prune@test.local');

        $keepUnread = (string) \Illuminate\Support\Str::uuid();
        $keepRecentRead = (string) \Illuminate\Support\Str::uuid();
        $dropOldRead = (string) \Illuminate\Support\Str::uuid();

        $payload = json_encode([
            'tipo' => ClubNotificationType::ASISTENCIA_MARCADA->value,
            'title' => 'Aviso',
            'body' => 'Cuerpo',
            'url' => '/eventos',
        ], JSON_THROW_ON_ERROR);

        DB::table('notifications')->insert([
            [
                'id' => $keepUnread,
                'type' => AsistenciaMarcadaNotification::class,
                'notifiable_type' => User::class,
                'notifiable_id' => $user->id,
                'data' => $payload,
                'read_at' => null,
                'created_at' => now()->subDays(30),
                'updated_at' => now()->subDays(30),
            ],
            [
                'id' => $keepRecentRead,
                'type' => AsistenciaMarcadaNotification::class,
                'notifiable_type' => User::class,
                'notifiable_id' => $user->id,
                'data' => $payload,
                'read_at' => now()->subDays(2),
                'created_at' => now()->subDays(3),
                'updated_at' => now()->subDays(2),
            ],
            [
                'id' => $dropOldRead,
                'type' => AsistenciaMarcadaNotification::class,
                'notifiable_type' => User::class,
                'notifiable_id' => $user->id,
                'data' => $payload,
                'read_at' => now()->subDays(10),
                'created_at' => now()->subDays(12),
                'updated_at' => now()->subDays(10),
            ],
        ]);

        config(['club_notifications.read_retention_days' => 7]);
        $deleted = app(ReadNotificationPruner::class)->prune();

        $this->assertSame(1, $deleted);
        $this->assertDatabaseHas('notifications', ['id' => $keepUnread]);
        $this->assertDatabaseHas('notifications', ['id' => $keepRecentRead]);
        $this->assertDatabaseMissing('notifications', ['id' => $dropOldRead]);
    }

    public function test_push_and_notification_endpoints(): void
    {
        $org = $this->createClubOrg('Club Notif Api');
        $director = $this->boardUser('director', $org, 'dir-notif-api@test.local');

        Sanctum::actingAs($director);

        $this->getJson('/api/v1/push/vapid-key')->assertOk()
            ->assertJsonPath('data.public_key', config('webpush.vapid.public_key'));

        $this->postJson('/api/v1/push/subscriptions', [
            'endpoint' => 'https://push.example.test/endpoint-1',
            'keys' => [
                'p256dh' => 'public-key-test',
                'auth' => 'auth-token-test',
            ],
            'contentEncoding' => 'aesgcm',
        ])->assertOk();

        $this->assertDatabaseHas('push_subscriptions', [
            'endpoint' => 'https://push.example.test/endpoint-1',
            'subscribable_id' => $director->id,
        ]);

        DB::table('notifications')->insert([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'type' => AsistenciaMarcadaNotification::class,
            'notifiable_type' => User::class,
            'notifiable_id' => $director->id,
            'data' => json_encode([
                'tipo' => ClubNotificationType::ASISTENCIA_MARCADA->value,
                'title' => 'Asistencia registrada',
                'body' => 'Tu asistencia quedó marcada.',
                'url' => '/eventos',
                'icon' => '/pwa-192x192.png',
            ], JSON_THROW_ON_ERROR),
            'read_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $listed = $this->getJson('/api/v1/notifications')->assertOk();
        $this->assertGreaterThanOrEqual(1, (int) $listed->json('data.unread_count'));
        $notificationId = $listed->json('data.items.0.id');
        $this->assertNotEmpty($notificationId);

        $this->patchJson("/api/v1/notifications/{$notificationId}/read")->assertOk()
            ->assertJsonPath('data.unread_count', 0);

        $this->postJson('/api/v1/notifications/read-all')->assertOk()
            ->assertJsonPath('data.unread_count', 0);

        $settings = $this->getJson('/api/v1/settings/clubes/notificaciones')->assertOk();
        $this->assertNotEmpty($settings->json('data.tipos'));

        $this->putJson('/api/v1/settings/clubes/notificaciones/'.ClubNotificationType::ABONO_APROBADO->value, [
            'activo' => false,
        ])->assertOk()
            ->assertJsonPath('data.activo', false);

        $this->deleteJson('/api/v1/push/subscriptions', [
            'endpoint' => 'https://push.example.test/endpoint-1',
        ])->assertOk();

        $this->assertDatabaseMissing('push_subscriptions', [
            'endpoint' => 'https://push.example.test/endpoint-1',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function eventPayload(string $name, ?int $tipoId = null): array
    {
        $start = now()->addDay();

        return array_filter([
            'name' => $name,
            'starts_at' => $start->toDateTimeString(),
            'ends_at' => $start->copy()->addDay()->toDateTimeString(),
            'tipo_evento_id' => $tipoId,
        ], fn ($value) => $value !== null);
    }

    private function economicTipoId(): int
    {
        $tipo = TipoEvento::query()->firstOrCreate(
            ['slug' => TipoEvento::SLUG_ACTIVIDAD_ECONOMICA],
            [
                'nombre' => 'Actividad económica',
                'estado' => true,
                'color' => '#22c55e',
            ],
        );

        return (int) $tipo->id;
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
            'active_rol_id' => $roleId ?: null,
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

        $roleId = Role::query()->where('name', $roleName)->value('id');
        if (! $roleId) {
            return;
        }

        PersonaOrganizacionRol::query()->create([
            'persona_organizacion_id' => $membership->id,
            'rol_id' => (int) $roleId,
        ]);
    }
}
