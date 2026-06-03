@extends('layouts.dashboard')

@section('title', 'Hóa Đơn Của Tôi')

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
                    <h3>Hóa Đơn Hàng Tháng</h3>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="container-fluid">
    <div class="row">
        <div class="col-sm-12">
            <div class="card">
                <div class="card-header">
                    <h5>Danh Sách Hóa Đơn</h5>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-bordered table-striped text-center">
                            <thead class="bg-light">
                                <tr>
                                    <th>STT</th>
                                    <th>Mã Hóa Đơn</th>
                                    <th>Kỳ Thanh Toán</th>
                                    <th>Hạn Cuối</th>
                                    <th>Tổng Tiền</th>
                                    <th>Trạng Thái</th>
                                    <th>Thao Tác</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($invoices as $key => $invoice)
                                    <tr>
                                        <td>{{ $key + 1 + ($invoices->currentPage() - 1) * $invoices->perPage() }}</td>
                                        <td class="font-weight-bold">{{ $invoice->invoice_code }}</td>
                                        <td>{{ $invoice->billing_month }}</td>
                                        <td>{{ $invoice->due_date->format('d/m/Y') }}</td>
                                        <td class="text-danger font-weight-bold">{{ number_format($invoice->total_amount, 0, ',', '.') }} đ</td>
                                        <td>
                                            @if($invoice->status == 'paid')
                                                <span class="status-badge badge-paid"><i class="fa fa-check-circle"></i> Đã thanh toán</span>
                                            @elseif($invoice->status == 'partial')
                                                <span class="status-badge badge-partial">Thanh toán 1 phần</span>
                                            @elseif($invoice->status == 'overdue')
                                                <span class="status-badge badge-overdue"><i class="fa fa-exclamation-triangle"></i> Quá hạn</span>
                                            @elseif($invoice->status == 'cancelled')
                                                <span class="status-badge badge-cancelled">Đã hủy</span>
                                            @else
                                                <span class="status-badge badge-unpaid">Chưa thanh toán</span>
                                            @endif
                                        </td>
                                        <td>
                                            <a href="{{ route('tenant.invoices.show', $invoice->id) }}" class="btn btn-primary btn-sm">
                                                <i class="fa fa-eye"></i> Xem chi tiết
                                            </a>
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="7" class="text-center text-muted">Bạn chưa có hóa đơn nào.</td>
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
