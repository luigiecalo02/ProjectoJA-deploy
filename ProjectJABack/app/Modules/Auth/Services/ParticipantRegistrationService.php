<?php

namespace App\Modules\Auth\Services;

use App\Models\User;
use App\Modules\Auth\Contracts\ParticipantOtpSender;
use App\Modules\Auth\Models\ParticipantRegistrationChallenge;
use App\Modules\Clubs\Models\Persona;
use App\Modules\Organizations\Models\PersonaOrganizacion;
use App\Modules\Users\Models\Role;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class ParticipantRegistrationService
{
    public function __construct(private readonly ParticipantOtpSender $otpSender) {}

    public function start(string $documentType, string $documentNumber): array
    {
        $documentType = Str::upper(trim($documentType));
        $documentNumber = trim($documentNumber);
        $identifierHash = hash_hmac(
            'sha256',
            "{$documentType}|{$documentNumber}",
            (string) config('app.key')
        );
        $otp = (string) random_int(100000, 999999);

        $persona = Persona::query()
            ->where('tipo_identificacion', $documentType)
            ->where('identificacion', $documentNumber)
            ->first();

        $eligible = $persona
            && ! User::withTrashed()->where('persona_id', $persona->id)->exists()
            && (filled($persona->correo) || filled($persona->telefono));

        ParticipantRegistrationChallenge::query()
            ->where('identifier_hash', $identifierHash)
            ->where(function ($query): void {
                $query->whereNull('consumed_at')
                    ->orWhereNotNull('verification_token_hash');
            })
            ->update([
                'consumed_at' => now(),
                'verification_token_hash' => null,
                'verification_expires_at' => null,
            ]);

        $challenge = ParticipantRegistrationChallenge::query()->create([
            'id' => (string) Str::uuid(),
            'persona_id' => $eligible ? $persona->id : null,
            'identifier_hash' => $identifierHash,
            'otp_hash' => Hash::make($otp),
            'expires_at' => now()->addMinutes(config('participant-registration.otp_ttl_minutes')),
        ]);

        if ($eligible) {
            try {
                if (! $this->otpSender->send($persona, $otp)) {
                    $challenge->update(['persona_id' => null]);
                }
            } catch (\Throwable) {
                $challenge->update(['persona_id' => null]);
            }
        }

        return [
            'challenge_id' => $challenge->id,
            'expires_in' => config('participant-registration.otp_ttl_minutes') * 60,
        ];
    }

    public function verify(string $challengeId, string $otp): array
    {
        $result = DB::transaction(function () use ($challengeId, $otp): ?array {
            $challenge = ParticipantRegistrationChallenge::query()
                ->lockForUpdate()
                ->find($challengeId);

            $valid = $challenge
                && $challenge->persona_id
                && ! $challenge->consumed_at
                && $challenge->expires_at->isFuture()
                && $challenge->attempts < config('participant-registration.max_otp_attempts')
                && Hash::check($otp, $challenge->otp_hash);

            if (! $valid) {
                if ($challenge && ! $challenge->consumed_at) {
                    $challenge->increment('attempts');
                }

                return null;
            }

            $token = Str::random(64);
            $challenge->update([
                'consumed_at' => now(),
                'verification_token_hash' => hash('sha256', $token),
                'verification_expires_at' => now()->addMinutes(
                    config('participant-registration.verification_ttl_minutes')
                ),
            ]);

            $persona = Persona::query()->findOrFail($challenge->persona_id);

            return [
                'verification_token' => $token,
                'missing_fields' => [...$this->missingFields($persona), 'password'],
                'expires_in' => config('participant-registration.verification_ttl_minutes') * 60,
            ];
        });

        if ($result === null) {
            throw ValidationException::withMessages([
                'otp' => ['El código no es válido o ya expiró.'],
            ]);
        }

        return $result;
    }

    public function complete(string $verificationToken, array $data): array
    {
        try {
            return DB::transaction(function () use ($verificationToken, $data): array {
                $challenge = ParticipantRegistrationChallenge::query()
                    ->where('verification_token_hash', hash('sha256', $verificationToken))
                    ->lockForUpdate()
                    ->first();

                if (! $challenge
                    || ! $challenge->persona_id
                    || ! $challenge->verification_expires_at?->isFuture()) {
                    throw ValidationException::withMessages([
                        'verification_token' => ['La verificación no es válida o ya expiró.'],
                    ]);
                }

                $persona = Persona::query()->lockForUpdate()->findOrFail($challenge->persona_id);
                if (User::withTrashed()->where('persona_id', $persona->id)->exists()) {
                    throw ValidationException::withMessages([
                        'verification_token' => ['El registro no puede completarse.'],
                    ]);
                }

                $updates = [];
                foreach (['correo', 'telefono', 'sexo', 'nombre1', 'apellido1'] as $field) {
                    if (! filled($persona->{$field}) && filled($data[$field] ?? null)) {
                        $updates[$field] = trim((string) $data[$field]);
                    }
                }
                if ($updates !== []) {
                    $persona->update($updates);
                    $persona->refresh();
                }

                $missing = $this->missingFields($persona);
                if ($missing !== []) {
                    throw ValidationException::withMessages(
                        collect($missing)->mapWithKeys(
                            fn (string $field) => [$field => ['Este dato es obligatorio.']]
                        )->all()
                    );
                }

                $user = User::query()->create([
                    'persona_id' => $persona->id,
                    'name' => $persona->full_name,
                    'email' => $persona->correo,
                    'password' => $data['password'],
                    'is_active' => true,
                ]);

                $this->assignGuestRole($persona);
                $challenge->update([
                    'verification_token_hash' => null,
                    'verification_expires_at' => null,
                ]);

                return [
                    'user' => $user,
                    'token' => $user->createToken('api')->plainTextToken,
                ];
            });
        } catch (QueryException $exception) {
            if (in_array((string) $exception->getCode(), ['23000', '19'], true)) {
                throw ValidationException::withMessages([
                    'verification_token' => ['El registro no puede completarse.'],
                ]);
            }

            throw $exception;
        }
    }

    /**
     * @return list<string>
     */
    private function missingFields(Persona $persona): array
    {
        return collect(['correo', 'telefono', 'sexo', 'nombre1', 'apellido1'])
            ->filter(fn (string $field) => ! filled($persona->{$field}))
            ->values()
            ->all();
    }

    private function assignGuestRole(Persona $persona): void
    {
        $roleId = Role::query()->where('name', 'invitado')->where('estado', true)->value('id');
        if (! $roleId) {
            throw ValidationException::withMessages([
                'verification_token' => ['El registro no está disponible temporalmente.'],
            ]);
        }

        $memberships = PersonaOrganizacion::query()
            ->where('persona_id', $persona->id)
            ->where('estado', true)
            ->get();
        $clubOrganizationIds = DB::table('clubes')
            ->whereNull('deleted_at')
            ->whereIn('organizacion_id', $memberships->pluck('organizacion_id'))
            ->pluck('organizacion_id');
        $targets = $clubOrganizationIds->isNotEmpty()
            ? $memberships->whereIn('organizacion_id', $clubOrganizationIds)
            : $memberships;

        foreach ($targets as $membership) {
            DB::table('persona_organizacion_rol')->insertOrIgnore([
                'persona_organizacion_id' => $membership->id,
                'rol_id' => $roleId,
                'created_at' => now(),
            ]);
        }
    }
}
