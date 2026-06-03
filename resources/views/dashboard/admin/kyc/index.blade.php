@extends('layouts.dashboard')

@section('title', 'Xét Duyệt KYC Chủ Trọ')

@section('content')
<div class="container-fluid">
    <div class="page-header">
        <div class="row">
            <div class="col-sm-6">
                <div class="page-header-left">
                    <h3>Xét Duyệt KYC Chủ Trọ</h3>
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
                    <h5>Danh sách hồ sơ chờ duyệt</h5>
                    <div>
                        <a href="{{ route('admin.kyc_web.index', ['status' => 'pending']) }}" class="btn btn-sm {{ $status == 'pending' ? 'btn-primary' : 'btn-outline-primary' }}">Chờ duyệt</a>
                        <a href="{{ route('admin.kyc_web.index', ['status' => 'verified']) }}" class="btn btn-sm {{ $status == 'verified' ? 'btn-success' : 'btn-outline-success' }}">Đã duyệt</a>
                        <a href="{{ route('admin.kyc_web.index', ['status' => 'rejected']) }}" class="btn btn-sm {{ $status == 'rejected' ? 'btn-danger' : 'btn-outline-danger' }}">Từ chối</a>
                    </div>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-bordered table-striped text-center">
                            <thead class="bg-light">
                                <tr>
                                    <th>ID</th>
                                    <th>Chủ Trọ</th>
                                    <th>Số Điện Thoại</th>
                                    <th>Ngày Gửi</th>
                                    <th>Trạng Thái</th>
                                    <th>Thao Tác</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($kycRequests as $req)
                                    <tr>
                                        <td>#{{ $req->id }}</td>
                                        <td>
                                            @if($req->user)
                                                {{ $req->user->name }}<br>
                                                <small class="text-muted">{{ $req->user->email }}</small>
                                            @else
                                                N/A
                                            @endif
                                        </td>
                                        <td>{{ $req->user->phone ?? 'N/A' }}</td>
                                        <td>{{ $req->created_at->format('d/m/Y H:i') }}</td>
                                        <td>
                                            @if($req->status == 'pending')
                                                <span class="badge badge-warning text-dark">Chờ duyệt</span>
                                            @elseif($req->status == 'verified')
                                                <span class="badge badge-success">Đã duyệt</span>
                                            @elseif($req->status == 'rejected')
                                                <span class="badge badge-danger">Từ chối</span>
                                            @endif
                                        </td>
                                        <td>
                                            @if($req->status == 'pending')
                                                <button class="btn btn-primary btn-sm btn-review" 
                                                    data-id="{{ $req->id }}"
                                                    data-front="{{ route('private-files.kyc', ['path' => base64_encode($req->id_card_front_url)]) }}"
                                                    data-back="{{ route('private-files.kyc', ['path' => base64_encode($req->id_card_back_url)]) }}"
                                                    data-proof="{{ route('private-files.kyc', ['path' => base64_encode($req->ownership_proof_url)]) }}">
                                                    <i class="fa fa-search"></i> Xem / Duyệt
                                                </button>
                                            @else
                                                <span class="text-muted"><i class="fa fa-lock"></i> Đã đóng</span>
                                            @endif
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="6" class="text-center text-muted">Không có hồ sơ nào.</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                    <div class="mt-4 d-flex justify-content-end">
                        {{ $kycRequests->appends(['status' => $status])->links() }}
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Modal Duyệt KYC -->
<div class="modal fade" id="kycModal" tabindex="-1" aria-labelledby="kycModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="kycModalLabel">Hồ Sơ KYC #<span id="modalKycId"></span></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="row">
                    <div class="col-md-6 mb-3 text-center">
                        <label class="font-weight-bold">Mặt trước CMND/CCCD</label>
                        <img id="imgFront" src="" alt="Mặt trước" class="img-fluid rounded border p-1" style="max-height: 250px; cursor: pointer;">
                    </div>
                    <div class="col-md-6 mb-3 text-center">
                        <label class="font-weight-bold">Mặt sau CMND/CCCD</label>
                        <img id="imgBack" src="" alt="Mặt sau" class="img-fluid rounded border p-1" style="max-height: 250px; cursor: pointer;">
                    </div>
                    <div class="col-md-12 text-center">
                        <label class="font-weight-bold">Giấy tờ chứng minh sở hữu</label>
                        <img id="imgProof" src="" alt="Sở hữu" class="img-fluid rounded border p-1" style="max-height: 300px; cursor: pointer;">
                    </div>
                </div>
            </div>
            <div class="modal-footer justify-content-between">
                <button type="button" class="btn btn-danger" id="btnReject">Từ chối</button>
                <div>
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Đóng</button>
                    <button type="button" class="btn btn-success" id="btnApprove">Phê duyệt</button>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection

