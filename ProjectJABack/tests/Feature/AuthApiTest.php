<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AuthApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_login_success(): void
    {
        $user = User::factory()->create([
            'email' => 'demo@projectja.local',
            'password' => 'Password1!',
            'is_active' => true,
        ]);
        $user->forceFill(['is_admin' => true])->save();
        $user->clearPermissionCache();

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'demo@projectja.local',
            'password' => 'Password1!',
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['data' => ['token', 'user']]);
    }

    public function test_login_fails_with_bad_credentials(): void
    {
        User::factory()->create([
            'email' => 'demo@projectja.local',
            'password' => 'Password1!',
        ]);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'demo@projectja.local',
            'password' => 'wrong',
        ])->assertStatus(422)->assertJsonPath('success', false);
    }

    public function test_inactive_user_cannot_login(): void
    {
        User::factory()->inactive()->create([
            'email' => 'inactive@projectja.local',
            'password' => 'Password1!',
        ]);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'inactive@projectja.local',
            'password' => 'Password1!',
        ])->assertStatus(422);
    }

    public function test_unverified_user_cannot_login(): void
    {
        User::factory()->unverified()->create([
            'email' => 'unverified@projectja.local',
            'password' => 'Password1!',
            'is_active' => true,
        ]);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'unverified@projectja.local',
            'password' => 'Password1!',
        ])->assertStatus(422);
    }

    public function test_verify_email_code_confirms_account(): void
    {
        $user = User::factory()->unverified()->create([
            'email' => 'new@projectja.local',
            'is_active' => true,
        ]);
        $user->forceFill([
            'email_verification_code_hash' => Hash::make('123456'),
            'email_verification_expires_at' => now()->addMinutes(15),
        ])->save();

        $this->postJson('/api/v1/auth/email/verify-code', [
            'email' => 'new@projectja.local',
            'code' => '123456',
        ])->assertOk()->assertJsonPath('success', true);

        $this->assertNotNull($user->fresh()->email_verified_at);
        $this->assertNull($user->fresh()->email_verification_code_hash);
    }

    public function test_verify_email_code_rejects_wrong_code(): void
    {
        $user = User::factory()->unverified()->create([
            'email' => 'new@projectja.local',
            'is_active' => true,
        ]);
        $user->forceFill([
            'email_verification_code_hash' => Hash::make('123456'),
            'email_verification_expires_at' => now()->addMinutes(15),
        ])->save();

        $this->postJson('/api/v1/auth/email/verify-code', [
            'email' => 'new@projectja.local',
            'code' => '000000',
        ])->assertStatus(422);

        $this->assertNull($user->fresh()->email_verified_at);
    }

    public function test_me_requires_auth(): void
    {
        $this->getJson('/api/v1/auth/me')->assertUnauthorized();
    }

    public function test_me_returns_user(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.email', $user->email);
    }
}
