<?php

namespace App\Modules\Risk\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Risk\Models\RiskFlag;
use App\Modules\Risk\Services\RiskFlagService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The risk queue.
 *
 * Reviewing a flag records a judgement; it never carries one out. If a
 * reviewer decides an account should be suspended they go and suspend it
 * through user management, where the action is deliberate, permissioned and
 * separately audited — which is the point.
 */
class AdminRiskController extends Controller
{
    public function __construct(private readonly RiskFlagService $risk) {}

    public function index(Request $request): Response
    {
        $status = (string) $request->query('status', RiskFlag::STATUS_OPEN);

        $flags = RiskFlag::query()
            ->when($status !== 'all', fn ($query) => $query->where('status', $status))
            ->with(['user:id,name,email', 'vendor:id,business_name', 'reviewer:id,name'])
            ->orderByRaw("field(severity, 'high', 'medium', 'low')")
            ->latest('id')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (RiskFlag $flag) => [
                'uuid' => $flag->uuid,
                'rule' => $flag->rule,
                'severity' => $flag->severity,
                'summary' => $flag->summary,
                'evidence' => $flag->evidence,
                'status' => $flag->status,
                'subject' => $flag->user?->name ?? $flag->vendor?->business_name ?? 'Unknown',
                'subjectKind' => $flag->user_id !== null ? 'customer' : 'vendor',
                'raisedAt' => $flag->created_at->format('j M Y'),
                'reviewedBy' => $flag->reviewer?->name,
                'reviewNote' => $flag->review_note,
                'outcome' => $flag->outcome,
            ]);

        return Inertia::render('Admin/Risk/Index', [
            'flags' => $flags,
            'filters' => ['status' => $status],
            'thresholds' => $this->risk->thresholds(),
        ]);
    }

    public function review(Request $request, RiskFlag $flag): RedirectResponse
    {
        $validated = $request->validate([
            'outcome' => ['required', Rule::in([
                RiskFlag::OUTCOME_NO_ACTION,
                RiskFlag::OUTCOME_WATCHING,
                RiskFlag::OUTCOME_ACTIONED,
            ])],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $this->risk->review($request->user(), $flag, $validated['outcome'], $validated['note'] ?? null);

        return back()->with('success', 'Flag reviewed and closed.');
    }
}
