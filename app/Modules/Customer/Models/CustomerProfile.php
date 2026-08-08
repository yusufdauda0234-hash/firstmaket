<?php

namespace App\Modules\Customer\Models;

use App\Models\User;
use App\Shared\Casts\Uppercase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $user_id
 * @property string|null $default_state
 * @property string|null $default_lga
 * @property string|null $default_recipient_name
 * @property string|null $default_recipient_phone
 * @property string|null $default_address
 * @property string|null $default_landmark
 * @property-read User $user
 */
class CustomerProfile extends Model
{
    protected $fillable = [
        'user_id',
        'default_state',
        'default_lga',
        'default_recipient_name',
        'default_recipient_phone',
        'default_address',
        'default_landmark',
    ];

    protected function casts(): array
    {
        return [
            // Matches how the same fields are stored on an order. State is
            // left alone: it is validated against the fixed Nigeria::STATES
            // list, which is not uppercase.
            'default_recipient_name' => Uppercase::class,
            'default_lga' => Uppercase::class,
            'default_address' => Uppercase::class,
            'default_landmark' => Uppercase::class,
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The saved delivery address, or null when none has been saved.
     *
     * Street, state and LGA must all be present — prefilling a form from
     * half an address gives the courier something it cannot use.
     *
     * @return array<string, string>|null
     */
    public function defaultAddress(): ?array
    {
        if ($this->default_address === null || $this->default_state === null || $this->default_lga === null) {
            return null;
        }

        return [
            'recipient_name' => $this->default_recipient_name ?? '',
            'recipient_phone' => $this->default_recipient_phone ?? '',
            'delivery_address' => $this->default_address,
            'state' => $this->default_state,
            'lga' => $this->default_lga,
            'landmark' => $this->default_landmark ?? '',
        ];
    }
}
