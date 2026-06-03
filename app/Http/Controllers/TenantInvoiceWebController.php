<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class TenantInvoiceWebController extends Controller
{
    /**
     * Hiển thị danh sách hóa đơn của khách thuê.
     */
    public function index()
    {
        $tenantId = Auth::id();

        // Lấy danh sách hóa đơn của tenant đang đăng nhập
        $invoices = Invoice::where('tenant_id', $tenantId)
            ->orderBy('created_at', 'desc')
            ->paginate(15);

        return view('dashboard.tenant.invoices.index', compact('invoices'));
    }

    /**
     * Hiển thị chi tiết một hóa đơn cụ thể.
     */
    public function show($id)
    {
        $tenantId = Auth::id();

        $invoice = Invoice::with(['items', 'contract', 'successfulPayments'])
            ->where('id', $id)
            ->where('tenant_id', $tenantId)
            ->firstOrFail();

        return view('dashboard.tenant.invoices.show', compact('invoice'));
    }
}
