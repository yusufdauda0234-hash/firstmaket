<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hero_slides', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->string('eyebrow', 60);
            $table->string('title', 150);
            $table->string('description', 300);
            $table->string('cta_label', 40);
            // Where the button goes. Not a free-text URL: the home page only
            // ever needs a small, known set of destinations, and a typo'd
            // path here would be a broken hero button in production with no
            // validation to catch it.
            $table->string('cta_target', 20)->default('auth_gate');
            // A preset key resolved client-side (Utils/heroThemes.ts), not raw
            // Tailwind classes: Tailwind's build only generates CSS for class
            // names it can see in the source at build time, so a gradient
            // typed into this row would silently render unstyled.
            $table->string('theme', 30)->default('brand');
            $table->string('emoji', 8)->default('🛍️');
            // 'from_price' and 'campaign_discount' compute a real figure from
            // live catalog/campaign data; only 'static' reads offer_value.
            $table->string('offer_type', 20)->default('static');
            $table->string('offer_label', 40);
            $table->string('offer_value', 40)->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['is_active', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hero_slides');
    }
};
