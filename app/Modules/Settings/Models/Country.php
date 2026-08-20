<?php

namespace App\Modules\Settings\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $code ISO 3166-1 alpha-2 code
 * @property string $name Full country name
 * @property string|null $capital Capital city
 * @property string|null $region Geographic region
 * @property string|null $flag_emoji Flag emoji
 * @property bool $is_active
 * @property int $sort_order
 */
class Country extends Model
{
    protected $fillable = ['code', 'name', 'capital', 'region', 'flag_emoji', 'is_active', 'sort_order'];

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

    public function states(): HasMany
    {
        return $this->hasMany(State::class);
    }
}
