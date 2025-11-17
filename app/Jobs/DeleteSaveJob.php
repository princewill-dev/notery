<?php

namespace App\Jobs;

use App\Models\Save;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\File;

class DeleteSaveJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $saveId;

    public function __construct(int $saveId)
    {
        $this->saveId = $saveId;
    }

    public function handle(): void
    {
        // Prepare daily job log file: storage/logs/jobs/laravel_YYYY-MM-DD-YYYY-MM-DD.log
        $today = now()->toDateString();
        $logDir = storage_path('logs/jobs');
        if (!File::exists($logDir)) {
            File::makeDirectory($logDir, 0755, true);
        }
        $logPath = $logDir . DIRECTORY_SEPARATOR . "laravel_{$today}-{$today}.log";
        $log = function (string $message) use ($logPath) {
            $ts = now()->format('Y-m-d H:i:s');
            @file_put_contents($logPath, "[{$ts}] {$message}" . PHP_EOL, FILE_APPEND);
        };

        $log("START DeleteSaveJob save_id={$this->saveId}");

        $save = Save::with('images')->find($this->saveId);
        if (!$save) {
            $log("SKIP save_id={$this->saveId} target already removed");
            return;
        }

        $deleted = 0;
        $errors = 0;
        foreach ($save->images as $img) {
            if (!empty($img->path) && Storage::exists($img->path)) {
                try {
                    if (Storage::delete($img->path)) {
                        $deleted++;
                        $log("FILE_DELETED save_id={$this->saveId} path={$img->path}");
                    } else {
                        $errors++;
                        $log("FILE_DELETE_FAILED save_id={$this->saveId} path={$img->path}");
                    }
                } catch (\Throwable $e) {
                    $errors++;
                    $log("FILE_DELETE_EXCEPTION save_id={$this->saveId} path={$img->path} error=" . $e->getMessage());
                }
            } else {
                $log("FILE_MISSING save_id={$this->saveId} path=" . ($img->path ?? '')); 
            }
            $img->delete();
        }

        $save->delete();
        $log("SAVE_RECORD_DELETED save_id={$this->saveId}");

        $log("DONE DeleteSaveJob save_id={$this->saveId} attachments_deleted={$deleted} errors={$errors}");
    }
}
