<?php

namespace App\Modules\Savings\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Savings\Models\CooperativeCycle;
use App\Modules\Savings\Models\CooperativeGroup;
use App\Modules\Savings\Models\CooperativeMember;
use App\Modules\Savings\Models\FamilyGroup;
use App\Modules\Savings\Models\FamilyGroupMember;
use App\Modules\Savings\Models\GroupPlan;
use App\Modules\Savings\Models\GroupPlanMember;
use App\Modules\Savings\Models\SavingsGoal;
use App\Modules\Savings\Services\CooperativeService;
use App\Modules\Savings\Services\FamilyGroupService;
use App\Modules\Savings\Services\GroupPlanService;
use App\Shared\Enums\SavingsGoalStatus;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Saving with other people. One screen covering all three models, because
 * from a customer's point of view they are one question — "can I do this
 * with someone else?" — with three answers.
 */
class GroupSavingsController extends Controller
{
    public function index(
        Request $request,
        GroupPlanService $groups,
        FamilyGroupService $families,
        CooperativeService $cooperatives,
    ): Response {
        $user = $request->user();

        return Inertia::render('Account/GroupSavings', [
            'groupPlans' => GroupPlan::query()
                ->with(['goal:id,target_kobo,paid_kobo', 'organiser:id,name'])
                ->whereHas('members', fn ($query) => $query->where('user_id', $user->id))
                ->latest('id')
                ->get()
                ->map(fn (GroupPlan $group) => [
                    'uuid' => $group->uuid,
                    'name' => $group->name,
                    'description' => $group->description,
                    'status' => $group->status,
                    'organiser' => $group->organiser->name,
                    'isOrganiser' => $group->organiser_id === $user->id,
                    'inviteCode' => $group->organiser_id === $user->id ? $group->invite_code : null,
                    'targetKobo' => $group->goal->target_kobo,
                    'savedKobo' => $group->goal->paid_kobo,
                    'myShareKobo' => $groups->shareFor($group, $user),
                    'myStatus' => $group->members()->where('user_id', $user->id)->value('status'),
                    'ledger' => $groups->ledger($group),
                ])->values(),

            // Plans the customer owns that could become a group.
            'eligiblePlans' => SavingsGoal::query()
                ->where('user_id', $user->id)
                ->where('status', SavingsGoalStatus::Saving)
                ->whereDoesntHave('groupPlan')
                ->get(['uuid', 'target_kobo', 'paid_kobo'])
                ->map(fn (SavingsGoal $goal) => [
                    'uuid' => $goal->uuid,
                    'targetKobo' => $goal->target_kobo,
                    'savedKobo' => $goal->paid_kobo,
                ])->values(),

            'familyGroups' => FamilyGroup::query()
                ->with('owner:id,name')
                ->whereHas('members', fn ($query) => $query->where('user_id', $user->id))
                ->get()
                ->map(fn (FamilyGroup $family) => [
                    'uuid' => $family->uuid,
                    'name' => $family->name,
                    'owner' => $family->owner->name,
                    'isOwner' => $family->owner_id === $user->id,
                    'inviteCode' => $family->owner_id === $user->id ? $family->invite_code : null,
                    'myStatus' => $family->members()->where('user_id', $user->id)->value('status'),
                    'iAmSharing' => (bool) $family->members()->where('user_id', $user->id)->value('shares_progress'),
                    'summary' => $families->summary($family),
                ])->values(),

            'cooperatives' => CooperativeGroup::query()
                ->with(['organiser:id,name', 'cycles' => fn ($query) => $query->where('status', CooperativeCycle::STATUS_OPEN)])
                ->whereHas('members', fn ($query) => $query->where('user_id', $user->id))
                ->get()
                ->map(function (CooperativeGroup $group) use ($user, $cooperatives) {
                    $openCycle = $group->cycles->first();

                    return [
                        'uuid' => $group->uuid,
                        'name' => $group->name,
                        'description' => $group->description,
                        'status' => $group->status,
                        'contributionKobo' => $group->contribution_kobo,
                        'cadence' => $group->cadence,
                        'organiser' => $group->organiser->name,
                        'isOrganiser' => $group->organiser_id === $user->id,
                        'inviteCode' => $group->organiser_id === $user->id ? $group->invite_code : null,
                        'myStatus' => $group->members()->where('user_id', $user->id)->value('status'),
                        'rotation' => $cooperatives->rotation($group),
                        'openCycle' => $openCycle ? [
                            'id' => $openCycle->id,
                            'number' => $openCycle->cycle_number,
                            'beneficiary' => User::query()->whereKey($openCycle->beneficiary_user_id)->value('name'),
                            'isMyTurn' => $openCycle->beneficiary_user_id === $user->id,
                            'hasPlan' => $openCycle->beneficiary_goal_id !== null,
                            'iHavePaid' => $openCycle->contributions()->where('user_id', $user->id)->exists(),
                            'paidCount' => $openCycle->contributions()->count(),
                            'memberCount' => $group->activeMembers()->count(),
                        ] : null,
                    ];
                })->values(),

            'myRunningPlans' => SavingsGoal::query()
                ->where('user_id', $user->id)
                ->where('status', SavingsGoalStatus::Saving)
                ->get(['uuid', 'target_kobo', 'paid_kobo'])
                ->map(fn (SavingsGoal $goal) => [
                    'uuid' => $goal->uuid,
                    'targetKobo' => $goal->target_kobo,
                    'savedKobo' => $goal->paid_kobo,
                ])->values(),
        ]);
    }

