<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class LandlordInvoiceWebController extends Controller
{
    /**
     * Hiển thị danh sách hóa đơn do hệ thống tự sinh cho các phòng của chủ trọ.
     */
    public function index()
    {
        $landlordId = Auth::id();

        // Tìm tất cả Invoices thuộc các Contracts của các Rooms của Chủ trọ này
        $invoices = Invoice::whereHas('contract.room', function ($query) use ($landlordId) {
                $query->where('chutro_id', $landlordId);
            })
            ->with(['tenant', 'contract.room'])
            ->orderBy('created_at', 'desc')
            ->paginate(20);

        return view('dashboard.billing.invoices.index', compact('invoices'));
    }
}
