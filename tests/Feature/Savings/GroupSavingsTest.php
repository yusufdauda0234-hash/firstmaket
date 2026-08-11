<?php

use App\Models\User;
use App\Modules\Catalog\Models\Product;
use App\Modules\Savings\Models\CooperativeCycle;
use App\Modules\Savings\Models\CooperativeGroup;
use App\Modules\Savings\Models\CooperativeMember;
use App\Modules\Savings\Models\GroupContribution;
use App\Modules\Savings\Models\GroupPlan;
use App\Modules\Savings\Models\GroupPlanMember;
use App\Modules\Savings\Models\PlanPayment;
use App\Modules\Savings\Models\SavingsGoal;
use App\Modules\Savings\Services\CooperativeService;
use App\Modules\Savings\Services\FamilyGroupService;
use App\Modules\Savings\Services\GroupPlanService;
use App\Shared\Enums\SavingsGoalStatus;
use Illuminate\Validation\ValidationException;

/**
 * Saving with other people, without inventing a wallet.
 *
 * Every test here defends one of the two claims the whole phase rests on:
 * that a contribution never stops belonging to the person who made it, and
 * that no group model creates a way to get cash back out. A "group savings"
 * feature that quietly pooled money, or let one member spend another's,
 * would undo the reason FirstMaket can say it is not a bank.
 */
beforeEach(function () {
    $this->groups = app(GroupPlanService::class);
    $this->families = app(FamilyGroupService::class);
    $this->cooperatives = app(CooperativeService::class);
});

function groupPlanFor(User $user, int $targetKobo = 300_000_00): SavingsGoal
{
    return SavingsGoal::query()->create([
        'user_id' => $user->id,
        'target_kobo' => $targetKobo,
        'delivery_fee_kobo' => 0,
        'installments' => 3,
        'installment_kobo' => (int) ($targetKobo / 3),
        'paid_kobo' => 0,
        'status' => SavingsGoalStatus::Saving,
    ]);
}

// ── Group purchase: ownership ───────────────────────────────────────────────

it('records every contribution against the member who made it', function () {
    $organiser = User::factory()->create();
    $friend = User::factory()->create();
    $group = $this->groups->create($organiser, groupPlanFor($organiser), 'Fridge for the flat');

    $this->groups->invite($organiser, $group, $friend);
    $this->groups->accept($friend, $group);

    $this->groups->contribute($organiser, $group, 100_000_00, 'REF-A');
    $this->groups->contribute($friend, $group, 50_000_00, 'REF-B');

    $shares = $group->fresh()->sharesByUser();

    expect($shares[$organiser->id])->toBe(100_000_00)
        ->and($shares[$friend->id])->toBe(50_000_00);
});

it('ties every share to a real verified payment rather than a typed number', function () {
    $organiser = User::factory()->create();
    $group = $this->groups->create($organiser, groupPlanFor($organiser), 'Shared TV');

    $contribution = $this->groups->contribute($organiser, $group, 40_000_00, 'REF-C');

    expect($contribution->plan_payment_id)->not->toBeNull()
        ->and(PlanPayment::query()->whereKey($contribution->plan_payment_id)->value('amount_kobo'))
        ->toBe(40_000_00);
});

it('never lets one member redirect or claim another member\'s contribution', function () {
    $organiser = User::factory()->create();
    $friend = User::factory()->create();
    $group = $this->groups->create($organiser, groupPlanFor($organiser), 'Shared generator');
    $this->groups->invite($organiser, $group, $friend);
    $this->groups->accept($friend, $group);

    $this->groups->contribute($friend, $group, 60_000_00, 'REF-D');

    // The organiser leaving the group, cancelling it, or anything else they
    // can do never moves the friend's row onto themselves. There is simply no
    // path that rewrites a contribution's owner.
    $this->groups->cancel($organiser, $group);

    $contribution = GroupContribution::query()->where('group_plan_id', $group->id)->firstOrFail();

    expect($contribution->user_id)->toBe($friend->id)
        ->and($this->groups->shareFor($group->fresh(), $organiser))->toBe(0);
});

it('refuses a contribution from somebody who was invited but never accepted', function () {
    $organiser = User::factory()->create();
    $stranger = User::factory()->create();
    $group = $this->groups->create($organiser, groupPlanFor($organiser), 'Shared cooker');
    $this->groups->invite($organiser, $group, $stranger);

    expect(fn () => $this->groups->contribute($stranger, $group, 10_000_00, 'REF-E'))
        ->toThrow(ValidationException::class);
});

