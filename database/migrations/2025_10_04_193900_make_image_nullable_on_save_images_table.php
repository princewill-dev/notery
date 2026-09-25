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
        // PostgreSQL: ensure the image column is nullable for disk-based
        // storage. Fresh installs already create it nullable, and SQLite does
        // not support this ALTER form, so only run it on pgsql.
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE save_images ALTER COLUMN image DROP NOT NULL');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            // May fail if nulls exist. Use with caution.
            DB::statement('ALTER TABLE save_images ALTER COLUMN image SET NOT NULL');
        }
    }
};
