<?php

namespace App\Modules\AI\Services;

use App\Models\Setting;
use App\Models\User;
use App\Modules\AI\Models\AssistantConfirmation;
use App\Modules\AI\Models\AssistantConversation;
use App\Modules\AI\Models\AssistantCostLog;
use App\Modules\AI\Models\AssistantMessage;
use App\Modules\AI\Models\AssistantRecommendation;
use App\Modules\Savings\Models\SavingsGoal;
use App\Modules\Savings\Services\SavingsGoalService;
use App\Shared\Contracts\AssistantDriverContract;
use App\Shared\Contracts\AuditLoggerContract;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The assistant, and the fence around it.
 *
 * The fence is the point of this class. A driver produces words and
 * suggestions; this decides what gets written down, what a customer is
 * allowed to act on, and when to stop answering. In particular:
 *
 *  - **Nothing acts without a recorded confirmation.** `act()` refuses
 *    unless an AssistantConfirmation row exists for that exact
 *    recommendation, created by that exact customer. There is no path from
 *    "the assistant suggested it" to "it happened".
 *  - **Suggestions expire.** A recommendation is priced on the numbers at
 *    the moment it was made. Acting on a stale one would apply figures the
 *    customer never saw, so it is refused instead.
 *  - **Everything is scoped to one customer.** Every read filters by user
 *    id; a conversation belonging to somebody else is a 403, not a lookup.
 *  - **Usage is capped twice** — per customer per day, so nobody can be
 *    charged for somebody else's runaway session, and platform-wide by
 *    spend, so a paid driver cannot quietly become an unbounded bill.
 */
class AssistantService
{
    private const DEFAULTS = [
        // Questions one customer may ask in a day.
        'assistant.daily_message_limit' => 40,
        // Platform-wide spend ceiling for a calendar day, in kobo.
        'assistant.daily_cost_cap_kobo' => 500_000,
        // How long a suggestion stays actionable.
        'assistant.recommendation_ttl_minutes' => 120,
        'assistant.max_question_length' => 500,
    ];

    public function __construct(
        private readonly AssistantDriverContract $driver,
        private readonly SavingsGoalService $goals,
        private readonly AuditLoggerContract $auditLogger,
    ) {}

    /**
     * Ask a question and record both sides of the exchange.
     */
    public function ask(User $user, string $question, ?AssistantConversation $conversation = null): AssistantMessage
    {
        $settings = array_map('intval', Setting::many(self::DEFAULTS));
        $question = trim($question);

        if ($question === '') {
            throw ValidationException::withMessages(['message' => 'Ask me something first.']);
        }

        if (mb_strlen($question) > $settings['assistant.max_question_length']) {
            throw ValidationException::withMessages([
                'message' => 'That is longer than I can take in one go. Try asking it in a shorter way.',
            ]);
        }

        $this->assertWithinLimits($user, $settings);

        $conversation = $conversation ?? $this->startConversation($user, $question);
        $this->assertOwns($user, $conversation);

        // Only this conversation's own turns are handed to the driver, and
        // only this customer's conversation exists to be read.
        $history = $conversation->messages()
            ->orderBy('id')
            ->limit(20)
            ->get(['role', 'body'])
            ->map(fn (AssistantMessage $message) => ['role' => $message->role, 'body' => $message->body])
            ->all();

        return DB::transaction(function () use ($user, $question, $conversation, $history, $settings) {
            $conversation->messages()->create([
                'role' => AssistantMessage::ROLE_CUSTOMER,
                'body' => $question,
            ]);

            $reply = $this->driver->reply($user, $question, $history);

            $answer = $conversation->messages()->create([
                'role' => AssistantMessage::ROLE_ASSISTANT,
                'body' => $reply->body,
                'evidence' => $reply->evidence,
                'driver' => $this->driver->name(),
            ]);

            foreach ($reply->suggestions as $suggestion) {
                $this->recordSuggestion($user, $conversation, $suggestion, $settings['assistant.recommendation_ttl_minutes']);
            }

            // Logged even at zero cost: the row is what makes a later switch
            // to a paid driver visible on day one rather than at the invoice.
            AssistantCostLog::query()->create([
                'user_id' => $user->id,
                'conversation_id' => $conversation->id,
                'driver' => $this->driver->name(),
                'prompt_tokens' => $reply->promptTokens,
                'completion_tokens' => $reply->completionTokens,
                'cost_kobo' => $reply->costKobo,
            ]);

            $conversation->forceFill(['last_message_at' => now()])->save();

            return $answer;
        });
    }

    public function startConversation(User $user, string $seed = ''): AssistantConversation
    {
        return AssistantConversation::query()->create([
            'user_id' => $user->id,
            'title' => $seed === '' ? 'New conversation' : mb_substr($seed, 0, 60),
            'last_message_at' => now(),
        ]);
    }

