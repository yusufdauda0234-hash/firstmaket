<?php

namespace App\Modules\Reporting\Services;

use App\Shared\Enums\ExpenseStatus;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Every movement of money, in one place.
 *
 * FirstMaket has no single transactions table and deliberately should not:
 * a customer charge, a vendor payout, an affiliate payout, a refund and an
 * office expense are genuinely different records with different lifecycles
 * and different owners. What was missing was a way to read them together.
 *
 * This union does that at query time. Nothing here writes, and no figure is
 * copied into a second place that could drift from the record it came from.
 */
class LedgerService
{
    /** Money coming in. */
    public const DIRECTION_IN = 'in';

    /** Money going out. */
    public const DIRECTION_OUT = 'out';

    /**
     * The ledger, newest first, paginated.
     *
     * @param  list<string>  $kinds  Empty for everything.
     */
    public function entries(Carbon $from, Carbon $to, array $kinds = [], string $direction = ''): LengthAwarePaginator
    {
        $union = $this->union($from, $to);

        $query = DB::query()
            ->fromSub($union, 'ledger')
            ->when($kinds !== [], fn (Builder $q) => $q->whereIn('kind', $kinds))
            ->when($direction !== '', fn (Builder $q) => $q->where('direction', $direction))
            ->orderByDesc('occurred_at')
            ->orderByDesc('kind');

        return $query->paginate(50);
    }

    /**
     * Walk the whole window in chunks, oldest first.
     *
     * For exports: a year of a busy marketplace is more rows than a PHP array
     * should be asked to hold, and an export that dies on the biggest month
     * is the one nobody can use.
     *
     * @param  callable(Collection<int, object>): void  $callback
     */
    public function each(Carbon $from, Carbon $to, callable $callback, int $size = 500): void
    {
        DB::query()
            ->fromSub($this->union($from, $to), 'ledger')
            ->orderBy('occurred_at')
            ->orderBy('kind')
            ->chunk($size, function ($rows) use ($callback) {
                $callback($rows);
            });
    }

    /**
     * The money picture over a window.
     *
     * @return array<string, mixed>
     */
    public function summary(Carbon $from, Carbon $to): array
    {
        $totals = DB::query()
            ->fromSub($this->union($from, $to), 'ledger')
            ->select('kind', 'direction', DB::raw('sum(amount_kobo) as total'), DB::raw('count(*) as entries'))
            ->groupBy('kind', 'direction')
            ->get();

        $byKind = [];
        $inKobo = 0;
        $outKobo = 0;

        foreach ($totals as $row) {
            $byKind[$row->kind] = [
                'kind' => (string) $row->kind,
                'label' => $this->label((string) $row->kind),
                'direction' => (string) $row->direction,
                'totalKobo' => (int) $row->total,
                'count' => (int) $row->entries,
            ];

            if ($row->direction === self::DIRECTION_IN) {
                $inKobo += (int) $row->total;
            } else {
                $outKobo += (int) $row->total;
            }
        }

        return [
            'inKobo' => $inKobo,
            'outKobo' => $outKobo,
            // What the platform is actually left holding. Not profit — a
            // vendor payout that has not run yet is money still in the
            // account but already owed, and this number says so honestly
            // rather than flattering it.
            'netKobo' => $inKobo - $outKobo,
            'commissionKobo' => $this->commissionKobo($from, $to),
            'byKind' => array_values($byKind),
            'byMonth' => $this->byMonth($from, $to),
        ];
    }

    /**
     * Platform revenue: the commission snapshotted onto each order, less the
     * promotional discount funded out of it.
     *
     * Read from orders rather than counted as a ledger line because no money
     * moves when it is earned — it is the platform's share of a charge that
     * already appears as a customer payment. Counting it again would double
     * the income.
     */
    public function commissionKobo(Carbon $from, Carbon $to): int
    {
        return (int) DB::table('orders')
            ->whereBetween('created_at', [$from, $to])
            ->whereNotIn('status', ['cancelled', 'vendor_rejected'])
            ->sum(DB::raw('commission_amount_kobo - promo_discount_kobo'));
    }

    /**
     * Month-by-month in and out, oldest first.
     *
     * @return list<array{month: string, inKobo: int, outKobo: int}>
     */
    private function byMonth(Carbon $from, Carbon $to): array
    {
        $rows = DB::query()
            ->fromSub($this->union($from, $to), 'ledger')
            ->select(
                DB::raw("date_format(occurred_at, '%Y-%m') as month"),
                'direction',
                DB::raw('sum(amount_kobo) as total'),
            )
            ->groupBy('month', 'direction')
            ->orderBy('month')
            ->get();

        $months = [];

        foreach ($rows as $row) {
            $month = (string) $row->month;
            $months[$month] ??= ['month' => $month, 'inKobo' => 0, 'outKobo' => 0];
            $months[$month][$row->direction === self::DIRECTION_IN ? 'inKobo' : 'outKobo'] += (int) $row->total;
        }

        return array_values($months);
    }

