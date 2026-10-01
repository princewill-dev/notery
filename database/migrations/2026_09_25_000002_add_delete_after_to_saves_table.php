<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * When a note reaches its view limit it is no longer retrievable, but its
     * attachment rows/files must survive briefly so the viewer can actually
     * download them from the response they just received. delete_after marks
     * when the cleanup command may purge the note and its files.
     */
    public function up(): void
    {
        Schema::table('saves', function (Blueprint $table) {
            $table->timestamp('delete_after')->nullable()->after('views_count');
            $table->index('delete_after');
        });
    }

    public function down(): void
    {
        Schema::table('saves', function (Blueprint $table) {
            $table->dropIndex(['delete_after']);
            $table->dropColumn('delete_after');
        });
    }
};
