<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            // Encrypted at rest via the model's 'encrypted' casts
            // (docs/firstmarket_Security_Compliance.md), so text not string.
            $table->text('bvn')->nullable();
            $table->text('nin')->nullable();
            $table->string('identity_status')->default('unverified');
            $table->string('default_state')->nullable();
            $table->string('default_lga')->nullable();
            $table->timestamps();

            $table->index('identity_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_profiles');
    }
};
