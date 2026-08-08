<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Admin-defined fields for the vendor "add product" form.
 *
 * Every kind of product is described differently — a phone has colour and
 * storage, a sofa has material and dimensions, a course has a video URL — so
 * the form cannot be a fixed list of columns. Staff define the fields per
 * category here and the vendor form renders itself from them, which means a
 * new product type needs no migration and no deploy.
 *
 * Values live in their own table rather than a JSON blob on products so a
 * field can be renamed, retyped or retired without rewriting every row, and
 * so filtering by "colour = red" stays an indexed lookup later.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_attributes', function (Blueprint $table) {
            $table->id();

            // Null means the field applies to every category. Otherwise it
            // applies to this category and everything nested under it.
            $table->foreignId('category_id')
                ->nullable()
                ->constrained('categories')
                ->cascadeOnDelete();

            // Stable machine name, used as the form field name.
            $table->string('key', 60);
            $table->string('label', 120);
            $table->string('type', 20);

            // Choices for select/multiselect, ignored by other types.
            $table->json('options')->nullable();

            // Appended after the input, e.g. "kg", "W", "inches".
            $table->string('unit', 20)->nullable();
            $table->string('help_text', 200)->nullable();
            $table->string('placeholder', 120)->nullable();

            $table->boolean('is_required')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);

            $table->timestamps();

            // One definition of a key per category. Global fields (null
            // category) are checked in the controller, since MySQL treats
            // NULLs as distinct and would allow duplicates here.
            $table->unique(['category_id', 'key']);
            $table->index(['category_id', 'is_active', 'sort_order']);
        });

        Schema::create('product_attribute_values', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('product_attribute_id')->constrained('product_attributes')->cascadeOnDelete();

            // JSON so one column holds text, numbers, booleans and the arrays
            // a multiselect produces, without a column per type.
            $table->json('value');

            $table->timestamps();

            $table->unique(['product_id', 'product_attribute_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_attribute_values');
        Schema::dropIfExists('product_attributes');
    }
};
