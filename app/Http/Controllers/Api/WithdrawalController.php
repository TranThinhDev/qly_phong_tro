<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SubmitWithdrawalRequest;
use App\Models\WithdrawalRequest;
use App\Services\WalletService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Controller cho luồng Rút Tiền (Dành cho Chủ Trọ)
 */
class WithdrawalController extends Controller
{
    /**
     * POST /api/landlord/withdraw
     *
     * Yêu cầu rút tiền từ ví.
     * Quy tắc tài chính: TIỀN PHẢI BỊ TRỪ NGAY LẬP TỨC (hold)
     * khi tạo yêu cầu rút tiền để tránh việc chủ trọ tạo
     * nhiều yêu cầu rút vượt quá số dư (Double Spend).
     */
    public function withdraw(SubmitWithdrawalRequest $request, WalletService $walletService): JsonResponse
    {
        $user = Auth::user();

        // ── 1. Kiểm tra KYC ───────────────────────────────────────────────
        if (! $user->isKycVerified()) {
            return response()->json([
                'message' => 'Bạn cần hoàn thành xác minh KYC để rút tiền.',
            ], 403);
        }

        $amount = (float) $request->input('amount');

        try {
            return DB::transaction(function () use ($request, $user, $amount, $walletService) {
                // Đảm bảo user có ví
                $wallet = $walletService->getOrCreateWallet($user->id);

                // ── 2. Tạo record WithdrawalRequest ────────────────────────
                // Trạng thái ban đầu là 'pending'. Nếu amount < 5tr, ta sẽ tự động
                // duyệt ở bước 4. Nhưng lúc tạo ra luôn phải đi qua luồng chuẩn.
                $withdrawal = new WithdrawalRequest([
                    'wallet_id'           => $wallet->id,
                    'amount'              => $amount,
                    'bank_code'           => $request->input('bank_code'),
                    'bank_account_number' => $request->input('bank_account_number'),
                    'bank_account_name'   => $request->input('bank_account_name'),
                ]);
                // Không lưu ngay vì ta muốn gán ID sau khi deduct funds,
                // nhưng deductFunds lại cần đối tượng có ID để làm reference.
                // Cho nên phải save nó trước với pending status.
                $withdrawal->save();

                // ── 3. Trừ tiền NGAY LẬP TỨC từ available_balance ──────────
                // Nếu không đủ tiền hoặc ví bị khóa, hàm này sẽ ném RuntimeException,
                // transaction bị rollback, withdrawal request sẽ không được tạo.
                $walletService->deductAvailableFunds(
                    $user->id,
                    $amount,
                    WalletService::TYPE_WITHDRAWAL, // Sử dụng hằng số chuẩn
                    "Rút tiền về tài khoản {$request->input('bank_account_number')} ({$request->input('bank_code')})",
                    $withdrawal
                );

                // ── 4. Logic Duyệt Tự Động (Auto-Payout Mock) ──────────────
                // Theo yêu cầu: Nếu < 5.000.000 VNĐ, tự động chuyển sang approved.
                if ($amount < 5000000) {
                    // Dùng SYSTEM_ID hoặc null để đánh dấu hệ thống tự duyệt.
                    // Chúng ta truyền ID của chính user hoặc 0 tùy thiết kế, ở đây pass null
                    // nhưng logic Model require adminId int. Chúng ta dùng 0 (System).
                    $withdrawal->approve(0, null, 'Hệ thống tự động duyệt do số tiền < 5 triệu VNĐ');
                    
                    Log::info('[Withdrawal] Yêu cầu rút tiền được TỰ ĐỘNG duyệt', [
                        'withdrawal_id' => $withdrawal->id,
                        'user_id'       => $user->id,
                        'amount'        => $amount,
                    ]);
                } else {
                    Log::info('[Withdrawal] Đã tạo yêu cầu rút tiền (Cần admin duyệt)', [
                        'withdrawal_id' => $withdrawal->id,
                        'user_id'       => $user->id,
                        'amount'        => $amount,
                    ]);
                }

                // Load relationship
                $withdrawal->load('wallet');

                return response()->json([
                    'message' => 'Yêu cầu rút tiền đã được tạo thành công.',
                    'data'    => clone $withdrawal, // Clone để trả về json không bị đổi tham chiếu
                ], 201);
            });
            
        } catch (\RuntimeException $e) {
            // Các lỗi liên quan đến số dư không đủ, ví bị đóng băng...
            return response()->json([
                'message' => $e->getMessage(),
            ], 422);
        } catch (\Throwable $e) {
            Log::error('[Withdrawal] Lỗi tạo yêu cầu rút tiền', [
                'user_id' => $user->id,
                'error'   => $e->getMessage(),
                'trace'   => $e->getTraceAsString(),
            ]);

            return response()->json([
                'message' => 'Đã có lỗi hệ thống xảy ra khi tạo yêu cầu rút tiền.',
            ], 500);
        }
    }
}