    // ── Group purchase ──────────────────────────────────────────────────────

    public function storeGroup(Request $request, GroupPlanService $groups): RedirectResponse
    {
        $validated = $request->validate([
            'plan_uuid' => ['required', 'string'],
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:500'],
        ]);

        $goal = SavingsGoal::query()->where('uuid', $validated['plan_uuid'])->firstOrFail();
        $groups->create($request->user(), $goal, $validated['name'], $validated['description'] ?? null);

        return back()->with('success', 'Group created. Share the invite code to bring people in.');
    }

    public function inviteToGroup(Request $request, GroupPlan $group, GroupPlanService $groups): RedirectResponse
    {
        $groups->invite($request->user(), $group, $this->findInvitee($request));

        return back()->with('success', 'Invitation sent. They have to accept before they can contribute.');
    }

    public function joinGroup(Request $request, GroupPlanService $groups): RedirectResponse
    {
        $validated = $request->validate(['invite_code' => ['required', 'string', 'max:12']]);
        $group = GroupPlan::query()->where('invite_code', $validated['invite_code'])->firstOrFail();

        $groups->accept($request->user(), $group);

        return back()->with('success', 'You have joined the group.');
    }

    public function acceptGroup(Request $request, GroupPlan $group, GroupPlanService $groups): RedirectResponse
    {
        $groups->accept($request->user(), $group);

        return back()->with('success', 'Invitation accepted.');
    }

    public function exitGroup(Request $request, GroupPlan $group, GroupPlanService $groups): RedirectResponse
    {
        $groups->exit($request->user(), $group);

        return back()->with('success', 'You have left the group. What you already contributed stays recorded against your name.');
    }

    public function cancelGroup(Request $request, GroupPlan $group, GroupPlanService $groups): RedirectResponse
    {
        $groups->cancel($request->user(), $group);

        return back()->with('success', 'Group cancelled.');
    }

    // ── Family ──────────────────────────────────────────────────────────────

    public function storeFamily(Request $request, FamilyGroupService $families): RedirectResponse
    {
        $validated = $request->validate(['name' => ['required', 'string', 'max:120']]);
        $families->create($request->user(), $validated['name']);

        return back()->with('success', 'Family group created.');
    }

    public function inviteToFamily(Request $request, FamilyGroup $family, FamilyGroupService $families): RedirectResponse
    {
        $families->invite($request->user(), $family, $this->findInvitee($request));

        return back()->with('success', 'Invitation sent.');
    }

