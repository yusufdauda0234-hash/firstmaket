<?php

namespace App\Modules\Settings\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $code ISO 3166-1 alpha-2 code
 * @property string $name Full country name
 * @property bool $is_active
 * @property int $sort_order
 */
class Country extends Model
{
    protected $fillable = ['code', 'name', 'is_active', 'sort_order'];

    protected function casts(): array
    {
        return [
            'is_active' => 'bool',
        ];
    }

    public static function active()
    {
        return self::where('is_active', true)->orderBy('sort_order')->get();
    }
}
