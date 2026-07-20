<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Platform-wide key/value configuration editable at runtime by Super
 * Administrator (guarded by the settings.manage permission). Domain-specific
 * settings tables (vendor_fee_settings, ai_settings) arrive with their own
 * modules in later sprints.
 */
class Setting extends Model
{
    protected $fillable = [
        'group',
        'key',
        'value',
    ];

    protected function casts(): array
    {
        return [
            'value' => 'array',
        ];
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return static::query()->where('key', $key)->value('value') ?? $default;
    }

    public static function set(string $key, mixed $value, string $group = 'core'): void
    {
        static::query()->updateOrCreate(
            ['key' => $key],
            ['value' => $value, 'group' => $group],
        );
    }
}
