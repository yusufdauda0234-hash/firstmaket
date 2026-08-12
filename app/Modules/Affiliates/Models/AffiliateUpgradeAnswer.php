<?php

namespace App\Modules\Affiliates\Models;

use App\Models\UploadedDocument;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AffiliateUpgradeAnswer extends Model
{
    protected $fillable = ['request_id', 'requirement_id', 'value', 'uploaded_document_id'];

    /** @return BelongsTo<AffiliateUpgradeRequest, $this> */
    public function request(): BelongsTo { return $this->belongsTo(AffiliateUpgradeRequest::class, 'request_id'); }

    /** @return BelongsTo<AffiliateRankRequirement, $this> */
    public function requirement(): BelongsTo { return $this->belongsTo(AffiliateRankRequirement::class, 'requirement_id'); }

    /** @return BelongsTo<UploadedDocument, $this> */
    public function document(): BelongsTo { return $this->belongsTo(UploadedDocument::class, 'uploaded_document_id'); }
}
