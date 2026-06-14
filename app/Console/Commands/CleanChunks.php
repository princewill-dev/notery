<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class CleanChunks extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'saves:clean-chunks';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Delete abandoned chunk upload directories older than 24 hours';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $chunksBase = 'chunks';
        $disk = Storage::disk('local');

        if (!$disk->exists($chunksBase)) {
            $this->info('No chunk directories found.');
            Log::channel('daily')->info('saves:clean-chunks — no chunk directories found');
            return Command::SUCCESS;
        }

        $directories = $disk->directories($chunksBase);
        $deletedDirs = 0;
        $deletedFiles = 0;
        $skipped = 0;
        $cutoff = now()->subHours(24);

        foreach ($directories as $dir) {
            $manifestPath = $dir . '/manifest.json';
            $createdAt = null;

            if ($disk->exists($manifestPath)) {
                try {
                    $manifest = json_decode($disk->get($manifestPath), true);
                    if (isset($manifest['created_at'])) {
                        $createdAt = \Carbon\Carbon::parse($manifest['created_at']);
                    }
                } catch (\Exception $e) {
                    // Manifest is corrupt — fall through to mtime fallback
                }
            }

            if ($createdAt === null) {
                $fullPath = $disk->path($dir);
                if (is_dir($fullPath)) {
                    $createdAt = \Carbon\Carbon::createFromTimestamp(filemtime($fullPath));
                }
            }

            if ($createdAt === null || $createdAt->gte($cutoff)) {
                $skipped++;
                continue;
            }

            // Count files before deleting
            $files = $disk->files($dir);
            $fileCount = count($files);
            $deletedFiles += $fileCount;

            $disk->deleteDirectory($dir);
            $deletedDirs++;
            $this->line("Deleted: {$dir} ({$fileCount} files)");
        }

        $message = "saves:clean-chunks complete — {$deletedDirs} directories ({$deletedFiles} files) deleted, {$skipped} skipped";
        $this->info($message);
        Log::channel('daily')->info($message);

        return Command::SUCCESS;
    }
}
