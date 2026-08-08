<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Drop the flat fee and the commission floor.
 *
 * Both existed to stop a percentage under-collecting on very cheap items —
 * at ₦500, 10% is ₦50 and the payment fee eats most of it. That is a real
 * problem, but not one this catalogue has: the cheapest listing is ₦2,000,
 * which earns ₦200 and comfortably covers itself.
 *
 * So they were two more fields on every rule form, serving nothing, and a
 * setting nobody can justify is a setting nobody maintains. The ceiling
 * stays: with listings up to ₦1.85m, an uncapped percentage takes ₦185,000
 * from a single sale, which is the problem this catalogue does have.
 *
 * Bring them back with a migration on the day sub-₦1,000 listings appear.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('commission_rules', function (Blueprint $table) {
            $table->dropColumn(['flat_fee_kobo', 'min_commission_kobo']);
        });
    }

    public function down(): void
    {
        Schema::table('commission_rules', function (Blueprint $table) {
            $table->unsignedBigInteger('flat_fee_kobo')->default(0)->after('rate_percent');
            $table->unsignedBigInteger('min_commission_kobo')->nullable()->after('flat_fee_kobo');
        });
    }
};
