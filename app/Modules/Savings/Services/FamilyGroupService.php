<?php

namespace App\Modules\Savings\Services;

use App\Models\User;
use App\Modules\Savings\Models\FamilyGroup;
use App\Modules\Savings\Models\FamilyGroupMember;
use App\Modules\Savings\Models\SavingsGoal;
use App\Shared\Enums\SavingsGoalStatus;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * A household's view of how everyone is getting on.
 *
 * This service moves no money and has no way to. It reads summaries of
 * members' own, entirely separate plans and adds them up for display —
 * which is the whole point: "family savings" elsewhere usually means a
 * pooled pot, and a pooled pot is exactly the thing FirstMaket does not
 * have. Nothing here creates one.
 *
 * Two consent rules, because a savings figure is a personal thing:
 *  - Nobody appears in a family group without accepting the invitation.
 *  - A member can stop sharing at any time and keeps their membership;
 *    they simply stop contributing figures to the summary.
 */
class FamilyGroupService
{
    public function create(User $owner, string $name): FamilyGroup
    {
        $group = FamilyGroup::query()->create([
            'owner_id' => $owner->id,
            'name' => $name,
            'invite_code' => $this->newInviteCode(),
        ]);

        $group->members()->create([
            'user_id' => $owner->id,
            'status' => FamilyGroupMember::STATUS_ACTIVE,
            'shares_progress' => true,
            'joined_at' => now(),
        ]);

        return $group;
    }

    public function invite(User $owner, FamilyGroup $group, User $invitee): FamilyGroupMember
    {
        if ($group->owner_id !== $owner->id) {
            throw ValidationException::withMessages(['group' => 'Only the group owner can invite.']);
        }

        $existing = $group->members()->where('user_id', $invitee->id)->first();

        if ($existing !== null) {
            throw ValidationException::withMessages(['member' => 'That person is already in the group.']);
        }

        return $group->members()->create([
            'user_id' => $invitee->id,
            'status' => FamilyGroupMember::STATUS_INVITED,
            'shares_progress' => true,
        ]);
    }

    public function accept(User $user, FamilyGroup $group): FamilyGroupMember
    {
        $member = $group->members()->where('user_id', $user->id)->first();

        if ($member === null || $member->status !== FamilyGroupMember::STATUS_INVITED) {
            throw ValidationException::withMessages(['member' => 'You have no open invitation to this group.']);
        }

        $member->forceFill(['status' => FamilyGroupMember::STATUS_ACTIVE, 'joined_at' => now()])->save();

        return $member;
    }

    /** Turn sharing on or off without leaving the group. */
    public function setSharing(User $user, FamilyGroup $group, bool $shares): FamilyGroupMember
    {
        $member = $group->members()->where('user_id', $user->id)->firstOrFail();
        $member->forceFill(['shares_progress' => $shares])->save();

        return $member;
    }

    public function leave(User $user, FamilyGroup $group): void
    {
        if ($group->owner_id === $user->id) {
            throw ValidationException::withMessages(['group' => 'The owner cannot leave their own group.']);
        }

        $group->members()->where('user_id', $user->id)->delete();
    }

    /**
     * The dashboard: one summary row per consenting member.
     *
     * Deliberately coarse. It reports how many plans somebody is running and
     * how far along they are — never what they are buying, never an address,
     * never a payment. A household summary that leaked "your brother is
     * saving for a ring" would be a worse feature than no feature.
     *
     * @return list<array{userId: int, name: string, sharing: bool, activePlans: int, targetKobo: int, savedKobo: int, progressPercent: int}>
     */
    public function summary(FamilyGroup $group): array
    {
        return $group->members()
            ->with('user:id,name')
            ->where('status', FamilyGroupMember::STATUS_ACTIVE)
            ->get()
            ->map(function (FamilyGroupMember $member) {
                if (! $member->shares_progress) {
                    return [
                        'userId' => $member->user_id,
                        'name' => $member->user->name,
                        'sharing' => false,
                        'activePlans' => 0,
                        'targetKobo' => 0,
                        'savedKobo' => 0,
                        'progressPercent' => 0,
                    ];
                }

                $plans = SavingsGoal::query()
                    ->where('user_id', $member->user_id)
                    ->where('status', SavingsGoalStatus::Saving)
                    ->get(['target_kobo', 'paid_kobo']);

                $target = (int) $plans->sum('target_kobo');
                $saved = (int) $plans->sum('paid_kobo');

                return [
                    'userId' => $member->user_id,
                    'name' => $member->user->name,
                    'sharing' => true,
                    'activePlans' => $plans->count(),
                    'targetKobo' => $target,
                    'savedKobo' => $saved,
                    'progressPercent' => $target > 0 ? (int) min(100, floor($saved * 100 / $target)) : 0,
                ];
            })
            ->values()
            ->all();
    }

    private function newInviteCode(): string
    {
        do {
            $code = Str::upper(Str::random(8));
        } while (FamilyGroup::query()->where('invite_code', $code)->exists());

        return $code;
    }
}
