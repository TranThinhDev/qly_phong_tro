@extends('layouts.dashboard')

@section('content')
    <!-- Container-fluid starts-->
    <div class="container-fluid">
        <div class="page-header">
            <div class="row">
                <div class="col-sm-6">
                    <div class="page-header-left">
                        <h3>Quản lý Hợp đồng
                            <small>Danh sách toàn bộ hợp đồng trong hệ thống</small>
                        </h3>
                    </div>
                </div>
                <div class="col-sm-6">
                    <ol class="breadcrumb pull-right">
                        <li class="breadcrumb-item"><a href="{{ route('home') }}"><i data-feather="home"></i></a></li>
                        <li class="breadcrumb-item active">Quản lý Hợp đồng</li>
                    </ol>
                </div>
            </div>
        </div>
    </div>
    <!-- Container-fluid Ends-->

    <!-- Container-fluid starts-->
    <div class="container-fluid">
        <div class="row">
            <div class="col-sm-12">
                <div class="card">
                    <div class="card-header">
                        <h5>Danh sách Hợp đồng</h5>
                    </div>
                    <div class="card-body">
                        <form action="{{ route('admin.contracts.index') }}" method="GET" class="row mb-4">
                            <div class="col-md-4">
                                <input type="text" name="search" class="form-control" placeholder="Tìm theo mã HĐ hoặc tên phòng..." value="{{ request('search') }}">
                            </div>
                            <div class="col-md-3">
                                <select name="status" class="form-select">
                                    <option value="">Tất cả trạng thái</option>
                                    <option value="draft" {{ request('status') == 'draft' ? 'selected' : '' }}>Bản nháp</option>
                                    <option value="active" {{ request('status') == 'active' ? 'selected' : '' }}>Đang hiệu lực</option>
                                    <option value="expired" {{ request('status') == 'expired' ? 'selected' : '' }}>Hết hạn</option>
                                    <option value="terminated" {{ request('status') == 'terminated' ? 'selected' : '' }}>Đã chấm dứt</option>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <button type="submit" class="btn btn-primary"><i data-feather="search"></i> Lọc</button>
                                <a href="{{ route('admin.contracts.index') }}" class="btn btn-light"><i data-feather="refresh-ccw"></i></a>
                            </div>
                        </form>
                        <div class="table-responsive">
                            <table class="table table-bordered table-striped">
                                <thead>
                                    <tr>
                                        <th>Mã HĐ</th>
                                        <th>Chủ trọ</th>
                                        <th>Người thuê</th>
                                        <th>Phòng</th>
                                        <th>Ngày tạo</th>
                                        <th>Tiền cọc</th>
                                        <th>Trạng thái</th>
                                        <th>Thao tác</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @forelse($contracts as $contract)
                                        <tr>
                                            <td>{{ $contract->contract_code }}</td>
                                            <td>{{ $contract->landlord ? $contract->landlord->name : 'N/A' }}</td>
                                            <td>{{ $contract->tenant ? $contract->tenant->name : 'N/A' }}</td>
                                            <td>{{ $contract->room ? $contract->room->name : 'N/A' }}</td>
                                            <td>{{ $contract->created_at->format('d-m-Y H:i') }}</td>
                                            <td>{{ number_format($contract->deposit_amount) }} VNĐ</td>
                                            <td>
                                                @if($contract->status == 'active')
                                                    <span class="badge badge-success">Đang hiệu lực</span>
                                                @elseif($contract->status == 'draft')
                                                    <span class="badge badge-secondary">Bản nháp</span>
                                                @elseif($contract->status == 'expired')
                                                    <span class="badge badge-warning">Hết hạn</span>
                                                @elseif($contract->status == 'terminated')
                                                    <span class="badge badge-danger">Đã chấm dứt</span>
                                                @else
                                                    <span class="badge badge-info">{{ $contract->status }}</span>
                                                @endif
                                            </td>
                                            <td>
                                                <a href="{{ route('contracts.download_pdf', $contract->id) }}" class="btn btn-sm btn-info text-white" target="_blank"><i data-feather="download"></i> PDF</a>
                                            </td>
                                        </tr>
                                    @empty
                                        <tr>
                                            <td colspan="8" class="text-center">Chưa có hợp đồng nào.</td>
                                        </tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                        <div class="mt-4">
                            {{ $contracts->links('pagination::bootstrap-4') }}
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <!-- Container-fluid Ends-->
@endsection
