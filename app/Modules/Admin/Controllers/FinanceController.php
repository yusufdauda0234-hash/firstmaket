<?php

namespace App\Modules\Admin\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Reporting\Services\ExpenseService;
use App\Modules\Reporting\Services\LedgerService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The money, read two ways: line by line, and as a picture.
 *
 * Both are read-only. Nothing here moves money — payouts, refunds and
 * reconciliation each have their own screen with their own permission, and
 * folding an action into a reporting page is how a reporting page becomes
 * something nobody dares give anyone access to.
 */
class FinanceController extends Controller
{
    /** Line by line: every settled movement, newest first. */
    public function transactions(Request $request, LedgerService $ledger): Response
    {
        [$from, $to] = $this->window($request);

        $kind = (string) $request->query('kind', '');
        $direction = (string) $request->query('direction', '');

        $entries = $ledger
            ->entries($from, $to, $kind === '' ? [] : [$kind], $direction)
            ->withQueryString()
            ->through(fn ($row) => [
                'kind' => $row->kind,
                'label' => collect($ledger->kinds())->firstWhere('value', $row->kind)['label'] ?? $row->kind,
                'direction' => $row->direction,
                'amountKobo' => (int) $row->amount_kobo,
                'party' => $row->party,
                'reference' => $row->reference,
                'occurredAt' => Carbon::parse($row->occurred_at)->format('j M Y, g:ia'),
            ]);

        return Inertia::render('Admin/Finance/Transactions', [
            'entries' => $entries,
            'kinds' => $ledger->kinds(),
            'filters' => [
                'kind' => $kind,
                'direction' => $direction,
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
            ],
        ]);
    }

    /** The picture: what came in, what went out, what is left. */
    public function summary(Request $request, LedgerService $ledger, ExpenseService $expenses): Response
    {
        [$from, $to] = $this->window($request);

        return Inertia::render('Admin/Finance/Summary', [
            'summary' => $ledger->summary($from, $to),
            'expensesByCategory' => $expenses->byCategory($from, $to),
            'filters' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
        ]);
    }

    /**
     * The ledger as a CSV.
     *
     * Streamed rather than built in memory: a year of a busy marketplace is
     * more rows than a PHP array should be asked to hold, and an export that
     * dies on the biggest month is the one nobody can use.
     */
    public function export(Request $request, LedgerService $ledger): StreamedResponse
    {
        [$from, $to] = $this->window($request);

        $filename = "firstmaket-transactions-{$from->toDateString()}-to-{$to->toDateString()}.csv";

        return response()->streamDownload(function () use ($ledger, $from, $to): void {
            $handle = fopen('php://output', 'wb');
            fputcsv($handle, ['Date', 'Type', 'Direction', 'Party', 'Reference', 'Amount (NGN)']);

            $ledger->each($from, $to, function ($rows) use ($handle): void {
                foreach ($rows as $row) {
                    fputcsv($handle, [
                        Carbon::parse($row->occurred_at)->toDateString(),
                        $row->kind,
                        $row->direction,
                        $row->party,
                        $row->reference,
                        // Naira with two decimals, not kobo: this opens in a
                        // spreadsheet, and an accountant reading 4500000 as
                        // ₦4.5m instead of ₦45,000 is a real mistake.
                        number_format($row->amount_kobo / 100, 2, '.', ''),
                    ]);
                }
            });

            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    /** @return array{0: Carbon, 1: Carbon} */
    private function window(Request $request): array
    {
        $to = $this->date($request->query('to')) ?? now()->endOfDay();
        $from = $this->date($request->query('from')) ?? $to->copy()->subMonths(11)->startOfMonth();

        // A backwards range would return nothing and read as "no money moved",
        // which is the wrong conclusion to hand anybody.
        [$from, $to] = $from->greaterThan($to) ? [$to, $from] : [$from, $to];

        return [$from->startOfDay(), $to->endOfDay()];
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