it('refuses a contribution from somebody who was never invited at all', function () {
    $organiser = User::factory()->create();
    $outsider = User::factory()->create();
    $group = $this->groups->create($organiser, groupPlanFor($organiser), 'Shared laptop');

    expect(fn () => $this->groups->contribute($outsider, $group, 10_000_00, 'REF-F'))
        ->toThrow(ValidationException::class);
});

it('keeps a departing member on the ledger, because there is no way to pay them back out', function () {
    $organiser = User::factory()->create();
    $friend = User::factory()->create();
    $group = $this->groups->create($organiser, groupPlanFor($organiser), 'Shared freezer');
    $this->groups->invite($organiser, $group, $friend);
    $this->groups->accept($friend, $group);
    $this->groups->contribute($friend, $group, 30_000_00, 'REF-G');

    $this->groups->exit($friend, $group);

    expect($this->groups->shareFor($group->fresh(), $friend))->toBe(30_000_00)
        ->and($group->fresh()->members()->where('user_id', $friend->id)->value('status'))
        ->toBe(GroupPlanMember::STATUS_EXITED);
});

it('will not let the organiser walk away from the plan they own', function () {
    $organiser = User::factory()->create();
    $group = $this->groups->create($organiser, groupPlanFor($organiser), 'Shared washer');

    expect(fn () => $this->groups->exit($organiser, $group))->toThrow(ValidationException::class);
});

it('refuses to open a group on somebody else\'s plan', function () {
    $organiser = User::factory()->create();
    $other = User::factory()->create();

    expect(fn () => $this->groups->create($organiser, groupPlanFor($other), 'Not mine'))
        ->toThrow(ValidationException::class);
});

it('does not double a share when the same payment reference is replayed', function () {
    $organiser = User::factory()->create();
    $group = $this->groups->create($organiser, groupPlanFor($organiser), 'Replay test');

    $this->groups->contribute($organiser, $group, 20_000_00, 'REF-SAME');
    $this->groups->contribute($organiser, $group, 20_000_00, 'REF-SAME');

    expect($group->fresh()->contributions()->count())->toBe(1)
        ->and($this->groups->shareFor($group->fresh(), $organiser))->toBe(20_000_00);
});

it('stops taking contributions once the group is cancelled', function () {
    $organiser = User::factory()->create();
    $group = $this->groups->create($organiser, groupPlanFor($organiser), 'Cancelled group');
    $this->groups->cancel($organiser, $group);

    expect(fn () => $this->groups->contribute($organiser, $group->fresh(), 10_000_00, 'REF-H'))
        ->toThrow(ValidationException::class);
});

// ── Family group: summarises, never pools ───────────────────────────────────

it('summarises each member\'s own separate plans without pooling anything', function () {
    $parent = User::factory()->create(['name' => 'Parent']);
    $child = User::factory()->create(['name' => 'Child']);

    $parentPlan = groupPlanFor($parent, 200_000_00);
    $parentPlan->forceFill(['paid_kobo' => 50_000_00])->save();
    $childPlan = groupPlanFor($child, 100_000_00);
    $childPlan->forceFill(['paid_kobo' => 25_000_00])->save();

    $family = $this->families->create($parent, 'Household');
    $this->families->invite($parent, $family, $child);
    $this->families->accept($child, $family);

    $summary = collect($this->families->summary($family->fresh()));

    expect($summary)->toHaveCount(2)
        ->and($summary->firstWhere('userId', $parent->id)['savedKobo'])->toBe(50_000_00)
        ->and($summary->firstWhere('userId', $child->id)['savedKobo'])->toBe(25_000_00);

    // Each plan is still owned by exactly one person, with its own balance.
    expect($parentPlan->fresh()->user_id)->toBe($parent->id)
        ->and($childPlan->fresh()->user_id)->toBe($child->id);
});

it('hides a member\'s figures the moment they stop sharing', function () {
    $parent = User::factory()->create();
    $child = User::factory()->create();
    $childPlan = groupPlanFor($child, 100_000_00);
    $childPlan->forceFill(['paid_kobo' => 40_000_00])->save();

    $family = $this->families->create($parent, 'Household');
    $this->families->invite($parent, $family, $child);
    $this->families->accept($child, $family);
    $this->families->setSharing($child, $family, false);

    $row = collect($this->families->summary($family->fresh()))->firstWhere('userId', $child->id);

    expect($row['sharing'])->toBeFalse()
        ->and($row['savedKobo'])->toBe(0);
});

