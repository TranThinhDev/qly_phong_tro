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

        // ── Nhắc nhở chủ trọ nhập chỉ số điện nước (ngày 28 hàng tháng) ──
        $schedule->command('billing:remind-utility')
                 ->monthlyOn(28, '08:00')
                 ->withoutOverlapping()
                 ->sendOutputTo(storage_path('logs/billing-remind-utility.log'));

        // ── Xử lý hóa đơn quá hạn (chạy vào 01:00 hàng ngày) ──────────────
        // Chạy hàng ngày để tính từ lúc due_date trôi qua
        // (Thường là due_date là ngày 10, nếu qua ngày 11 lúc 01:00 sẽ bị phạt)
        $schedule->command('billing:process-overdue')
                 ->dailyAt('01:00')
                 ->withoutOverlapping()
                 ->sendOutputTo(storage_path('logs/billing-process-overdue.log'));
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
