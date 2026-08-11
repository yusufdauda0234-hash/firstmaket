<?php

use App\Models\Setting;
use App\Models\User;
use App\Modules\AI\Models\AssistantConfirmation;
use App\Modules\AI\Models\AssistantConversation;
use App\Modules\AI\Models\AssistantCostLog;
use App\Modules\AI\Models\AssistantMessage;
use App\Modules\AI\Models\AssistantRecommendation;
use App\Modules\AI\Services\AssistantService;
use App\Modules\Savings\Models\PlanPayment;
use App\Modules\Savings\Models\SavingsGoal;
use App\Shared\Enums\PlanCadence;
use App\Shared\Enums\SavingsGoalStatus;
use Illuminate\Validation\ValidationException;

/**
 * The assistant, and the fence around it.
 *
 * Two claims are worth more than everything else here, and both get their
 * own tests: the assistant cannot change anything without the customer
 * saying yes, and it cannot see anybody but the customer asking. A savings
 * assistant that failed either one would be a liability, not a feature.
 */
beforeEach(function () {
    $this->assistant = app(AssistantService::class);
    $this->customer = User::factory()->create();
});

function assistantOwnPlan(User $user, array $attributes = []): SavingsGoal
{
    return SavingsGoal::query()->create(array_merge([
        'user_id' => $user->id,
        'target_kobo' => 300_000_00,
        'delivery_fee_kobo' => 0,
        'cadence' => PlanCadence::Monthly,
        'installments' => 3,
        'installment_kobo' => 100_000_00,
        'paid_kobo' => 100_000_00,
        'payments_made' => 1,
        'next_due_at' => now()->addMonth(),
        'status' => SavingsGoalStatus::Saving,
    ], $attributes));
}

// ── It may propose; it may not act ──────────────────────────────────────────

it('refuses to act on a suggestion the customer never confirmed', function () {
    $plan = assistantOwnPlan($this->customer, ['next_due_at' => now()->subMonths(2)]);
    $this->assistant->ask($this->customer, 'Am I behind on anything?');

    $recommendation = AssistantRecommendation::query()
        ->where('action', AssistantRecommendation::ACTION_PAUSE)
        ->firstOrFail();

    expect(fn () => $this->assistant->act($this->customer, $recommendation))
        ->toThrow(ValidationException::class);

    expect($plan->fresh()->paused_at)->toBeNull();
});

it('refuses to act on a suggestion the customer explicitly declined', function () {
    $plan = assistantOwnPlan($this->customer, ['next_due_at' => now()->subMonths(2)]);
    $this->assistant->ask($this->customer, 'Am I behind?');

    $recommendation = AssistantRecommendation::query()
        ->where('action', AssistantRecommendation::ACTION_PAUSE)
        ->firstOrFail();

    $this->assistant->confirm($this->customer, $recommendation, AssistantConfirmation::DECISION_DECLINED);

    expect(fn () => $this->assistant->act($this->customer, $recommendation->fresh()))
        ->toThrow(ValidationException::class);

    expect($plan->fresh()->paused_at)->toBeNull();
});

it('acts only once the customer has accepted, and records who decided', function () {
    $plan = assistantOwnPlan($this->customer, ['next_due_at' => now()->subMonths(2)]);
    $this->assistant->ask($this->customer, 'Am I behind?');

    $recommendation = AssistantRecommendation::query()
        ->where('action', AssistantRecommendation::ACTION_PAUSE)
        ->firstOrFail();

    $this->assistant->confirm($this->customer, $recommendation, AssistantConfirmation::DECISION_ACCEPTED, '127.0.0.1', 'agent');
    $this->assistant->act($this->customer, $recommendation->fresh());

    expect($plan->fresh()->paused_at)->not->toBeNull()
        ->and(AssistantConfirmation::query()->where('recommendation_id', $recommendation->id)->value('user_id'))
        ->toBe($this->customer->id);
});

it('never takes a payment, however a suggestion is confirmed', function () {
    $plan = assistantOwnPlan($this->customer, ['next_due_at' => now()->subMonths(2)]);
    $before = PlanPayment::query()->count();

    $this->assistant->ask($this->customer, 'Am I behind?');

    foreach (AssistantRecommendation::query()->where('user_id', $this->customer->id)->get() as $recommendation) {
        try {
            $this->assistant->confirm($this->customer, $recommendation, AssistantConfirmation::DECISION_ACCEPTED);
            $this->assistant->act($this->customer, $recommendation->fresh());
        } catch (ValidationException) {
            // Suggestions that need the customer to choose details refuse
            // here on purpose; that is the point.
        }
    }

    expect(PlanPayment::query()->count())->toBe($before)
        ->and($plan->fresh()->paid_kobo)->toBe(100_000_00);
});

