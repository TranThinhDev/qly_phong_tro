@extends('layouts.dashboard')

@section('content')
    <div class="container-fluid">
        <div class="page-header">
            <div class="row">
                <div class="col-sm-6">
                    <div class="page-header-left">
                        <h3>Quản lý Hóa đơn
                            <small>Danh sách toàn bộ hóa đơn trong hệ thống</small>
                        </h3>
                    </div>
                </div>
                <div class="col-sm-6">
                    <ol class="breadcrumb pull-right">
                        <li class="breadcrumb-item"><a href="{{ route('home') }}"><i data-feather="home"></i></a></li>
                        <li class="breadcrumb-item active">Quản lý Hóa đơn</li>
                    </ol>
                </div>
            </div>
        </div>
    </div>
    <div class="container-fluid">
        <div class="row">
            <div class="col-sm-12">
                <div class="card">
                    <div class="card-header">
                        <h5>Danh sách Hóa đơn</h5>
                    </div>
                    <div class="card-body">
                        <form action="{{ route('admin.invoices.index') }}" method="GET" class="row mb-4">
                            <div class="col-md-4">
                                <input type="text" name="search" class="form-control" placeholder="Tìm theo mã Hóa đơn hoặc tên phòng..." value="{{ request('search') }}">
                            </div>
                            <div class="col-md-3">
                                <select name="status" class="form-select">
                                    <option value="">Tất cả trạng thái</option>
                                    <option value="unpaid" {{ request('status') == 'unpaid' ? 'selected' : '' }}>Chưa thanh toán</option>
                                    <option value="partial" {{ request('status') == 'partial' ? 'selected' : '' }}>Thanh toán 1 phần</option>
                                    <option value="paid" {{ request('status') == 'paid' ? 'selected' : '' }}>Đã thanh toán</option>
                                    <option value="overdue" {{ request('status') == 'overdue' ? 'selected' : '' }}>Quá hạn</option>
                                    <option value="cancelled" {{ request('status') == 'cancelled' ? 'selected' : '' }}>Đã hủy</option>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <button type="submit" class="btn btn-primary"><i data-feather="search"></i> Lọc</button>
                                <a href="{{ route('admin.invoices.index') }}" class="btn btn-light"><i data-feather="refresh-ccw"></i></a>
                            </div>
                        </form>
                        <div class="table-responsive">
                            <table class="table table-bordered table-striped">
                                <thead>
                                    <tr>
                                        <th>Mã HĐ/ID</th>
                                        <th>Chủ trọ</th>
                                        <th>Người thuê</th>
                                        <th>Phòng</th>
                                        <th>Tổng tiền</th>
                                        <th>Tháng</th>
                                        <th>Trạng thái</th>
                                        <th>Ngày tạo</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @forelse($invoices as $invoice)
                                        <tr>
                                            <td>#{{ $invoice->id }}</td>
                                            <td>{{ $invoice->landlord ? $invoice->landlord->name : 'N/A' }}</td>
                                            <td>{{ $invoice->tenant ? $invoice->tenant->name : 'N/A' }}</td>
                                            <td>{{ $invoice->contract && $invoice->contract->room ? $invoice->contract->room->name : 'N/A' }}</td>
                                            <td>{{ number_format($invoice->total_amount ?? 0) }} VNĐ</td>
                                            <td>{{ $invoice->month ?? 'N/A' }}/{{ $invoice->year ?? 'N/A' }}</td>
                                            <td>
                                                @if(isset($invoice->status) && $invoice->status == 'paid')
                                                    <span class="badge badge-success">Đã thanh toán</span>
                                                @elseif(isset($invoice->status) && $invoice->status == 'unpaid')
                                                    <span class="badge badge-warning">Chưa thanh toán</span>
                                                @elseif(isset($invoice->status) && $invoice->status == 'overdue')
                                                    <span class="badge badge-danger">Quá hạn</span>
                                                @else
                                                    <span class="badge badge-info">{{ $invoice->status ?? 'N/A' }}</span>
                                                @endif
                                            </td>
                                            <td>{{ $invoice->created_at->format('d-m-Y H:i') }}</td>
                                        </tr>
                                    @empty
                                        <tr>
                                            <td colspan="8" class="text-center">Chưa có hóa đơn nào.</td>
                                        </tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                        <div class="mt-4">
                            {{ $invoices->links('pagination::bootstrap-4') }}
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    @endsection
