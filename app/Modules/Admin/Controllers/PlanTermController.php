<?php

namespace App\Modules\Admin\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Admin\Support\StarterTemplates;
use App\Modules\Savings\Models\PlanTerm;
use App\Modules\Savings\Models\SavingsGoal;
use App\Shared\Contracts\AuditLoggerContract;
use App\Shared\Enums\PlanCadence;
use App\Shared\Enums\SavingsGoalStatus;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Admin control over the Pay Small Small terms customers may choose:
 * how often they pay, how many instalments it takes, and the order value a
 * term is worth offering on.
 *
 * Editing a term never touches a plan already running — those snapshot their
 * cadence and instalment count at signup — so the worst an admin can do here
 * is change what the next customer is offered. Deactivating is preferred to
 * deleting for the same reason, and deleting is refused outright while plans
 * still reference the term.
 */
class PlanTermController extends Controller
{
    /** Each instalment is a separate card charge, so a term has to stay usable. */
    private const MAX_INSTALMENTS = 120;

    public function index(): Response
    {
        $terms = PlanTerm::query()
            ->orderBy('sort_order')
            ->orderBy('duration_months')
            ->get()
            ->map(fn (PlanTerm $term) => [
                'id' => $term->id,
                'name' => $term->name,
                'cadence' => $term->cadence->value,
                'cadenceLabel' => $term->cadence->shortLabel(),
                'installments' => $term->installments,
                'durationMonths' => $term->duration_months,
                'durationLabel' => $term->durationLabel(),
                'minTargetNaira' => $term->min_target_kobo / 100,
                'firstPaymentDueDays' => $term->first_payment_due_days,
                'firstPaymentLabel' => $term->firstPaymentLabel(),
                'missedPaymentsAllowed' => $term->missed_payments_allowed,
                'isActive' => $term->is_active,
                'planCount' => SavingsGoal::query()->where('plan_term_id', $term->id)->count(),
                'activePlanCount' => SavingsGoal::query()
                    ->where('plan_term_id', $term->id)
                    ->where('status', SavingsGoalStatus::Saving)
                    ->count(),
            ]);

        return Inertia::render('Admin/Settings/PlanTerms', [
            'terms' => $terms,
            'cadences' => PlanCadence::options(),
            // The form previews the payment count, so it needs the same
            // figures the enum uses — never a second hardcoded copy.
            'cadenceMath' => PlanCadence::math(),
            'templates' => StarterTemplates::forDisplay(StarterTemplates::planTerms()),
        ]);
    }

    /**
     * Switch several terms on or off at once.
     *
     * Only activation, never deletion: a term customers have used is history a
     * running plan still points at, and deleting in bulk would be mostly
     * silent refusals. Switching one off simply stops it being offered.
     */
    public function bulkUpdate(Request $request, AuditLoggerContract $auditLogger): RedirectResponse
    {
        $validated = $request->validate([
            'action' => ['required', 'string', 'in:activate,deactivate'],
            'ids' => ['required', 'array', 'min:1', 'max:100'],
            'ids.*' => ['required', 'integer'],
        ], [
            'ids.required' => 'Select at least one term first.',
        ]);

        $active = $validated['action'] === 'activate';
        $terms = PlanTerm::query()->whereIn('id', $validated['ids'])->get();

        foreach ($terms as $term) {
            $term->update(['is_active' => $active, 'updated_by' => $request->user()->id]);
        }

        $auditLogger->log(
            actor: $request->user(),
            subject: $request->user(),
            action: 'admin.plan_terms_bulk_'.$validated['action'],
            newValues: ['plan_term_ids' => $terms->pluck('id')->all()],
        );

        $count = $terms->count();

        return back()->with(
            'success',
            $count.' term'.($count === 1 ? '' : 's').' '.($active ? 'switched on' : 'switched off').'.'
        );
    }

    /**
     * Lay down a ready-made set of schedules.
     *
     * A cadence and a duration together identify a term, so applying twice
     * adds nothing rather than creating a second "Monthly over 3 months".
     */
    public function applyTemplate(Request $request, AuditLoggerContract $auditLogger): RedirectResponse
    {
        $templates = StarterTemplates::planTerms();

        $validated = $request->validate([
            'template' => ['required', 'string', Rule::in(array_keys($templates))],
        ]);

        $added = 0;

        foreach ($templates[$validated['template']]['rows'] as $row) {
            $exists = PlanTerm::query()
                ->where('cadence', $row['cadence'])
                ->where('duration_months', $row['duration_months'])
                ->exists();

            if ($exists) {
                continue;
            }

            $cadence = $row['cadence'];
            $months = $row['duration_months'];

            PlanTerm::query()->create([
                'cadence' => $cadence,
                'duration_months' => $months,
                'installments' => $cadence->installmentsFor($months),
                'name' => $cadence->label().' over '.$months.' month'.($months === 1 ? '' : 's'),
                'min_target_kobo' => $row['min_target_kobo'] ?? 0,
                'first_payment_due_days' => $row['first_payment_due_days'] ?? 0,
                'missed_payments_allowed' => $row['missed_payments_allowed'] ?? 2,
                'is_active' => true,
                'updated_by' => $request->user()->id,
            ]);
            $added++;
        }

        $auditLogger->log(
            actor: $request->user(),
            subject: $request->user(),
            action: 'admin.plan_terms_template_applied',
            newValues: ['template' => $validated['template'], 'added' => $added],
        );

        return back()->with(
            $added > 0 ? 'success' : 'error',
            $added > 0
                ? $added.' plan'.($added === 1 ? '' : 's').' added. Customers can choose them at checkout now.'
                : 'Nothing added — those schedules are already offered.',
        );
    }

