<?php

namespace App\Modules\Savings\Services;

use App\Models\User;
use App\Modules\Savings\Models\GroupContribution;
use App\Modules\Savings\Models\GroupPlan;
use App\Modules\Savings\Models\GroupPlanMember;
use App\Modules\Savings\Models\PlanPayment;
use App\Modules\Savings\Models\SavingsGoal;
use App\Shared\Contracts\AuditLoggerContract;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Group purchase plans: several people funding one basket.
 *
 * The safety properties here are all about ownership, because a group is
 * where "whose money is this" gets blurry:
 *
 *  - Nobody is added to a group without accepting an invitation. A leaked
 *    invite code cannot enrol somebody silently.
 *  - A contribution is always recorded against the member who made it, tied
 *    to the PlanPayment it came from. A share is never a typed number.
 *  - No member can move, reassign, or spend another member's contribution.
 *    There is no API for it, because there is no balance to move.
 *  - The goods go to the organiser's address, and every member is told that
 *    before they can contribute a naira. That is the honest framing of what
 *    a group purchase is: several people buying one thing for one delivery.
 */
class GroupPlanService
{
    public function __construct(
        private readonly SavingsGoalService $goals,
        private readonly AuditLoggerContract $auditLogger,
    ) {}

    /**
     * Start a group around a plan the organiser already owns.
     *
     * Deliberately built on an existing plan rather than creating one: the
     * plan already carries the frozen price, delivery address and schedule,
     * and a group is a way of paying for it, not a different kind of thing.
     */
    public function create(User $organiser, SavingsGoal $goal, string $name, ?string $description = null): GroupPlan
    {
        if ($goal->user_id !== $organiser->id) {
            throw ValidationException::withMessages(['plan' => 'You can only open a group on your own plan.']);
        }

        if (GroupPlan::query()->where('savings_goal_id', $goal->id)->exists()) {
            throw ValidationException::withMessages(['plan' => 'That plan already has a group.']);
        }

        return DB::transaction(function () use ($organiser, $goal, $name, $description) {
            $group = GroupPlan::query()->create([
                'savings_goal_id' => $goal->id,
                'organiser_id' => $organiser->id,
                'name' => $name,
                'description' => $description,
                'status' => GroupPlan::STATUS_OPEN,
                'invite_code' => $this->newInviteCode(),
            ]);

            $group->members()->create([
                'user_id' => $organiser->id,
                'role' => GroupPlanMember::ROLE_ORGANISER,
                'status' => GroupPlanMember::STATUS_ACTIVE,
                'joined_at' => now(),
            ]);

            $this->auditLogger->log(
                actor: $organiser,
                subject: $group,
                action: 'savings.group_created',
                newValues: ['name' => $name, 'target_kobo' => $goal->target_kobo],
            );

            return $group;
        });
    }

    public function invite(User $organiser, GroupPlan $group, User $invitee): GroupPlanMember
    {
        $this->assertOrganiser($organiser, $group);

        if ($invitee->id === $organiser->id) {
            throw ValidationException::withMessages(['member' => 'You are already in this group.']);
        }

        $existing = $group->members()->where('user_id', $invitee->id)->first();

        if ($existing !== null && $existing->status !== GroupPlanMember::STATUS_EXITED) {
            throw ValidationException::withMessages(['member' => 'That person is already invited.']);
        }

        if ($existing !== null) {
            $existing->forceFill(['status' => GroupPlanMember::STATUS_INVITED, 'exited_at' => null])->save();

            return $existing;
        }

        return $group->members()->create([
            'user_id' => $invitee->id,
            'role' => GroupPlanMember::ROLE_MEMBER,
            'status' => GroupPlanMember::STATUS_INVITED,
        ]);
    }

    /**
     * Accept an invitation. Nobody joins a group without doing this, so
     * knowing the invite code is never enough on its own.
     */
    public function accept(User $user, GroupPlan $group): GroupPlanMember
    {
        $member = $group->members()->where('user_id', $user->id)->first();

        if ($member === null || $member->status !== GroupPlanMember::STATUS_INVITED) {
            throw ValidationException::withMessages(['member' => 'You have no open invitation to this group.']);
        }

        $member->forceFill(['status' => GroupPlanMember::STATUS_ACTIVE, 'joined_at' => now()])->save();

        return $member;
    }

