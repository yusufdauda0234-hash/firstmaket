<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Admin-composed messages sent out to the userbase.
 *
 * Kept as rows rather than fire-and-forget sends because a broadcast is one
 * of the few admin actions that reaches every customer at once: staff need to
 * see what was already sent (and to whom) before sending again, and support
 * needs to be able to answer "what was that message I got".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('announcements', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->string('title');
            $table->text('body');

            // all | role | user — how the recipient set was chosen. Stored
            // alongside the resolved count so a later role change does not
            // rewrite the history of who this actually went to.
            $table->string('audience', 20);
            $table->foreignId('role_id')->nullable()->constrained('roles')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();

            // Which channels the sender asked for. The recipient's own
            // preferences narrow this further at send time — an admin can
            // choose fewer channels than someone has enabled, never more.
            $table->json('channels');
            $table->string('category', 30);

            $table->unsignedInteger('recipients_count')->default(0);
            $table->foreignId('sent_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->index(['created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('announcements');
    }
};
