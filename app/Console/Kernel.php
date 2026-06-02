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
        // $schedule->command('inspire')->hourly();

        // ── Giải phóng phòng hết hạn giữ chỗ (mỗi phút) ──────────────────
        $schedule->command('app:release-expired-holds')
                 ->everyMinute()
                 ->withoutOverlapping(); // Ngăn 2 process chạy song song nếu lần trước chưa xong

        // ── Module Auto-Billing: Sinh hóa đơn hàng tháng ──────────────────
        // Chạy vào ngày 1 hàng tháng lúc 00:00 server time.
        // withoutOverlapping(): tránh chạy 2 lần nếu process tháng trước còn dang dở.
        // appendOutputTo(): ghi log ra file để debug dễ hơn.
        $schedule->command('billing:run-monthly')
                 ->monthlyOn(1, '00:00')
                 ->withoutOverlapping()
                 ->sendOutputTo(storage_path('logs/billing-monthly.log'))
                 ->emailOutputOnFailure(config('mail.from.address'));
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
