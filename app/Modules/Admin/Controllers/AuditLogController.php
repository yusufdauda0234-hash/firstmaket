<?php

namespace App\Modules\Admin\Controllers;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The audit trail, readable.
 *
 * Every money, plan, listing, vendor, order and admin state change has always
 * been written to audit_logs — there was simply nowhere to read it without a
 * database client. This is that page.
 *
 * Read-only, and deliberately so: an audit trail somebody can edit is not an
 * audit trail. There is no destroy action here and there never should be.
 */
class AuditLogController extends Controller
{
    /** How many days of history the page offers. */
    private const RANGES = [7, 30, 90, 365];

    public function index(Request $request): Response
    {
        $filters = [
            'domain' => (string) $request->query('domain', ''),
            'action' => (string) $request->query('action', ''),
            'actor' => (string) $request->query('actor', ''),
            'days' => (int) $request->query('days', 30),
        ];

        if (! in_array($filters['days'], self::RANGES, true)) {
            $filters['days'] = 30;
        }

        $since = now()->subDays($filters['days']);

        $logs = AuditLog::query()
            ->where('created_at', '>=', $since)
            ->when($filters['domain'] !== '', fn (Builder $q) => $q->where('action', 'like', $filters['domain'].'.%'))
            ->when($filters['action'] !== '', fn (Builder $q) => $q->where('action', $filters['action']))
            ->when($filters['actor'] !== '', fn (Builder $q) => $q->whereIn(
                'actor_id',
                User::query()
                    ->where(fn (Builder $user) => $user
                        ->where('name', 'like', '%'.$filters['actor'].'%')
                        ->orWhere('email', 'like', '%'.$filters['actor'].'%'))
                    ->select('id'),
            ))
            ->latest('id')
            ->paginate(50)
            ->withQueryString();

        // Actor names resolved in one query over the page's rows rather than
        // through the morph relation: actor_type is almost always User, and
        // eager-loading a morphTo would fire a query per distinct type per
        // page for no benefit.
        $actorNames = User::query()
            ->whereIn('id', $logs->pluck('actor_id')->filter()->unique())
            ->pluck('name', 'id');

        return Inertia::render('Admin/Audit/Index', [
            'logs' => $logs->through(fn (AuditLog $log) => [
                'id' => $log->id,
                'action' => $log->action,
                'domain' => str_contains($log->action, '.') ? strtok($log->action, '.') : 'other',
                // "System" rather than a blank: a null actor means the change
                // came from a job or a webhook, which is information, not an
                // absence.
                'actor' => $log->actor_id === null
                    ? 'System'
                    : ($actorNames[$log->actor_id] ?? 'Deleted user #'.$log->actor_id),
                'subject' => class_basename($log->subject_type).' #'.$log->subject_id,
                'oldValues' => $log->old_values ?: null,
                'newValues' => $log->new_values ?: null,
                'ip' => $log->ip_address,
                'at' => $log->created_at?->format('j M Y, g:i:sa'),
            ]),
            'filters' => $filters,
            'ranges' => self::RANGES,
            'domains' => $this->domains($since),
            'actions' => $this->actions($since, $filters['domain']),
        ]);
    }

    /**
     * The action prefixes actually present in the window.
     *
     * Derived from the data rather than a hardcoded list: the list of audited
     * actions grows with every feature, and a filter that quietly stops
     * offering the newest ones is worse than no filter.
     *
     * @return list<array{value: string, label: string, count: int}>
     */
    private function domains(Carbon $since): array
    {
        return AuditLog::query()
            ->where('created_at', '>=', $since)
            ->select(DB::raw("substring_index(action, '.', 1) as domain"), DB::raw('count(*) as total'))
            ->groupBy('domain')
            ->orderByDesc('total')
            ->get()
            ->map(fn ($row) => [
                'value' => (string) $row->domain,
                'label' => ucfirst(str_replace('_', ' ', (string) $row->domain)),
                'count' => (int) $row->total,
            ])
            ->all();
    }

    /**
     * Individual actions, narrowed to the chosen domain.
     *
     * @return list<array{value: string, label: string}>
     */
    private function actions(Carbon $since, string $domain): array
    {
        return AuditLog::query()
            ->where('created_at', '>=', $since)
            ->when($domain !== '', fn (Builder $q) => $q->where('action', 'like', $domain.'.%'))
            ->select('action')
            ->distinct()
            ->orderBy('action')
            ->pluck('action')
            ->map(fn (string $action) => [
                'value' => $action,
                'label' => ucfirst(str_replace(['.', '_'], [' · ', ' '], $action)),
            ])
            ->all();
    }
}
