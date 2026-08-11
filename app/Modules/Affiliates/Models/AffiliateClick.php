<?php

namespace App\Modules\Affiliates\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AffiliateClick extends Model
{
    public $timestamps = false;
    protected $fillable = ['affiliate_link_id', 'ip_hash', 'fingerprint_hash', 'created_at'];
    protected function casts(): array { return ['created_at' => 'datetime']; }
    /** @return BelongsTo<AffiliateLink, $this> */
    public function link(): BelongsTo { return $this->belongsTo(AffiliateLink::class, 'affiliate_link_id'); }
}
