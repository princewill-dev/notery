<?php

namespace App\Console\Commands;

use App\Jobs\DeleteSaveJob;
use App\Models\Save;
use Illuminate\Console\Command;

class CleanupOldSaves extends Command
{
    protected $signature = 'saves:cleanup {--hours=24 : Delete attachments from saves older than this many hours}';
    protected $description = 'Clean up attachments from old saves';

    public function handle()
    {
        $hours = (int) $this->option('hours');
        $threshold = now()->subHours($hours);
        
        // Find saves with attachments that are older than the threshold
        $saves = Save::whereHas('images')
            ->where('created_at', '<', $threshold)
            ->get();

        if ($saves->isEmpty()) {
            $this->info('No old saves with attachments found.');
            return 0;
        }

        $this->info("Found {$saves->count()} saves with attachments older than {$hours} hours.");
        
        foreach ($saves as $save) {
            DeleteSaveJob::dispatch($save->id);
            $this->info("Queued cleanup for save ID: {$save->id}");
        }

        $this->info('Cleanup jobs queued successfully.');
        return 0;
    }
}
