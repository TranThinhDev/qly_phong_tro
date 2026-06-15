<?php

namespace App\Http\Controllers\admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Contract;

class AdminContractController extends Controller
{
    public function index(Request $request)
    {
        $query = Contract::with(['room', 'tenant', 'landlord']);

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function($q) use ($search) {
                $q->where('contract_code', 'like', "%{$search}%")
                  ->orWhereHas('room', function($qr) use ($search) {
                      $qr->where('name', 'like', "%{$search}%");
                  });
            });
        }

        $contracts = $query->orderBy('created_at', 'desc')->paginate(15)->appends($request->all());
            
        return view('dashboard.admin.contracts.index', compact('contracts'));
    }
}
