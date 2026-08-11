<?php

namespace App\Modules\Savings\Services;

use App\Models\Setting;
use App\Models\User;
use App\Modules\Savings\Models\PlanPayment;
use App\Modules\Savings\Models\SavingsGoal;
use App\Shared\Enums\SavingsGoalStatus;

/**
 * Suggestions about a customer's saving, worked out from what they have
 * actually paid.
 *
 * Rules, not a model. Phase 2C wants advice grounded in real payment history,
 * and for that job arithmetic on the customer's own record beats a language
 * model: it is explainable line by line, it costs nothing, it cannot invent a
 * figure, and no financial data leaves the platform to produce it.
 *
 * Everything here is advisory and says so. Nothing in this class moves money,
 * changes a plan, or takes a decision — the phase plan requires that, and it
 * is also the only honest posture for software guessing at somebody's budget.
 *
 * Every threshold is a setting, so staff can tune what counts as "behind" or
 * "a realistic amount" without a deploy.
 */
class SavingsAssistant
{
    private const DEFAULTS = [
        // How many payments to look at when judging someone's usual rhythm.
        'assistant.history_payments' => 6,
        // Below this many payments there is no pattern worth claiming.
        'assistant.minimum_payments' => 3,
        // Suggest topping up when a plan is this far behind, as a percentage
        // of one instalment.
        'assistant.behind_tolerance_percent' => 50,
    ];

    /**
     * Advice for one customer, as a list of plain-language notes.
     *
     * Each note carries its own evidence so the page can show why it is being
     * said — advice a customer cannot check is advice they should not trust.
     *
     * @return array<int, array{key: string, tone: string, title: string, body: string, evidence: array<string, mixed>}>
     */
    public function adviceFor(User $user): array
    {
        $settings = array_map('intval', Setting::many(self::DEFAULTS));

        $payments = PlanPayment::query()
            ->where('user_id', $user->id)
            ->latest('id')
            ->limit($settings['assistant.history_payments'])
            ->get(['amount_kobo', 'created_at']);

        $plans = SavingsGoal::query()
            ->where('user_id', $user->id)
            ->where('status', SavingsGoalStatus::Saving)
            ->get();

        $notes = [];

        // ── Not enough history to say anything ──
        if ($payments->count() < $settings['assistant.minimum_payments']) {
            $notes[] = [
                'key' => 'getting_started',
                'tone' => 'neutral',
                'title' => 'Still getting to know your rhythm',
                'body' => 'Once you have made a few payments we can suggest an amount that fits how you actually save.',
                'evidence' => ['payments_so_far' => $payments->count()],
            ];

            return $notes;
        }

        $typical = (int) round($payments->avg('amount_kobo'));
        $largest = (int) $payments->max('amount_kobo');

        $notes[] = [
            'key' => 'typical_payment',
            'tone' => 'neutral',
            'title' => 'You usually pay about '.$this->naira($typical),
            'body' => 'Across your last '.$payments->count().' payments. Anything at or below this has proved comfortable for you.',
            'evidence' => ['typical_kobo' => $typical, 'largest_kobo' => $largest],
        ];

        foreach ($plans as $plan) {
            $behind = $this->behindByKobo($plan);
            $tolerance = (int) round($plan->installment_kobo * $settings['assistant.behind_tolerance_percent'] / 100);

            if ($behind > $tolerance) {
                $notes[] = [
                    'key' => 'behind:'.$plan->uuid,
                    'tone' => 'warning',
                    'title' => 'One plan is behind by '.$this->naira($behind),
                    // Framed as what would fix it, not as a scolding. The
                    // customer already knows they are behind.
                    'body' => 'Paying '.$this->naira(min($behind, $typical)).' would bring it back on track. There is no penalty for paying in smaller amounts more often.',
                    'evidence' => [
                        'plan_uuid' => $plan->uuid,
                        'behind_kobo' => $behind,
                        'installment_kobo' => $plan->installment_kobo,
                    ],
                ];

                continue;
            }

            // ── On track, and worth saying so ──
            $paymentsLeft = (int) ceil($plan->remainingKobo() / max(1, $plan->installment_kobo));

            if ($paymentsLeft > 0 && $paymentsLeft <= 3) {
                $notes[] = [
                    'key' => 'nearly_there:'.$plan->uuid,
                    'tone' => 'positive',
                    'title' => $paymentsLeft === 1 ? 'One payment to go' : $paymentsLeft.' payments to go',
                    'body' => $this->naira($plan->remainingKobo()).' left on this plan. Keep going at your usual pace and it is yours.',
                    'evidence' => ['plan_uuid' => $plan->uuid, 'remaining_kobo' => $plan->remainingKobo()],
                ];
            }
        }

        // ── Could they comfortably finish sooner? ──
        $affordableExtra = $this->couldFinishSooner($plans, $typical);

        if ($affordableExtra !== null) {
            $notes[] = [
                'key' => 'finish_sooner',
                'tone' => 'positive',
                'title' => 'You could finish sooner',
                'body' => 'Your usual payment is larger than this plan asks for. Paying what you normally do would clear it about '
                    .$affordableExtra.' payment'.($affordableExtra === 1 ? '' : 's').' earlier.',
                'evidence' => ['payments_saved' => $affordableExtra, 'typical_kobo' => $typical],
            ];
        }

        return $notes;
    }

    /**
     * How far behind schedule a plan has fallen, in money.
     *
     * Missed payments times the instalment, capped at what is actually left —
     * a plan cannot be behind by more than it still owes.
     */
    private function behindByKobo(SavingsGoal $plan): int
    {
        return min($plan->remainingKobo(), $plan->missedPayments() * $plan->installment_kobo);
    }

    /**
     * Payments a customer would save by paying their usual amount.
     *
     * Only offered where their habit genuinely exceeds the schedule, and only
     * for a plan that is up to date — suggesting someone pay *more* while they
     * are behind reads as tone deaf.
     *
     * @param  \Illuminate\Support\Collection<int, SavingsGoal>  $plans
     */
    private function couldFinishSooner($plans, int $typical): ?int
    {
        foreach ($plans as $plan) {
            if ($plan->missedPayments() > 0 || $plan->installment_kobo >= $typical) {
                continue;
            }

            $atSchedule = (int) ceil($plan->remainingKobo() / max(1, $plan->installment_kobo));
            $atHabit = (int) ceil($plan->remainingKobo() / max(1, $typical));

            if ($atSchedule - $atHabit >= 1) {
                return $atSchedule - $atHabit;
            }
        }

        return null;
    }

    private function naira(int $kobo): string
    {
        return '₦'.number_format($kobo / 100, 2);
    }
}
