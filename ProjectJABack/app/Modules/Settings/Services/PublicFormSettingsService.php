<?php

namespace App\Modules\Settings\Services;

use App\Models\User;
use App\Modules\Settings\Models\AppSetting;

final class PublicFormSettingsService
{
    /**
     * @return array{
     *     enabled: bool,
     *     allow_request_asociacion: bool,
     *     allow_request_distrito: bool,
     *     allow_request_iglesia: bool,
     *     allow_request_club: bool
     * }
     */
    public function defaults(): array
    {
        return [
            'enabled' => true,
            'allow_request_asociacion' => true,
            'allow_request_distrito' => true,
            'allow_request_iglesia' => true,
            'allow_request_club' => true,
        ];
    }

    /**
     * @return array{
     *     enabled: bool,
     *     allow_request_asociacion: bool,
     *     allow_request_distrito: bool,
     *     allow_request_iglesia: bool,
     *     allow_request_club: bool
     * }
     */
    public function get(): array
    {
        $stored = AppSetting::current()->public_form ?? [];
        $defaults = $this->defaults();
        if (! is_array($stored)) {
            return $defaults;
        }

        return [
            'enabled' => array_key_exists('enabled', $stored)
                ? (bool) $stored['enabled']
                : $defaults['enabled'],
            'allow_request_asociacion' => array_key_exists('allow_request_asociacion', $stored)
                ? (bool) $stored['allow_request_asociacion']
                : $defaults['allow_request_asociacion'],
            'allow_request_distrito' => array_key_exists('allow_request_distrito', $stored)
                ? (bool) $stored['allow_request_distrito']
                : $defaults['allow_request_distrito'],
            'allow_request_iglesia' => array_key_exists('allow_request_iglesia', $stored)
                ? (bool) $stored['allow_request_iglesia']
                : $defaults['allow_request_iglesia'],
            'allow_request_club' => array_key_exists('allow_request_club', $stored)
                ? (bool) $stored['allow_request_club']
                : $defaults['allow_request_club'],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, bool>
     */
    public function update(array $data, User $actor): array
    {
        $settings = AppSetting::current();
        $settings->public_form = [
            'enabled' => (bool) ($data['enabled'] ?? false),
            'allow_request_asociacion' => (bool) ($data['allow_request_asociacion'] ?? false),
            'allow_request_distrito' => (bool) ($data['allow_request_distrito'] ?? false),
            'allow_request_iglesia' => (bool) ($data['allow_request_iglesia'] ?? false),
            'allow_request_club' => (bool) ($data['allow_request_club'] ?? false),
        ];
        $settings->updated_by = $actor->id;
        $settings->save();

        return $this->get();
    }

    public function allows(string $key): bool
    {
        return (bool) ($this->get()[$key] ?? false);
    }
}
