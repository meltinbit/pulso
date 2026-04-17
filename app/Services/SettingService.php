<?php

namespace App\Services;

use App\Models\AppSetting;
use Illuminate\Support\Facades\Crypt;

class SettingService
{
    public function get(int $userId, string $key, ?string $default = null): ?string
    {
        $setting = AppSetting::where('user_id', $userId)->where('key', $key)->first();

        if (! $setting || $setting->value === null) {
            return $default;
        }

        return $setting->is_encrypted
            ? Crypt::decryptString($setting->value)
            : $setting->value;
    }

    public function set(int $userId, string $key, ?string $value, string $group = 'general', bool $encrypted = false): void
    {
        $storedValue = ($value !== null && $encrypted)
            ? Crypt::encryptString($value)
            : $value;

        AppSetting::updateOrCreate(
            ['user_id' => $userId, 'key' => $key],
            [
                'group' => $group,
                'value' => $storedValue,
                'is_encrypted' => $encrypted,
            ]
        );
    }

    /** @return array<string, ?string> */
    public function getGroup(int $userId, string $group): array
    {
        return AppSetting::where('user_id', $userId)
            ->where('group', $group)
            ->get()
            ->mapWithKeys(fn (AppSetting $setting) => [
                $setting->key => $setting->is_encrypted && $setting->value !== null
                    ? Crypt::decryptString($setting->value)
                    : $setting->value,
            ])
            ->toArray();
    }
}
