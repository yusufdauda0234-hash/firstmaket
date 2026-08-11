<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2E: returns, refunds and disputes.
 *
 * An order here is already a single unit of a single product from a single
 * vendor, so a return is one row against one order — there is no line table to
 * add, which is the one piece of luck in this feature.
 *
 * Two decisions worth knowing about:
 *
 * 1. The policy is snapshotted onto every request. The window, who pays the
 *    return delivery, and whether the item had to be unopened are copied in at
 *    the moment the customer opens the case. Editing the published policy next
 *    month must not silently rewrite the terms of a case already in flight —
 *    the customer agreed to what the page said on the day.
 *
 * 2. `refunds.gateway_reference` is unique. This is the whole idempotency
 *    story for outward money: a retried refund, a double-clicked admin button
 *    or a replayed job cannot pay the same money twice, because the second
 *    insert cannot exist.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('return_requests', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();

            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained('users')->cascadeOnDelete();
            // Denormalised so a vendor's return queue is one indexed lookup
            // rather than a join through orders on every page load.
            $table->foreignId('vendor_id')->constrained('vendor_profiles')->cascadeOnDelete();

            $table->string('reason', 30);
            $table->text('reason_note')->nullable();
            $table->string('status', 20)->default('requested');

            // ── Policy snapshot, taken when the case was opened ──
            $table->unsignedSmallInteger('policy_window_days');
            $table->string('return_delivery_paid_by', 20);
            $table->boolean('required_unopened')->default(false);

            // What may be sent back to the customer if this is upheld. Capped
            // at what the order was actually worth, computed once here rather
            // than recalculated later against prices that may have moved.
            $table->unsignedBigInteger('refundable_kobo');

            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('review_note', 500)->nullable();

            $table->timestamp('received_at')->nullable();
            $table->timestamp('resolved_at')->nullable();

            $table->timestamps();

            $table->index(['customer_id', 'status']);
            $table->index(['vendor_id', 'status']);
            $table->index(['status', 'created_at']);
        });

        // Every state change, with who caused it. A money decision that cannot
        // be reconstructed afterwards is a money decision nobody can defend.
        Schema::create('return_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('return_request_id')->constrained()->cascadeOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('from_status', 20)->nullable();
            $table->string('to_status', 20);
            $table->string('note', 500)->nullable();
            $table->timestamps();

            $table->index(['return_request_id', 'id']);
        });

        // Customer-supplied photos. Stored on the private disk: a photo of
        // someone's living room is personal data, not a public asset.
        Schema::create('return_evidence', function (Blueprint $table) {
            $table->id();
            $table->foreignId('return_request_id')->constrained()->cascadeOnDelete();
            $table->string('disk', 30)->default('private');
            $table->string('path', 500);
            $table->timestamps();
        });

        Schema::create('refunds', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();

            $table->foreignId('return_request_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained('users')->cascadeOnDelete();
            // Who authorised money leaving. Never null in practice — a refund
            // cannot be triggered by a customer or by a scheduler.
            $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();

            $table->unsignedBigInteger('amount_kobo');

            // 'card' reverses the original charge; 'plan_credit' returns value
            // to a Pay Small Small plan, which is the only route allowed when
            // the order came from one — money paid into a plan is never cash.
            $table->string('destination', 20);
            $table->string('status', 20)->default('pending');

            // The idempotency key. Unique, so the same refund cannot be
            // issued twice however many times it is retried.
            $table->string('gateway_reference', 100)->nullable()->unique();
            $table->string('failure_reason', 255)->nullable();
            $table->timestamp('completed_at')->nullable();

            $table->timestamps();

            $table->index(['order_id', 'status']);
        });

        // Categories that can only come back faulty — perishables, underwear,
        // pierced jewellery, made-to-order. A flag beats a hardcoded list:
        // staff add categories without a deploy.
        Schema::table('categories', function (Blueprint $table) {
            $table->boolean('returnable_on_change_of_mind')->default(true)->after('slug');
        });
    }

    public function down(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->dropColumn('returnable_on_change_of_mind');
        });

        Schema::dropIfExists('refunds');
        Schema::dropIfExists('return_evidence');
        Schema::dropIfExists('return_events');
        Schema::dropIfExists('return_requests');
    }
};
