<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\KycRequest;
use Illuminate\Http\Request;

class KycWebController extends Controller
{
    public function index(Request $request)
    {
        $status = $request->query('status', 'pending');

        $kycRequests = KycRequest::with('user')
            ->where('status', $status)
            ->orderBy('created_at', 'asc')
            ->paginate(15);

        return view('dashboard.admin.kyc.index', compact('kycRequests', 'status'));
    }
}
