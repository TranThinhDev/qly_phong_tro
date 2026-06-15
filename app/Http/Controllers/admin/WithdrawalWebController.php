<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\WithdrawalRequest;
use App\Models\Notification;
use App\Services\WalletService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class WithdrawalWebController extends Controller
{
    public function index(Request $request)
    {
        $status = $request->query('status', 'pending');

        $withdrawals = WithdrawalRequest::with(['user', 'wallet'])
            ->where('status', $status)
            ->orderBy('created_at', 'asc')
            ->paginate(15);

        return view('dashboard.admin.withdrawals.index', compact('withdrawals', 'status'));
    }

    /**
     * Admin duyệt yêu cầu rút tiền.
     */
    public function approve(Request $request, int $id)
    {
        $request->validate([
            'proof_image' => 'required|image|mimes:jpeg,png,jpg|max:5120',
            'notes'       => 'nullable|string|max:255',
        ], [
            'proof_image.required' => 'Vui lòng tải lên ảnh biên lai chuyển khoản.',
            'proof_image.image'    => 'File tải lên phải là hình ảnh.',
            'proof_image.max'      => 'Kích thước ảnh không được vượt quá 5MB.',
        ]);

        $adminId = Auth::id();

        try {
            return DB::transaction(function () use ($id, $request, $adminId) {
                $withdrawal = WithdrawalRequest::lockForUpdate()->find($id);

                if (! $withdrawal) {
                    return response()->json(['message' => 'Yêu cầu không tồn tại.'], 404);
                }

                if ($withdrawal->status !== 'pending') {
                    return response()->json(['message' => "Yêu cầu đã được xử lý (Trạng thái: {$withdrawal->status})."], 422);
                }

                $proofPath = $request->file('proof_image')->store('private_withdrawals', 'local');
                $withdrawal->approve($adminId, $proofPath, $request->input('notes'));

                Log::info('[AdminWithdrawal] Đã duyệt yêu cầu rút tiền', [
                    'withdrawal_id' => $withdrawal->id,
                    'admin_id'      => $adminId,
                ]);

                // ── Gửi Notification cho Chủ trọ ────────────────────────
                $this->MakeNotification(
                    $withdrawal->user_id,
                    "Yêu cầu rút tiền #" . $withdrawal->id . " số tiền " . number_format($withdrawal->amount, 0, ',', '.') . "đ đã được phê duyệt.",
                    'landlord.wallet.index'
                );

                return response()->json(['message' => 'Đã duyệt yêu cầu rút tiền thành công.']);
            });

        } catch (\Throwable $e) {
            Log::error('[AdminWithdrawal] Lỗi duyệt yêu cầu rút tiền', [
                'withdrawal_id' => $id,
                'error'         => $e->getMessage()
            ]);
            return response()->json(['message' => 'Đã có lỗi hệ thống xảy ra.'], 500);
        }
    }

    /**
     * Admin từ chối yêu cầu rút tiền.
     */
    public function reject(Request $request, int $id, WalletService $walletService)
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
                $withdrawal = WithdrawalRequest::lockForUpdate()->find($id);

                if (! $withdrawal) {
                    return response()->json(['message' => 'Yêu cầu không tồn tại.'], 404);
                }

                if ($withdrawal->status !== 'pending') {
                    return response()->json(['message' => "Yêu cầu đã được xử lý (Trạng thái: {$withdrawal->status})."], 422);
                }

                $withdrawal->reject($adminId, $reason);

                // Hoàn tiền lại cho user
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
                ]);

                // ── Gửi Notification cho Chủ trọ ────────────────────────
                $this->MakeNotification(
                    $withdrawal->user_id,
                    "Yêu cầu rút tiền #" . $withdrawal->id . " bị từ chối. Lý do: {$reason}. Tiền đã được hoàn lại ví.",
                    'landlord.wallet.index'
                );

                return response()->json(['message' => 'Đã từ chối yêu cầu và hoàn tiền vào ví chủ trọ.']);
            });

        } catch (\Throwable $e) {
            Log::error('[AdminWithdrawal] Lỗi từ chối yêu cầu rút tiền', [
                'withdrawal_id' => $id,
                'error'         => $e->getMessage()
            ]);
            return response()->json(['message' => 'Đã có lỗi hệ thống xảy ra.'], 500);
        }
    }
}