    /**
     * Record the customer's answer to a suggestion.
     *
     * Declining is recorded as deliberately as accepting: "I offered this and
     * they said no" is exactly as important as the reverse when somebody
     * later asks why a plan did or did not change.
     */
    public function confirm(User $user, AssistantRecommendation $recommendation, string $decision, ?string $ip = null, ?string $userAgent = null): AssistantConfirmation
    {
        if ($recommendation->user_id !== $user->id) {
            abort(403);
        }

        if ($recommendation->status !== AssistantRecommendation::STATUS_OFFERED) {
            throw ValidationException::withMessages(['recommendation' => 'You have already answered this suggestion.']);
        }

        if ($recommendation->hasExpired()) {
            $recommendation->forceFill(['status' => AssistantRecommendation::STATUS_EXPIRED])->save();

            throw ValidationException::withMessages([
                'recommendation' => 'That suggestion is out of date — the figures behind it have moved on. Ask again for a fresh one.',
            ]);
        }

        return DB::transaction(function () use ($user, $recommendation, $decision, $ip, $userAgent) {
            $confirmation = AssistantConfirmation::query()->create([
                'recommendation_id' => $recommendation->id,
                'user_id' => $user->id,
                'decision' => $decision,
                'ip_address' => $ip,
                'user_agent' => $userAgent,
            ]);

            $recommendation->forceFill([
                'status' => $decision === AssistantConfirmation::DECISION_ACCEPTED
                    ? AssistantRecommendation::STATUS_ACCEPTED
                    : AssistantRecommendation::STATUS_DECLINED,
            ])->save();

            $this->auditLogger->log(
                actor: $user,
                subject: $recommendation,
                action: 'assistant.recommendation_'.$decision,
                newValues: ['action' => $recommendation->action, 'plan_id' => $recommendation->savings_goal_id],
            );

            return $confirmation;
        });
    }

    /**
     * Carry out a recommendation the customer has accepted.
     *
     * Refuses outright unless a confirmation row exists saying this customer
     * accepted this exact suggestion. That check is the whole safety
     * property: there is no argument, flag, or code path that lets the
     * assistant reach a plan without a human having said yes first.
     *
     * Note what it will not do even then: it never takes a payment. The
     * actions it can carry out change a schedule or pause reminders — the
     * things a customer could do themselves on the plan page. Money still
     * only moves through a verified Paystack charge.
     */
    public function act(User $user, AssistantRecommendation $recommendation): SavingsGoal
    {
        if ($recommendation->user_id !== $user->id) {
            abort(403);
        }

        $confirmation = $recommendation->confirmation()->first();

        if ($confirmation === null
            || $confirmation->user_id !== $user->id
            || $confirmation->decision !== AssistantConfirmation::DECISION_ACCEPTED
        ) {
            throw ValidationException::withMessages([
                'recommendation' => 'Nothing happens until you confirm a suggestion yourself.',
            ]);
        }

        $goal = $recommendation->goal;

        if ($goal === null || $goal->user_id !== $user->id) {
            abort(403);
        }

        return match ($recommendation->action) {
            AssistantRecommendation::ACTION_PAUSE => $this->goals->pause($user, $goal),
            // Reschedule and switch both need a choice the customer makes on
            // the plan page — the suggestion takes them there rather than
            // picking for them.
            default => throw ValidationException::withMessages([
                'recommendation' => 'This suggestion is carried out on the plan page, where you choose the details yourself.',
            ]),
        };
    }

    /** @return list<array<string, mixed>> */
    public function conversationsFor(User $user): array
    {
        return AssistantConversation::query()
            ->where('user_id', $user->id)
            ->orderByDesc('last_message_at')
            ->limit(30)
            ->get()
            ->map(fn (AssistantConversation $conversation) => [
                'uuid' => $conversation->uuid,
                'title' => $conversation->title,
                'lastMessageAt' => $conversation->last_message_at?->diffForHumans(),
            ])
            ->all();
    }

    public function remainingQuestionsToday(User $user): int
    {
        $settings = array_map('intval', Setting::many(self::DEFAULTS));

        return max(0, $settings['assistant.daily_message_limit'] - $this->askedToday($user));
    }

    private function recordSuggestion(User $user, AssistantConversation $conversation, array $suggestion, int $ttlMinutes): void
    {
        AssistantRecommendation::query()->create([
            'conversation_id' => $conversation->id,
            'user_id' => $user->id,
            'savings_goal_id' => $suggestion['goalId'] ?? null,
            'action' => $suggestion['action'],
            'title' => $suggestion['title'],
            'body' => $suggestion['body'],
            'payload' => $suggestion['payload'] ?? null,
            'evidence' => $suggestion['evidence'] ?? null,
            'status' => AssistantRecommendation::STATUS_OFFERED,
            'expires_at' => now()->addMinutes($ttlMinutes),
        ]);
    }

    /**
     * @param  array<string, int>  $settings
     */
    private function assertWithinLimits(User $user, array $settings): void
    {
        if ($this->askedToday($user) >= $settings['assistant.daily_message_limit']) {
            throw ValidationException::withMessages([
                'message' => 'You have reached today\'s limit for questions. It resets tomorrow, and support can help in the meantime.',
            ]);
        }

        $spentToday = (int) AssistantCostLog::query()
            ->where('created_at', '>=', now()->startOfDay())
            ->sum('cost_kobo');

        if ($spentToday >= $settings['assistant.daily_cost_cap_kobo']) {
            throw ValidationException::withMessages([
                'message' => 'The assistant is resting for today. Please try again tomorrow, or contact support if it is urgent.',
            ]);
        }
    }

    private function askedToday(User $user): int
    {
        return AssistantMessage::query()
            ->where('role', AssistantMessage::ROLE_CUSTOMER)
            ->where('created_at', '>=', now()->startOfDay())
            ->whereHas('conversation', fn ($query) => $query->where('user_id', $user->id))
            ->count();
    }

    private function assertOwns(User $user, AssistantConversation $conversation): void
    {
        if ($conversation->user_id !== $user->id) {
            abort(403);
        }
    }
}
