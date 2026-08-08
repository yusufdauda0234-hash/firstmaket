<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a courier is, beyond a user row with a role.
 *
 * A dispatcher choosing who to send to Gwarinpa needs to know what the
 * courier drives (a motorcycle cannot take a fridge) and where they normally
 * work. A plate number is what goes in the incident report when a parcel
 * does not arrive. None of that belongs on `users`, which every customer
 * shares.
 *
 * Kept as a profile rather than columns on the assignment because it is
 * about the person, not the trip: it survives between deliveries and is what
 * the dispatch screen filters on.
 *
 * `carrier` is nullable and unused today — every courier is FirstMaket's own
 * staff. It exists so that adding a third-party carrier (GIG, Kwik) later,
 * or lifting this whole side out into a standalone courier product, does not
 * need the assignment tables rewritten.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('courier_profiles', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();

            // motorcycle | car | van | truck | on_foot
            $table->string('vehicle_type', 20)->default('motorcycle');
            $table->string('vehicle_plate', 20)->nullable();

            // Where this courier normally works. A dispatcher filters on it;
            // it is not a hard restriction, because a rider covering for
            // somebody else should not be blocked by their own profile.
            $table->string('base_state', 60)->nullable();
            $table->string('base_lga', 80)->nullable();

            // How many live shipments this courier should be holding at once.
            // Zero means no ceiling. Advisory: the dispatch screen warns, it
            // does not refuse, because a real day overrides a default.
            $table->unsignedSmallInteger('max_open_shipments')->default(0);

            // Reserved for a third-party carrier. Null = FirstMaket's own.
            $table->string('carrier', 40)->nullable();

            $table->boolean('is_available')->default(true);
            $table->text('note')->nullable();
            $table->timestamps();

            $table->index(['is_available', 'base_state']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('courier_profiles');
    }
};
