<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     */
    protected function schedule(Schedule $schedule): void
    {
        // Purge notes past the 3h TTL and consumed notes past their short
        // post-view download grace period. Runs frequently so consumed notes
        // do not linger; withoutOverlapping prevents concurrent runs.
        $schedule->command('saves:cleanup')
            ->everyFiveMinutes()
            ->withoutOverlapping();

        // Reap abandoned chunk upload directories. The command itself only
        // deletes directories older than 24 hours.
        $schedule->command('saves:clean-chunks')
            ->hourly()
            ->withoutOverlapping();
    }

    /**
     * Register the commands for the application.
     */
    protected function commands(): void
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}
