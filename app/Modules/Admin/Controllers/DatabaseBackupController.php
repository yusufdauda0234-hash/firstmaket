<?php

namespace App\Modules\Admin\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Admin\Services\DatabaseBackupService;
use App\Shared\Contracts\AuditLoggerContract;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DatabaseBackupController extends Controller
{
    public function index(DatabaseBackupService $service): Response
    {
        return Inertia::render('Admin/Settings/Backup', [
            'tables' => $service->tables(),
            'mysqldumpAvailable' => $service->mysqldumpAvailable(),
        ]);
    }

    public function download(Request $request, DatabaseBackupService $service, AuditLoggerContract $auditLogger): StreamedResponse
    {
        $validated = $request->validate(['tables' => ['nullable', 'array'], 'tables.*' => ['string']]);
        $tables = $validated['tables'] ?? [];

        if (! $service->mysqldumpAvailable()) {
            throw ValidationException::withMessages([
                'tables' => 'mysqldump is not available on this server, so a backup cannot be generated here.',
            ]);
        }

        $process = $service->dumpCommand($tables);
        $filename = 'firstmaket-'.($tables === [] ? 'full' : 'partial').'-backup-'.now()->format('Y-m-d_His').'.sql';

        $auditLogger->log(
            actor: $request->user(),
            subject: $request->user(),
            action: 'admin.database_backup_downloaded',
            newValues: ['tables' => $tables === [] ? 'all' : $tables],
        );

        return response()->streamDownload(function () use ($process) {
            $process->run(function (string $type, string $buffer): void {
                echo $buffer;
            });

            if (! $process->isSuccessful()) {
                report(new \RuntimeException('mysqldump failed: '.$process->getErrorOutput()));
            }
        }, $filename, ['Content-Type' => 'application/sql']);
    }

    public function truncate(Request $request, DatabaseBackupService $service, AuditLoggerContract $auditLogger): RedirectResponse
    {
        $validated = $request->validate([
            'tables' => ['required', 'array', 'min:1'],
            'tables.*' => ['string'],
            // A deliberate extra step beyond the confirm dialog already
            // shown client-side: this wipes data with no undo, and a typed
            // phrase is much harder to trigger by an accidental double-click
            // than a second button.
            'confirm' => ['required', 'in:DELETE'],
        ]);

        $wiped = $service->truncateTables($validated['tables']);

        $auditLogger->log(
            actor: $request->user(),
            subject: $request->user(),
            action: 'admin.database_tables_truncated',
            newValues: ['tables' => $wiped],
        );

        $summary = collect($wiped)
            ->map(fn (int $count, string $table) => "{$table} ({$count})")
            ->implode(', ');

        return back()->with('success', "Cleared: {$summary}.");
    }
}