it('will not act on a stale suggestion whose figures have moved on', function () {
    assistantOwnPlan($this->customer, ['next_due_at' => now()->subMonths(2)]);
    $this->assistant->ask($this->customer, 'Am I behind?');

    $recommendation = AssistantRecommendation::query()
        ->where('action', AssistantRecommendation::ACTION_PAUSE)
        ->firstOrFail();
    $recommendation->forceFill(['expires_at' => now()->subMinute()])->save();

    expect(fn () => $this->assistant->confirm($this->customer, $recommendation, AssistantConfirmation::DECISION_ACCEPTED))
        ->toThrow(ValidationException::class);

    expect($recommendation->fresh()->status)->toBe(AssistantRecommendation::STATUS_EXPIRED);
});

it('cannot be answered twice', function () {
    assistantOwnPlan($this->customer, ['next_due_at' => now()->subMonths(2)]);
    $this->assistant->ask($this->customer, 'Am I behind?');
    $recommendation = AssistantRecommendation::query()->firstOrFail();

    $this->assistant->confirm($this->customer, $recommendation, AssistantConfirmation::DECISION_ACCEPTED);

    expect(fn () => $this->assistant->confirm($this->customer, $recommendation->fresh(), AssistantConfirmation::DECISION_DECLINED))
        ->toThrow(ValidationException::class);
});

// ── It sees one customer ────────────────────────────────────────────────────

it('never lets one customer confirm another customer\'s suggestion', function () {
    assistantOwnPlan($this->customer, ['next_due_at' => now()->subMonths(2)]);
    $this->assistant->ask($this->customer, 'Am I behind?');
    $recommendation = AssistantRecommendation::query()->firstOrFail();

    $stranger = User::factory()->create();

    expect(fn () => $this->assistant->confirm($stranger, $recommendation, AssistantConfirmation::DECISION_ACCEPTED))
        ->toThrow(Symfony\Component\HttpKernel\Exception\HttpException::class);
});

it('never lets one customer post into another customer\'s conversation', function () {
    $conversation = $this->assistant->startConversation($this->customer);
    $stranger = User::factory()->create();

    expect(fn () => $this->assistant->ask($stranger, 'Show me their plans', $conversation))
        ->toThrow(Symfony\Component\HttpKernel\Exception\HttpException::class);
});

it('never mentions another customer\'s figures in an answer', function () {
    $other = User::factory()->create(['name' => 'Someone Else']);
    assistantOwnPlan($other, ['target_kobo' => 987_654_00, 'paid_kobo' => 123_456_00]);

    // The asking customer has nothing at all.
    $answer = $this->assistant->ask($this->customer, 'How are my plans going?');

    expect($answer->body)->not->toContain('987,654')
        ->and($answer->body)->not->toContain('123,456')
        ->and($answer->body)->not->toContain('Someone Else');
});

it('shows a customer only their own conversations', function () {
    $this->assistant->startConversation($this->customer, 'Mine');
    $stranger = User::factory()->create();
    $this->assistant->startConversation($stranger, 'Theirs');

    $titles = collect($this->assistant->conversationsFor($this->customer))->pluck('title');

    expect($titles)->toContain('Mine')->and($titles)->not->toContain('Theirs');
});

it('refuses to open another customer\'s conversation over HTTP', function () {
    $stranger = User::factory()->create();
    $theirs = $this->assistant->startConversation($stranger, 'Private');

    $this->actingAs($this->customer)
        ->get(route('assistant.index', ['conversation' => $theirs->uuid]))
        ->assertNotFound();
});

// ── Answers are grounded, not invented ──────────────────────────────────────

it('answers from the customer\'s own record and shows what it used', function () {
    assistantOwnPlan($this->customer, ['target_kobo' => 200_000_00, 'paid_kobo' => 50_000_00]);

    $answer = $this->assistant->ask($this->customer, 'How are my plans going?');

    expect($answer->body)->toContain('₦50,000.00')
        ->and($answer->evidence['saved_kobo'])->toBe(50_000_00)
        ->and($answer->evidence['target_kobo'])->toBe(200_000_00);
});

