<?php

namespace App\Modules\Returns\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A photo the customer attached to a return.
 *
 * Kept on the private disk and served through a signed, authorised route
 * rather than a public URL — a photo of somebody's living room is personal
 * data, and a guessable public path would publish it.
 *
 * @property int $id
 * @property int $return_request_id
 * @property string $disk
 * @property string $path
 */
class ReturnEvidence extends Model
{
    protected $table = 'return_evidence';

    protected $fillable = ['return_request_id', 'disk', 'path'];

    public function request(): BelongsTo
    {
        return $this->belongsTo(ReturnRequest::class, 'return_request_id');
    }
}
