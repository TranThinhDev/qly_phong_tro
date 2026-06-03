<?php

namespace App\Http\Controllers;

use App\Services\WalletService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

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
}