@section('js')
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
    $(document).ready(function () {
        $.ajaxSetup({
            headers: {
                'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content'),
                'Accept': 'application/json'
            }
        });

        let currentKycId = null;

        $('.btn-review').click(function () {
            currentKycId = $(this).data('id');
            $('#modalKycId').text(currentKycId);
            
            // Render secure image URLs
            $('#imgFront').attr('src', $(this).data('front'));
            $('#imgBack').attr('src', $(this).data('back'));
            $('#imgProof').attr('src', $(this).data('proof'));

            $('#kycModal').modal('show');
        });

        // Xử lý Phê Duyệt
        $('#btnApprove').click(function () {
            let btn = $(this);
            Swal.fire({
                title: 'Xác nhận duyệt?',
                text: "Sau khi duyệt, chủ trọ sẽ có thể bắt đầu tạo hóa đơn và ví tiền.",
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#28a745',
                cancelButtonColor: '#d33',
                confirmButtonText: 'Đồng ý Duyệt',
                cancelButtonText: 'Hủy'
            }).then((result) => {
                if (result.isConfirmed) {
                    btn.prop('disabled', true).html('<i class="fa fa-spinner fa-spin"></i> Đang xử lý...');
                    
                    let url = "{{ route('admin.kyc.approve', ':id') }}".replace(':id', currentKycId);
                    
                    $.ajax({
                        url: url,
                        type: 'POST',
                        success: function (res) {
                            $('#kycModal').modal('hide');
                            Swal.fire('Thành công!', res.message, 'success').then(() => {
                                window.location.reload();
                            });
                        },
                        error: function (xhr) {
                            btn.prop('disabled', false).html('Phê duyệt');
                            Swal.fire('Lỗi', xhr.responseJSON?.message || 'Có lỗi xảy ra', 'error');
                        }
                    });
                }
            });
        });

        // Xử lý Từ chối
        $('#btnReject').click(function () {
            let btn = $(this);
            Swal.fire({
                title: 'Từ chối hồ sơ',
                input: 'textarea',
                inputLabel: 'Lý do từ chối',
                inputPlaceholder: 'Nhập lý do từ chối...',
                showCancelButton: true,
                confirmButtonText: 'Từ chối',
                cancelButtonText: 'Hủy',
                confirmButtonColor: '#d33',
                inputValidator: (value) => {
                    if (!value) {
                        return 'Bạn cần nhập lý do từ chối!'
                    }
                }
            }).then((result) => {
                if (result.isConfirmed) {
                    btn.prop('disabled', true).html('<i class="fa fa-spinner fa-spin"></i> Đang xử lý...');
                    
                    let url = "{{ route('admin.kyc.reject', ':id') }}".replace(':id', currentKycId);
                    
                    $.ajax({
                        url: url,
                        type: 'POST',
                        data: { reason: result.value },
                        success: function (res) {
                            $('#kycModal').modal('hide');
                            Swal.fire('Đã từ chối!', res.message, 'success').then(() => {
                                window.location.reload();
                            });
                        },
                        error: function (xhr) {
                            btn.prop('disabled', false).html('Từ chối');
                            Swal.fire('Lỗi', xhr.responseJSON?.message || 'Có lỗi xảy ra', 'error');
                        }
                    });
                }
            });
        });
    });
</script>
@endsection
