<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Notification;
use App\Models\Room;
use App\Models\BookingInformation;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
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

        $now           = now();
        $releasedCount = 0;
        $skippedCount  = 0;

        try {
            // Lấy danh sách ứng viên ban đầu (chưa lock).
            // lockForUpdate() sẽ được thực hiện bên trong transaction của từng room
            // để tránh giữ lock toàn bộ batch quá lâu.
            Room::where('status', 2)
                ->where('hold_until', '<', $now)
                ->chunkById(100, function ($rooms) use (&$releasedCount, &$skippedCount, $now) {
                    foreach ($rooms as $room) {
                        $this->processRoom($room->id, $now, $releasedCount, $skippedCount);
                    }
                });

            $this->info("Đã hoàn thành! Giải phóng: {$releasedCount} phòng. Bỏ qua (đã xử lý bởi IPN): {$skippedCount} phòng.");

        } catch (\Exception $e) {
            $this->error('Đã xảy ra lỗi khi giải phóng phòng: ' . $e->getMessage());
            Log::error('[ReleaseExpiredHolds] Lỗi không mong đợi', [
                'message' => $e->getMessage(),
                'trace'   => $e->getTraceAsString(),
            ]);
        }
    }

    /**
     * Xử lý nhả giữ chỗ cho một phòng cụ thể bên trong một DB Transaction riêng.
     *
     * Mỗi phòng chạy trong transaction độc lập để:
     *  - Lỗi ở phòng này không rollback toàn bộ batch.
     *  - lockForUpdate() chỉ giữ row-level lock trong thời gian ngắn nhất có thể.
     *
     * Cơ chế bảo vệ 3 lớp:
     *  1. Re-fetch với lockForUpdate() → serialise với VNPAY IPN handler.
     *  2. Re-check idempotent: status vẫn == 2 và hold_until vẫn đã hết hạn.
     *  3. Chỉ huỷ booking_type = 'deposit' + status = 'pending' để không
     *     ảnh hưởng lịch hẹn (appointment) hoặc đơn đã được IPN xác nhận (paid).
     */
    private function processRoom(int $roomId, Carbon $now, int &$releasedCount, int &$skippedCount): void
    {
        try {
            DB::transaction(function () use ($roomId, $now, &$releasedCount, &$skippedCount) {

                // ── Lớp 1: Re-fetch với row-level lock ───────────────────────
                // Nếu VNPAY IPN đang trong transaction cho room này,
                // câu lệnh này sẽ BLOCK và đợi IPN commit/rollback xong.
                $room = Room::lockForUpdate()->find($roomId);

                if (! $room) {
                    return; // Phòng đã bị xoá
                }

                // ── Lớp 2: Re-check idempotent ───────────────────────────────
                // Sau khi lock được giải phóng bởi IPN, kiểm tra lại:
                //   - status vẫn == 2 (Holding): nếu IPN đã xử lý, status sẽ != 2
                //   - hold_until vẫn đã hết hạn: tránh nhả nhầm phòng vừa được gia hạn
                if ($room->status !== 2 || ! $room->hold_until || $room->hold_until->gt($now)) {
                    $skippedCount++;
                    $this->line("  [SKIP] Phòng ID {$roomId}: đã được xử lý bởi IPN hoặc chưa hết hạn.");
                    return;
                }

                // ── Nhả phòng về trạng thái trống ────────────────────────────
                $room->update([
                    'status'     => 1,    // 1 = Available
                    'hold_until' => null,
                ]);

                // ── Lớp 3: Huỷ booking với filter chặt chẽ ───────────────────
                // Chỉ huỷ booking loại 'deposit' ở trạng thái 'pending'.
                //   - Không huỷ 'appointment'  → lịch hẹn không liên quan đến hold.
                //   - Không huỷ 'paid'          → IPN đã thanh toán thành công.
                //   - Không huỷ 'cancelled'     → đã huỷ từ trước rồi.
                $cancelledCount = BookingInformation::where('rooms_id', $room->id)
                    ->where('booking_type', 'deposit')
                    ->where('status', 'pending')
                    ->lockForUpdate()   // Block nếu IPN đang update booking này
                    ->get()
                    ->each(function ($booking) use ($room) {
                        // Re-check trạng thái booking sau khi có lock
                        if ($booking->status === 'pending') {
                            $booking->update(['status' => 'cancelled']);

                            // ── Thông báo cho Khách hàng ─────────────────────────
                            // Wrap trong try-catch: lỗi thông báo không được gây
                            // rollback transaction đang chạy.
                            $customerUser = User::where('email', $booking->email)->first();
                            if ($customerUser) {
                                try {
                                    Notification::create([
                                        'user_id' => $customerUser->id,
                                        'title'   => 'Thời gian giữ chỗ phòng ' . ($room->name ?? 'N/A')
                                                   . ' đã hết hạn do chưa thanh toán.',
                                        'status'  => 0,  // 0 = chưa đọc
                                        'link'    => null,
                                    ]);
                                } catch (\Throwable $notifEx) {
                                    Log::warning('[ReleaseExpiredHolds] Không thể tạo thông báo cho khách hàng', [
                                        'booking_id' => $booking->id,
                                        'email'      => $booking->email,
                                        'message'    => $notifEx->getMessage(),
                                    ]);
                                }
                            }
                        }
                    })
                    ->count();

                $releasedCount++;
                $this->line("  [OK]   Phòng ID {$roomId} đã giải phóng. Booking deposit đã huỷ: {$cancelledCount}.");
            });

        } catch (\Exception $e) {
            // Lỗi từng room được log riêng và KHÔNG ném lại,
            // đảm bảo các phòng còn lại trong batch vẫn được xử lý.
            $this->warn("  [ERR]  Phòng ID {$roomId}: " . $e->getMessage());
            Log::error('[ReleaseExpiredHolds] Lỗi khi xử lý phòng', [
                'room_id' => $roomId,
                'message' => $e->getMessage(),
                'trace'   => $e->getTraceAsString(),
            ]);
        }
    }
}
