<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 9 (AI, Reporting, and Operational Controls): the suspend/ban action
 * itself and its reason/who/when — session revocation on Suspended/Banned
 * status was already built in Sprint 2 (EnsureUserIsActive middleware) and
 * needs no changes. Mirrors the reason-field pattern already used on
 * vendor_profiles rather than a separate status-events table, since the
 * generic audit_logs table already carries full history.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('status_reason')->nullable()->after('status');
            $table->foreignId('status_changed_by')->nullable()->after('status_reason')->constrained('users');
            $table->timestamp('status_changed_at')->nullable()->after('status_changed_by');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('status_changed_by');
            $table->dropColumn(['status_reason', 'status_changed_at']);
        });
    }
};