    public function store(Request $request, AuditLoggerContract $auditLogger): RedirectResponse
    {
        $term = PlanTerm::query()->create($this->validated($request) + ['updated_by' => $request->user()->id]);

        $auditLogger->log(
            actor: $request->user(),
            subject: $term,
            action: 'admin.plan_term_created',
            newValues: $term->only(['name', 'cadence', 'duration_months', 'installments', 'min_target_kobo', 'first_payment_due_days', 'missed_payments_allowed', 'is_active']),
        );

        return back()->with('success', 'Plan term added.');
    }

    public function update(Request $request, PlanTerm $planTerm, AuditLoggerContract $auditLogger): RedirectResponse
    {
        $before = $planTerm->only(['name', 'cadence', 'duration_months', 'installments', 'min_target_kobo', 'first_payment_due_days', 'missed_payments_allowed', 'is_active']);

        $planTerm->update($this->validated($request, $planTerm) + ['updated_by' => $request->user()->id]);

        $auditLogger->log(
            actor: $request->user(),
            subject: $planTerm,
            action: 'admin.plan_term_updated',
            oldValues: $before,
            newValues: $planTerm->only(['name', 'cadence', 'duration_months', 'installments', 'min_target_kobo', 'first_payment_due_days', 'missed_payments_allowed', 'is_active']),
        );

        return back()->with('success', 'Plan term updated. Plans already running keep the terms they started on.');
    }

    public function destroy(Request $request, PlanTerm $planTerm, AuditLoggerContract $auditLogger): RedirectResponse
    {
        // A term that plans point at is history, not configuration.
        if (SavingsGoal::query()->where('plan_term_id', $planTerm->id)->exists()) {
            return back()->withErrors([
                'term' => 'Customers have used this term, so it cannot be deleted. Deactivate it instead — it will stop being offered without disturbing their plans.',
            ]);
        }

        $auditLogger->log(actor: $request->user(), subject: $planTerm, action: 'admin.plan_term_deleted');

        $planTerm->delete();

        return back()->with('success', 'Plan term deleted.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?PlanTerm $existing = null): array
    {
        $validated = $request->validate([
            'cadence' => ['required', Rule::enum(PlanCadence::class)],
            // The duration is the input; the payment count is derived from it
            // (PlanCadence::installmentsFor) so a term can never claim one
            // length while charging for another. Two years is the ceiling.
            'duration_months' => ['required', 'integer', 'min:1', 'max:24'],
            'min_target_naira' => ['nullable', 'numeric', 'min:0', 'max:100000000'],
            // 0 means the customer pays at checkout; anything higher is how
            // many days they get before the plan is revoked. Capped at 90 —
            // a longer runway than that is not a deadline.
            'first_payment_due_days' => ['nullable', 'integer', 'min:0', 'max:90'],
            // How many scheduled payments may be missed before the plan is
            // let go. 0 means never let go for inactivity.
            'missed_payments_allowed' => ['nullable', 'integer', 'min:0', 'max:24'],
            'is_active' => ['boolean'],
        ], [
            'duration_months.min' => 'A term has to run for at least a month.',
            'duration_months.max' => 'Two years is the longest term we offer.',
            'first_payment_due_days.max' => 'Ninety days is the longest we hold a price without a payment.',
            'missed_payments_allowed.max' => 'Two dozen missed payments is not a plan any more.',
        ]);

        $cadence = PlanCadence::from($validated['cadence']);
        $months = (int) $validated['duration_months'];

        // "Yearly over 18 months" cannot be honoured — it either overruns the
        // stated duration or quietly drops a payment. Refuse rather than round.
        if (! $cadence->dividesEvenly($months)) {
            throw ValidationException::withMessages([
                'duration_months' => 'A yearly term has to run in whole years — 12 or 24 months.',
            ]);
        }

        $installments = $cadence->installmentsFor($months);

        // A monthly term of one month is a single payment — that is paying in
        // full, not a plan.
        if ($installments < 2) {
            throw ValidationException::withMessages([
                'duration_months' => 'That works out to a single payment, which is just paying in full. Choose a longer run.',
            ]);
        }

        // Every instalment is its own card charge. Daily over two years would
        // be 720 of them, which is a support burden rather than a product.
        if ($installments > self::MAX_INSTALMENTS) {
            throw ValidationException::withMessages([
                'duration_months' => "That is {$installments} separate payments. Keep a term under ".self::MAX_INSTALMENTS.' — each one is a card charge the customer has to make.',
            ]);
        }

        // Cadence + duration is what makes a term distinct, so catch the clash
        // here rather than letting the unique index surface a raw SQL error.
        $clash = PlanTerm::query()
            ->where('cadence', $cadence->value)
            ->where('duration_months', $months)
            ->when($existing !== null, fn ($query) => $query->whereKeyNot($existing->id))
            ->exists();

        if ($clash) {
            throw ValidationException::withMessages([
                'duration_months' => 'A '.Str::lower($cadence->label()).' term over that many months already exists.',
            ]);
        }

        return [
            // Always derived, never typed. A hand-written name could say
            // "Easy 6" on a schedule that runs three months, and the label
            // would contradict the maths on the customer own plan page.
            'name' => $cadence->suggestedName($months),
            'cadence' => $cadence->value,
            'duration_months' => $months,
            'min_target_kobo' => (int) round(((float) ($validated['min_target_naira'] ?? 0)) * 100),
            'first_payment_due_days' => (int) ($validated['first_payment_due_days'] ?? 0),
            'missed_payments_allowed' => (int) ($validated['missed_payments_allowed'] ?? 3),
            'is_active' => (bool) ($validated['is_active'] ?? true),
        ];
    }
}
