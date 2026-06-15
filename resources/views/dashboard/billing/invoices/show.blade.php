@extends('layouts.app')

@section('content')
<section class="breadcrumb-section p-0">
    <img src="{{ asset('assets/images/inner-background.jpg') }}" class="bg-img img-fluid" alt="">
    <div class="container">
        <div class="breadcrumb-content">
            <div>
                <h2>Chi tiết Hóa đơn #{{ $invoice->id }}</h2>
                <nav aria-label="breadcrumb" class="theme-breadcrumb">
                    <ol class="breadcrumb">
                        <li class="breadcrumb-item"><a href="{{ route('trang_chu') }}">Trang chủ</a></li>
                        <li class="breadcrumb-item"><a href="{{ route('landlord.invoices.index') }}">Quản lý Hóa đơn</a></li>
                        <li class="breadcrumb-item active">Chi tiết</li>
                    </ol>
                </nav>
            </div>
        </div>
    </div>
</section>

<section class="property-section">
    <div class="container">
        <div class="row">
            <div class="col-md-8">
                <div class="card">
                    <div class="card-header">
                        <h4>Thông tin Hóa đơn</h4>
                    </div>
                    <div class="card-body">
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <strong>Phòng:</strong> {{ $invoice->contract->room->name ?? 'N/A' }}
                            </div>
                            <div class="col-md-6">
                                <strong>Kỳ thanh toán:</strong> {{ $invoice->billing_month ?? 'N/A' }}
                            </div>
                        </div>
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <strong>Người thuê:</strong> {{ $invoice->tenant->name ?? 'N/A' }}
                            </div>
                            <div class="col-md-6">
                                <strong>Hạn thanh toán:</strong> {{ $invoice->due_date ? date('d/m/Y', strtotime($invoice->due_date)) : 'N/A' }}
                            </div>
                        </div>
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <strong>Trạng thái:</strong> 
                                @if($invoice->status == 'paid')
                                    <span class="badge bg-success">Đã thanh toán</span>
                                @elseif($invoice->status == 'unpaid')
                                    <span class="badge bg-warning">Chưa thanh toán</span>
                                @elseif($invoice->status == 'overdue')
                                    <span class="badge bg-danger">Quá hạn</span>
                                @elseif($invoice->status == 'cancelled')
                                    <span class="badge bg-secondary">Đã hủy</span>
                                @else
                                    <span class="badge bg-info">{{ $invoice->status }}</span>
                                @endif
                            </div>
                            <div class="col-md-6">
                                <strong>Ngày lập:</strong> {{ $invoice->created_at->format('d/m/Y H:i') }}
                            </div>
                        </div>

                        <hr>
                        <h5>Chi tiết các khoản thu</h5>
                        <table class="table mt-3">
                            <thead>
                                <tr>
                                    <th>Mô tả</th>
                                    <th class="text-end">Số tiền (VNĐ)</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($invoice->items as $item)
                                <tr>
                                    <td>{{ $item->description }}</td>
                                    <td class="text-end">{{ number_format($item->amount) }}</td>
                                </tr>
                                @empty
                                <tr>
                                    <td colspan="2" class="text-center">Không có chi tiết.</td>
                                </tr>
                                @endforelse
                            </tbody>
                            <tfoot>
                                <tr>
                                    <th>Tổng cộng</th>
                                    <th class="text-end text-danger">{{ number_format($invoice->total_amount) }} VNĐ</th>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
            </div>
            
            <div class="col-md-4">
                <div class="card">
                    <div class="card-header">
                        <h4>Hành động</h4>
                    </div>
                    <div class="card-body">
                        @if (session('error'))
                            <div class="alert alert-danger">{{ session('error') }}</div>
                        @endif
                        @if (session('success'))
                            <div class="alert alert-success">{{ session('success') }}</div>
                        @endif

                        @if(in_array($invoice->status, ['unpaid', 'partial', 'overdue']))
                            <form action="{{ route('landlord.invoices.update_status', $invoice->id) }}" method="POST">
                                @csrf
                                <div class="mb-3">
                                    <label class="form-label">Cập nhật trạng thái</label>
                                    <select name="status" class="form-select" required>
                                        <option value="">-- Chọn trạng thái --</option>
                                        <option value="paid">Đã thanh toán (Xác nhận nhận tiền)</option>
                                        <option value="cancelled">Hủy hóa đơn</option>
                                    </select>
                                </div>
                                <button type="submit" class="btn btn-primary w-100">Cập nhật</button>
                            </form>
                        @else
                            <div class="alert alert-info">
                                Hóa đơn này đang ở trạng thái <strong>{{ $invoice->status }}</strong> và không thể cập nhật thêm.
                            </div>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>
@endsection
