<?php

namespace App\Http\Controllers;

use App\Services\WalletService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class WalletWebController extends Controller
{
    /**
     * Hiển thị Dashboard Ví tiền của chủ trọ.
     */
    public function index(WalletService $walletService)
    {
        $landlordId = Auth::id();

        // Lấy hoặc tạo ví
        $wallet = $walletService->getOrCreateWallet($landlordId);

        // Lấy lịch sử giao dịch
        $transactions = $wallet->transactions()
            ->orderBy('created_at', 'desc')
            ->paginate(15);

        return view('dashboard.wallet.index', compact('wallet', 'transactions'));
    }

    /**
     * Xử lý yêu cầu rút tiền.
     */
    public function withdraw(Request $request, WalletService $walletService)
    {
        $request->validate([
            'amount' => 'required|numeric|min:50000',
            'bank_code' => 'required|string',
            'bank_account_number' => 'required|string',
            'bank_account_name' => 'required|string',
        ]);

        $landlordId = Auth::id();

        try {
            DB::transaction(function () use ($request, $walletService, $landlordId) {
                // 1. Tạo yêu cầu rút tiền
                $withdrawal = \App\Models\WithdrawalRequest::create([
                    'user_id'             => $landlordId,
                    'amount'              => $request->amount,
                    'bank_code'           => $request->bank_code,
                    'bank_account_number' => $request->bank_account_number,
                    'bank_account_name'   => strtoupper($request->bank_account_name),
                    'status'              => 'pending'
                ]);

                // 2. Trừ tiền khỏi ví khả dụng
                $walletService->deductAvailableFunds(
                    $landlordId,
                    (float) $request->amount,
                    WalletService::TYPE_WITHDRAWAL,
                    "Rút tiền về tài khoản {$request->bank_code} - {$request->bank_account_number}",
                    $withdrawal
                );
            });

            return response()->json(['success' => true, 'message' => 'Đã gửi yêu cầu rút tiền thành công. Vui lòng chờ admin xử lý.']);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 400);
        }
    }
}
