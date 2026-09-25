<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Track when each peer was last seen so abandoned/squatting peers can be
     * evicted and their slot released.
     */
    public function up(): void
    {
        Schema::table('portal_sessions', function (Blueprint $table) {
            $table->json('peer_seen_at')->nullable()->after('peer_ids');
        });
    }

    public function down(): void
    {
        Schema::table('portal_sessions', function (Blueprint $table) {
            $table->dropColumn('peer_seen_at');
        });
    }
};
