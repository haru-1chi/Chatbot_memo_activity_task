<?php

namespace App\Console;

use App\Console\Commands\SendMemoMessages;
use App\Console\Commands\SendSummaryMessages;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;
use App\Models\User;
use Carbon\Carbon;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     */
    protected function schedule(Schedule $schedule)
    {
        $users = User::whereNotNull('telegram_chat_id')->get();
        foreach ($users as $user) {
            if ($user->memo_time && $user->summary_time) {
                $memoTime = Carbon::createFromFormat('H:i:s', $user->memo_time);
                $memoHourAndMinute = $memoTime->format('H:i');
                $schedule->command('send:memo-messages')->dailyAt($memoHourAndMinute);

                $summaryTime = Carbon::createFromFormat('H:i:s', $user->summary_time);
                $summaryHourAndMinute = $summaryTime->format('H:i');
                $schedule->command('send:summary-messages')->dailyAt($summaryHourAndMinute);
            } else {
                $schedule->command('send:memo-messages')->dailyAt('12:00');
                $schedule->command('send:summary-messages')->dailyAt('18:00');
            }
        }
    }

    /**
     * Register the commands for the application.
     */
    protected function commands(): void
    {
        $this->load(__DIR__ . '/Commands');

        require base_path('routes/console.php');
    }


}
