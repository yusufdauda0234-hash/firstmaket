<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Platform-wide key/value configuration, editable at runtime by staff.
 *
 * Reads are memoised for the life of the request. Every value that moves from
 * a config file to this table becomes a database query on whatever page reads
 * it, and the hot ones — the returns window on an order page, the risk
 * thresholds on every flag evaluation — are read repeatedly within a single
 * request. Loading the table once and answering from memory keeps "make it
 * configurable" from quietly turning into "make it slower".
 *
 * The cache is per-request only, deliberately. A cross-request cache would
 * need invalidating on every write from every worker, and settings are small
 * enough that one query per request is the right trade.
 */
class Setting extends Model
{
    protected $fillable = [
        'group',
        'key',
        'value',
    ];

    /** @var array<string, mixed>|null */
    private static ?array $memo = null;

    protected function casts(): array
    {
        return [
            'value' => 'array',
        ];
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return static::all_()[$key] ?? $default;
    }

    /**
     * Several settings at once, each falling back to its own default.
     *
     * @param  array<string, mixed>  $keysWithDefaults
     * @return array<string, mixed>
     */
    public static function many(array $keysWithDefaults): array
    {
        $loaded = static::all_();

        $out = [];

        foreach ($keysWithDefaults as $key => $default) {
            $out[$key] = $loaded[$key] ?? $default;
        }

        return $out;
    }

    public static function set(string $key, mixed $value, string $group = 'core'): void
    {
        static::query()->updateOrCreate(
            ['key' => $key],
            ['value' => $value, 'group' => $group],
        );

        // Keep the memo truthful for the rest of this request rather than
        // dropping it: a controller that saves settings and then re-renders
        // the page would otherwise read the table again for every field.
        if (self::$memo !== null) {
            self::$memo[$key] = $value;
        }
    }

    /** Drop the memo — used by tests that change settings between assertions. */
    public static function flushCache(): void
    {
        self::$memo = null;
    }

    /**
     * The whole table, keyed, loaded at most once per request.
     *
     * @return array<string, mixed>
     */
    private static function all_(): array
    {
        return self::$memo ??= static::query()->pluck('value', 'key')->all();
    }
}