it('leaves an invited family member out of the summary until they accept', function () {
    $parent = User::factory()->create();
    $child = User::factory()->create();
    groupPlanFor($child)->forceFill(['paid_kobo' => 10_000_00])->save();

    $family = $this->families->create($parent, 'Household');
    $this->families->invite($parent, $family, $child);

    expect(collect($this->families->summary($family->fresh()))->pluck('userId'))
        ->not->toContain($child->id);
});

// ── Cooperative: rotation without a cash payout ─────────────────────────────

function cooperativeOfThree(): array
{
    $organiser = User::factory()->create(['name' => 'Organiser']);
    $second = User::factory()->create(['name' => 'Second']);
    $third = User::factory()->create(['name' => 'Third']);

    $service = app(CooperativeService::class);
    $group = $service->create($organiser, 'Market ajo', 10_000_00);

    foreach ([$second, $third] as $member) {
        $service->invite($organiser, $group, $member);
        $service->accept($member, $group);
    }

    return [$group->fresh(), $organiser, $second, $third];
}

it('funds the beneficiary\'s own plan rather than paying anybody cash', function () {
    [$group, $organiser, $second, $third] = cooperativeOfThree();
    $cycle = $this->cooperatives->start($organiser, $group);

    $plan = groupPlanFor($organiser, 100_000_00);
    $this->cooperatives->nominatePlan($organiser, $cycle, $plan);

    $this->cooperatives->contribute($second, $cycle->fresh(), 'COOP-1');
    $this->cooperatives->contribute($third, $cycle->fresh(), 'COOP-2');

    // The money landed on a named plan owned by the beneficiary. There is no
    // balance anywhere, and nothing became cash.
    expect($plan->fresh()->paid_kobo)->toBe(20_000_00)
        ->and($plan->fresh()->user_id)->toBe($organiser->id);
});

it('refuses a contribution until the beneficiary has named where it lands', function () {
    [$group, $organiser, $second] = cooperativeOfThree();
    $cycle = $this->cooperatives->start($organiser, $group);

    expect(fn () => $this->cooperatives->contribute($second, $cycle, 'COOP-3'))
        ->toThrow(ValidationException::class);
});

it('will not let a beneficiary point a cycle at somebody else\'s plan', function () {
    [$group, $organiser, $second] = cooperativeOfThree();
    $cycle = $this->cooperatives->start($organiser, $group);

    expect(fn () => $this->cooperatives->nominatePlan($organiser, $cycle, groupPlanFor($second)))
        ->toThrow(ValidationException::class);
});

it('will not let the organiser choose where another member\'s turn lands', function () {
    [$group, $organiser, $second, $third] = cooperativeOfThree();
    $first = $this->cooperatives->start($organiser, $group);
    $this->cooperatives->nominatePlan($organiser, $first, groupPlanFor($organiser));
    $this->cooperatives->contribute($second, $first->fresh(), 'C1');
    $this->cooperatives->contribute($third, $first->fresh(), 'C2');
    $this->cooperatives->contribute($organiser, $first->fresh(), 'C3');

    $secondCycle = $this->cooperatives->closeCycle($organiser, $first->fresh());

    // It is the second member's turn now — the organiser cannot nominate for them.
    expect(fn () => $this->cooperatives->nominatePlan($organiser, $secondCycle, groupPlanFor($organiser)))
        ->toThrow(ValidationException::class);
});

it('fixes the rotation when the group starts so nobody\'s turn can be moved later', function () {
    [$group, $organiser, $second, $third] = cooperativeOfThree();
    $this->cooperatives->start($organiser, $group);

    // Joining after the start is refused outright.
    $latecomer = User::factory()->create();
    expect(fn () => $this->cooperatives->invite($organiser, $group->fresh(), $latecomer))
        ->toThrow(ValidationException::class);

    $order = collect($this->cooperatives->rotation($group->fresh()))->pluck('userId')->all();
    expect($order)->toBe([$organiser->id, $second->id, $third->id]);
});

it('refuses to close a cycle while somebody still owes their contribution', function () {
    [$group, $organiser, $second] = cooperativeOfThree();
    $cycle = $this->cooperatives->start($organiser, $group);
    $this->cooperatives->nominatePlan($organiser, $cycle, groupPlanFor($organiser));
    $this->cooperatives->contribute($second, $cycle->fresh(), 'C4');

    expect(fn () => $this->cooperatives->closeCycle($organiser, $cycle->fresh()))
        ->toThrow(ValidationException::class);
});

