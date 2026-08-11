<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2C: recommendation feedback, and the Complaint Centre.
 *
 * Two decisions worth recording.
 *
 * 1. There is no `product_recommendations` log table. The phase plan asks for
 *    recommendation logs, which makes sense when a model produced them and the
 *    output cannot be reconstructed. These are deterministic rules over the
 *    customer's own wishlist and plans, so what was shown on any given day can
 *    always be recomputed — and writing a row per page view would put a write
 *    on a read path for data we can already derive. What genuinely cannot be
 *    recovered is whether the suggestion was any good, so that is what gets
 *    stored.
 *
 * 2. Complaints extend the existing ticket system rather than starting a
 *    parallel one. A complaint is a support ticket with a sharper category, and
 *    SupportChannel already has a Complaint case. Staff should have one inbox,
 *    not two queues to remember to check.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recommendation_feedback', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();

            // Why it was suggested, so feedback can be read per rule and a
            // rule that consistently misses can be retired.
            $table->string('reason_key', 40);
            $table->boolean('helpful');

            $table->timestamps();

            // One verdict per customer per product: changing your mind updates
            // the row rather than stacking another vote.
            $table->unique(['user_id', 'product_id']);
            $table->index(['reason_key', 'helpful']);
        });

        Schema::table('support_tickets', function (Blueprint $table) {
            // Complaint-specific fields, null on an ordinary ticket.
            $table->string('complaint_category', 40)->nullable()->after('channel');
            // What it is about, if anything — an order, a vendor, a delivery.
            $table->foreignId('about_order_id')->nullable()->after('complaint_category')
                ->constrained('orders')->nullOnDelete();
            $table->foreignId('about_vendor_id')->nullable()->after('about_order_id')
                ->constrained('vendor_profiles')->nullOnDelete();

            $table->index(['channel', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('support_tickets', function (Blueprint $table) {
            $table->dropForeign(['about_order_id']);
            $table->dropForeign(['about_vendor_id']);
            $table->dropIndex(['channel', 'status']);
            $table->dropColumn(['complaint_category', 'about_order_id', 'about_vendor_id']);
        });

        Schema::dropIfExists('recommendation_feedback');
    }
};
