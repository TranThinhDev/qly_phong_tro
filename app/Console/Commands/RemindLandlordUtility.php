<?php

namespace App\Console\Commands;

use App\Models\Contract;
use App\Models\Room;
use App\Models\User;
use App\Models\UtilityReading;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Carbon\Carbon;
use App\Mail\UtilityReminderMail;

class RemindLandlordUtility extends Command
{
    /**
     * Chạy vào ngày 28 hàng tháng
     */
    protected $signature = 'billing:remind-utility {--month= : Tháng kỳ tới} {--year= : Năm kỳ tới}';
    protected $description = 'Nhắc nhở chủ trọ cập nhật chỉ số điện nước cho tháng sắp tới';

    public function handle()
    {
        // Mặc định nhắc cho tháng SAU (vì chạy ngày 28)
        $targetDate = now()->addMonth();
        
        $month = (int) ($this->option('month') ?: $targetDate->month);
        $year  = (int) ($this->option('year')  ?: $targetDate->year);

        $this->info("Bắt đầu gửi nhắc nhở cập nhật chỉ số điện/nước cho kỳ {$month}/{$year}...");

        // 1. Tìm tất cả chủ trọ có hợp đồng đang active
        $landlordIds = Contract::where('status', 'active')
            ->where(function ($q) use ($year, $month) {
                // Đảm bảo hợp đồng còn hiệu lực trong tháng tới
                $targetDateString = Carbon::create($year, $month, 1)->toDateString();
                $q->whereNull('end_date')
                  ->orWhere('end_date', '>=', $targetDateString);
            })
            ->pluck('landlord_id')
            ->unique();

        $remindedCount = 0;

        // 2. Với mỗi chủ trọ, kiểm tra xem họ còn phòng nào chưa nhập không
        foreach ($landlordIds as $landlordId) {
            $landlord = User::find($landlordId);
            if (!$landlord) continue;

            // Tìm các phòng thuộc chủ trọ đang có hợp đồng active
            $activeRoomIds = Contract::where('landlord_id', $landlordId)
                ->where('status', 'active')
                ->pluck('room_id')
                ->unique();

            // Đếm số phòng đã nhập chỉ số cho tháng tới
            $readingsCount = UtilityReading::whereIn('room_id', $activeRoomIds)
                ->where('month', $month)
                ->where('year', $year)
                ->count();

            $totalRooms = $activeRoomIds->count();
            $missingRoomsCount = $totalRooms - $readingsCount;

            // Nếu còn phòng chưa nhập, gửi email nhắc nhở
            if ($missingRoomsCount > 0) {
                try {
                    Mail::to($landlord->email)->send(
                        new UtilityReminderMail($landlord, $missingRoomsCount, $month, $year)
                    );
                    $remindedCount++;
                } catch (\Exception $e) {
                    Log::error("[RemindLandlordUtility] Lỗi gửi mail cho landlord #{$landlord->id}: " . $e->getMessage());
                }
            }
        }

        $this->info("Đã gửi email nhắc nhở tới {$remindedCount} chủ trọ.");
        Log::info("[RemindLandlordUtility] Hoàn thành nhắc nhở kỳ {$month}/{$year}. Đã gửi: {$remindedCount}");
        
        return Command::SUCCESS;
    }
}