it('moves the turn on to the next member once everyone has paid', function () {
    [$group, $organiser, $second, $third] = cooperativeOfThree();
    $cycle = $this->cooperatives->start($organiser, $group);
    $this->cooperatives->nominatePlan($organiser, $cycle, groupPlanFor($organiser));

    foreach ([[$organiser, 'D1'], [$second, 'D2'], [$third, 'D3']] as [$member, $reference]) {
        $this->cooperatives->contribute($member, $cycle->fresh(), $reference);
    }

    $next = $this->cooperatives->closeCycle($organiser, $cycle->fresh());

    expect($next->beneficiary_user_id)->toBe($second->id)
        ->and($next->cycle_number)->toBe(2)
        ->and($cycle->fresh()->status)->toBe(CooperativeCycle::STATUS_CLOSED);
});

it('refuses to pay into a cycle twice', function () {
    [$group, $organiser, $second] = cooperativeOfThree();
    $cycle = $this->cooperatives->start($organiser, $group);
    $this->cooperatives->nominatePlan($organiser, $cycle, groupPlanFor($organiser));
    $this->cooperatives->contribute($second, $cycle->fresh(), 'E1');

    expect(fn () => $this->cooperatives->contribute($second, $cycle->fresh(), 'E2'))
        ->toThrow(ValidationException::class);
});

it('completes the group once every member has had a turn', function () {
    [$group, $organiser, $second, $third] = cooperativeOfThree();
    $cycle = $this->cooperatives->start($organiser, $group);

    foreach ([$organiser, $second, $third] as $index => $beneficiary) {
        $this->cooperatives->nominatePlan($beneficiary, $cycle->fresh(), groupPlanFor($beneficiary));

        foreach ([$organiser, $second, $third] as $payer) {
            $this->cooperatives->contribute($payer, $cycle->fresh(), "F{$index}-{$payer->id}");
        }

        $cycle = $this->cooperatives->closeCycle($organiser, $cycle->fresh()) ?? $cycle;
    }

    expect($group->fresh()->status)->toBe(CooperativeGroup::STATUS_COMPLETED);
});

it('needs at least two members before a rotation can start', function () {
    $organiser = User::factory()->create();
    $group = $this->cooperatives->create($organiser, 'Solo ajo', 5_000_00);

    expect(fn () => $this->cooperatives->start($organiser, $group))->toThrow(ValidationException::class);
});

// ── Over HTTP ───────────────────────────────────────────────────────────────

it('keeps the whole saving-together area behind a login', function () {
    $this->get('/savings/together')->assertRedirect();
});

it('never shows one customer another customer\'s group', function () {
    $organiser = User::factory()->create();
    $stranger = User::factory()->create();
    $this->groups->create($organiser, groupPlanFor($organiser), 'Private group');

    $response = $this->actingAs($stranger)->get('/savings/together');

    $response->assertOk();
    expect($response->getContent())->not->toContain('Private group');
});

it('gives the same answer whether an invited address exists or not', function () {
    $organiser = User::factory()->create();
    $group = $this->groups->create($organiser, groupPlanFor($organiser), 'Invite probe');

    // An invite form that says "no such user" is a way to test whether an
    // address is registered here.
    $this->actingAs($organiser)
        ->post("/savings/together/groups/{$group->uuid}/invite", ['identifier' => 'nobody@example.test'])
        ->assertSessionHasErrors('identifier');

    $errors = session('errors')->get('identifier');
    expect($errors[0])->not->toContain('not found')
        ->and($errors[0])->not->toContain('does not exist');
});

it('refuses to let a non-organiser invite people into a group', function () {
    $organiser = User::factory()->create();
    $member = User::factory()->create();
    $outsider = User::factory()->create(['email' => 'outsider@example.test']);
    $group = $this->groups->create($organiser, groupPlanFor($organiser), 'Closed group');
    $this->groups->invite($organiser, $group, $member);
    $this->groups->accept($member, $group);

    $this->actingAs($member)
        ->post("/savings/together/groups/{$group->uuid}/invite", ['identifier' => 'outsider@example.test'])
        ->assertSessionHasErrors('group');

    expect($group->fresh()->members()->where('user_id', $outsider->id)->exists())->toBeFalse();
});

it('exposes no route anywhere under saving-together that pays money out', function () {
    $withdrawalish = collect(app('router')->getRoutes())
        ->filter(fn ($route) => str_starts_with($route->uri(), 'savings/together'))
        ->filter(fn ($route) => (bool) preg_match('/withdraw|payout|cash|transfer|refund/i', $route->uri()));

    expect($withdrawalish)->toBeEmpty();
});
