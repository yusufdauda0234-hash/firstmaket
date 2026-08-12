<?php

namespace App\Modules\Admin\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Reporting\Models\Expense;
use App\Modules\Reporting\Services\ExpenseService;
use App\Shared\Enums\ExpenseCategory;
use App\Shared\Enums\ExpenseStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * What the business spends: recording it, approving it, and seeing where it
 * went.
 *
 * Guarded by permission:expenses.manage; approving needs expenses.approve on
 * top, because signing off spend is a different job from writing it down.
 */
class ExpenseController extends Controller
{
    public function index(Request $request, ExpenseService $expenses): Response
    {
        [$from, $to] = $this->window($request);

        $filters = [
            'category' => (string) $request->query('category', ''),
            'status' => (string) $request->query('status', ''),
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
        ];

        $rows = Expense::query()
            ->with(['recorder:id,name', 'approver:id,name'])
            ->whereBetween('incurred_on', [$from->toDateString(), $to->toDateString()])
            ->when($filters['category'] !== '', fn (Builder $q) => $q->where('category', $filters['category']))
            ->when($filters['status'] !== '', fn (Builder $q) => $q->where('status', $filters['status']))
            ->orderByDesc('incurred_on')
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (Expense $expense) => $this->present($expense, $request));

        return Inertia::render('Admin/Finance/Expenses', [
            'expenses' => $rows,
            'filters' => $filters,
            'categories' => $expenses->categories(),
            'summary' => [
                'totalKobo' => $expenses->totalKobo($from, $to),
                'approvedKobo' => $expenses->approvedKobo($from, $to),
                'byCategory' => $expenses->byCategory($from, $to),
                'byMonth' => $expenses->byMonth($from, $to),
            ],
            'canApprove' => $request->user()->can('expenses.approve'),
        ]);
    }

    public function store(Request $request, ExpenseService $expenses): RedirectResponse
    {
        $validated = $request->validate([
            'category' => ['required', Rule::enum(ExpenseCategory::class)],
            'description' => ['required', 'string', 'max:200'],
            'payee' => ['nullable', 'string', 'max:120'],
            'amount_naira' => ['required', 'numeric', 'min:0.01', 'max:99999999'],
            // No future dating: an expense is something that happened, and a
            // total for this month that includes next month's rent is not a
            // total anybody can use.
            'incurred_on' => ['required', 'date', 'before_or_equal:today'],
            'payment_method' => ['nullable', 'string', 'max:30'],
            'note' => ['nullable', 'string', 'max:1000'],
            'receipt' => ['nullable', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120'],
        ]);

        $expenses->record($request->user(), [
            'category' => $validated['category'],
            'description' => $validated['description'],
            'payee' => $validated['payee'] ?? null,
            // Entered in naira, stored in kobo like every other figure in the
            // system. round() rather than a cast: (int) (0.29 * 100) is 28.
            'amount_kobo' => (int) round(((float) $validated['amount_naira']) * 100),
            'incurred_on' => $validated['incurred_on'],
            'payment_method' => $validated['payment_method'] ?? null,
            'note' => $validated['note'] ?? null,
            'receipt_path' => $request->hasFile('receipt')
                ? $request->file('receipt')->store('expense-receipts', 'local')
                : null,
        ]);

        return back()->with('success', 'Expense recorded.');
    }

    public function decide(Request $request, Expense $expense, ExpenseService $expenses): RedirectResponse
    {
        $validated = $request->validate([
            'status' => ['required', Rule::in([ExpenseStatus::Approved->value, ExpenseStatus::Rejected->value])],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $expenses->decide(
            $request->user(),
            $expense,
            ExpenseStatus::from($validated['status']),
            $validated['note'] ?? null,
        );

        return back()->with('success', 'Decision recorded.');
    }

    /**
     * The attached receipt.
     *
     * Streamed through the app on a private disk rather than served from
     * public storage: an expense receipt carries supplier names, amounts and
     * sometimes account details, and a guessable public URL is not access
     * control.
     */
    public function receipt(Expense $expense): StreamedResponse
    {
        abort_if($expense->receipt_path === null, 404);
        abort_unless(Storage::disk('local')->exists($expense->receipt_path), 404);

        return Storage::disk('local')->download(
            $expense->receipt_path,
            $expense->reference.'-receipt.'.pathinfo($expense->receipt_path, PATHINFO_EXTENSION),
        );
    }

    /** @return array<string, mixed> */
    private function present(Expense $expense, Request $request): array
    {
        return [
            'uuid' => $expense->uuid,
            'reference' => $expense->reference,
            'category' => $expense->category->value,
            'categoryLabel' => $expense->category->label(),
            'description' => $expense->description,
            'payee' => $expense->payee,
            'amountKobo' => $expense->amount_kobo,
            'incurredOn' => $expense->incurred_on->format('j M Y'),
            'paymentMethod' => $expense->payment_method,
            'note' => $expense->note,
            'hasReceipt' => $expense->receipt_path !== null,
            'status' => $expense->status->value,
            'statusLabel' => $expense->status->label(),
            'recordedBy' => $expense->recorder?->name ?? 'Unknown',
            'approvedBy' => $expense->approver?->name,
            'decisionNote' => $expense->decision_note,
            // Computed here rather than in the page: the "not your own"
            // rule lives in the service, and the button must agree with it
            // or staff will click something that then refuses.
            'canDecide' => $request->user()->can('expenses.approve')
                && ! $expense->isDecided()
                && $expense->recorded_by !== $request->user()->id,
        ];
    }

    /** @return array{0: Carbon, 1: Carbon} */
    private function window(Request $request): array
    {
        $to = $this->date($request->query('to')) ?? now()->endOfMonth();
        $from = $this->date($request->query('from')) ?? $to->copy()->subMonths(11)->startOfMonth();

        // A backwards range would silently return nothing and read as "we
        // spent nothing", which is the wrong conclusion to hand anybody.
        return $from->greaterThan($to) ? [$to, $from] : [$from, $to];
    }

    private function date(mixed $value): ?Carbon
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }
}
