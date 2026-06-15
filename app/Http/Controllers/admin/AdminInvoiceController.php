<?php

namespace App\Http\Controllers\admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Invoice;

class AdminInvoiceController extends Controller
{
    public function index(Request $request)
    {
        $query = Invoice::with(['tenant', 'contract.room']);

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function($q) use ($search) {
                $q->where('invoice_code', 'like', "%{$search}%")
                ->orWhere('id', 'like', "%{$search}%")
                ->orWhereHas('contract.room', function($qr) use ($search) {
                    $qr->where('name', 'like', "%{$search}%");
                });
            });
        }

        $invoices = $query->orderBy('created_at', 'desc')->paginate(15)->appends($request->all());
            
        return view('dashboard.admin.invoices.index', compact('invoices'));
    }
}
