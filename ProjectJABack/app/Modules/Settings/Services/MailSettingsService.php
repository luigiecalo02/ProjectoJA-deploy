<?php

namespace App\Modules\Settings\Services;

use App\Models\User;
use App\Modules\Settings\Models\AppSetting;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

final class MailSettingsService
{
    /**
     * @return array<string, mixed>
     */
    public function publicConfig(): array
    {
        $mail = AppSetting::current()->mail ?? [];

        $password = $this->decryptPassword($mail['password'] ?? null);

        return [
            'host' => $mail['host'] ?? '',
            'port' => isset($mail['port']) ? (int) $mail['port'] : 587,
            'encryption' => $mail['encryption'] ?? 'tls',
            'username' => $mail['username'] ?? '',
            'from_address' => $mail['from_address'] ?? '',
            'from_name' => $mail['from_name'] ?? config('app.name'),
            'password' => $password ?? '',
            'password_set' => $password !== null,
            'configured' => $this->isConfigured(),
        ];
    }

    public function isConfigured(): bool
    {
        $mail = AppSetting::current()->mail ?? [];

        return filled($mail['host'] ?? null)
            && filled($mail['from_address'] ?? null)
            && filled($mail['username'] ?? null)
            && filled($mail['password'] ?? null);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function update(array $data, User $actor): array
    {
        $settings = AppSetting::current();
        $current = $settings->mail ?? [];

        $password = $data['password'] ?? null;
        if (filled($password)) {
            $current['password'] = Crypt::encryptString((string) $password);
        }

        $settings->mail = [
            'host' => trim((string) ($data['host'] ?? $current['host'] ?? '')),
            'port' => (int) ($data['port'] ?? $current['port'] ?? 587),
            'encryption' => $data['encryption'] ?? $current['encryption'] ?? 'tls',
            'username' => trim((string) ($data['username'] ?? $current['username'] ?? '')),
            'password' => $current['password'] ?? null,
            'from_address' => trim((string) ($data['from_address'] ?? $current['from_address'] ?? '')),
            'from_name' => trim((string) ($data['from_name'] ?? $current['from_name'] ?? config('app.name'))),
        ];
        $settings->updated_by = $actor->id;
        $settings->save();

        return $this->publicConfig();
    }

    public function apply(): void
    {
        $mail = $this->decrypted();
        if ($mail === null) {
            return;
        }

        $encryption = $mail['encryption'] === 'none' ? null : $mail['encryption'];

        config([
            'mail.default' => 'smtp',
            'mail.mailers.smtp.transport' => 'smtp',
            'mail.mailers.smtp.host' => $mail['host'],
            'mail.mailers.smtp.port' => $mail['port'],
            'mail.mailers.smtp.encryption' => $encryption,
            'mail.mailers.smtp.username' => $mail['username'],
            'mail.mailers.smtp.password' => $mail['password'],
            'mail.from.address' => $mail['from_address'],
            'mail.from.name' => $mail['from_name'],
        ]);
    }

    public function sendTest(string $to): void
    {
        if (! $this->isConfigured()) {
            throw ValidationException::withMessages([
                'mail' => ['Configura primero la cuenta de correo SMTP.'],
            ]);
        }

        $this->apply();
        Mail::raw('Correo de prueba de ProjectJA. La configuración SMTP funciona.', function ($message) use ($to) {
            $message->to($to)->subject('ProjectJA · Correo de prueba');
        });
    }

    private function decryptPassword(mixed $encrypted): ?string
    {
        if (! filled($encrypted)) {
            return null;
        }

        try {
            return Crypt::decryptString((string) $encrypted);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return array{host: string, port: int, encryption: string, username: string, password: string, from_address: string, from_name: string}|null
     */
    private function decrypted(): ?array
    {
        $mail = AppSetting::current()->mail ?? [];
        if (! $this->isConfigured()) {
            return null;
        }

        $password = $this->decryptPassword($mail['password'] ?? null);
        if ($password === null) {
            return null;
        }

        return [
            'host' => (string) $mail['host'],
            'port' => (int) $mail['port'],
            'encryption' => (string) ($mail['encryption'] ?? 'tls'),
            'username' => (string) $mail['username'],
            'password' => $password,
            'from_address' => (string) $mail['from_address'],
            'from_name' => (string) ($mail['from_name'] ?? config('app.name')),
        ];
    }
}
