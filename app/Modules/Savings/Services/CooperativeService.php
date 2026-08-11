<?php

namespace App\Modules\Savings\Services;

use App\Models\User;
use App\Modules\Savings\Models\CooperativeContribution;
use App\Modules\Savings\Models\CooperativeCycle;
use App\Modules\Savings\Models\CooperativeGroup;
use App\Modules\Savings\Models\CooperativeMember;
use App\Modules\Savings\Models\SavingsGoal;
use App\Shared\Contracts\AuditLoggerContract;
use App\Shared\Enums\SavingsGoalStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Rotating savings — ajo/esusu — without a cash payout.
 *
 * Offline, everyone pays in and one member takes the pot home in cash. That
 * cannot exist here, and pretending otherwise would be the single most
 * dangerous thing this codebase could do: the whole safety argument is that
 * money only ever moves through a verified Paystack charge and can only ever
 * become goods.
 *
 * So the rotation is kept and the cash is not. Each cycle, every member pays
 * their contribution **into the beneficiary's own plan**. The member whose
 * turn it is watches their plan jump forward by everyone else's money. They
 * still cannot take a naira out of it — they get the goods, which is what
 * they were saving for anyway.
 *
 * What that buys, and what it costs, stated plainly:
 *  - It keeps the social discipline that makes ajo work.
 *  - It cannot be used to raise emergency cash, which is one real reason
 *    people join an ajo. This model is not a substitute for that, and the UI
 *    says so rather than letting somebody find out at their turn.
 *
 * The rotation order is fixed when the group starts and never moves after
 * contributions begin — an organiser who could reorder turns mid-run could
 * take everyone's money and put their own turn first.
 */
class CooperativeService
{
    public function __construct(
        private readonly SavingsGoalService $goals,
        private readonly AuditLoggerContract $auditLogger,
    ) {}

    public function create(User $organiser, string $name, int $contributionKobo, string $cadence = 'monthly', ?string $description = null): CooperativeGroup
    {
        if ($contributionKobo <= 0) {
            throw ValidationException::withMessages(['contribution' => 'A contribution has to be more than nothing.']);
        }

        return DB::transaction(function () use ($organiser, $name, $contributionKobo, $cadence, $description) {
            $group = CooperativeGroup::query()->create([
                'organiser_id' => $organiser->id,
                'name' => $name,
                'description' => $description,
                'contribution_kobo' => $contributionKobo,
                'cadence' => $cadence,
                'status' => CooperativeGroup::STATUS_FORMING,
                'invite_code' => $this->newInviteCode(),
            ]);

            $group->members()->create([
                'user_id' => $organiser->id,
                'position' => 1,
                'status' => CooperativeMember::STATUS_ACTIVE,
                'joined_at' => now(),
            ]);

            return $group;
        });
    }

    public function invite(User $organiser, CooperativeGroup $group, User $invitee): CooperativeMember
    {
        $this->assertOrganiser($organiser, $group);

        if ($group->status !== CooperativeGroup::STATUS_FORMING) {
            throw ValidationException::withMessages([
                'group' => 'Members can only join while the group is still forming — the rotation is fixed once it starts.',
            ]);
        }

        if ($group->members()->where('user_id', $invitee->id)->exists()) {
            throw ValidationException::withMessages(['member' => 'That person is already in the group.']);
        }

        return $group->members()->create([
            'user_id' => $invitee->id,
            'position' => (int) $group->members()->max('position') + 1,
            'status' => CooperativeMember::STATUS_INVITED,
        ]);
    }

    public function accept(User $user, CooperativeGroup $group): CooperativeMember
    {
        $member = $group->members()->where('user_id', $user->id)->first();

        if ($member === null || $member->status !== CooperativeMember::STATUS_INVITED) {
            throw ValidationException::withMessages(['member' => 'You have no open invitation to this group.']);
        }

        $member->forceFill(['status' => CooperativeMember::STATUS_ACTIVE, 'joined_at' => now()])->save();

        return $member;
    }

    /**
     * Fix the rotation and open the first cycle.
     *
     * After this nobody joins and no position moves. Everyone can see the
     * whole order before agreeing to pay anything into it.
     */
    public function start(User $organiser, CooperativeGroup $group): CooperativeCycle
    {
        $this->assertOrganiser($organiser, $group);

        if ($group->status !== CooperativeGroup::STATUS_FORMING) {
            throw ValidationException::withMessages(['group' => 'This group has already started.']);
        }

        $members = $group->activeMembers()->get();

        if ($members->count() < 2) {
            throw ValidationException::withMessages(['group' => 'A rotating group needs at least two members who have joined.']);
        }

        return DB::transaction(function () use ($group, $members, $organiser) {
            // Positions are renumbered once, here, so gaps left by people who
            // never accepted do not turn into silent skipped turns.
            $members->values()->each(function (CooperativeMember $member, int $index) {
                $member->forceFill(['position' => $index + 1])->save();
            });

            $group->forceFill(['status' => CooperativeGroup::STATUS_ACTIVE])->save();

            $cycle = $this->openCycle($group, 1, $members->first()->user_id);

            $this->auditLogger->log(
                actor: $organiser,
                subject: $group,
                action: 'savings.cooperative_started',
                newValues: ['members' => $members->count(), 'contribution_kobo' => $group->contribution_kobo],
            );

            return $cycle;
        });
    }

