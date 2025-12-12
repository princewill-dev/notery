<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('saves', function (Blueprint $table) {
            $table->unsignedInteger('max_views')->nullable()->after('writeup');
            $table->unsignedInteger('views_count')->default(0)->after('max_views');
        });
    }

    public function down(): void
    {
        Schema::table('saves', function (Blueprint $table) {
            $table->dropColumn(['max_views', 'views_count']);
        });
    }
};
