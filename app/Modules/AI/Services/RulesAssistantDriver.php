<?php

namespace App\Modules\AI\Services;

use App\Models\Setting;
use App\Models\User;
use App\Shared\DTOs\AssistantReply;
use App\Modules\AI\Models\AssistantRecommendation;
use App\Modules\Catalog\Models\Product;
use App\Modules\Savings\Models\PlanPayment;
use App\Modules\Savings\Models\SavingsGoal;
use App\Modules\Savings\Services\SavingsAssistant;
use App\Shared\Contracts\AssistantDriverContract;
use App\Shared\Enums\SavingsGoalStatus;

/**
 * The shipped assistant: arithmetic on the customer's own record, in plain
 * language.
 *
 * This is the driver FirstMaket runs on, and the reasoning is the same one
 * that decided Phase 2C — for "help me understand my own saving", a
 * deterministic engine beats a language model on every axis that matters
 * here. It cannot invent a figure. Every sentence can be traced to a row.
 * It costs nothing per question, so nobody has to ration a customer asking
 * about their own money. And no financial data leaves the platform to
 * produce an answer.
 *
 * The AssistantDriverContract exists so a hosted-model driver can be added
 * when there is a reason to prefer one — better handling of vague or
 * open-ended questions, mainly. Nothing above this class would change.
 *
 * The honest limitation, stated because the UI states it too: this answers
 * questions about the customer's own plans and payments. Asked something
 * outside that, it says so instead of guessing.
 */
class RulesAssistantDriver implements AssistantDriverContract
{
    public function __construct(private readonly SavingsAssistant $advice) {}

    public function name(): string
    {
        return 'rules';
    }

    public function reply(User $user, string $question, array $history = []): AssistantReply
    {
        $intent = $this->classify($question);

        return match ($intent) {
            'progress' => $this->progress($user),
            'behind' => $this->behind($user),
            'afford' => $this->afford($user),
            'cheaper' => $this->cheaper($user),
            'finish' => $this->finish($user),
            default => $this->fallback($user),
        };
    }

    /**
     * Keyword matching, deliberately.
     *
     * A rules engine that pretended to understand arbitrary phrasing would
     * be worse than one with an obvious shape: when this does not recognise
     * a question it says what it can answer, rather than confidently
     * answering a question nobody asked.
     */
    private function classify(string $question): string
    {
        $q = mb_strtolower($question);

        $map = [
            'behind' => ['behind', 'late', 'missed', 'catch up', 'owe'],
            'afford' => ['afford', 'how much should', 'realistic', 'too much', 'manage'],
            'cheaper' => ['cheaper', 'stuck', 'switch', 'different product', 'something else', 'lower'],
            'finish' => ['finish', 'how long', 'when will', 'done', 'complete', 'sooner'],
            'progress' => ['progress', 'how am i', 'status', 'plans', 'saved', 'doing'],
        ];

        foreach ($map as $intent => $keywords) {
            foreach ($keywords as $keyword) {
                if (str_contains($q, $keyword)) {
                    return $intent;
                }
            }
        }

        return 'unknown';
    }

    private function runningPlans(User $user)
    {
        return SavingsGoal::query()
            ->where('user_id', $user->id)
            ->where('status', SavingsGoalStatus::Saving)
            ->with('items')
            ->get();
    }

    private function progress(User $user): AssistantReply
    {
        $plans = $this->runningPlans($user);

        if ($plans->isEmpty()) {
            return new AssistantReply(
                'You have no plans running at the moment. When you start one, I can tell you how it is going and what a comfortable payment would look like.',
                ['running_plans' => 0],
            );
        }

        $saved = (int) $plans->sum('paid_kobo');
        $target = (int) $plans->sum('target_kobo');
        $lines = $plans->map(fn (SavingsGoal $plan) => '• '.$this->naira($plan->paid_kobo).' of '
            .$this->naira($plan->target_kobo).' ('.$plan->progressPercent().'%)')->implode("\n");

        return new AssistantReply(
            "You have {$plans->count()} plan".($plans->count() === 1 ? '' : 's')." running.\n\n{$lines}\n\n"
                .'Altogether that is '.$this->naira($saved).' put aside towards '.$this->naira($target).'.',
            ['running_plans' => $plans->count(), 'saved_kobo' => $saved, 'target_kobo' => $target],
        );
    }

