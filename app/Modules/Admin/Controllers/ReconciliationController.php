<?php

namespace App\Modules\Admin\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Payments\Models\SettlementImport;
use App\Modules\Payments\Services\ReconciliationService;
use App\Shared\Enums\ReconciliationStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Finance reconciliation dashboard (Sprint 4, admin subdomain). Finance
 * Officers import a Paystack settlement batch and review how it matches the
 * internal ledger. Read-only against the ledger — reconciliation never moves
 * money.
 */
class ReconciliationController extends Controller
{
    public function index(Request $request): Response
    {
        $imports = SettlementImport::query()
            ->withCount([
                'items',
                'items as unmatched_count' => fn (Builder $q) => $q->where('status', '!=', ReconciliationStatus::Matched->value),
            ])
            ->with('importedBy:id,name')
            ->latest('id')
            ->paginate(15)
            ->through(fn (SettlementImport $import) => [
                'id' => $import->id,
                'provider' => $import->provider,
                'status' => $import->status,
                'itemsCount' => (int) $import->getAttribute('items_count'),
                'unmatchedCount' => (int) $import->getAttribute('unmatched_count'),
                'importedBy' => $import->importedBy?->name,
                'completedAt' => $import->completed_at?->toDayDateTimeString(),
                'createdAt' => $import->created_at->toDayDateTimeString(),
            ]);

        return Inertia::render('Admin/Reconciliation/Index', ['imports' => $imports]);
    }

    public function show(SettlementImport $settlementImport): Response
    {
        $settlementImport->load(['importedBy:id,name']);

        $items = $settlementImport->items()
            ->with('walletTransaction:id,uuid,user_id')
            ->latest('id')
            ->get()
            ->map(fn ($item) => [
                'id' => $item->id,
                'reference' => $item->paystack_reference,
                'providerAmountKobo' => $item->provider_amount_kobo,
                'ledgerAmountKobo' => $item->ledger_amount_kobo,
                'status' => $item->status->value,
            ]);

        $summary = $settlementImport->items()
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        return Inertia::render('Admin/Reconciliation/Show', [
            'import' => [
                'id' => $settlementImport->id,
                'provider' => $settlementImport->provider,
                'status' => $settlementImport->status,
                'importedBy' => $settlementImport->importedBy?->name,
                'completedAt' => $settlementImport->completed_at?->toDayDateTimeString(),
            ],
            'items' => $items,
            'summary' => [
                'matched' => (int) ($summary['matched'] ?? 0),
                'missing_in_ledger' => (int) ($summary['missing_in_ledger'] ?? 0),
                'missing_in_provider' => (int) ($summary['missing_in_provider'] ?? 0),
                'amount_mismatch' => (int) ($summary['amount_mismatch'] ?? 0),
            ],
        ]);
    }

    public function store(Request $request, ReconciliationService $service): RedirectResponse
    {
        $validated = $request->validate([
            // CSV/paste: one "reference,amount_in_naira" per line.
            'settlement' => ['required', 'string', 'max:100000'],
        ]);

        $lines = [];
        foreach (preg_split('/\r\n|\r|\n/', $validated['settlement']) ?: [] as $row) {
            $row = trim($row);
            if ($row === '' || stripos($row, 'reference') === 0) {
                continue; // skip blanks and an optional header row
            }
            $parts = array_map('trim', explode(',', $row));
            if (count($parts) < 2 || $parts[0] === '' || ! is_numeric($parts[1])) {
                continue;
            }
            $lines[] = [
                'reference' => $parts[0],
                'amount_kobo' => (int) round(((float) $parts[1]) * 100),
            ];
        }

        if ($lines === []) {
            return back()->withErrors(['settlement' => 'No valid "reference,amount" lines were found.']);
        }

        $import = $service->reconcile($request->user(), $lines);

        return redirect()
            ->route('admin.reconciliation.show', $import->id)
            ->with('success', 'Settlement reconciled — '.count($lines).' lines processed.');
    }
}
