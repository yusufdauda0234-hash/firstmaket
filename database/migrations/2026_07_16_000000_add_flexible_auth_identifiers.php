<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 2 Addendum: registration/login with email OR phone, OTP through the
 * matching channel, and Google/Facebook social login
 * (docs/FirstMaket_Implementation_Plan.md "Sprint 2 Addendum").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Exactly one of email/phone is required at signup (enforced at
            // the application layer); social-only accounts have no password
            // until the user sets one.
            $table->string('email')->nullable()->change();
            $table->string('phone')->nullable()->change();
            $table->string('password')->nullable()->change();
        });

        Schema::table('otp_codes', function (Blueprint $table) {
            $table->renameColumn('phone', 'destination');
        });

        Schema::table('otp_codes', function (Blueprint $table) {
            // 'sms' or 'email' — the channel the code was delivered through.
            $table->string('channel')->default('sms')->after('user_id');
        });

        Schema::create('social_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('provider');
            $table->string('provider_id');
            $table->string('provider_email')->nullable();
            $table->string('avatar_url')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'provider_id']);
            $table->index(['user_id', 'provider']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('social_accounts');

        Schema::table('otp_codes', function (Blueprint $table) {
            $table->dropColumn('channel');
        });

        Schema::table('otp_codes', function (Blueprint $table) {
            $table->renameColumn('destination', 'phone');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('email')->nullable(false)->change();
            $table->string('phone')->nullable(false)->change();
            $table->string('password')->nullable(false)->change();
        });
    }
};
