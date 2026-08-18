<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('countries', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique()->comment('ISO 3166-1 alpha-2 code, e.g., NG');
            $table->string('name')->comment('Full country name');
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });

        // Seed with Nigeria by default
        \Illuminate\Support\Facades\DB::table('countries')->insert([
            ['code' => 'NG', 'name' => 'Nigeria', 'is_active' => true, 'sort_order' => 0, 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('countries');
    }
};
