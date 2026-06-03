<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\WithdrawalRequest;
use App\Services\WalletService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AdminWithdrawalController extends Controller
{
    /**
     * GET /api/admin/withdrawals
     * Lấy danh sách các yêu cầu rút tiền đang chờ duyệt.
     */
    public function index(Request $request): JsonResponse
    {
        $perPage = $request->query('per_page', 20);

        $withdrawals = WithdrawalRequest::with('user:id,name,email,phone')
            ->pending() // Dùng scopePending từ model
            ->orderBy('created_at', 'asc') // Cũ nhất duyệt trước
            ->paginate($perPage);

        return response()->json([
            'data' => $withdrawals
        ]);
    }

    /**
     * POST /api/admin/withdrawals/{id}/approve
     * 
     * Admin duyệt yêu cầu rút tiền.
     * Bắt buộc phải tải lên hình ảnh biên lai (proof_image).
     */
    public function approve(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'proof_image' => 'required|image|mimes:jpeg,png,jpg|max:5120', // Tối đa 5MB
            'notes'       => 'nullable|string|max:255',
        ], [
            'proof_image.required' => 'Vui lòng tải lên ảnh biên lai chuyển khoản.',
            'proof_image.image'    => 'File tải lên phải là hình ảnh.',
            'proof_image.max'      => 'Kích thước ảnh không được vượt quá 5MB.',
        ]);

        $adminId = Auth::id();

        try {
            return DB::transaction(function () use ($id, $request, $adminId) {
                // Lock row để tránh 2 admin cùng duyệt
                $withdrawal = WithdrawalRequest::lockForUpdate()->find($id);

                if (! $withdrawal) {
                    return response()->json(['message' => 'Yêu cầu không tồn tại.'], 404);
                }

                if (! $withdrawal->isPending()) {
                    return response()->json([
                        'message' => "Yêu cầu đã được xử lý (Trạng thái: {$withdrawal->status}).",
                    ], 422);
                }

                // ── Lưu file vào private disk (giống KYC) ────────────────────
                $proofPath = $request->file('proof_image')->store('private_withdrawals', 'local');

                // ── Duyệt (đổi status) ────────────────────────────────────────
                // Không cần tác động WalletService vì tiền đã bị trừ (deductAvailableFunds)
                // lúc tạo yêu cầu rồi. Chuyển tiền thực tế diễn ra qua ngân hàng.
                $withdrawal->approve($adminId, $proofPath, $request->input('notes'));

                Log::info('[AdminWithdrawal] Đã duyệt yêu cầu rút tiền', [
                    'withdrawal_id' => $withdrawal->id,
                    'admin_id'      => $adminId,
                ]);

                return response()->json([
                    'message' => 'Đã duyệt yêu cầu rút tiền thành công.',
                    'data'    => clone $withdrawal, // clone để không lộ file path ẩn
                ]);
            });

        } catch (\Throwable $e) {
            Log::error('[AdminWithdrawal] Lỗi duyệt yêu cầu rút tiền', [
                'withdrawal_id' => $id,
                'error'         => $e->getMessage(),
                'trace'         => $e->getTraceAsString(),
            ]);

            return response()->json([
                'message' => 'Đã có lỗi hệ thống xảy ra.',
            ], 500);
        }
    }

    /**
     * POST /api/admin/withdrawals/{id}/reject
     * 
     * Admin từ chối yêu cầu rút tiền.
     * Bắt buộc có lý do từ chối. Số tiền phải được hoàn lại vào ví.
     */
    public function reject(Request $request, int $id, WalletService $walletService): JsonResponse
    {
        $request->validate([
            'reason' => 'required|string|min:5|max:255',
        ], [
            'reason.required' => 'Vui lòng nhập lý do từ chối.',
            'reason.min'      => 'Lý do từ chối quá ngắn.',
        ]);

        $adminId = Auth::id();
        $reason  = $request->input('reason');

        try {
            return DB::transaction(function () use ($id, $reason, $adminId, $walletService) {
                // Lock row
                $withdrawal = WithdrawalRequest::lockForUpdate()->find($id);

                if (! $withdrawal) {
                    return response()->json(['message' => 'Yêu cầu không tồn tại.'], 404);
                }

                if (! $withdrawal->isPending()) {
                    return response()->json([
                        'message' => "Yêu cầu đã được xử lý (Trạng thái: {$withdrawal->status}).",
                    ], 422);
                }

                // ── 1. Từ chối yêu cầu (cập nhật model) ──────────────────────
                $withdrawal->reject($adminId, $reason);

                // ── 2. Hoàn tiền lại cho user qua WalletService ──────────────
                // Dùng topUp() vì ta cần cộng tiền vào available_balance
                $walletService->topUp(
                    $withdrawal->user->id,
                    (float) $withdrawal->amount,
                    $withdrawal,
                    "Hoàn tiền do yêu cầu rút tiền bị từ chối: {$reason}"
                );

                Log::info('[AdminWithdrawal] Đã từ chối và hoàn tiền', [
                    'withdrawal_id' => $withdrawal->id,
                    'admin_id'      => $adminId,
                    'amount'        => $withdrawal->amount,
                    'reason'        => $reason,
                ]);

                return response()->json([
                    'message' => 'Đã từ chối yêu cầu và hoàn tiền vào ví chủ trọ.',
                    'data'    => $withdrawal,
                ]);
            });

        } catch (\Throwable $e) {
            Log::error('[AdminWithdrawal] Lỗi từ chối yêu cầu rút tiền', [
                'withdrawal_id' => $id,
                'error'         => $e->getMessage(),
                'trace'         => $e->getTraceAsString(),
            ]);

            return response()->json([
                'message' => 'Đã có lỗi hệ thống xảy ra.',
            ], 500);
        }
    }
}
