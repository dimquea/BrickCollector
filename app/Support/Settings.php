<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Application settings, kept in a table rather than in .env.
 *
 * These are the user's choices, not deployment configuration: they must
 * survive an add-on rebuild and be changeable from the interface, neither of
 * which is true of environment variables.
 */
class Settings
{
    /** @var array<string, string|null>|null */
    private static ?array $cache = null;

    public static function get(string $key, ?string $default = null): ?string
    {
        self::$cache ??= DB::table('settings')->pluck('value', 'key')->all();

        return self::$cache[$key] ?? $default;
    }

    public static function put(string $key, ?string $value): void
    {
        DB::table('settings')->upsert(
            [['key' => $key, 'value' => $value]],
            ['key'],
            ['value'],
        );

        self::$cache = null;
    }

    /** ISO 4217 code used to render prices. Amounts are stored in minor units. */
    public static function currency(): string
    {
        return self::get('currency', config('brickcollector.currency'));
    }

    public static function forget(): void
    {
        self::$cache = null;
    }
}
