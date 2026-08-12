<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Where the current rank's referral quota starts counting from.
 *
 * Two columns doing two different jobs:
 *
 * `rank_entered_at` is for people — "on this rank since March" — and is only
 * ever displayed.
 *
 * `rank_baseline_conversion_id` is what the quota is actually counted
 * against, and it is an id rather than a timestamp on purpose. Timestamps
 * here are accurate to the second, and a partner upgrading in the same second
 * as a referral lands would otherwise have that referral counted against
 * whichever side of the boundary the comparison happened to fall. Ids are
 * monotonic and exact, so "everything after this point" has one answer.
 *
 * Both are backfilled so partners who existed before ranks did keep their
 * whole history against the rank they are on.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('affiliates', function (Blueprint $table) {
            $table->timestamp('rank_entered_at')->nullable()->after('tier_id');
            $table->unsignedBigInteger('rank_baseline_conversion_id')->nullable()->after('rank_entered_at');
        });

        // Nothing has upgraded yet, so their whole history belongs to the rank
        // they are on: baseline stays null, meaning "count everything".
        DB::table('affiliates')
            ->whereNull('rank_entered_at')
            ->update(['rank_entered_at' => DB::raw('created_at')]);
    }

    public function down(): void
    {
        Schema::table('affiliates', function (Blueprint $table) {
            $table->dropColumn(['rank_entered_at', 'rank_baseline_conversion_id']);
        });
    }
};
