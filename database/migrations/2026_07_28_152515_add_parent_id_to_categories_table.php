<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Categories become a tree: "Electronics" > "Phones & Tablets" > "Android
 * Phones". A flat list cannot express how a marketplace is actually browsed,
 * and the per-category product fields added alongside this need somewhere to
 * inherit from — a field defined on Electronics should apply to everything
 * beneath it.
 *
 * Depth is not enforced in the schema; the admin screen caps it, so that
 * limit can change without a migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            if (! Schema::hasColumn('categories', 'parent_id')) {
                $table->foreignId('parent_id')
                    ->nullable()
                    ->after('id')
                    ->constrained('categories')
                    // Deleting a parent is refused by the controller; restrict
                    // here too so no other path can orphan a subtree.
                    ->restrictOnDelete();
            }

            if (! Schema::hasColumn('categories', 'description')) {
                $table->string('description', 300)->nullable()->after('slug');
            }
        });
    }

    public function down(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            if (Schema::hasColumn('categories', 'parent_id')) {
                $table->dropForeign(['parent_id']);
                $table->dropColumn('parent_id');
            }

            if (Schema::hasColumn('categories', 'description')) {
                $table->dropColumn('description');
            }
        });
    }
};
