<?php

namespace App\Console\Commands;

use App\Models\Save;
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
        
        // Find all saves older than 3 hours
        $oldSaves = Save::where('created_at', '<', $threeHoursAgo)->with('images')->get();
        
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
        
        $this->info("Cleanup complete: Deleted {$deletedCount} saves and {$deletedFilesCount} files.");
        
        return Command::SUCCESS;
    }
}
