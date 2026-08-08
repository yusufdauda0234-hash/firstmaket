<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Shipments: the physical parcel, as distinct from the order.
 *
 * Orders are one row per unit, because commission, promo share and vendor
 * payout are all per unit and must stay that way. But three kettles bought
 * together from one vendor are one box, one pickup and one doorstep — and
 * assigning three "deliveries" for it put three identical stops on a
 * courier's list and counted the day's work wrong.
 *
 * So a shipment is one row per (checkout session, vendor): one pickup, one
 * drop, however many units. Orders keep their own status — the shipment
 * moves them all together — and the shipment carries everything that belongs
 * to the trip rather than to the money: who is carrying it, how many times
 * they have tried, and the code the customer reads out on arrival.
 *
 * The delivery address is snapshotted here rather than joined from the
 * orders: a courier's list must not need a five-table join, and the address
 * a parcel was dispatched to should not silently change if the customer
 * edits their saved one afterwards.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shipments', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();

            // Null only for orders raised before checkout sessions existed.
            $table->foreignId('checkout_session_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('vendor_id')->constrained('vendor_profiles');
            $table->foreignId('customer_id')->constrained('users');

            // Mirrors the order chain, plus two outcomes an order does not
            // have: a delivery can fail without the order being cancelled.
            // pending | ready_for_pickup | packed | shipped | out_for_delivery
            // | delivered | failed | cancelled
            //
            // Starts pending — the record exists as soon as the money lands,
            // but there is nothing to collect until the vendor has packed it.
            $table->string('status', 30)->default('pending');

            $table->text('delivery_address');
            $table->string('state', 60);
            $table->string('lga', 80);
            $table->string('recipient_name', 120)->nullable();
            $table->string('recipient_phone', 20)->nullable();
            $table->string('landmark', 160)->nullable();

            // Read out by the customer at the door. Four digits because it is
            // spoken aloud, often over a bad line — not a security token, a
            // confirmation that the right person took the parcel.
            $table->string('delivery_code', 8)->nullable();
            $table->unsignedTinyInteger('attempt_count')->default(0);

            $table->timestamp('dispatched_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->foreignId('delivered_by')->nullable()->constrained('users')->nullOnDelete();
            // Set when an admin closed the delivery without the code, which
            // has to be possible (a customer who lost it still needs their
            // parcel) and has to be visible.
            $table->foreignId('proof_overridden_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index(['status', 'state']);
            $table->index(['vendor_id', 'status']);
        });

        Schema::create('delivery_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shipment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('courier_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedTinyInteger('attempt_no');
            // delivered | no_one_home | wrong_address | refused | unreachable
            // | other
            $table->string('outcome', 30);
            $table->string('note', 300)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['shipment_id', 'attempt_no']);
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('shipment_id')->nullable()->after('checkout_session_id')
                ->constrained()->nullOnDelete();
        });

        Schema::table('delivery_assignments', function (Blueprint $table) {
            $table->foreignId('shipment_id')->nullable()->after('order_id')
                ->constrained()->cascadeOnDelete();

            $table->index(['shipment_id', 'status']);
        });

        // An assignment now covers a shipment. order_id stays, nullable, so
        // rows written before this migration keep saying what they meant.
        DB::statement('ALTER TABLE delivery_assignments MODIFY order_id BIGINT UNSIGNED NULL');

        $this->backfillShipments();
    }

    /**
     * Give every existing order a shipment, grouped exactly as new ones will
     * be, so the dispatch screen and the courier list are not blind to orders
     * placed before today.
     */
    private function backfillShipments(): void
    {
        $groups = DB::table('orders')
            ->select('checkout_session_id', 'vendor_id', 'customer_id')
            ->whereNull('shipment_id')
            ->groupBy('checkout_session_id', 'vendor_id', 'customer_id')
            ->get();

        foreach ($groups as $group) {
            $sample = DB::table('orders')
                ->where('vendor_id', $group->vendor_id)
                ->where('customer_id', $group->customer_id)
                ->when(
                    $group->checkout_session_id === null,
                    fn ($query) => $query->whereNull('checkout_session_id'),
                    fn ($query) => $query->where('checkout_session_id', $group->checkout_session_id),
                )
                ->whereNull('shipment_id')
                ->first();

            if ($sample === null) {
                continue;
            }

            $shipmentId = DB::table('shipments')->insertGetId([
                'uuid' => (string) Str::uuid(),
                'checkout_session_id' => $group->checkout_session_id,
                'vendor_id' => $group->vendor_id,
                'customer_id' => $group->customer_id,
                // Whatever the sample order is at. An order the vendor has not
                // finished with maps to pending rather than into the dispatch
                // queue — backfilling a parcel nobody has packed straight onto
                // a courier's list would be worse than not backfilling at all.
                'status' => in_array($sample->status, [
                    'ready_for_pickup', 'packed', 'shipped', 'out_for_delivery', 'delivered', 'cancelled',
                ], true) ? $sample->status : 'pending',
                'delivery_address' => $sample->delivery_address,
                'state' => $sample->state,
                'lga' => $sample->lga,
                'recipient_name' => $sample->recipient_name,
                'recipient_phone' => $sample->recipient_phone,
                'landmark' => $sample->landmark,
                'delivered_at' => $sample->delivered_at,
                'created_at' => $sample->created_at,
                'updated_at' => now(),
            ]);

            DB::table('orders')
                ->where('vendor_id', $group->vendor_id)
                ->where('customer_id', $group->customer_id)
                ->when(
                    $group->checkout_session_id === null,
                    fn ($query) => $query->whereNull('checkout_session_id'),
                    fn ($query) => $query->where('checkout_session_id', $group->checkout_session_id),
                )
                ->whereNull('shipment_id')
                ->update(['shipment_id' => $shipmentId]);

            DB::table('delivery_assignments')
                ->whereIn('order_id', DB::table('orders')->where('shipment_id', $shipmentId)->pluck('id'))
                ->whereNull('shipment_id')
                ->update(['shipment_id' => $shipmentId]);
        }
    }

    public function down(): void
    {
        Schema::table('delivery_assignments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('shipment_id');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('shipment_id');
        });

        Schema::dropIfExists('delivery_attempts');
        Schema::dropIfExists('shipments');
    }
};
