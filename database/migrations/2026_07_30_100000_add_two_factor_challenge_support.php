<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Turns 2FA from a one-time enrollment ritual into a real second factor.
 *
 * Two additions: a replay guard for TOTP codes (a code stays valid for a
 * window of seconds, so the accepted window has to be remembered), and a table
 * of devices that have already passed the challenge so staff are not asked for
 * a code on every sign-in.
 *
 * Trusted devices are a table rather than a cookie alone so they can be listed
 * and revoked — a lost laptop must be removable without resetting 2FA for the
 * whole account.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'two_factor_last_used_at')) {
            Schema::table('users', function (Blueprint $table) {
                $table->timestamp('two_factor_last_used_at')->nullable()->after('two_factor_confirmed_at');
            });
        }

        if (! Schema::hasTable('two_factor_devices')) {
            Schema::create('two_factor_devices', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();

                // Only the hash is stored: a leak of this table must not yield
                // cookies that skip the second factor.
                $table->string('token_hash', 64)->unique();

                $table->string('label')->nullable();
                $table->string('ip_address', 45)->nullable();
                $table->timestamp('last_used_at')->nullable();
                // datetime, not timestamp: MariaDB in strict mode rejects a
                // NOT NULL timestamp with no default, because the implicit
                // zero-date default is itself invalid.
                $table->dateTime('expires_at');
                $table->timestamps();

                $table->index(['user_id', 'expires_at']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('two_factor_devices');

        if (Schema::hasColumn('users', 'two_factor_last_used_at')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('two_factor_last_used_at');
            });
        }
    }
};
