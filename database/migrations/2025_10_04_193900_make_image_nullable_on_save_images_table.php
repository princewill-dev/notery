<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // PostgreSQL: make the image column nullable to support disk-based storage
        DB::statement('ALTER TABLE save_images ALTER COLUMN image DROP NOT NULL');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Revert: set NOT NULL again (may fail if nulls exist). Use with caution.
        DB::statement('ALTER TABLE save_images ALTER COLUMN image SET NOT NULL');
    }
};
