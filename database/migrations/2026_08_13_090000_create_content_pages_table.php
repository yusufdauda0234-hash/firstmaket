<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Admin-editable public pages: terms, privacy policy, data deletion, and
 * anything else the business needs to publish without a deploy.
 *
 * Three of these are not ordinary marketing pages. Google's OAuth consent
 * screen and Meta's app review both fetch a fixed URL and refuse the
 * integration if it 404s, so `terms`, `privacy-policy` and `data-deletion`
 * are flagged `is_system`: their slug cannot be edited and they cannot be
 * deleted, only unpublished. A renamed slug would silently break a live
 * sign-in integration weeks later, with nothing in this application to
 * indicate why.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_pages', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();

            // The URL. Lower case by convention and by the model's mutator —
            // this is one of the fields the uppercase-on-write rule exempts,
            // along with email, password and description.
            $table->string('slug')->unique();
            $table->string('title');

            // Shown under the heading and used as the meta description, which
            // is what Google prints beneath the link in search results.
            $table->string('summary', 300)->nullable();

            /*
             * The body, as an ordered list of {heading, body} sections rather
             * than one HTML blob.
             *
             * A blob needs a rich-text editor and then needs sanitising on
             * the way out, because an admin account that can store arbitrary
             * HTML on a public page is a stored-XSS hole aimed at every
             * visitor. Sections carry no markup at all: they render as text,
             * so there is nothing to sanitise and nothing to get wrong.
             */
            $table->json('sections');

            // Unpublished pages 404 for the public but stay editable, so a
            // page can be drafted over several sittings.
            $table->boolean('is_published')->default(false);

            // Footer placement, so adding a page also puts a link to it on
            // the site without touching the layout.
            $table->boolean('show_in_footer')->default(false);
            $table->unsignedInteger('sort_order')->default(0);

            $table->boolean('is_system')->default(false);

            // "Last updated" on a legal page is load-bearing: it is how a
            // reader knows which version they agreed to. Distinct from
            // updated_at, which moves when somebody fixes a typo.
            $table->timestamp('effective_at')->nullable();

            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index(['is_published', 'show_in_footer', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_pages');
    }
};