    /**
     * Name the plan this cycle's money will land on.
     *
     * The beneficiary chooses it themselves and it has to be their own
     * running plan — the organiser cannot point everyone's contributions at
     * a plan the beneficiary did not pick.
     */
    public function nominatePlan(User $beneficiary, CooperativeCycle $cycle, SavingsGoal $goal): CooperativeCycle
    {
        if ($cycle->beneficiary_user_id !== $beneficiary->id) {
            throw ValidationException::withMessages(['cycle' => 'Only this cycle\'s beneficiary can choose where it lands.']);
        }

        if ($goal->user_id !== $beneficiary->id) {
            throw ValidationException::withMessages(['plan' => 'You can only nominate one of your own plans.']);
        }

        if ($goal->status !== SavingsGoalStatus::Saving) {
            throw ValidationException::withMessages(['plan' => 'That plan is no longer running.']);
        }

        $cycle->forceFill(['beneficiary_goal_id' => $goal->id])->save();

        return $cycle;
    }

    /**
     * A member pays their turn's contribution into the beneficiary's plan.
     *
     * Note what this is not: there is no cooperative balance, and this method
     * cannot create one. The money goes straight onto a named plan owned by a
     * named person, through the same verified-payment path as any other plan
     * payment.
     */
    public function contribute(User $user, CooperativeCycle $cycle, ?string $reference = null): CooperativeContribution
    {
        $group = $cycle->group;

        if ($cycle->status !== CooperativeCycle::STATUS_OPEN) {
            throw ValidationException::withMessages(['cycle' => 'This cycle is closed.']);
        }

        if ($cycle->beneficiary_goal_id === null) {
            throw ValidationException::withMessages([
                'cycle' => 'This cycle has nowhere to land yet — the beneficiary has not chosen a plan.',
            ]);
        }

        $member = $group->members()->where('user_id', $user->id)->first();

        if ($member === null || $member->status !== CooperativeMember::STATUS_ACTIVE) {
            throw ValidationException::withMessages(['cycle' => 'Only active members contribute.']);
        }

        if ($cycle->contributions()->where('user_id', $user->id)->exists()) {
            throw ValidationException::withMessages(['cycle' => 'You have already paid into this cycle.']);
        }

        return DB::transaction(function () use ($user, $cycle, $group, $reference) {
            $payment = $this->goals->recordPayment(
                $user,
                $cycle->beneficiaryGoal,
                $group->contribution_kobo,
                'card',
                $reference,
            );

            $existing = CooperativeContribution::query()->where('plan_payment_id', $payment->id)->first();

            if ($existing !== null) {
                return $existing;
            }

            return CooperativeContribution::query()->create([
                'cooperative_cycle_id' => $cycle->id,
                'user_id' => $user->id,
                'plan_payment_id' => $payment->id,
                'amount_kobo' => $payment->amount_kobo,
            ]);
        });
    }

    /**
     * Close a fully-paid cycle and open the next member's turn.
     *
     * Refuses while anyone still owes: closing early would move the rotation
     * on past somebody who paid but never got their turn.
     */
    public function closeCycle(User $organiser, CooperativeCycle $cycle): ?CooperativeCycle
    {
        $group = $cycle->group;
        $this->assertOrganiser($organiser, $group);

        $expected = $group->activeMembers()->count();

        if ($cycle->contributions()->count() < $expected) {
            throw ValidationException::withMessages([
                'cycle' => 'Not everyone has paid into this cycle yet.',
            ]);
        }

        return DB::transaction(function () use ($cycle, $group) {
            $cycle->forceFill(['status' => CooperativeCycle::STATUS_CLOSED, 'closed_at' => now()])->save();

            $members = $group->activeMembers()->get();
            $next = $members->firstWhere('position', $cycle->cycle_number + 1);

            if ($next === null) {
                // Everybody has had their turn.
                $group->forceFill(['status' => CooperativeGroup::STATUS_COMPLETED])->save();

                return null;
            }

            return $this->openCycle($group, $cycle->cycle_number + 1, $next->user_id);
        });
    }

    /**
     * Who is owed a turn, and who has had one.
     *
     * @return list<array{userId: int, name: string, position: int, status: string, hasReceived: bool}>
     */
    public function rotation(CooperativeGroup $group): array
    {
        $received = $group->cycles()->pluck('beneficiary_user_id')->all();

        return $group->members()
            ->with('user:id,name')
            ->orderBy('position')
            ->get()
            ->map(fn (CooperativeMember $member) => [
                'userId' => $member->user_id,
                'name' => $member->user->name,
                'position' => $member->position,
                'status' => $member->status,
                'hasReceived' => in_array($member->user_id, $received, true),
            ])
            ->values()
            ->all();
    }

    private function openCycle(CooperativeGroup $group, int $number, int $beneficiaryId): CooperativeCycle
    {
        return $group->cycles()->create([
            'cycle_number' => $number,
            'beneficiary_user_id' => $beneficiaryId,
            'status' => CooperativeCycle::STATUS_OPEN,
            'opened_at' => now(),
        ]);
    }

    private function assertOrganiser(User $user, CooperativeGroup $group): void
    {
        if ($group->organiser_id !== $user->id) {
            throw ValidationException::withMessages(['group' => 'Only the organiser can do that.']);
        }
    }

    private function newInviteCode(): string
    {
        do {
            $code = Str::upper(Str::random(8));
        } while (CooperativeGroup::query()->where('invite_code', $code)->exists());

        return $code;
    }
}