    /**
     * The union itself.
     *
     * Every branch produces the same six columns so the pieces can be read as
     * one list. Only settled money appears: a pending charge is not income
     * and a queued payout has not left the account.
     */
    private function union(Carbon $from, Carbon $to): Builder
    {
        $payments = DB::table('paystack_transactions')
            ->join('users', 'users.id', '=', 'paystack_transactions.user_id')
            ->where('paystack_transactions.status', 'success')
            // Verified by webhook, not merely reported by the browser. The
            // callback is a hint; this column is the proof.
            ->whereNotNull('paystack_transactions.webhook_verified_at')
            ->whereBetween('paystack_transactions.webhook_verified_at', [$from, $to])
            ->select([
                DB::raw("'customer_payment' as kind"),
                DB::raw("'".self::DIRECTION_IN."' as direction"),
                'paystack_transactions.amount_kobo as amount_kobo',
                'paystack_transactions.webhook_verified_at as occurred_at',
                'users.name as party',
                'paystack_transactions.paystack_reference as reference',
            ]);

        $refunds = DB::table('refunds')
            ->join('users', 'users.id', '=', 'refunds.customer_id')
            ->where('refunds.status', 'completed')
            ->whereNotNull('refunds.completed_at')
            ->whereBetween('refunds.completed_at', [$from, $to])
            ->select([
                DB::raw("'refund' as kind"),
                DB::raw("'".self::DIRECTION_OUT."' as direction"),
                'refunds.amount_kobo as amount_kobo',
                'refunds.completed_at as occurred_at',
                'users.name as party',
                DB::raw('coalesce(refunds.gateway_reference, refunds.uuid) as reference'),
            ]);

        $vendorPayouts = DB::table('vendor_payout_items')
            ->join('vendor_profiles', 'vendor_profiles.id', '=', 'vendor_payout_items.vendor_id')
            ->where('vendor_payout_items.status', 'paid')
            ->whereNotNull('vendor_payout_items.paid_at')
            ->whereBetween('vendor_payout_items.paid_at', [$from, $to])
            ->select([
                DB::raw("'vendor_payout' as kind"),
                DB::raw("'".self::DIRECTION_OUT."' as direction"),
                'vendor_payout_items.amount_kobo as amount_kobo',
                'vendor_payout_items.paid_at as occurred_at',
                'vendor_profiles.business_name as party',
                DB::raw("coalesce(vendor_payout_items.paystack_transfer_reference, concat('VP-', vendor_payout_items.id)) as reference"),
            ]);

        $affiliatePayouts = DB::table('affiliate_payout_items')
            ->join('affiliates', 'affiliates.id', '=', 'affiliate_payout_items.affiliate_id')
            ->join('users as affiliate_users', 'affiliate_users.id', '=', 'affiliates.user_id')
            ->where('affiliate_payout_items.status', 'paid')
            ->whereNotNull('affiliate_payout_items.paid_at')
            ->whereBetween('affiliate_payout_items.paid_at', [$from, $to])
            ->select([
                DB::raw("'affiliate_payout' as kind"),
                DB::raw("'".self::DIRECTION_OUT."' as direction"),
                'affiliate_payout_items.amount_kobo as amount_kobo',
                'affiliate_payout_items.paid_at as occurred_at',
                'affiliate_users.name as party',
                DB::raw("coalesce(affiliate_payout_items.paystack_transfer_reference, concat('AP-', affiliate_payout_items.id)) as reference"),
            ]);

        $expenses = DB::table('business_expenses')
            // Approved only. A claim nobody has signed off is not money the
            // business has agreed it spent, and putting it in the ledger
            // would make the net position move on somebody's data entry.
            ->where('business_expenses.status', ExpenseStatus::Approved->value)
            ->whereBetween('business_expenses.incurred_on', [$from->toDateString(), $to->toDateString()])
            ->select([
                DB::raw("'expense' as kind"),
                DB::raw("'".self::DIRECTION_OUT."' as direction"),
                'business_expenses.amount_kobo as amount_kobo',
                DB::raw('business_expenses.incurred_on as occurred_at'),
                DB::raw('coalesce(business_expenses.payee, business_expenses.description) as party'),
                'business_expenses.reference as reference',
            ]);

        return $payments
            ->unionAll($refunds)
            ->unionAll($vendorPayouts)
            ->unionAll($affiliatePayouts)
            ->unionAll($expenses);
    }

    /** @return list<array{value: string, label: string, direction: string}> */
    public function kinds(): array
    {
        return [
            ['value' => 'customer_payment', 'label' => 'Customer payments', 'direction' => self::DIRECTION_IN],
            ['value' => 'refund', 'label' => 'Refunds', 'direction' => self::DIRECTION_OUT],
            ['value' => 'vendor_payout', 'label' => 'Vendor payouts', 'direction' => self::DIRECTION_OUT],
            ['value' => 'affiliate_payout', 'label' => 'Affiliate payouts', 'direction' => self::DIRECTION_OUT],
            ['value' => 'expense', 'label' => 'Business expenses', 'direction' => self::DIRECTION_OUT],
        ];
    }

    private function label(string $kind): string
    {
        foreach ($this->kinds() as $option) {
            if ($option['value'] === $kind) {
                return $option['label'];
            }
        }

        return ucfirst(str_replace('_', ' ', $kind));
    }
}
