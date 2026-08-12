<?php

namespace App\Modules\Reporting\Models;

use App\Models\User;
use App\Shared\Enums\ExpenseCategory;
use App\Shared\Enums\ExpenseStatus;
use App\Shared\Traits\HasUuid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One thing the business spent money on.
 *
 * @property int $id
 * @property string $uuid
 * @property string $reference
 * @property ExpenseCategory $category
 * @property string $description
 * @property string|null $payee
 * @property int $amount_kobo
 * @property Carbon $incurred_on
 * @property string|null $payment_method
 * @property string|null $note
 * @property string|null $receipt_path
 * @property ExpenseStatus $status
 * @property int|null $recorded_by
 * @property int|null $approved_by
 * @property Carbon|null $approved_at
 * @property string|null $decision_note
 */
class Expense extends Model
{
    use HasUuid;

    protected $table = 'business_expenses';

    protected $fillable = [
        'reference',
        'category',
        'description',
        'payee',
        'amount_kobo',
        'incurred_on',
        'payment_method',
        'note',
        'receipt_path',
        'status',
        'recorded_by',
        'approved_by',
        'approved_at',
        'decision_note',
    ];

    protected function casts(): array
    {
        return [
            'category' => ExpenseCategory::class,
            'status' => ExpenseStatus::class,
            'amount_kobo' => 'integer',
            'incurred_on' => 'date',
            'approved_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    /** @return BelongsTo<User, $this> */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /**
     * Spend that counts.
     *
     * Rejected claims stay on the record — deleting them would hide that
     * somebody asked — but they are not money the business parted with, so
     * every total goes through this.
     *
     * @param  Builder<Expense>  $query
     */
    public function scopeCounted(Builder $query): void
    {
        $query->where('status', '!=', ExpenseStatus::Rejected);
    }

    public function isDecided(): bool
    {
        return $this->status !== ExpenseStatus::Pending;
    }
}
