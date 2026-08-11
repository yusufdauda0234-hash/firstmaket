<?php

namespace App\Modules\AI\Models;

use App\Models\User;
use App\Shared\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AssistantConversation extends Model
{
    use HasUuid;

    protected $fillable = ['user_id', 'title', 'last_message_at'];

    protected function casts(): array
    {
        return ['last_message_at' => 'datetime'];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo { return $this->belongsTo(User::class); }

    /** @return HasMany<AssistantMessage, $this> */
    public function messages(): HasMany { return $this->hasMany(AssistantMessage::class, 'conversation_id'); }

    /** @return HasMany<AssistantRecommendation, $this> */
    public function recommendations(): HasMany { return $this->hasMany(AssistantRecommendation::class, 'conversation_id'); }
}
