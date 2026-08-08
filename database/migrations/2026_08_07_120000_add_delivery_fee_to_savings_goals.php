<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Delivery on a Pay Small Small plan.
 *
 * Until now a plan charged nothing to deliver while a card checkout charged
 * the full rate — so the same basket cost less if you paid for it over six
 * months, which is backwards, and it contradicted the rule that nothing is
 * free unless an admin has set it so on the delivery-rates page.
 *
 * The fee is locked here at plan creation, alongside the item prices, for
 * the same reason they are: a plan runs for months, and a customer who
 * agreed to a target must not owe more because a rate moved while they were
 * paying it off. It is folded into `target_kobo`, so the whole thing is
 * settled by the last instalment and nothing is owed at the door.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('savings_goals', function (Blueprint $table) {
            $table->unsignedBigInteger('delivery_fee_kobo')->default(0)->after('target_kobo');
        });
    }

    public function down(): void
    {
        Schema::table('savings_goals', function (Blueprint $table) {
            $table->dropColumn('delivery_fee_kobo');
        });
    }
};
