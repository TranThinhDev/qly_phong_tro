@extends('layouts.dashboard')

@section('title', 'Quản Lý Hóa Đơn Hàng Tháng')

@section('style')
<style>
    .status-badge { font-size: 13px; padding: 5px 10px; border-radius: 4px; }
    .badge-paid { background-color: #d4edda; color: #155724; }
    .badge-unpaid { background-color: #fff3cd; color: #856404; }
    .badge-partial { background-color: #cce5ff; color: #004085; }
    .badge-overdue { background-color: #f8d7da; color: #721c24; }
    .badge-cancelled { background-color: #e2e3e5; color: #383d41; }
</style>
@endsection

@section('content')
<div class="container-fluid">
    <div class="page-header">
        <div class="row">
            <div class="col-sm-6">
                <div class="page-header-left">
                    <h3>Quản Lý Hóa Đơn</h3>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="container-fluid">
    <div class="row">
        <div class="col-sm-12">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5>Danh sách tất cả hóa đơn của khách thuê</h5>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-bordered table-striped text-center" id="invoiceTable">
                            <thead class="bg-light">
                                <tr>
                                    <th>Mã Hóa Đơn</th>
                                    <th>Phòng</th>
                                    <th>Khách Thuê</th>
                                    <th>Kỳ T.Toán</th>
                                    <th>Hạn Cuối</th>
                                    <th>Tổng Tiền</th>
                                    <th>Trạng Thái</th>
                                    <th>Giải Phóng</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($invoices as $invoice)
                                    <tr>
                                        <td class="font-weight-bold">{{ $invoice->invoice_code }}</td>
                                        <td>{{ $invoice->contract->room->name ?? 'N/A' }}</td>
                                        <td>
                                            @if($invoice->tenant)
                                                {{ $invoice->tenant->name }}<br>
                                                <small class="text-muted">{{ $invoice->tenant->phone }}</small>
                                            @else
                                                N/A
                                            @endif
                                        </td>
                                        <td>{{ $invoice->billing_month }}</td>
                                        <td>{{ $invoice->due_date->format('d/m/Y') }}</td>
                                        <td class="text-danger font-weight-bold">{{ number_format($invoice->total_amount, 0, ',', '.') }} đ</td>
                                        <td>
                                            @if($invoice->status == 'paid')
                                                <span class="status-badge badge-paid">Đã thanh toán</span>
                                            @elseif($invoice->status == 'partial')
                                                <span class="status-badge badge-partial">Thanh toán 1 phần</span>
                                            @elseif($invoice->status == 'overdue')
                                                <span class="status-badge badge-overdue">Quá hạn</span>
                                            @elseif($invoice->status == 'cancelled')
                                                <span class="status-badge badge-cancelled">Đã hủy</span>
                                            @else
                                                <span class="status-badge badge-unpaid">Chưa thanh toán</span>
                                            @endif
                                        </td>
                                        <td>
                                            @if($invoice->status == 'paid')
                                                @if($invoice->is_fund_released)
                                                    <i class="fa fa-check text-success" title="Đã cộng vào số dư khả dụng"></i>
                                                @else
                                                    <i class="fa fa-clock-o text-warning" title="Đang trong escrow, chờ 24h"></i> Chờ 24h
                                                @endif
                                            @else
                                                -
                                            @endif
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="8" class="text-center text-muted">Chưa có hóa đơn nào được tạo.</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                    
                    <!-- Phân trang -->
                    <div class="mt-4 d-flex justify-content-end">
                        {{ $invoices->links() }}
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
