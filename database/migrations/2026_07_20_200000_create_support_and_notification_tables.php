<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 7 Support and Notifications (docs/firstmarket-Database_Schema.md
 * section 10): Laravel's notifications table for the in-app inbox, per-user
 * per-category channel preferences, a delivery log for failure monitoring,
 * support tickets with message threads, hotline call logs with IVR reasons,
 * and dynamic FAQ entries.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Laravel database-channel notifications — the customer inbox/feed.
        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });

        Schema::create('notification_preferences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('category', 30);
            $table->boolean('email_enabled')->default(true);
            $table->boolean('sms_enabled')->default(false);
            $table->boolean('browser_enabled')->default(true);
            $table->timestamps();

            $table->unique(['user_id', 'category']);
        });

        // One row per attempted send, for delivery-failure monitoring.
        Schema::create('notification_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->uuid('notification_id')->nullable();
            $table->string('channel', 20); // email | sms | browser
            $table->string('provider', 60)->nullable();
            $table->string('status', 20); // sent | failed
            $table->string('provider_reference')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['user_id', 'channel']);
            $table->index('status');
        });

        Schema::create('support_tickets', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('customer_id')->constrained('users');
            $table->foreignId('assigned_to')->nullable()->constrained('users');
            $table->string('channel', 20); // faq | whatsapp | hotline | chat | complaint
            $table->string('subject');
            $table->string('status', 20)->default('open');
            $table->string('priority', 10)->default('normal');
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'assigned_to']);
            $table->index('customer_id');
        });

        Schema::create('support_ticket_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('support_ticket_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sender_id')->constrained('users');
            $table->text('message');
            $table->string('channel', 20)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index('support_ticket_id');
        });

        Schema::create('hotline_call_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained('users');
            $table->foreignId('support_ticket_id')->nullable()->constrained();
            $table->string('phone', 30);
            $table->string('reason', 30); // IVR reason category
            $table->string('ivr_selection', 10)->nullable();
            $table->string('call_reference')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->timestamps();

            $table->index('customer_id');
        });

        Schema::create('faqs', function (Blueprint $table) {
            $table->id();
            $table->string('category', 60);
            $table->string('question');
            $table->text('answer');
            $table->string('status', 20)->default('published');
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['status', 'category', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('faqs');
        Schema::dropIfExists('hotline_call_logs');
        Schema::dropIfExists('support_ticket_messages');
        Schema::dropIfExists('support_tickets');
        Schema::dropIfExists('notification_deliveries');
        Schema::dropIfExists('notification_preferences');
        Schema::dropIfExists('notifications');
    }
};
