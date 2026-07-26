<?php

namespace App\Modules\Admin\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Reporting\Services\ReportingService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Sprint 9 operational reporting dashboard
 * (docs/FirstMaket_Implementation_Plan.md): signups, deposits, plan
 * completions, order volume, vendor activity, and product approval
 * outcomes for an admin-chosen date range, with a CSV export per report.
 * All figures come straight from ReportingService's live source-table
 * queries — nothing here is cached or snapshotted.
 */
class ReportingController extends Controller
{
    /** @var list<string> */
    private const REPORT_KEYS = [
        'signups', 'deposits', 'plan-completions', 'order-volume', 'vendor-activity', 'product-approvals',
    ];

    public function index(Request $request, ReportingService $reports): Response
    {
        [$from, $to] = $this->resolveRange($request);

        return Inertia::render('Admin/Reports/Index', [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'signups' => $reports->signups($from, $to),
            'deposits' => $reports->deposits($from, $to),
            'planCompletions' => $reports->planCompletions($from, $to),
            'orderVolume' => $reports->orderVolume($from, $to),
            'vendorActivity' => $reports->vendorActivity($from, $to),
            'productApprovalOutcomes' => $reports->productApprovalOutcomes($from, $to),
        ]);
    }

    public function export(Request $request, string $report, ReportingService $reports): StreamedResponse
    {
        abort_unless(in_array($report, self::REPORT_KEYS, true), 404);

        [$from, $to] = $this->resolveRange($request);

        $rows = match ($report) {
            'signups' => $reports->signups($from, $to)['rows'],
            'deposits' => $reports->deposits($from, $to)['rows'],
            'plan-completions' => $reports->planCompletions($from, $to)['rows'],
            'order-volume' => $reports->orderVolume($from, $to)['rows'],
            'vendor-activity' => $reports->vendorActivity($from, $to)['rows'],
            'product-approvals' => $reports->productApprovalOutcomes($from, $to)['rows'],
        };

        return response()->streamDownload(function () use ($rows) {
            $handle = fopen('php://output', 'w');

            if ($rows !== []) {
                fputcsv($handle, array_keys($rows[0]));
                foreach ($rows as $row) {
                    fputcsv($handle, $row);
                }
            }

            fclose($handle);
        }, "{$report}-{$from->toDateString()}-to-{$to->toDateString()}.csv", [
            'Content-Type' => 'text/csv',
        ]);
    }

    /** @return array{0: Carbon, 1: Carbon} */
    private function resolveRange(Request $request): array
    {
        $to = $request->query('to') ? Carbon::parse((string) $request->query('to'))->endOfDay() : now()->endOfDay();
        $from = $request->query('from') ? Carbon::parse((string) $request->query('from'))->startOfDay() : now()->subDays(30)->startOfDay();

        return [$from, $to];
    }
}
