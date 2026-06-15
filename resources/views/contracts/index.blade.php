@extends('layouts.app')

@section('content')
<section class="breadcrumb-section p-0">
    <img src="{{ asset('assets/images/inner-background.jpg') }}" class="bg-img img-fluid" alt="">
    <div class="container">
        <div class="breadcrumb-content">
            <div>
                <h2>Quản lý Hợp đồng</h2>
                <nav aria-label="breadcrumb" class="theme-breadcrumb">
                    <ol class="breadcrumb">
                        <li class="breadcrumb-item"><a href="{{ route('trang_chu') }}">Trang chủ</a></li>
                        <li class="breadcrumb-item active" aria-current="page">Quản lý Hợp đồng</li>
                    </ol>
                </nav>
            </div>
        </div>
    </div>
</section>

<section class="property-section">
    <div class="container">
        <div class="row">
            <div class="col-12">
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h4 class="card-title mb-0">Danh sách Hợp đồng của bạn</h4>
                        @if($user->role == 2)
                            <a href="{{ route('contracts.create') }}" class="btn btn-primary">Tạo Hợp đồng Mới</a>
                        @endif
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-bordered table-striped">
                                <thead>
                                    <tr>
                                        <th>Mã HĐ</th>
                                        <th>Phòng</th>
                                        @if($user->role == 2)
                                            <th>Người thuê</th>
                                        @else
                                            <th>Chủ trọ</th>
                                        @endif
                                        <th>Ngày bắt đầu</th>
                                        <th>Tiền cọc</th>
                                        <th>Trạng thái</th>
                                        <th>Hành động</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @forelse($contracts as $contract)
                                        <tr>
                                            <td>{{ $contract->contract_code }}</td>
                                            <td>{{ $contract->room ? $contract->room->name : 'N/A' }}</td>
                                            @if($user->role == 2)
                                                <td>{{ $contract->tenant ? $contract->tenant->name : 'N/A' }}</td>
                                            @else
                                                <td>{{ $contract->landlord ? $contract->landlord->name : 'N/A' }}</td>
                                            @endif
                                            <td>{{ date('d-m-Y', strtotime($contract->start_date)) }}</td>
                                            <td>{{ number_format($contract->deposit_amount) }} VNĐ</td>
                                            <td>
                                                @if($contract->status == 'active')
                                                    <span class="badge bg-success">Đang hiệu lực</span>
                                                @elseif($contract->status == 'draft')
                                                    <span class="badge bg-secondary">Bản nháp</span>
                                                @elseif($contract->status == 'expired')
                                                    <span class="badge bg-warning">Hết hạn</span>
                                                @elseif($contract->status == 'terminated')
                                                    <span class="badge bg-danger">Đã chấm dứt</span>
                                                @else
                                                    <span class="badge bg-info">{{ $contract->status }}</span>
                                                @endif
                                            </td>
                                            <td>
                                                @if($contract->status == 'draft' && $user->role == 3)
                                                    <a href="{{ route('contracts.sign', $contract->id) }}" class="btn btn-sm btn-success">Ký HĐ</a>
                                                @endif
                                                <a href="{{ route('contracts.download_pdf', $contract->id) }}" class="btn btn-sm btn-info text-white">Tải PDF</a>
                                            </td>
                                        </tr>
                                    @empty
                                        <tr>
                                            <td colspan="7" class="text-center">Bạn chưa có hợp đồng nào.</td>
                                        </tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                        <div class="mt-3">
                            {{ $contracts->links() }}
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>
@endsection
