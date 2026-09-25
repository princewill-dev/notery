<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Portal messages were stored in plaintext. Text bodies are now encrypted
     * with Crypt, and file bytes use the streaming EncryptedFile format.
     */
    public function up(): void
    {
        Schema::table('portal_messages', function (Blueprint $table) {
            $table->boolean('is_encrypted')->default(false)->after('content');
        });
    }

    public function down(): void
    {
        Schema::table('portal_messages', function (Blueprint $table) {
            $table->dropColumn('is_encrypted');
        });
    }
};
