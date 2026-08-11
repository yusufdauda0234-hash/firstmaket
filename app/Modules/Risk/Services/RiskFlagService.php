<?php

namespace App\Modules\Risk\Services;

use App\Models\Setting;
use App\Models\User;
use App\Modules\Payments\Models\PaystackTransaction;
use App\Modules\Risk\Models\RiskFlag;
use App\Modules\Savings\Models\SavingsGoal;
use App\Modules\Vendor\Models\VendorProfile;
use App\Shared\Contracts\AuditLoggerContract;
use App\Shared\Enums\OrderStatus;
use App\Shared\Enums\PaystackTransactionStatus;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Raises the patterns Phase 2D asks staff to keep an eye on.
 *
 * Every threshold is a setting. What counts as "too many failed payments"
 * depends on the size and shape of the business, and staff should be able to
 * tune it as they learn — a constant here would mean either a deploy or an
 * alert nobody trusts.
 *
 * The one thing this service will never do is act. It writes a row and stops.
 * Suspending an account on a heuristic would mean locking a customer out of
 * money they have already saved because a rule fired, and no amount of fraud
 * prevention justifies that being automatic.
 */
class RiskFlagService
{
    /** Rule identifiers, also the setting-key stems. */
    public const RULE_FAILED_PAYMENTS = 'failed_payments';

    public const RULE_RAPID_PLAN_SWITCHING = 'rapid_plan_switching';

    public const RULE_VENDOR_REJECTION_SPIKE = 'vendor_rejection_spike';

    public const RULE_VENDOR_RETURN_SPIKE = 'vendor_return_spike';

    /** Thresholds, all overridable from the admin screen. */
    private const DEFAULTS = [
        'risk.failed_payments_threshold' => 3,
        'risk.failed_payments_window_days' => 7,
        'risk.plan_switches_threshold' => 3,
        'risk.plan_switches_window_days' => 30,
        'risk.vendor_rejection_percent' => 25,
        'risk.vendor_rejection_minimum_orders' => 8,
        'risk.vendor_return_percent' => 20,
        'risk.vendor_return_minimum_orders' => 8,
    ];

    public function __construct(private readonly AuditLoggerContract $auditLogger) {}

    /** @return array<string, int> */
    public function thresholds(): array
    {
        return array_map('intval', Setting::many(self::DEFAULTS));
    }

    /**
     * Run every rule and raise what is missing.
     *
     * @return int Flags raised this run.
     */
    public function sweep(): int
    {
        return $this->flagFailedPayments()
            + $this->flagRapidPlanSwitching()
            + $this->flagVendorRejectionSpikes()
            + $this->flagVendorReturnSpikes();
    }

    /**
     * Repeated failed charges against one customer.
     *
     * Often innocent — an expired card, a bank outage — which is exactly why
     * it is a flag and not a block.
     */
    private function flagFailedPayments(): int
    {
        $t = $this->thresholds();

        $offenders = PaystackTransaction::query()
            ->where('status', PaystackTransactionStatus::Failed)
            ->where('created_at', '>=', now()->subDays($t['risk.failed_payments_window_days']))
            ->groupBy('user_id')
            ->havingRaw('count(*) >= ?', [$t['risk.failed_payments_threshold']])
            ->pluck(DB::raw('count(*) as failures'), 'user_id');

        $raised = 0;

        foreach ($offenders as $userId => $failures) {
            if ($userId === null) {
                continue;
            }

            $raised += $this->raise(
                rule: self::RULE_FAILED_PAYMENTS,
                severity: 'medium',
                summary: $failures.' failed payments in the last '.$t['risk.failed_payments_window_days'].' days.',
                evidence: ['failures' => (int) $failures, 'window_days' => $t['risk.failed_payments_window_days']],
                userId: (int) $userId,
            );
        }

        return $raised;
    }

    /** A customer moving a plan between products unusually often. */
    private function flagRapidPlanSwitching(): int
    {
        $t = $this->thresholds();

        $offenders = SavingsGoal::query()
            ->where('switch_count', '>=', $t['risk.plan_switches_threshold'])
            ->where('updated_at', '>=', now()->subDays($t['risk.plan_switches_window_days']))
            ->get(['user_id', 'switch_count']);

        $raised = 0;

        foreach ($offenders as $goal) {
            $raised += $this->raise(
                rule: self::RULE_RAPID_PLAN_SWITCHING,
                severity: 'low',
                summary: 'A plan has been switched '.$goal->switch_count.' times recently.',
                evidence: ['switch_count' => (int) $goal->switch_count],
                userId: (int) $goal->user_id,
            );
        }

        return $raised;
    }

