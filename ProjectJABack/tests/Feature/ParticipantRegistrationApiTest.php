<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Auth\Contracts\ParticipantOtpSender;
use App\Modules\Auth\Models\ParticipantRegistrationChallenge;
use App\Modules\Clubs\Models\Persona;
use App\Modules\Organizations\Models\PersonaOrganizacion;
use App\Modules\Users\Models\Role;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ParticipantRegistrationApiTest extends TestCase
{
    use RefreshDatabase;

    private object $sender;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        $this->sender = new class implements ParticipantOtpSender
        {
            public ?string $otp = null;

            public function send(Persona $persona, string $otp): bool
            {
                $this->otp = $otp;

                return true;
            }
        };
        $this->app->instance(ParticipantOtpSender::class, $this->sender);
    }

    public function test_start_does_not_reveal_whether_person_exists_and_hashes_otp(): void
    {
        $persona = $this->createPersona();

        $existing = $this->postJson('/api/v1/auth/participant-registration/start', [
            'tipo_identificacion' => 'CC',
            'identificacion' => $persona->identificacion,
        ]);
        $unknown = $this->postJson('/api/v1/auth/participant-registration/start', [
            'tipo_identificacion' => 'CC',
            'identificacion' => '99999999',
        ]);

        $existing->assertOk()->assertJsonStructure(['data' => ['challenge_id', 'expires_in']]);
        $unknown->assertOk()->assertJsonStructure(['data' => ['challenge_id', 'expires_in']]);
        $this->assertSame($existing->json('message'), $unknown->json('message'));

        $challenge = ParticipantRegistrationChallenge::query()->findOrFail(
            $existing->json('data.challenge_id')
        );
        $this->assertNotSame($this->sender->otp, $challenge->otp_hash);
        $this->assertTrue(Hash::check($this->sender->otp, $challenge->otp_hash));
    }

    public function test_otp_expires_and_is_single_use(): void
    {
        $persona = $this->createPersona();
        $challengeId = $this->start($persona);

        $this->postJson('/api/v1/auth/participant-registration/verify', [
            'challenge_id' => $challengeId,
            'otp' => '000000',
        ])->assertUnprocessable();
        $this->assertSame(
            1,
            ParticipantRegistrationChallenge::query()->findOrFail($challengeId)->attempts
        );

        $verified = $this->postJson('/api/v1/auth/participant-registration/verify', [
            'challenge_id' => $challengeId,
            'otp' => $this->sender->otp,
        ]);
        $verified->assertOk()
            ->assertJsonPath('data.missing_fields', ['password'])
            ->assertJsonStructure(['data' => ['verification_token']]);

        $this->postJson('/api/v1/auth/participant-registration/verify', [
            'challenge_id' => $challengeId,
            'otp' => $this->sender->otp,
        ])->assertUnprocessable();

        $expiredId = $this->start($persona);
        ParticipantRegistrationChallenge::query()->whereKey($expiredId)->update([
            'expires_at' => now()->subSecond(),
        ]);
        $this->postJson('/api/v1/auth/participant-registration/verify', [
            'challenge_id' => $expiredId,
            'otp' => $this->sender->otp,
        ])->assertUnprocessable();
    }

    public function test_complete_creates_linked_user_and_adds_guest_without_removing_roles(): void
    {
        $persona = $this->createPersona([
            'telefono' => null,
            'sexo' => null,
        ]);
        $membership = $this->createClubMembership($persona);
        $director = Role::query()->where('name', 'director')->firstOrFail();
        DB::table('persona_organizacion_rol')->insert([
            'persona_organizacion_id' => $membership->id,
            'rol_id' => $director->id,
            'created_at' => now(),
        ]);

        $challengeId = $this->start($persona);
        $verificationToken = $this->postJson('/api/v1/auth/participant-registration/verify', [
            'challenge_id' => $challengeId,
            'otp' => $this->sender->otp,
        ])->assertOk()->json('data.verification_token');

        $this->postJson('/api/v1/auth/participant-registration/complete', [
            'verification_token' => $verificationToken,
            'telefono' => '+573001112233',
            'sexo' => 'F',
            'password' => 'Password1!',
            'password_confirmation' => 'Password1!',
        ])->assertCreated()->assertJsonStructure(['data' => ['token']]);

        $user = User::query()->where('persona_id', $persona->id)->firstOrFail();
        $this->assertSame($persona->correo, $user->email);
        $roleNames = DB::table('persona_organizacion_rol')
            ->join('roles', 'roles.id', '=', 'persona_organizacion_rol.rol_id')
            ->where('persona_organizacion_id', $membership->id)
            ->pluck('roles.name')
            ->all();
        $this->assertContains('director', $roleNames);
        $this->assertContains('invitado', $roleNames);

        $this->postJson('/api/v1/auth/participant-registration/complete', [
            'verification_token' => $verificationToken,
            'password' => 'Password1!',
            'password_confirmation' => 'Password1!',
        ])->assertUnprocessable();
    }

    private function start(Persona $persona): string
    {
        return $this->postJson('/api/v1/auth/participant-registration/start', [
            'tipo_identificacion' => $persona->tipo_identificacion,
            'identificacion' => $persona->identificacion,
        ])->assertOk()->json('data.challenge_id');
    }

    private function createPersona(array $overrides = []): Persona
    {
        return Persona::query()->create(array_merge([
            'tipo_identificacion' => 'CC',
            'identificacion' => (string) random_int(1000000, 9999999),
            'nombre1' => 'Ana',
            'apellido1' => 'Prueba',
            'correo' => 'ana'.random_int(1, 999999).'@example.test',
            'telefono' => '+573001234567',
            'sexo' => 'F',
        ], $overrides));
    }

    private function createClubMembership(Persona $persona): PersonaOrganizacion
    {
        $typeId = DB::table('tipo_organizacion')->where('nombre', 'Club')->value('id');
        if (! $typeId) {
            $typeId = DB::table('tipo_organizacion')->insertGetId([
                'nombre' => 'Club',
                'estado' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        $organizationId = DB::table('organizacion')->insertGetId([
            'tipo_organizacion_id' => $typeId,
            'nombre' => 'Club de prueba',
            'estado' => true,
        ]);
        DB::table('clubes')->insert([
            'organizacion_id' => $organizationId,
            'nombre' => 'Club de prueba',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return PersonaOrganizacion::query()->create([
            'persona_id' => $persona->id,
            'organizacion_id' => $organizationId,
            'estado' => true,
        ]);
    }
}
