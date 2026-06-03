@extends('layouts.dashboard')

@section('title', 'Xét Duyệt Yêu Cầu Rút Tiền')

@section('style')
<style>
    .bank-info { background: #f8f9fa; padding: 15px; border-radius: 5px; border: 1px solid #dee2e6; margin-bottom: 20px; }
</style>
@endsection

@section('content')
<div class="container-fluid">
    <div class="page-header">
        <div class="row">
            <div class="col-sm-6">
                <div class="page-header-left">
                    <h3>Duyệt Yêu Cầu Rút Tiền</h3>
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
                    <h5>Danh sách yêu cầu rút tiền</h5>
                    <div>
                        <a href="{{ route('admin.withdrawals_web.index', ['status' => 'pending']) }}" class="btn btn-sm {{ $status == 'pending' ? 'btn-primary' : 'btn-outline-primary' }}">Chờ xử lý</a>
                        <a href="{{ route('admin.withdrawals_web.index', ['status' => 'approved']) }}" class="btn btn-sm {{ $status == 'approved' ? 'btn-success' : 'btn-outline-success' }}">Đã duyệt</a>
                        <a href="{{ route('admin.withdrawals_web.index', ['status' => 'rejected']) }}" class="btn btn-sm {{ $status == 'rejected' ? 'btn-danger' : 'btn-outline-danger' }}">Từ chối</a>
                    </div>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-bordered table-striped text-center">
                            <thead class="bg-light">
                                <tr>
                                    <th>Mã YC</th>
                                    <th>Chủ Trọ</th>
                                    <th>Số Tiền</th>
                                    <th>Ngân Hàng</th>
                                    <th>Số TK</th>
                                    <th>Tên Chủ TK</th>
                                    <th>Trạng Thái</th>
                                    <th>Thao Tác</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($withdrawals as $req)
                                    <tr>
                                        <td>#{{ $req->id }}</td>
                                        <td>
                                            @if($req->user)
                                                {{ $req->user->name }}<br>
                                                <small class="text-muted">{{ $req->user->phone }}</small>
                                            @else
                                                N/A
                                            @endif
                                        </td>
                                        <td class="font-weight-bold text-danger">{{ number_format($req->amount, 0, ',', '.') }} đ</td>
                                        <td>{{ $req->bank_code }}</td>
                                        <td class="font-weight-bold">{{ $req->bank_account_number }}</td>
                                        <td>{{ $req->bank_account_name }}</td>
                                        <td>
                                            @if($req->status == 'pending')
                                                <span class="badge badge-warning text-dark">Chờ xử lý</span>
                                            @elseif($req->status == 'approved')
                                                <span class="badge badge-success">Đã hoàn tất</span>
                                            @elseif($req->status == 'rejected')
                                                <span class="badge badge-danger">Bị từ chối</span>
                                            @endif
                                        </td>
                                        <td>
                                            @if($req->status == 'pending')
                                                <button class="btn btn-primary btn-sm btn-process" 
                                                    data-id="{{ $req->id }}"
                                                    data-amount="{{ number_format($req->amount, 0, ',', '.') }}"
                                                    data-bank="{{ $req->bank_code }}"
                                                    data-acc="{{ $req->bank_account_number }}"
                                                    data-name="{{ $req->bank_account_name }}">
                                                    <i class="fa fa-cogs"></i> Xử lý
                                                </button>
                                            @else
                                                <span class="text-muted"><i class="fa fa-lock"></i> Đã đóng</span>
                                            @endif
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="8" class="text-center text-muted">Không có yêu cầu rút tiền nào.</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                    <div class="mt-4 d-flex justify-content-end">
                        {{ $withdrawals->appends(['status' => $status])->links() }}
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Modal Xử lý Rút Tiền -->
<div class="modal fade" id="processModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Xử Lý Rút Tiền #<span id="modalWdId"></span></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="bank-info">
                    <p class="mb-1">Ngân hàng: <strong id="modalBank"></strong></p>
                    <p class="mb-1">Số TK: <strong id="modalAcc"></strong></p>
                    <p class="mb-1">Tên TK: <strong id="modalName"></strong></p>
                    <hr>
                    <p class="mb-0 text-danger font-weight-bold" style="font-size:1.2rem;">Số tiền: <span id="modalAmount"></span> đ</p>
                </div>

                <div class="alert alert-info small">
                    <i class="fa fa-info-circle"></i> Hãy thực hiện chuyển khoản cho chủ trọ qua ứng dụng ngân hàng, sau đó tải lên biên lai để hoàn tất.
                </div>

                <form id="frmApprove" enctype="multipart/form-data">
                    <div class="mb-3">
                        <label class="form-label">Tải lên biên lai chuyển khoản (Bắt buộc) <span class="text-danger">*</span></label>
                        <input type="file" name="proof_image" class="form-control" accept="image/png, image/jpeg, image/jpg" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Ghi chú (Không bắt buộc)</label>
                        <textarea name="notes" class="form-control" rows="2" placeholder="VD: Đã chuyển khoản qua MBBank..."></textarea>
                    </div>
                </form>
            </div>
            <div class="modal-footer justify-content-between">
                <button type="button" class="btn btn-outline-danger" id="btnReject">Từ chối (Hoàn tiền)</button>
                <div>
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Đóng</button>
                    <button type="submit" form="frmApprove" class="btn btn-success" id="btnSubmitApprove">Xác nhận chuyển khoản</button>
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

        let currentWdId = null;

        $('.btn-process').click(function () {
            currentWdId = $(this).data('id');
            $('#modalWdId').text(currentWdId);
            $('#modalBank').text($(this).data('bank'));
            $('#modalAcc').text($(this).data('acc'));
            $('#modalName').text($(this).data('name'));
            $('#modalAmount').text($(this).data('amount'));
            
            // Reset form
            $('#frmApprove')[0].reset();
            $('#processModal').modal('show');
        });

        // Submit Phê Duyệt (Dùng FormData vì có upload file)
        $('#frmApprove').on('submit', function (e) {
            e.preventDefault();
            let btn = $('#btnSubmitApprove');
            
            let formData = new FormData(this);
            
            btn.prop('disabled', true).html('<i class="fa fa-spinner fa-spin"></i> Đang tải lên...');
            
            let url = "{{ route('admin.withdrawals.approve', ':id') }}".replace(':id', currentWdId);

            $.ajax({
                url: url,
                type: 'POST',
                data: formData,
                contentType: false,
                processData: false,
                success: function (res) {
                    $('#processModal').modal('hide');
                    Swal.fire('Thành công!', res.message, 'success').then(() => {
                        window.location.reload();
                    });
                },
                error: function (xhr) {
                    btn.prop('disabled', false).html('Xác nhận chuyển khoản');
                    let msg = xhr.responseJSON?.message || 'Có lỗi xảy ra trong quá trình tải lên.';
                    if(xhr.status === 422 && xhr.responseJSON?.errors) {
                        msg = Object.values(xhr.responseJSON.errors).map(val => val[0]).join('<br>');
                    }
                    Swal.fire('Lỗi', msg, 'error');
                }
            });
        });

        // Xử lý Từ chối (Refund)
        $('#btnReject').click(function () {
            let btn = $(this);
            Swal.fire({
                title: 'Từ chối rút tiền?',
                text: 'Hệ thống sẽ hoàn lại số tiền vào số dư khả dụng của chủ trọ.',
                input: 'textarea',
                inputLabel: 'Lý do từ chối',
                inputPlaceholder: 'Nhập lý do từ chối (VD: Sai số tài khoản...)',
                showCancelButton: true,
                confirmButtonText: 'Từ chối & Hoàn tiền',
                cancelButtonText: 'Hủy',
                confirmButtonColor: '#d33',
                inputValidator: (value) => {
                    if (!value) {
                        return 'Bạn cần nhập lý do từ chối!'
                    }
                }
            }).then((result) => {
                if (result.isConfirmed) {
                    btn.prop('disabled', true).html('<i class="fa fa-spinner fa-spin"></i> Đang hoàn tiền...');
                    
                    let url = "{{ route('admin.withdrawals.reject', ':id') }}".replace(':id', currentWdId);
                    
                    $.ajax({
                        url: url,
                        type: 'POST',
                        data: { reason: result.value },
                        success: function (res) {
                            $('#processModal').modal('hide');
                            Swal.fire('Thành công!', res.message, 'success').then(() => {
                                window.location.reload();
                            });
                        },
                        error: function (xhr) {
                            btn.prop('disabled', false).html('Từ chối (Hoàn tiền)');
                            let msg = xhr.responseJSON?.message || 'Có lỗi xảy ra.';
                            Swal.fire('Lỗi', msg, 'error');
                        }
                    });
                }
            });
        });
    });
</script>
@endsection