    private function behind(User $user): AssistantReply
    {
        $plans = $this->runningPlans($user);
        $behind = $plans->filter(fn (SavingsGoal $plan) => $plan->missedPayments() > 0);

        if ($behind->isEmpty()) {
            return new AssistantReply(
                'Nothing is behind. Every plan you have is up to date with its schedule.',
                ['plans_behind' => 0],
            );
        }

        $suggestions = [];

        foreach ($behind as $plan) {
            $missed = $plan->missedPayments();
            $shortfall = min($plan->remainingKobo(), $missed * $plan->installment_kobo);

            // A pause is the honest suggestion for somebody who has fallen
            // behind: it stops the plan drifting toward dormancy without
            // asking for money they have already shown they do not have.
            //
            // Only offered where the plan can actually be paused. A plan with
            // no payments on it is refused by SavingsGoalService::pause() —
            // pausing one would be a free price lock — and suggesting
            // something the system will then refuse is worse than saying
            // nothing.
            if ($plan->payments_made >= 1) {
                $suggestions[] = [
                    'action' => AssistantRecommendation::ACTION_PAUSE,
                    'title' => 'Pause this plan instead of falling further behind',
                    'body' => 'Pausing stops reminders and automatic debit for a while. Your price stays frozen and nothing you have paid is affected.',
                    'goalId' => $plan->id,
                    'payload' => ['plan_uuid' => $plan->uuid],
                    'evidence' => ['missed_payments' => $missed, 'shortfall_kobo' => $shortfall],
                ];
            }

            $suggestions[] = [
                'action' => AssistantRecommendation::ACTION_RESCHEDULE,
                'title' => 'Spread it over longer, smaller payments',
                'body' => 'A longer run means a smaller amount each time. Nothing you have already paid changes.',
                'goalId' => $plan->id,
                'payload' => ['plan_uuid' => $plan->uuid],
                'evidence' => ['current_installment_kobo' => $plan->installment_kobo],
            ];
        }

        $total = (int) $behind->sum(fn (SavingsGoal $plan) => min($plan->remainingKobo(), $plan->missedPayments() * $plan->installment_kobo));

        return new AssistantReply(
            $behind->count() === 1
                ? 'One plan is behind by '.$this->naira($total).'. There is no penalty for that, and no interest — it just means the goods are further away than the schedule says.'
                : $behind->count().' plans are behind, by '.$this->naira($total).' between them. There is no penalty and no interest.',
            ['plans_behind' => $behind->count(), 'shortfall_kobo' => $total],
            $suggestions,
        );
    }

    private function afford(User $user): AssistantReply
    {
        $notes = $this->advice->adviceFor($user);
        $typical = collect($notes)->firstWhere('key', 'typical_payment');

        if ($typical === null) {
            return new AssistantReply(
                'You have not made enough payments yet for me to see a pattern. After three or four I can tell you what has actually proved comfortable, rather than guessing.',
                ['payments_seen' => count($notes)],
            );
        }

        return new AssistantReply(
            $typical['title'].". ".$typical['body'].
            "\n\nThat is what your own record shows, not a target anybody set for you.",
            $typical['evidence'],
        );
    }

