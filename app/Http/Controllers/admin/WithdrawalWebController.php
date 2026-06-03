<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\WithdrawalRequest;
use Illuminate\Http\Request;

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
}
