<?php

namespace App\Http\Controllers\admin;

use App\Http\Controllers\Controller;
use App\Models\BookingInformation;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Quản lý khiếu nại & yêu cầu hoàn tiền cho Admin.
 *
 * Routes (web, prefix /admin/khieu-nai, middleware auth+checkAdmin):
 *   GET  /          → index()
 *   POST /{id}/duyet  → approve()
 *   POST /{id}/tu-choi → reject()
 */
class DisputeWebController extends Controller
{
    /**
     * Hiển thị danh sách tất cả yêu cầu hoàn tiền.
     * Ưu tiên hiện các đơn đang chờ xử lý (requested) lên đầu.
     */
    public function index()
    {
        $disputes = BookingInformation::with('room')
            ->whereNotNull('refund_status')          // chỉ lấy đơn đã có yêu cầu hoàn tiền
            ->orderByRaw("FIELD(refund_status, 'requested', 'refunded', 'rejected')")
            ->orderByDesc('updated_at')
            ->paginate(20);

        return view('dashboard.disputes.index', compact('disputes'));
    }

    /**
     * Admin duyệt yêu cầu hoàn tiền.
     * Cập nhật refund_status → 'refunded', huỷ booking, giải phóng phòng.
     *
     * @param  int  $id  booking_information.id
     */
    public function approve($id)
    {
        try {
            $booking = BookingInformation::with('room')->findOrFail($id);

            // Kiểm tra đơn phải đang ở trạng thái 'requested'
            if ($booking->refund_status !== 'requested') {
                return back()->with(
                    'toast',
                    ['Đơn này không có yêu cầu hoàn tiền đang chờ xử lý.', 'orange']
                );
            }

            DB::transaction(function () use ($booking) {

                // 1. Duyệt hoàn tiền + huỷ booking
                $booking->update([
                    'refund_status' => 'refunded',
                    'status'        => 'cancelled',
                ]);

                // 2. Giải phóng phòng về trạng thái trống
                if ($booking->room) {
                    $booking->room->update([
                        'status'     => 1,        // 1 = Phòng trống
                        'hold_until' => null,
                    ]);
                }

                // TODO: Gọi VNPAY Refund API tại đây khi tích hợp thật
                // VnpayService::refund($booking->transaction_id, $booking->deposit_amount);
            });

            // ── Gửi thông báo cho Khách hàng SAU khi transaction commit ────────
            $roomName    = $booking->room->name ?? 'N/A';
            $customerUser = User::where('email', $booking->email)->first();
            if ($customerUser) {
                try {
                    Notification::create([
                        'user_id' => $customerUser->id,
                        'title'   => 'Yêu cầu hoàn tiền phòng ' . $roomName
                                   . ' của bạn đã được Admin phê duyệt.',
                        'status'  => 0,
                        'link'    => route('booking.index'),
                    ]);
                } catch (\Throwable $notifEx) {
                    Log::warning('[DisputeWebController@approve] Không thể tạo thông báo cho khách hàng', [
                        'email'   => $booking->email,
                        'message' => $notifEx->getMessage(),
                    ]);
                }
            }

            return back()->with(
                'toast',
                ['Đã duyệt hoàn tiền thành công. Booking đã bị huỷ và phòng đã được giải phóng.', 'green']
            );

        } catch (\Throwable $e) {

            Log::error('[DisputeWebController@approve] Lỗi không mong đợi', [
                'admin_id'   => auth()->id(),
                'booking_id' => $id,
                'message'    => $e->getMessage(),
                'trace'      => $e->getTraceAsString(),
            ]);

            return back()->with(
                'toast',
                ['Đã xảy ra lỗi hệ thống. Vui lòng thử lại.', 'red']
            );
        }
    }

    /**
     * Admin từ chối yêu cầu hoàn tiền.
     * Cập nhật refund_status → 'rejected', giữ nguyên booking và trạng thái phòng.
     *
     * @param  int  $id  booking_information.id
     */
    public function reject($id)
    {
        try {
            $booking = BookingInformation::findOrFail($id);

            // Kiểm tra đơn phải đang ở trạng thái 'requested'
            if ($booking->refund_status !== 'requested') {
                return back()->with(
                    'toast',
                    ['Đơn này không có yêu cầu hoàn tiền đang chờ xử lý.', 'orange']
                );
            }

            $booking->update([
                'refund_status' => 'rejected',
            ]);

            // ── Gửi thông báo cho Khách hàng ─────────────────────────────────────
            // Thực hiện ngoài transaction vì reject() không dùng DB::transaction
            $roomName     = optional($booking->load('room')->room)->name ?? 'N/A';
            $customerUser = User::where('email', $booking->email)->first();
            if ($customerUser) {
                try {
                    Notification::create([
                        'user_id' => $customerUser->id,
                        'title'   => 'Yêu cầu hoàn tiền phòng ' . $roomName
                                   . ' của bạn đã bị từ chối.',
                        'status'  => 0,
                        'link'    => route('booking.index'),
                    ]);
                } catch (\Throwable $notifEx) {
                    Log::warning('[DisputeWebController@reject] Không thể tạo thông báo cho khách hàng', [
                        'email'   => $booking->email,
                        'message' => $notifEx->getMessage(),
                    ]);
                }
            }

            return back()->with(
                'toast',
                ['Đã từ chối yêu cầu hoàn tiền.', 'orange']
            );

        } catch (\Throwable $e) {

            Log::error('[DisputeWebController@reject] Lỗi không mong đợi', [
                'admin_id'   => auth()->id(),
                'booking_id' => $id,
                'message'    => $e->getMessage(),
                'trace'      => $e->getTraceAsString(),
            ]);

            return back()->with(
                'toast',
                ['Đã xảy ra lỗi hệ thống. Vui lòng thử lại.', 'red']
            );
        }
    }
}
