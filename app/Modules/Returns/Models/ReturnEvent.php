<?php

namespace App\Modules\Returns\Models;

use App\Models\User;
use App\Shared\Enums\ReturnStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One step in a return's history, with who took it.
 *
 * Append-only by convention: the timeline the customer, the vendor and an
 * auditor read is the same list, so a money decision can always be
 * reconstructed after the fact.
 *
 * @property int $id
 * @property int $return_request_id
 * @property int|null $actor_id
 * @property ReturnStatus|null $from_status
 * @property ReturnStatus $to_status
 * @property string|null $note
 */
class ReturnEvent extends Model
{
    protected $fillable = ['return_request_id', 'actor_id', 'from_status', 'to_status', 'note'];

    protected function casts(): array
    {
        return [
            'from_status' => ReturnStatus::class,
            'to_status' => ReturnStatus::class,
        ];
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(ReturnRequest::class, 'return_request_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
