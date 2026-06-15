@extends('layouts.app')

@section('content')
<section class="breadcrumb-section p-0">
    <img src="{{ asset('assets/images/inner-background.jpg') }}" class="bg-img img-fluid" alt="">
    <div class="container">
        <div class="breadcrumb-content">
            <div>
                <h2>Chi tiết Hợp đồng {{ $contract->contract_code }}</h2>
                <nav aria-label="breadcrumb" class="theme-breadcrumb">
                    <ol class="breadcrumb">
                        <li class="breadcrumb-item"><a href="{{ route('trang_chu') }}">Trang chủ</a></li>
                        <li class="breadcrumb-item"><a href="{{ $user->role == 2 ? route('landlord.contracts.index') : route('tenant.contracts.index') }}">Quản lý Hợp đồng</a></li>
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
                        <h4>Thông tin Hợp đồng</h4>
                    </div>
                    <div class="card-body">
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <strong>Mã Hợp đồng:</strong> {{ $contract->contract_code }}
                            </div>
                            <div class="col-md-6">
                                <strong>Phòng:</strong> {{ $contract->room ? $contract->room->name : 'N/A' }}
                            </div>
                        </div>
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <strong>Chủ trọ:</strong> {{ $contract->landlord ? $contract->landlord->name : 'N/A' }}
                            </div>
                            <div class="col-md-6">
                                <strong>Người thuê:</strong> {{ $contract->tenant ? $contract->tenant->name : 'N/A' }}
                            </div>
                        </div>
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <strong>Ngày bắt đầu:</strong> {{ date('d/m/Y', strtotime($contract->start_date)) }}
                            </div>
                            <div class="col-md-6">
                                <strong>Ngày kết thúc:</strong> {{ $contract->end_date ? date('d/m/Y', strtotime($contract->end_date)) : 'Không thời hạn' }}
                            </div>
                        </div>
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <strong>Giá thuê hàng tháng:</strong> <span class="text-danger">{{ number_format($contract->monthly_rent) }} VNĐ</span>
                            </div>
                            <div class="col-md-6">
                                <strong>Tiền cọc:</strong> <span class="text-danger">{{ number_format($contract->deposit_amount) }} VNĐ</span>
                            </div>
                        </div>
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <strong>Trạng thái:</strong> 
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
                            </div>
                            <div class="col-md-6">
                                <strong>Ngày ký:</strong> {{ $contract->signed_at ? $contract->signed_at->format('d/m/Y H:i') : 'Chưa ký' }}
                            </div>
                        </div>
                        <hr>
                        <h5>Điều khoản & Ghi chú</h5>
                        <div class="mt-3">
                            <strong>Điều khoản nội bộ:</strong>
                            <p class="mt-2">{{ $contract->terms_content ?? 'Không có điều khoản tùy chỉnh.' }}</p>
                        </div>
                        <div class="mt-3">
                            <strong>Ghi chú:</strong>
                            <p class="mt-2">{{ $contract->notes ?? 'Không có ghi chú.' }}</p>
                        </div>
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

                        <a href="{{ route('contracts.download_pdf', $contract->id) }}" class="btn btn-info w-100 mb-3 text-white">Tải Xuất Hợp Đồng (PDF)</a>

                        @if($user->role == 0 && $contract->status == 'draft')
                            <a href="{{ route('contracts.sign', $contract->id) }}" class="btn btn-success w-100 mb-3">Ký Hợp Đồng</a>
                        @endif

                        @if($user->role == 2)
                            @if($contract->status == 'draft')
                                <!-- Ghi chú: Có thể trỏ vào route sửa hợp đồng sau này -->
                                <button class="btn btn-warning w-100 mb-3" disabled>Chỉnh sửa Hợp đồng (Đang phát triển)</button>
                            @endif

                            @if(in_array($contract->status, ['active', 'draft']))
                                <form action="{{ route('contracts.terminate', $contract->id) }}" method="POST" onsubmit="return confirm('Bạn có chắc chắn muốn chấm dứt hợp đồng này?');">
                                    @csrf
                                    <button type="submit" class="btn btn-danger w-100">Chấm dứt Hợp đồng</button>
                                </form>
                            @endif
                        @endif
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>
@endsection
