<?php

namespace App\Modules\Admin\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Process\Process;

/**
 * Raw database export and table wipe for the highest-privilege admin screen
 * in the app — gated behind system.backup, held by no seeded role (see
 * RolesAndPermissionsSeeder), so only Super Administrator reaches it out of
 * the box.
 *
 * Deliberately does not exclude any table from the wipe list: an earlier
 * design hard-blocked money/audit tables, but the business decided a Super
 * Administrator should be able to clear anything, including by mistake —
 * the confirmation step in the controller/UI is the safety net, not a
 * table denylist.
 */
class DatabaseBackupService
{
    /** @return list<array{name: string, rowCount: int}> */
    public function tables(): array
    {
        return collect($this->tableNames())
            ->sort()
            ->map(fn (string $table) => [
                'name' => $table,
                'rowCount' => (int) DB::table($table)->count(),
            ])
            ->values()
            ->all();
    }

    public function mysqldumpAvailable(): bool
    {
        try {
            $process = new Process(['mysqldump', '--version']);
            $process->run();

            return $process->isSuccessful();
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Streams a mysqldump straight to the HTTP response body — never
     * buffered fully in PHP memory, so this scales to a database far larger
     * than the app server's RAM.
     *
     * @param  list<string>  $tables  Empty means every table.
     */
    public function dumpCommand(array $tables = []): Process
    {
        $connection = config('database.connections.mysql');
        $validTables = $tables === []
            ? []
            : array_values(array_intersect($tables, $this->tableNames()));

        if ($tables !== [] && $validTables === []) {
            throw ValidationException::withMessages(['tables' => 'None of the selected tables exist.']);
        }

        $args = [
            'mysqldump',
            '--host='.$connection['host'],
            '--port='.$connection['port'],
            '--user='.$connection['username'],
            '--single-transaction',
            '--quick',
            '--no-tablespaces',
            '--column-statistics=0',
        ];

        // Azure MySQL refuses unencrypted connections — same CA bundle the
        // app connection itself uses (config/database.php).
        $sslCa = env('MYSQL_ATTR_SSL_CA');
        if ($sslCa) {
            $args[] = '--ssl-ca='.$sslCa;
        }

        $args[] = $connection['database'];
        array_push($args, ...$validTables);

        $process = new Process($args, null, ['MYSQL_PWD' => (string) $connection['password']]);
        $process->setTimeout(null);

        return $process;
    }

    /**
     * Wipes every row from each named table. Truncates rather than deletes
     * (resets auto-increment, and is not slowed down by row-by-row logging)
     * and disables FK checks for the batch so a table can be cleared even
     * while another still references it — re-enabled in every case via
     * finally, including when a table name turns out invalid.
     *
     * @param  list<string>  $tableNames
     * @return array<string, int> Table name => row count wiped.
     */
    public function truncateTables(array $tableNames): array
    {
        $valid = array_values(array_unique(array_intersect($tableNames, $this->tableNames())));

        if ($valid === []) {
            throw ValidationException::withMessages(['tables' => 'Select at least one existing table.']);
        }

        $wiped = [];

        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        try {
            foreach ($valid as $table) {
                $wiped[$table] = (int) DB::table($table)->count();
                DB::table($table)->truncate();
            }
        } finally {
            DB::statement('SET FOREIGN_KEY_CHECKS=1');
        }

        return $wiped;
    }

    /**
     * Plain table names belonging to this app's own database only.
     *
     * Schema::getTableListing(schema: null, ...) does not mean "the current
     * database" — MySQL's information_schema.tables has no concept of a
     * "current" schema, so passing null lists every table in every database
     * the connection's user can see across the whole server. On a shared
     * MySQL instance (dev/staging boxes often host several apps' databases
     * side by side) that would let this screen list, download, or truncate
     * another application's tables entirely. The schema must always be
     * pinned to this connection's own database name.
     */
    private function tableNames(): array
    {
        return Schema::getTableListing(schema: DB::connection()->getDatabaseName(), schemaQualified: false);
    }
}
