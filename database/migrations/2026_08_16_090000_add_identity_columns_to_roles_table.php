<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2F: roles an administrator can create.
 *
 * Two columns turn Spatie's plain `roles` table into something staff can
 * safely manage themselves:
 *
 * - `is_system` marks the seven roles the platform ships with and depends on
 *   (Gate::before checks 'Super Administrator' by name, StaffController
 *   assumes 'Vendor'/'Customer' exist, and so on). A system role can have its
 *   permissions edited — that is the whole point of this phase — but it can
 *   never be renamed or deleted, so a mis-click cannot remove the role the
 *   codebase is quietly depending on existing.
 * - `description` is shown next to the role wherever staff pick one, so
 *   "Logistics Coordinator" doesn't need tribal knowledge to understand.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            $table->boolean('is_system')->default(false)->after('name');
            $table->string('description', 255)->nullable()->after('is_system');
        });

        // The seven roles already seeded are exactly the ones the codebase
        // depends on by name — mark them system in the same migration that
        // adds the column, so the flag is never briefly false for a role
        // that would break something if deleted.
        DB::table('roles')->whereIn('name', [
            'Super Administrator',
            'Administrator',
            'Support Agent',
            'Logistics Personnel',
            'Finance Officer',
            'Vendor',
            'Customer',
        ])->update(['is_system' => true]);
    }

    public function down(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            $table->dropColumn(['is_system', 'description']);
        });
    }
};
