<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Site-wide switches staff flip from the admin, like Snow Day mode.
 */
class Settings
{
    public static function get(string $key, mixed $default = null): mixed
    {
        $all = Cache::remember('stoop:settings', now()->addMinutes(10), fn () => DB::table('site_settings')->pluck('value', 'key')->all());

        return array_key_exists($key, $all) ? json_decode($all[$key], true) : $default;
    }

    public static function set(string $key, mixed $value): void
    {
        DB::table('site_settings')->updateOrInsert(['key' => $key], ['value' => json_encode($value), 'updated_at' => now(), 'created_at' => now()]);
        Cache::forget('stoop:settings');
    }

    public static function snowDay(): bool
    {
        return (bool) self::get('snow_day', false);
    }
}