it('says what it cannot help with rather than guessing', function () {
    $answer = $this->assistant->ask($this->customer, 'What is the capital of France?');

    expect($answer->evidence['intent'])->toBe('unknown')
        ->and($answer->body)->toContain('support team');
});

it('does not offer a pause it knows the plan would refuse', function () {
    // A plan with nothing paid into it cannot be paused — that would be a
    // free price lock — so the assistant must not suggest it, however far
    // behind the schedule says the plan is.
    assistantOwnPlan($this->customer, [
        'paid_kobo' => 0,
        'payments_made' => 0,
        'next_due_at' => now()->subMonths(2),
    ]);

    $this->assistant->ask($this->customer, 'Am I behind?');

    expect(AssistantRecommendation::query()
        ->where('action', AssistantRecommendation::ACTION_PAUSE)
        ->exists())->toBeFalse();
});

it('does not claim a pattern before there is one', function () {
    $answer = $this->assistant->ask($this->customer, 'How much should I be paying?');

    expect($answer->body)->toContain('not made enough payments');
});

// ── Cost and abuse limits ───────────────────────────────────────────────────

it('stops a customer once they hit the daily question limit', function () {
    Setting::set('assistant.daily_message_limit', 2, 'assistant');
    Setting::flushCache();

    $this->assistant->ask($this->customer, 'How are my plans going?');
    $this->assistant->ask($this->customer, 'Am I behind?');

    expect(fn () => $this->assistant->ask($this->customer, 'And again?'))
        ->toThrow(ValidationException::class);
});

it('counts one customer\'s questions separately from another\'s', function () {
    Setting::set('assistant.daily_message_limit', 1, 'assistant');
    Setting::flushCache();

    $this->assistant->ask($this->customer, 'How are my plans going?');

    $other = User::factory()->create();
    // The other customer still has their own allowance.
    expect($this->assistant->ask($other, 'How are my plans going?'))->not->toBeNull();
});

it('stops answering platform-wide once the daily spend cap is reached', function () {
    Setting::set('assistant.daily_cost_cap_kobo', 1_000, 'assistant');
    Setting::flushCache();

    AssistantCostLog::query()->create([
        'user_id' => $this->customer->id,
        'driver' => 'test',
        'cost_kobo' => 5_000,
    ]);

    expect(fn () => $this->assistant->ask($this->customer, 'How are my plans going?'))
        ->toThrow(ValidationException::class);
});

it('logs the cost of every exchange even when the driver is free', function () {
    $this->assistant->ask($this->customer, 'How are my plans going?');

    expect(AssistantCostLog::query()->where('user_id', $this->customer->id)->count())->toBe(1)
        ->and(AssistantCostLog::query()->value('driver'))->toBe('rules');
});

it('refuses a question longer than it can take in', function () {
    expect(fn () => $this->assistant->ask($this->customer, str_repeat('a', 501)))
        ->toThrow(ValidationException::class);
});

it('refuses an empty question', function () {
    expect(fn () => $this->assistant->ask($this->customer, '   '))->toThrow(ValidationException::class);
});

// ── Over HTTP ───────────────────────────────────────────────────────────────

it('keeps the assistant behind a login', function () {
    $this->get('/account/assistant')->assertRedirect();
});

it('records both sides of the exchange', function () {
    assistantOwnPlan($this->customer);

    $this->actingAs($this->customer)
        ->post(route('assistant.ask'), ['message' => 'How are my plans going?'])
        ->assertRedirect();

    $conversation = AssistantConversation::query()->where('user_id', $this->customer->id)->firstOrFail();

    expect($conversation->messages()->where('role', AssistantMessage::ROLE_CUSTOMER)->count())->toBe(1)
        ->and($conversation->messages()->where('role', AssistantMessage::ROLE_ASSISTANT)->count())->toBe(1);
});

it('lets a customer delete their own conversation but not somebody else\'s', function () {
    $mine = $this->assistant->startConversation($this->customer, 'Mine');
    $stranger = User::factory()->create();
    $theirs = $this->assistant->startConversation($stranger, 'Theirs');

    $this->actingAs($this->customer)
        ->delete(route('assistant.conversations.destroy', $theirs->uuid))
        ->assertForbidden();

    $this->actingAs($this->customer)
        ->delete(route('assistant.conversations.destroy', $mine->uuid))
        ->assertRedirect();

    expect(AssistantConversation::query()->whereKey($theirs->id)->exists())->toBeTrue()
        ->and(AssistantConversation::query()->whereKey($mine->id)->exists())->toBeFalse();
});
