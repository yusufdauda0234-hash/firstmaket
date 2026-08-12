<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the business spends.
 *
 * Named business_expenses rather than expenses so nobody later mistakes it
 * for something a customer or a vendor sees — this is the platform's own
 * outgoings, and it never appears outside the admin portal.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('business_expenses', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->string('reference', 32)->unique();

            $table->string('category', 40);
            $table->string('description');
            $table->string('payee')->nullable();

            $table->unsignedBigInteger('amount_kobo');
            // The date the money was spent, not the date somebody got round
            // to typing it in. A month's total has to mean the month it
            // happened in, or every report is quietly wrong at the edges.
            $table->date('incurred_on');
            $table->string('payment_method', 30)->nullable();
            $table->text('note')->nullable();
            $table->string('receipt_path')->nullable();

            $table->string('status', 20)->default('pending');
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->text('decision_note')->nullable();

            $table->timestamps();

            $table->index(['incurred_on']);
            $table->index(['category', 'incurred_on']);
            $table->index(['status', 'incurred_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_expenses');
    }
};