    /**
     * Leave a group.
     *
     * What already went in stays in: there is no withdrawal anywhere in
     * FirstMaket, and a group is not a loophole in that. The contribution
     * ledger keeps their name against it, so if the group is later cancelled
     * or disputed there is a record of exactly what they put in.
     *
     * The organiser cannot leave — somebody has to own the plan and take
     * delivery. They cancel the group instead.
     */
    public function exit(User $user, GroupPlan $group): void
    {
        $member = $group->members()->where('user_id', $user->id)->firstOrFail();

        if ($member->role === GroupPlanMember::ROLE_ORGANISER) {
            throw ValidationException::withMessages([
                'member' => 'The organiser owns the plan and cannot leave it. Cancel the group instead.',
            ]);
        }

        $member->forceFill(['status' => GroupPlanMember::STATUS_EXITED, 'exited_at' => now()])->save();

        $this->auditLogger->log(
            actor: $user,
            subject: $group,
            action: 'savings.group_member_exited',
            newValues: ['contributed_kobo' => $this->shareFor($group, $user)],
        );
    }

    /**
     * Record a member's contribution.
     *
     * Called only after a verified payment, exactly like every other way
     * money reaches a plan — the PlanPayment is created by SavingsGoalService
     * and this writes the ownership row that says whose it was.
     */
    public function contribute(User $user, GroupPlan $group, int $amountKobo, ?string $reference = null): GroupContribution
    {
        if (! $group->isOpen()) {
            throw ValidationException::withMessages(['group' => 'This group is no longer accepting contributions.']);
        }

        $member = $group->members()->where('user_id', $user->id)->first();

        if ($member === null || ! $member->isActive()) {
            throw ValidationException::withMessages(['group' => 'Only members who have joined can contribute.']);
        }

        return DB::transaction(function () use ($user, $group, $amountKobo, $reference) {
            $payment = $this->goals->recordPayment($user, $group->goal, $amountKobo, 'card', $reference);

            // A replayed webhook returns the original payment; the unique
            // index on plan_payment_id means the share is never doubled.
            $existing = GroupContribution::query()->where('plan_payment_id', $payment->id)->first();

            if ($existing !== null) {
                return $existing;
            }

            $contribution = GroupContribution::query()->create([
                'group_plan_id' => $group->id,
                'user_id' => $user->id,
                'plan_payment_id' => $payment->id,
                'amount_kobo' => $payment->amount_kobo,
            ]);

            if ($group->goal->fresh()->isCovered()) {
                $group->forceFill(['status' => GroupPlan::STATUS_FUNDED])->save();
            }

            return $contribution;
        });
    }

    public function cancel(User $organiser, GroupPlan $group): void
    {
        $this->assertOrganiser($organiser, $group);

        $group->forceFill(['status' => GroupPlan::STATUS_CANCELLED])->save();

        $this->auditLogger->log(
            actor: $organiser,
            subject: $group,
            action: 'savings.group_cancelled',
            newValues: ['shares' => $group->sharesByUser()],
        );
    }

    /** What one member has put in, in kobo. */
    public function shareFor(GroupPlan $group, User $user): int
    {
        return (int) $group->contributions()->where('user_id', $user->id)->sum('amount_kobo');
    }

    /**
     * The full ownership picture for a group.
     *
     * @return list<array{userId: int, name: string, role: string, status: string, contributedKobo: int, sharePercent: float}>
     */
    public function ledger(GroupPlan $group): array
    {
        $shares = $group->sharesByUser();
        $total = array_sum($shares);

        return $group->members()
            ->with('user:id,name')
            ->get()
            ->map(function (GroupPlanMember $member) use ($shares, $total) {
                $contributed = $shares[$member->user_id] ?? 0;

                return [
                    'userId' => $member->user_id,
                    'name' => $member->user->name,
                    'role' => $member->role,
                    'status' => $member->status,
                    'contributedKobo' => $contributed,
                    'sharePercent' => $total > 0 ? round($contributed * 100 / $total, 1) : 0.0,
                ];
            })
            ->values()
            ->all();
    }

    private function assertOrganiser(User $user, GroupPlan $group): void
    {
        if ($group->organiser_id !== $user->id) {
            throw ValidationException::withMessages(['group' => 'Only the organiser can do that.']);
        }
    }

    private function newInviteCode(): string
    {
        do {
            $code = Str::upper(Str::random(8));
        } while (GroupPlan::query()->where('invite_code', $code)->exists());

        return $code;
    }
}
