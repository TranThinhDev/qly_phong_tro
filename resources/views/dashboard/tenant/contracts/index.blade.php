@extends('layouts.dashboard')

@section('title', 'Hợp Đồng Của Tôi')

@section('content')
<div class="container-fluid">
    <div class="page-header">
        <div class="row">
            <div class="col-sm-6">
                <div class="page-header-left">
                    <h3>Hợp Đồng Của Tôi</h3>
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
                    <h5>Danh sách hợp đồng</h5>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-bordered table-striped text-center">
                            <thead class="bg-light">
                                <tr>
                                    <th>Mã HĐ</th>
                                    <th>Phòng</th>
                                    <th>Chủ trọ</th>
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
                                        <td>{{ $contract->landlord ? $contract->landlord->name : 'N/A' }}</td>
                                        <td>{{ date('d-m-Y', strtotime($contract->start_date)) }}</td>
                                        <td>{{ number_format($contract->deposit_amount) }} VNĐ</td>
                                        <td>
                                            @if($contract->status == 'active')
                                                <span class="badge bg-success">Đang hiệu lực</span>
                                            @elseif($contract->status == 'draft')
                                                <span class="badge bg-secondary">Bản nháp</span>
                                            @elseif($contract->status == 'pending_payment')
                                                <span class="badge bg-warning text-dark">Chờ thanh toán</span>
                                            @elseif($contract->status == 'expired')
                                                <span class="badge bg-warning">Hết hạn</span>
                                            @elseif($contract->status == 'terminated')
                                                <span class="badge bg-danger">Đã chấm dứt</span>
                                            @else
                                                <span class="badge bg-info">{{ $contract->status }}</span>
                                            @endif
                                        </td>
                                        <td>
                                            @if($contract->status == 'draft')
                                                <a href="{{ route('contracts.sign', $contract->id) }}" class="btn btn-sm btn-success">Ký HĐ</a>
                                            @elseif($contract->status == 'pending_payment')
                                                <form action="{{ route('contracts.agree_pay', $contract->id) }}" method="POST" class="d-inline">
                                                    @csrf
                                                    <button type="submit" class="btn btn-sm btn-warning">Thanh toán cọc</button>
                                                </form>
                                            @endif
                                            <a href="{{ route('contracts.show', $contract->id) }}" class="btn btn-sm btn-primary">Chi tiết</a>
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
@endsection
