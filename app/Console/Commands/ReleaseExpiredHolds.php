<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Room;
use App\Models\BookingInformation;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class ReleaseExpiredHolds extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:release-expired-holds';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Giải phóng các phòng hết hạn giữ chỗ và hủy các booking tương ứng đang ở trạng thái pending';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Bắt đầu giải phóng các phòng hết hạn giữ chỗ...');
        
        $now = now();
        $releasedCount = 0;

        try {
            Room::where('status', 2)
                ->where('hold_until', '<', $now)
                ->chunkById(100, function ($rooms) use (&$releasedCount) {
                    foreach ($rooms as $room) {
                        // Cập nhật trạng thái phòng thành trống (1) và xóa hold_until
                        $room->update([
                            'status' => 1,
                            'hold_until' => null,
                        ]);

                        // Tìm các Booking liên quan đang ở trạng thái pending và chuyển sang cancelled
                        $updatedBookings = $room->getBooking()
                            ->where('status', 'pending')
                            ->update([
                                'status' => 'cancelled'
                            ]);

                        $releasedCount++;

                        $this->line("Giải phóng phòng ID {$room->id} thành công. Số lượng booking đã hủy: {$updatedBookings}");
                    }
                });

            $this->info("Đã hoàn thành! Đã giải phóng {$releasedCount} phòng.");
        } catch (\Exception $e) {
            $this->error("Đã xảy ra lỗi khi giải phóng phòng: " . $e->getMessage());
            Log::error("[ReleaseExpiredHolds Command] Error: " . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);
        }
    }
}
