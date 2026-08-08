<?php

use App\Modules\Orders\Models\CategoryCommissionRate;
use App\Modules\Orders\Models\CommissionRule;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One table for every commission rule, whatever it applies to.
 *
 * A flat percentage per category cannot price a catalogue honestly. Two
 * pieces of electrical wire at ₦500 and ₦5,000 sit in the same category and
 * earn ₦50 and ₦500 at the same 10% — but the ₦500 sale costs the same to
 * process, deliver and support as the other, so the small one barely covers
 * itself while the large one may be priced out of the market.
 *
 * So a rule carries three things a bare percentage cannot:
 *
 *  - a SCOPE: everything, a category, a vendor, or a single product
 *  - a PRICE BAND, so the same category can charge differently at £5 and £500
 *  - FLOORS AND CEILINGS, so a percentage never collects less than a sale
 *    costs to handle, nor an indefensible amount on a large one
 *
 * Rules resolve most-specific-first, and within a scope the band containing
 * the unit price wins. Existing per-category rates are carried across so
 * nothing a customer is quoted changes on deploy.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commission_rules', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();

            // global | category | vendor | product
            $table->string('scope_type', 20)->default('global');
            // The category, vendor or product it applies to; null when global.
            $table->unsignedBigInteger('scope_id')->nullable();

            // Inclusive floor, exclusive ceiling. Null ceiling = no upper
            // bound, which is what a single unbanded rule uses.
            $table->unsignedBigInteger('min_price_kobo')->default(0);
            $table->unsignedBigInteger('max_price_kobo')->nullable();

            $table->decimal('rate_percent', 5, 2)->default(0);
            // Charged on top of the percentage — covers the fixed cost of
            // handling a sale at all, which no percentage can express.
            $table->unsignedBigInteger('flat_fee_kobo')->default(0);
            // Floor and ceiling on the resulting commission.
            $table->unsignedBigInteger('min_commission_kobo')->nullable();
            $table->unsignedBigInteger('max_commission_kobo')->nullable();

            $table->boolean('is_active')->default(true);
            $table->string('note', 200)->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['is_active', 'scope_type', 'scope_id']);
        });

        // Carry the existing per-category rates over, so what the business
        // charges today survives the change untouched.
        foreach (CategoryCommissionRate::query()->get() as $rate) {
            if (CategoryCommissionRate::activeFor($rate->category_id)?->id !== $rate->id) {
                continue; // Superseded by a later effective_from.
            }

            CommissionRule::query()->create([
                'scope_type' => 'category',
                'scope_id' => $rate->category_id,
                'rate_percent' => $rate->rate_percent,
                'is_active' => true,
                'note' => 'Carried over from the per-category rates.',
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('commission_rules');
    }
};
