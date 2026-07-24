<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('otp_codes', function (Blueprint $table) {
            $table->id();
            // Nullable so pre-registration OTPs can exist before a user row
            // does (docs/FirstMaket-Database_Schema.md).
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('phone');
            $table->string('purpose');
            $table->string('code_hash');
            $table->timestamp('expires_at');
            $table->timestamp('verified_at')->nullable();
            $table->unsignedSmallInteger('attempt_count')->default(0);
            $table->string('request_ip', 45)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['phone', 'purpose', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('otp_codes');
    }
};
