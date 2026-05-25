<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BookingInformation;
use App\Models\Room;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class DisputeController extends Controller
{
    /**
     * Khách hàng yêu cầu hoàn tiền cho một booking đã thanh toán.
     *
     * POST /api/dispute/request-refund
     * Body: { booking_id, reason }
     * Middleware: auth:api
     */
    public function requestRefund(Request $request): JsonResponse
    {
        // ── 1. Validate đầu vào ──────────────────────────────────────────────
        $validated = $request->validate([
            'booking_id' => ['required', 'integer', 'exists:booking_information,id'],
            'reason'     => ['required', 'string', 'max:500'],
        ], [
            'booking_id.required' => 'Vui lòng cung cấp mã booking.',
            'booking_id.exists'   => 'Booking không tồn tại.',
            'reason.required'     => 'Vui lòng nhập lý do yêu cầu hoàn tiền.',
            'reason.max'          => 'Lý do không được vượt quá 500 ký tự.',
        ]);

        try {
            // ── 2. Lấy booking & kiểm tra quyền sở hữu ─────────────────────
            $booking = BookingInformation::findOrFail($validated['booking_id']);

            // Booking phải thuộc về người dùng hiện tại (khớp theo email)
            if ($booking->email !== auth()->user()->email) {
                return response()->json([
                    'success' => false,
                    'message' => 'Bạn không có quyền thực hiện thao tác này.',
                    'data'    => null,
                ], 403);
            }

            // ── 3. Kiểm tra trạng thái booking ──────────────────────────────
            if ($booking->status !== 'paid') {
                return response()->json([
                    'success' => false,
                    'message' => 'Chỉ có thể yêu cầu hoàn tiền cho booking đã thanh toán.',
                    'data'    => ['current_status' => $booking->status],
                ], 422);
            }

            // ── 4. Kiểm tra thời hạn 48 giờ ─────────────────────────────────
            if ($booking->created_at->lt(now()->subHours(48))) {
                return response()->json([
                    'success' => false,
                    'message' => 'Đã quá thời hạn 48 giờ để yêu cầu hoàn tiền.',
                    'data'    => [
                        'booked_at'  => $booking->created_at->toIso8601String(),
                        'expired_at' => $booking->created_at->addHours(48)->toIso8601String(),
                    ],
                ], 422);
            }

            // ── 5. Cập nhật trạng thái hoàn tiền ────────────────────────────
            $booking->update([
                'refund_status' => 'requested',
                'refund_reason' => $validated['reason'],
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Yêu cầu hoàn tiền đã được ghi nhận. Admin sẽ xem xét và phản hồi sớm nhất.',
                'data'    => [
                    'booking_code'  => $booking->booking_code,
                    'refund_status' => $booking->refund_status,
                    'refund_reason' => $booking->refund_reason,
                ],
            ], 200);

        } catch (\Throwable $e) {
            Log::error('[DisputeController@requestRefund] Lỗi không mong đợi', [
                'user_id'    => auth()->id(),
                'booking_id' => $validated['booking_id'] ?? null,
                'message'    => $e->getMessage(),
                'trace'      => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Đã xảy ra lỗi hệ thống. Vui lòng thử lại sau.',
                'data'    => null,
            ], 500);
        }
    }

    /**
     * Admin duyệt yêu cầu hoàn tiền: hủy booking và giải phóng phòng.
     *
     * POST /api/admin/dispute/approve-refund
     * Body: { booking_id }
     * Middleware: auth:api + role:admin
     */
    public function approveRefund(Request $request): JsonResponse
    {
        // ── 1. Validate đầu vào ──────────────────────────────────────────────
        $validated = $request->validate([
            'booking_id' => ['required', 'integer', 'exists:booking_information,id'],
        ], [
            'booking_id.required' => 'Vui lòng cung cấp mã booking.',
            'booking_id.exists'   => 'Booking không tồn tại.',
        ]);

        try {
            // ── 2. Kiểm tra sơ bộ trước khi vào transaction ─────────────────
            // (Optimistic pre-check — giảm tải transaction, không phải guard chính)
            $preCheck = BookingInformation::findOrFail($validated['booking_id']);
            if ($preCheck->refund_status !== 'requested') {
                return response()->json([
                    'success' => false,
                    'message' => 'Booking này không có yêu cầu hoàn tiền đang chờ xử lý.',
                    'data'    => ['refund_status' => $preCheck->refund_status],
                ], 422);
            }

            // ── 3. DB Transaction với pessimistic locking ────────────────────
            $result = DB::transaction(function () use ($validated): array {

                // 3a. Re-fetch booking với lock — serialise với các request đồng thời
                // (ví dụ: 2 admin cùng approve 1 booking, hoặc customer gửi lại request)
                $booking = BookingInformation::lockForUpdate()
                    ->findOrFail($validated['booking_id']);

                // 3b. Re-check idempotent sau khi có lock
                // Nếu request khác vừa approve xong, refund_status sẽ không còn là 'requested'
                if ($booking->refund_status !== 'requested') {
                    throw new \RuntimeException(
                        'Yêu cầu hoàn tiền này đã được xử lý trước đó (refund_status: ' . $booking->refund_status . ').'
                    );
                }

                // 3c. Cập nhật booking: duyệt hoàn tiền và huỷ đơn
                $booking->update([
                    'refund_status' => 'refunded',
                    'status'        => 'cancelled',
                ]);

                // 3d. Giải phóng phòng — với validation trạng thái chặt chẽ
                if ($booking->rooms_id) {
                    // Re-fetch room với lock, không dùng eager-load cũ (có thể stale)
                    $room = Room::lockForUpdate()->find($booking->rooms_id);

                    if ($room) {
                        // Guard: chỉ nhả phòng khi đang ở trạng thái 'Đã đặt' (Booked = 3)
                        // Nếu status != 3, phòng có thể đã được xử lý bởi luồng khác
                        // hoặc đang ở trạng thái không liên quan → KHÔNG nhả để tránh data corruption
                        if ($room->status !== 3) {
                            throw new \RuntimeException(
                                'Phòng này hiện không ở trạng thái Đã đặt (Booked). ' .
                                'Status hiện tại: ' . $room->status . '. Không thể giải phóng.'
                            );
                        }

                        $room->update([
                            'status'     => 1,    // 1 = Available (Phòng trống)
                            'hold_until' => null,
                        ]);
                    }
                }

                // TODO: Tích hợp VNPAY Refund API
                // VnpayService::refund($booking->transaction_id, $booking->deposit_amount);

                return [
                    'booking_code'   => $booking->booking_code,
                    'refund_status'  => $booking->refund_status,
                    'booking_status' => $booking->status,
                    'room_id'        => $booking->rooms_id,
                ];
            });

            return response()->json([
                'success' => true,
                'message' => 'Duyệt hoàn tiền thành công. Booking đã được hủy và phòng đã được giải phóng.',
                'data'    => $result,
            ], 200);

        } catch (\RuntimeException $e) {
            // Lỗi nghiệp vụ có thể đoán trước (double-approve, room status sai)
            Log::warning('[DisputeController@approveRefund] Lỗi nghiệp vụ', [
                'admin_id'   => auth()->id(),
                'booking_id' => $validated['booking_id'] ?? null,
                'message'    => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'data'    => null,
            ], 422);

        } catch (\Throwable $e) {
            Log::error('[DisputeController@approveRefund] Lỗi không mong đợi', [
                'admin_id'   => auth()->id(),
                'booking_id' => $validated['booking_id'] ?? null,
                'message'    => $e->getMessage(),
                'trace'      => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Đã xảy ra lỗi hệ thống. Vui lòng thử lại sau.',
                'data'    => null,
            ], 500);
        }
    }
}
