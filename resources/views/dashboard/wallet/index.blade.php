@extends('layouts.dashboard')

@section('title', 'Ví Tiền Của Tôi')

@section('style')
<style>
    .wallet-card { border-radius: 10px; color: white; padding: 25px; margin-bottom: 30px; box-shadow: 0 4px 15px rgba(0,0,0,0.1); }
    .bg-available { background: linear-gradient(45deg, #28a745, #20c997); }
    .bg-pending { background: linear-gradient(45deg, #ffc107, #fd7e14); }
    .wallet-title { font-size: 16px; font-weight: 600; text-transform: uppercase; margin-bottom: 10px; opacity: 0.9; }
    .wallet-amount { font-size: 32px; font-weight: bold; margin-bottom: 0; }
    .txn-type { font-weight: 500; }
    .text-credit { color: #28a745; font-weight: bold; }
    .text-debit { color: #dc3545; font-weight: bold; }
</style>
@endsection

@section('content')
<div class="container-fluid">
    <div class="page-header">
        <div class="row">
            <div class="col-sm-6">
                <div class="page-header-left">
                    <h3>Ví Tiền Của Tôi</h3>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="container-fluid">
    <!-- Thẻ số dư -->
    <div class="row">
        <!-- Khả dụng -->
        <div class="col-md-6">
            <div class="wallet-card bg-available position-relative">
                <div class="wallet-title">Tiền Khả Dụng</div>
                <h3 class="wallet-amount">{{ number_format($wallet->available_balance, 0, ',', '.') }} đ</h3>
                <p class="mt-2 mb-0 opacity-75 small">Có thể rút về tài khoản ngân hàng ngay lập tức.</p>
                <button type="button" class="btn btn-light position-absolute" style="top: 25px; right: 25px;" data-bs-toggle="modal" data-bs-target="#withdrawModal">
                    <i class="fa fa-money"></i> Rút Tiền
                </button>
            </div>
        </div>

        <!-- Đang giam (Escrow) -->
        <div class="col-md-6">
            <div class="wallet-card bg-pending">
                <div class="wallet-title">Tiền Đang Giam (Pending)</div>
                <h3 class="wallet-amount">{{ number_format($wallet->pending_balance, 0, ',', '.') }} đ</h3>
                <p class="mt-2 mb-0 text-dark opacity-75 small">Tiền cọc hoặc thanh toán chờ xử lý/hoàn tất (Tự động giải phóng sau 24h).</p>
            </div>
        </div>
    </div>

    <!-- Lịch sử giao dịch -->
    <div class="row">
        <div class="col-sm-12">
            <div class="card">
                <div class="card-header">
                    <h5>Lịch Sử Giao Dịch</h5>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-bordered table-striped text-center">
                            <thead class="bg-light">
                                <tr>
                                    <th>Mã GD</th>
                                    <th>Thời Gian</th>
                                    <th>Loại Giao Dịch</th>
                                    <th>Diễn Giải</th>
                                    <th class="text-right">Số Tiền</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($transactions as $txn)
                                    <tr>
                                        <td>#{{ $txn->id }}</td>
                                        <td>{{ $txn->created_at->format('d/m/Y H:i') }}</td>
                                        <td>
                                            @if($txn->type == 'top_up') <span class="badge badge-success">Nạp Tiền</span>
                                            @elseif($txn->type == 'deposit_escrow') <span class="badge badge-warning text-dark">Tiền Vào Escrow</span>
                                            @elseif($txn->type == 'release_fund') <span class="badge badge-info">Giải Phóng Escrow</span>
                                            @elseif($txn->type == 'refund_tenant') <span class="badge badge-danger">Hoàn Tiền Khách</span>
                                            @elseif($txn->type == 'pay_invoice') <span class="badge badge-secondary">Thanh Toán Hóa Đơn</span>
                                            @elseif($txn->type == 'withdrawal') <span class="badge badge-dark">Rút Tiền</span>
                                            @else <span class="badge badge-light text-dark">{{ $txn->type }}</span>
                                            @endif
                                        </td>
                                        <td class="text-left">{{ $txn->description }}</td>
                                        <td class="text-right">
                                            @if($txn->isCredit())
                                                <span class="text-credit">+ {{ number_format($txn->amount, 0, ',', '.') }} đ</span>
                                            @else
                                                <span class="text-debit">- {{ number_format($txn->amount, 0, ',', '.') }} đ</span>
                                            @endif
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="5" class="text-center text-muted">Chưa có giao dịch nào phát sinh.</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                    
                    <div class="mt-4 d-flex justify-content-end">
                        {{ $transactions->links() }}
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Modal Rút Tiền -->
<div class="modal fade" id="withdrawModal" tabindex="-1" aria-labelledby="withdrawModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <form id="frmWithdraw">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="withdrawModalLabel">Rút Tiền Khả Dụng</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p class="text-muted small mb-4">Số dư khả dụng hiện tại: <strong class="text-success">{{ number_format($wallet->available_balance, 0, ',', '.') }} đ</strong></p>
                    
                    <div class="mb-3">
                        <label class="form-label">Số Tiền Muốn Rút (VNĐ)</label>
                        <input type="number" name="amount" class="form-control" placeholder="Tối thiểu 50.000đ" min="50000" max="{{ $wallet->available_balance }}" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Ngân Hàng</label>
                        <select name="bank_code" class="form-select form-control" required>
                            <option value="">-- Chọn Ngân Hàng --</option>
                            <option value="VIETCOMBANK">Vietcombank</option>
                            <option value="TECHCOMBANK">Techcombank</option>
                            <option value="MBBANK">MB Bank</option>
                            <option value="ACB">ACB</option>
                            <option value="VIETINBANK">Vietinbank</option>
                            <option value="BIDV">BIDV</option>
                            <option value="AGRIBANK">Agribank</option>
                            <option value="VPBANK">VPBank</option>
                            <option value="TPBANK">TPBank</option>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Số Tài Khoản</label>
                        <input type="text" name="bank_account_number" class="form-control" placeholder="Nhập số tài khoản ngân hàng" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Tên Chủ Tài Khoản</label>
                        <input type="text" name="bank_account_name" class="form-control" placeholder="NGUYEN VAN A" style="text-transform: uppercase;" required>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Đóng</button>
                    <button type="submit" class="btn btn-success" id="btnSubmitWithdraw">Xác Nhận Rút Tiền</button>
                </div>
            </div>
        </form>
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

        $('#frmWithdraw').on('submit', function (e) {
            e.preventDefault();
            
            let btn = $('#btnSubmitWithdraw');
            let originalText = btn.html();
            let amountInput = $('input[name="amount"]').val();

            // Client-side validation
            if(amountInput < 50000) {
                Swal.fire('Lỗi', 'Số tiền rút tối thiểu là 50,000đ', 'error');
                return;
            }

            btn.prop('disabled', true).html('<i class="fa fa-spinner fa-spin"></i> Đang xử lý...');

            $.ajax({
                url: "{{ route('landlord.withdraw') }}",
                type: "POST",
                data: $(this).serialize(),
                success: function (res) {
                    $('#withdrawModal').modal('hide');
                    Swal.fire({
                        title: 'Thành công!',
                        text: res.message,
                        icon: 'success',
                        confirmButtonText: 'Đóng'
                    }).then((result) => {
                        window.location.reload();
                    });
                },
                error: function (xhr) {
                    btn.prop('disabled', false).html(originalText);
                    
                    let message = 'Có lỗi xảy ra, vui lòng thử lại.';
                    if(xhr.status === 422) {
                        // Validation errors
                        if(xhr.responseJSON && xhr.responseJSON.errors) {
                            let errors = Object.values(xhr.responseJSON.errors).map(val => val[0]).join('<br>');
                            message = errors;
                        } else if(xhr.responseJSON && xhr.responseJSON.message) {
                            message = xhr.responseJSON.message;
                        }
                    } else if(xhr.status === 403) {
                        message = xhr.responseJSON.message || 'Bạn chưa được phép rút tiền.';
                    } else if(xhr.responseJSON && xhr.responseJSON.message) {
                        message = xhr.responseJSON.message;
                    }

                    Swal.fire({
                        title: 'Lỗi',
                        html: message,
                        icon: 'error'
                    });
                }
            });
        });
    });
</script>
@endsection
