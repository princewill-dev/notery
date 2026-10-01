<?php

namespace App\Console\Commands;

use App\Models\Save;
use App\Http\Controllers\PortalController;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class CleanupOldSaves extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'saves:cleanup';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Delete all saved files older than 3 hours from storage';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $threeHoursAgo = now()->subHours(3);

        // Delete notes older than 3 hours, plus consumed notes whose short
        // post-view download grace period has elapsed.
        $oldSaves = Save::where(function ($query) use ($threeHoursAgo) {
                $query->where('created_at', '<', $threeHoursAgo)
                    ->orWhere(function ($grace) {
                        $grace->whereNotNull('delete_after')
                            ->where('delete_after', '<=', now());
                    });
            })
            ->with('images')
            ->get();
        
        $deletedCount = 0;
        $deletedFilesCount = 0;
        
        foreach ($oldSaves as $save) {
            // Delete all associated files from storage
            foreach ($save->images as $image) {
                if (!empty($image->path) && Storage::exists($image->path)) {
                    Storage::delete($image->path);
                    $deletedFilesCount++;
                }
                $image->delete();
            }
            
            // Delete the save record
            $save->delete();
            $deletedCount++;
        }

        // Clean up expired and closed portal sessions
        $deletedPortals = PortalController::deleteExpiredSessions();
        
        $this->info("Cleanup complete: Deleted {$deletedCount} saves, {$deletedFilesCount} files, and {$deletedPortals} portal sessions.");
        
        return Command::SUCCESS;
    }
}
