<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('countries', function (Blueprint $table) {
            $table->string('capital')->nullable()->after('code');
            $table->string('region')->nullable()->after('capital');
            $table->string('flag_emoji')->nullable()->after('region');
        });
    }

    public function down(): void
    {
        Schema::table('countries', function (Blueprint $table) {
            $table->dropColumn(['capital', 'region', 'flag_emoji']);
        });
    }
};
