<?php

namespace App\Services;

use App\Models\AppSetting;
use Illuminate\Support\Facades\Crypt;

class SettingService
{
    public function get(string $key, ?string $default = null): ?string
    {
        $setting = AppSetting::where('key', $key)->first();

        if (! $setting) {
            return $default;
        }

        if ($setting->value === null) {
            return $default;
        }

        return $setting->is_encrypted
            ? Crypt::decryptString($setting->value)
            : $setting->value;
    }

    public function set(string $key, ?string $value, string $group = 'general', bool $encrypted = false): void
    {
        $storedValue = ($value !== null && $encrypted)
            ? Crypt::encryptString($value)
            : $value;

        AppSetting::updateOrCreate(
            ['key' => $key],
            [
                'group' => $group,
                'value' => $storedValue,
                'is_encrypted' => $encrypted,
            ]
        );
    }

    /** @return array<string, ?string> */
    public function getGroup(string $group): array
    {
        return AppSetting::where('group', $group)
            ->get()
            ->mapWithKeys(fn (AppSetting $setting) => [
                $setting->key => $setting->is_encrypted && $setting->value !== null
                    ? Crypt::decryptString($setting->value)
                    : $setting->value,
            ])
            ->toArray();
    }
}
