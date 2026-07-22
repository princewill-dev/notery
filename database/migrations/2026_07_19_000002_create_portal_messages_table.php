<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('portal_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('portal_session_id')->constrained('portal_sessions')->cascadeOnDelete();
            $table->string('type');
            $table->text('content')->nullable();
            $table->string('image_path')->nullable();
            $table->string('image_mime')->nullable();
            $table->bigInteger('image_size')->nullable();
            $table->string('peer_id');
            $table->timestamp('created_at')->nullable();
            $table->index(['portal_session_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('portal_messages');
    }
};