    /**
     * Cheaper products a stalled plan could actually reach.
     *
     * Only ever suggested for a plan that is genuinely stuck, and only where
     * the alternative is one the customer has already paid enough to cover —
     * "switch to something you can have today" is useful; "switch to a
     * different thing you also cannot afford" is not.
     */
    private function cheaper(User $user): AssistantReply
    {
        $plans = $this->runningPlans($user);
        $stalled = $plans->filter(fn (SavingsGoal $plan) => $plan->missedPayments() > 0);

        if ($stalled->isEmpty()) {
            return new AssistantReply(
                'None of your plans look stuck, so there is nothing I would suggest switching. You can still change what a plan is for at any time from the plan page.',
                ['stalled_plans' => 0],
            );
        }

        $suggestions = [];
        $named = [];

        foreach ($stalled as $plan) {
            $categoryIds = $plan->items->pluck('product.category_id')->filter()->unique();

            $affordable = Product::query()
                ->approved()
                ->when($categoryIds->isNotEmpty(), fn ($query) => $query->whereIn('category_id', $categoryIds))
                ->where('price_kobo', '<=', $plan->paid_kobo)
                ->orderByDesc('price_kobo')
                ->limit(3)
                ->get(['uuid', 'name', 'price_kobo']);

            if ($affordable->isEmpty()) {
                continue;
            }

            $named = $affordable->pluck('name')->all();

            $suggestions[] = [
                'action' => AssistantRecommendation::ACTION_SWITCH_TO_CHEAPER,
                'title' => 'Switch to something your '.$this->naira($plan->paid_kobo).' already covers',
                'body' => 'You would take delivery now instead of carrying on saving. The choice is yours and nothing changes until you make it.',
                'goalId' => $plan->id,
                'payload' => [
                    'plan_uuid' => $plan->uuid,
                    'options' => $affordable->map(fn (Product $product) => [
                        'uuid' => $product->uuid,
                        'name' => $product->name,
                        'priceKobo' => $product->price_kobo,
                    ])->all(),
                ],
                'evidence' => ['paid_kobo' => $plan->paid_kobo, 'missed_payments' => $plan->missedPayments()],
            ];
        }

        if ($suggestions === []) {
            return new AssistantReply(
                'I could not find anything in the catalogue that what you have saved would already cover. Pausing the plan or spreading it over longer are the two things that usually help most here.',
                ['stalled_plans' => $stalled->count()],
            );
        }

        return new AssistantReply(
            'There are things you could have delivered now for what you have already put aside'
                .($named !== [] ? ' — for example '.implode(', ', array_slice($named, 0, 2)).'.' : '.')
                ."\n\nSwitching is entirely your call. Nothing moves unless you say so.",
            ['stalled_plans' => $stalled->count()],
            $suggestions,
        );
    }

    private function finish(User $user): AssistantReply
    {
        $plans = $this->runningPlans($user);

        if ($plans->isEmpty()) {
            return new AssistantReply('You have no plans running, so there is nothing to finish yet.', []);
        }

        $lines = $plans->map(function (SavingsGoal $plan) {
            $left = (int) ceil($plan->remainingKobo() / max(1, $plan->installment_kobo));

            return '• '.$this->naira($plan->remainingKobo()).' to go — about '.$left.' more payment'.($left === 1 ? '' : 's')
                .' at '.$this->naira($plan->installment_kobo).' each.';
        })->implode("\n");

        return new AssistantReply(
            "At your current schedule:\n\n{$lines}\n\nPaying more than the instalment whenever you can brings that forward; there is no penalty for paying early.",
            ['plans' => $plans->count()],
        );
    }

    private function fallback(User $user): AssistantReply
    {
        return new AssistantReply(
            "I can only help with your own saving here, and I would rather say so than guess.\n\n"
                ."Try asking me:\n"
                ."• How are my plans going?\n"
                ."• Am I behind on anything?\n"
                ."• How much should I be paying?\n"
                ."• When will I finish?\n"
                ."• Is there something cheaper I could switch to?\n\n"
                .'For anything else — an order, a delivery, a refund — the support team is the right place.',
            ['intent' => 'unknown'],
        );
    }

    private function naira(int $kobo): string
    {
        return '₦'.number_format($kobo / 100, 2);
    }
}