    public function joinFamily(Request $request, FamilyGroupService $families): RedirectResponse
    {
        $validated = $request->validate(['invite_code' => ['required', 'string', 'max:12']]);
        $family = FamilyGroup::query()->where('invite_code', $validated['invite_code'])->firstOrFail();

        $families->accept($request->user(), $family);

        return back()->with('success', 'You have joined the family group.');
    }

    public function setFamilySharing(Request $request, FamilyGroup $family, FamilyGroupService $families): RedirectResponse
    {
        $validated = $request->validate(['shares_progress' => ['required', 'boolean']]);
        $families->setSharing($request->user(), $family, (bool) $validated['shares_progress']);

        return back()->with('success', $validated['shares_progress']
            ? 'Your progress is shared with this group again.'
            : 'Your figures are hidden from this group.');
    }

    public function leaveFamily(Request $request, FamilyGroup $family, FamilyGroupService $families): RedirectResponse
    {
        $families->leave($request->user(), $family);

        return back()->with('success', 'You have left the family group.');
    }

    // ── Cooperative ─────────────────────────────────────────────────────────

    public function storeCooperative(Request $request, CooperativeService $cooperatives): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:500'],
            'contribution_naira' => ['required', 'numeric', 'min:100', 'max:10000000'],
            'cadence' => ['required', 'in:weekly,monthly'],
        ]);

        $cooperatives->create(
            $request->user(),
            $validated['name'],
            (int) round(((float) $validated['contribution_naira']) * 100),
            $validated['cadence'],
            $validated['description'] ?? null,
        );

        return back()->with('success', 'Cooperative created. Invite members while it is still forming.');
    }

    public function inviteToCooperative(Request $request, CooperativeGroup $group, CooperativeService $cooperatives): RedirectResponse
    {
        $cooperatives->invite($request->user(), $group, $this->findInvitee($request));

        return back()->with('success', 'Invitation sent.');
    }

    public function joinCooperative(Request $request, CooperativeService $cooperatives): RedirectResponse
    {
        $validated = $request->validate(['invite_code' => ['required', 'string', 'max:12']]);
        $group = CooperativeGroup::query()->where('invite_code', $validated['invite_code'])->firstOrFail();

        $cooperatives->accept($request->user(), $group);

        return back()->with('success', 'You have joined the cooperative.');
    }

    public function startCooperative(Request $request, CooperativeGroup $group, CooperativeService $cooperatives): RedirectResponse
    {
        $cooperatives->start($request->user(), $group);

        return back()->with('success', 'Rotation fixed and the first turn is open.');
    }

    public function nominatePlan(Request $request, CooperativeCycle $cycle, CooperativeService $cooperatives): RedirectResponse
    {
        $validated = $request->validate(['plan_uuid' => ['required', 'string']]);
        $goal = SavingsGoal::query()->where('uuid', $validated['plan_uuid'])->firstOrFail();

        $cooperatives->nominatePlan($request->user(), $cycle, $goal);

        return back()->with('success', 'This turn will fund that plan.');
    }

    public function closeCycle(Request $request, CooperativeCycle $cycle, CooperativeService $cooperatives): RedirectResponse
    {
        $cooperatives->closeCycle($request->user(), $cycle);

        return back()->with('success', 'Turn closed.');
    }

    /**
     * Look up somebody to invite by the identifier they already share.
     *
     * Deliberately gives the same message whether the account exists or not:
     * an invite form that says "no such user" is a way to test whether an
     * email or phone number is registered here.
     */
    private function findInvitee(Request $request): User
    {
        $validated = $request->validate(['identifier' => ['required', 'string', 'max:190']]);
        $identifier = trim($validated['identifier']);

        $user = User::query()
            ->where('email', $identifier)
            ->orWhere('phone', $identifier)
            ->first();

        if ($user === null) {
            throw ValidationException::withMessages([
                'identifier' => 'We could not send that invitation. Check the email or phone number and try again.',
            ]);
        }

        return $user;
    }
}
