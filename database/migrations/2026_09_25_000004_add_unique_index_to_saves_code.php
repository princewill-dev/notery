<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

return new class extends Migration
{
    public function up(): void
    {
        // A duplicate code means one note is unreachable (lookups use ->first()).
        // The plaintext code is not recoverable from the stored hash, so the
        // duplicate cannot be re-coded: keep the oldest row in each group and
        // remove the unreachable duplicates plus their attachment files.
        $duplicateCodes = DB::table('saves')
            ->select('code')
            ->groupBy('code')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('code');

        foreach ($duplicateCodes as $code) {
            $ids = DB::table('saves')->where('code', $code)->orderBy('id')->pluck('id');

            // Drop the first id (kept); delete the rest.
            foreach ($ids->slice(1) as $id) {
                $paths = DB::table('save_images')->where('save_id', $id)->pluck('path');

                foreach ($paths as $path) {
                    if (!empty($path) && Storage::exists($path)) {
                        Storage::delete($path);
                    }
                }

                DB::table('save_images')->where('save_id', $id)->delete();
                DB::table('saves')->where('id', $id)->delete();
            }
        }

        Schema::table('saves', function (Blueprint $table) {
            $table->unique('code');
        });
    }

    public function down(): void
    {
        Schema::table('saves', function (Blueprint $table) {
            $table->dropUnique(['code']);
        });
    }
};
