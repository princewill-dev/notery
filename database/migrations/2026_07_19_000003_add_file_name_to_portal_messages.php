<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('portal_messages', function (Blueprint $table) {
            $table->string('file_name')->nullable()->after('type');
        });
    }

    public function down(): void
    {
        Schema::table('portal_messages', function (Blueprint $table) {
            $table->dropColumn('file_name');
        });
    }
};
