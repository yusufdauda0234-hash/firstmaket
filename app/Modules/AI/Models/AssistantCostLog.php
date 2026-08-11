<?php

namespace App\Modules\AI\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AssistantCostLog extends Model
{
    const UPDATED_AT = null;

    protected $fillable = ['user_id', 'conversation_id', 'driver', 'prompt_tokens', 'completion_tokens', 'cost_kobo'];

    protected function casts(): array
    {
        return [
            'prompt_tokens' => 'integer',
            'completion_tokens' => 'integer',
            'cost_kobo' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
}