    /** A vendor rejecting a high share of what they are sent. */
    private function flagVendorRejectionSpikes(): int
    {
        return $this->flagVendorRate(
            rule: self::RULE_VENDOR_REJECTION_SPIKE,
            status: OrderStatus::VendorRejected,
            percentKey: 'risk.vendor_rejection_percent',
            minimumKey: 'risk.vendor_rejection_minimum_orders',
            label: 'rejected',
        );
    }

    /** A vendor whose deliveries come back more often than they should. */
    private function flagVendorReturnSpikes(): int
    {
        $t = $this->thresholds();
        $raised = 0;

        $rows = DB::table('return_requests')
            ->join('orders', 'orders.id', '=', 'return_requests.order_id')
            ->where('return_requests.status', 'refunded')
            ->groupBy('return_requests.vendor_id')
            ->select('return_requests.vendor_id', DB::raw('count(*) as returns'))
            ->get();

        foreach ($rows as $row) {
            $delivered = DB::table('orders')
                ->where('vendor_id', $row->vendor_id)
                ->where('status', OrderStatus::Delivered->value)
                ->count();

            if ($delivered < $t['risk.vendor_return_minimum_orders']) {
                continue;
            }

            $percent = round($row->returns / $delivered * 100, 1);

            if ($percent < $t['risk.vendor_return_percent']) {
                continue;
            }

            $raised += $this->raise(
                rule: self::RULE_VENDOR_RETURN_SPIKE,
                severity: 'high',
                summary: $percent.'% of delivered orders were returned and refunded.',
                evidence: ['returned' => (int) $row->returns, 'delivered' => $delivered, 'percent' => $percent],
                vendorId: (int) $row->vendor_id,
            );
        }

        return $raised;
    }

    /** Shared shape for "what share of a vendor's orders ended up like this". */
    private function flagVendorRate(
        string $rule,
        OrderStatus $status,
        string $percentKey,
        string $minimumKey,
        string $label,
    ): int {
        $t = $this->thresholds();
        $raised = 0;

        $rows = DB::table('orders')
            ->groupBy('vendor_id')
            ->select('vendor_id', DB::raw('count(*) as total'))
            ->selectRaw('sum(case when status = ? then 1 else 0 end) as matched', [$status->value])
            ->having('total', '>=', $t[$minimumKey])
            ->get();

        foreach ($rows as $row) {
            $percent = round($row->matched / max(1, $row->total) * 100, 1);

            if ($percent < $t[$percentKey]) {
                continue;
            }

            $raised += $this->raise(
                rule: $rule,
                severity: 'high',
                summary: $percent.'% of orders were '.$label.'.',
                evidence: ['matched' => (int) $row->matched, 'total' => (int) $row->total, 'percent' => $percent],
                vendorId: (int) $row->vendor_id,
            );
        }

        return $raised;
    }

    /**
     * Write the flag, unless an unreviewed one already says the same thing.
     *
     * The unique index does the deduplication, so a sweep that runs twice in a
     * day does not bury a reviewer in copies of a condition they have not had
     * a chance to look at yet.
     */
    private function raise(
        string $rule,
        string $severity,
        string $summary,
        array $evidence,
        ?int $userId = null,
        ?int $vendorId = null,
    ): int {
        try {
            $flag = RiskFlag::query()->create([
                'user_id' => $userId,
                'vendor_id' => $vendorId,
                // Non-null on purpose — see the migration note on the index.
                'subject_key' => $userId !== null ? 'user:'.$userId : 'vendor:'.$vendorId,
                'rule' => $rule,
                'severity' => $severity,
                'summary' => $summary,
                'evidence' => $evidence,
                'status' => RiskFlag::STATUS_OPEN,
            ]);
        } catch (UniqueConstraintViolationException) {
            return 0; // Already flagged and still open.
        }

        $this->auditLogger->log(
            actor: null,
            subject: $flag,
            action: 'risk.flag_raised',
            newValues: ['rule' => $rule, 'severity' => $severity],
        );

        return 1;
    }

    /**
     * Record what a reviewer decided.
     *
     * The outcome is a note, not an instruction: marking a flag "actioned"
     * records that a human went and did something, it does not do anything.
     */
    public function review(User $staff, RiskFlag $flag, string $outcome, ?string $note = null): RiskFlag
    {
        $flag->update([
            'status' => RiskFlag::STATUS_REVIEWED,
            'reviewed_by' => $staff->id,
            'reviewed_at' => now(),
            'review_note' => $note,
            'outcome' => $outcome,
        ]);

        $this->auditLogger->log(
            actor: $staff,
            subject: $flag,
            action: 'risk.flag_reviewed',
            newValues: ['outcome' => $outcome],
        );

        return $flag;
    }
}
