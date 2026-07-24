<?php

namespace App\Modules\Customer\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $user_id
 * @property string|null $default_state
 * @property string|null $default_lga
 * @property-read User $user
 */
class CustomerProfile extends Model
{
    protected $fillable = [
        'user_id',
        'default_state',
        'default_lga',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
