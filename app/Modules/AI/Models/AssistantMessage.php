<?php

namespace App\Modules\AI\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AssistantMessage extends Model
{
    public const ROLE_CUSTOMER = 'customer';

    public const ROLE_ASSISTANT = 'assistant';

    const UPDATED_AT = null;

    protected $fillable = ['conversation_id', 'role', 'body', 'evidence', 'driver'];

    protected function casts(): array
    {
        return ['evidence' => 'array', 'created_at' => 'datetime'];
    }

    /** @return BelongsTo<AssistantConversation, $this> */
    public function conversation(): BelongsTo { return $this->belongsTo(AssistantConversation::class, 'conversation_id'); }
}
