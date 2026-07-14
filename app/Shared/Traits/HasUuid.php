<?php

namespace App\Shared\Traits;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Public identifier convention: integer ids stay internal, uuid is what
 * appears in URLs and route model binding (docs/firstmarket-Database_Schema.md).
 *
 * @property string $uuid
 */
trait HasUuid
{
    public static function bootHasUuid(): void
    {
        static::creating(function (Model $model): void {
            /** @var Model&self $model */
            if (empty($model->uuid)) {
                $model->uuid = (string) Str::uuid();
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }
}
