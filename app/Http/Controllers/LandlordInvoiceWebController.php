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

    /**
     * Hiển thị chi tiết một hóa đơn cho chủ trọ
     */
    public function show($id)
    {
        $landlordId = Auth::id();

        $invoice = Invoice::whereHas('contract.room', function ($query) use ($landlordId) {
                $query->where('chutro_id', $landlordId);
            })
            ->with(['tenant', 'contract.room', 'items'])
            ->findOrFail($id);

        return view('dashboard.billing.invoices.show', compact('invoice'));
    }

    /**
     * Cập nhật trạng thái hóa đơn (chỉ cho phép chuyển sang paid/cancelled)
     */
    public function updateStatus(Request $request, $id)
    {
        $landlordId = Auth::id();

        $invoice = Invoice::whereHas('contract.room', function ($query) use ($landlordId) {
                $query->where('chutro_id', $landlordId);
            })
            ->findOrFail($id);

        $request->validate([
            'status' => 'required|in:paid,cancelled'
        ]);

        try {
            $invoice->transitionTo($request->status);
            $invoice->save();
            return redirect()->back()->with('success', 'Cập nhật trạng thái thành công.');
        } catch (\InvalidArgumentException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }
    }
}
