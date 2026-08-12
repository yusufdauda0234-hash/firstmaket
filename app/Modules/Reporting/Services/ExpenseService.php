<?php

namespace App\Modules\Reporting\Services;

use App\Models\User;
use App\Modules\Reporting\Models\Expense;
use App\Shared\Contracts\AuditLoggerContract;
use App\Shared\Enums\ExpenseCategory;
use App\Shared\Enums\ExpenseStatus;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Recording, approving and totalling what the business spends.
 */
class ExpenseService
{
    public function __construct(private readonly AuditLoggerContract $auditLogger) {}

    /**
     * Record a new expense. Always starts pending — recording is not
     * approving, even when the same person can do both.
     *
     * @param  array<string, mixed>  $data
     */
    public function record(User $actor, array $data): Expense
    {
        return DB::transaction(function () use ($actor, $data) {
            $expense = Expense::query()->create([
                // Replaced below with a number built from the row id, the one
                // value guaranteed unique without a counter to lock.
                'reference' => 'pending-'.uniqid(),
                'category' => $data['category'],
                'description' => $data['description'],
                'payee' => $data['payee'] ?? null,
                'amount_kobo' => $data['amount_kobo'],
                'incurred_on' => $data['incurred_on'],
                'payment_method' => $data['payment_method'] ?? null,
                'note' => $data['note'] ?? null,
                'receipt_path' => $data['receipt_path'] ?? null,
                'status' => ExpenseStatus::Pending,
                'recorded_by' => $actor->id,
            ]);

            $expense->forceFill([
                'reference' => sprintf('EXP-%s-%05d', $expense->incurred_on->format('Y'), $expense->id),
            ])->save();

            $this->auditLogger->log(
                actor: $actor,
                subject: $expense,
                action: 'finance.expense_recorded',
                newValues: [
                    'reference' => $expense->reference,
                    'category' => $expense->category->value,
                    'amount_kobo' => $expense->amount_kobo,
                    'incurred_on' => $expense->incurred_on->toDateString(),
                ],
            );

            return $expense;
        });
    }

    /**
     * Approve or reject.
     *
     * A decision is made once. Re-approving an already-approved expense, or
     * quietly flipping an approved one to rejected weeks later, would let a
     * total change after somebody had already acted on it.
     */
    public function decide(User $actor, Expense $expense, ExpenseStatus $status, ?string $note = null): Expense
    {
        if ($expense->isDecided()) {
            throw ValidationException::withMessages([
                'status' => "This expense was already {$expense->status->value}.",
            ]);
        }

        if ($status === ExpenseStatus::Pending) {
            throw ValidationException::withMessages([
                'status' => 'Choose approve or reject.',
            ]);
        }

        // The person who spent it cannot be the person who signs it off.
        // Without this, "record and approve" is one action wearing two names.
        if ($expense->recorded_by === $actor->id) {
            throw ValidationException::withMessages([
                'status' => 'Somebody else has to approve an expense you recorded.',
            ]);
        }

        $previous = $expense->status;

        $expense->forceFill([
            'status' => $status,
            'approved_by' => $actor->id,
            'approved_at' => now(),
            'decision_note' => $note,
        ])->save();

        $this->auditLogger->log(
            actor: $actor,
            subject: $expense,
            action: 'finance.expense_'.$status->value,
            oldValues: ['status' => $previous->value],
            newValues: ['status' => $status->value, 'note' => $note],
        );

        return $expense;
    }

    /**
     * Spend per category over a window.
     *
     * @return list<array{category: string, label: string, totalKobo: int, count: int}>
     */
    public function byCategory(Carbon $from, Carbon $to): array
    {
        return Expense::query()
            ->counted()
            ->whereBetween('incurred_on', [$from->toDateString(), $to->toDateString()])
            ->select('category', DB::raw('sum(amount_kobo) as total'), DB::raw('count(*) as entries'))
            ->groupBy('category')
            ->orderByDesc('total')
            ->get()
            ->map(fn ($row) => [
                'category' => (string) $row->category->value,
                'label' => $row->category->label(),
                'totalKobo' => (int) $row->total,
                'count' => (int) $row->entries,
            ])
            ->all();
    }

    /**
     * Spend per month over a window, oldest first.
     *
     * @return list<array{month: string, totalKobo: int}>
     */
    public function byMonth(Carbon $from, Carbon $to): array
    {
        return Expense::query()
            ->counted()
            ->whereBetween('incurred_on', [$from->toDateString(), $to->toDateString()])
            ->select(DB::raw("date_format(incurred_on, '%Y-%m') as month"), DB::raw('sum(amount_kobo) as total'))
            ->groupBy('month')
            ->orderBy('month')
            ->get()
            ->map(fn ($row) => ['month' => (string) $row->month, 'totalKobo' => (int) $row->total])
            ->all();
    }

    /** Total spend in the window, approved and pending but never rejected. */
    public function totalKobo(Carbon $from, Carbon $to): int
    {
        return (int) Expense::query()
            ->counted()
            ->whereBetween('incurred_on', [$from->toDateString(), $to->toDateString()])
            ->sum('amount_kobo');
    }

    /** Approved spend only — the figure that belongs in a profit line. */
    public function approvedKobo(Carbon $from, Carbon $to): int
    {
        return (int) Expense::query()
            ->where('status', ExpenseStatus::Approved)
            ->whereBetween('incurred_on', [$from->toDateString(), $to->toDateString()])
            ->sum('amount_kobo');
    }

    /** @return list<array{value: string, label: string}> */
    public function categories(): array
    {
        return ExpenseCategory::options();
    }
}
