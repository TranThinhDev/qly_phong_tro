@extends('layouts.dashboard')

@section('title', 'Chi Tiết Hóa Đơn')

@section('style')
<style>
    .invoice-header { background-color: #f8f9fa; padding: 20px; border-radius: 8px; margin-bottom: 20px; border-left: 4px solid #007bff; }
    .status-badge { font-size: 14px; padding: 6px 12px; border-radius: 4px; }
    .badge-paid { background-color: #d4edda; color: #155724; }
    .badge-unpaid { background-color: #fff3cd; color: #856404; }
    .badge-overdue { background-color: #f8d7da; color: #721c24; }
    .badge-verified { background-color: #cce5ff; color: #004085; font-size: 12px; padding: 3px 8px; border-radius: 20px;}
    .qr-container { text-align: center; padding: 20px; border: 1px dashed #ccc; border-radius: 8px; background: #fafafa; }
    .qr-container img { max-width: 250px; }
</style>
@endsection

@section('content')
<div class="container-fluid mt-4">
    <div class="row">
        <!-- Chi tiết hóa đơn -->
        <div class="col-lg-8">
            <div class="card">
                <div class="card-body">
                    <div class="invoice-header d-flex justify-content-between align-items-center">
                        <div>
                            <h4>Hóa Đơn: <span class="text-primary">{{ $invoice->invoice_code }}</span></h4>
                            <p class="mb-0 text-muted">Kỳ thanh toán: {{ $invoice->billing_month }}</p>
                        </div>
                        <div>
                            @if($invoice->status == 'paid')
                                <span class="status-badge badge-paid"><i class="fa fa-check-circle"></i> Đã thanh toán</span>
                            @elseif($invoice->status == 'overdue')
                                <span class="status-badge badge-overdue"><i class="fa fa-exclamation-triangle"></i> Quá hạn</span>
                            @else
                                <span class="status-badge badge-unpaid">Chưa thanh toán</span>
                            @endif
                        </div>
                    </div>

                    <!-- Thông tin cơ bản -->
                    <div class="row mb-4">
                        <div class="col-md-6">
                            <p><strong>Ngày tạo:</strong> {{ $invoice->created_at->format('d/m/Y') }}</p>
                            <p><strong>Hạn chót:</strong> <span class="text-danger">{{ $invoice->due_date->format('d/m/Y') }}</span></p>
                        </div>
                        <div class="col-md-6 text-md-end">
                            <p><strong>Chủ phòng:</strong> 
                                {{ $invoice->contract->room->chutro->name ?? 'N/A' }}
                                @if(optional($invoice->contract->room->chutro)->isKycVerified())
                                    <span class="badge-verified ml-1"><i class="fa fa-check-circle"></i> Đã xác thực KYC</span>
                                @endif
                            </p>
                            <p><strong>Phòng:</strong> {{ $invoice->contract->room->name ?? 'N/A' }}</p>
                        </div>
                    </div>

                    <!-- Bảng chi tiết -->
                    <h5 class="mb-3">Chi tiết phí</h5>
                    <div class="table-responsive">
                        <table class="table table-bordered">
                            <thead class="bg-light">
                                <tr>
                                    <th>#</th>
                                    <th>Loại phí</th>
                                    <th>Mô tả</th>
                                    <th class="text-center">Số lượng</th>
                                    <th class="text-right">Đơn giá</th>
                                    <th class="text-right">Thành tiền</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($invoice->items as $index => $item)
                                <tr>
                                    <td>{{ $index + 1 }}</td>
                                    <td>
                                        @if($item->type == 'rent') Tiền thuê phòng
                                        @elseif($item->type == 'utility_electric') Tiền điện
                                        @elseif($item->type == 'utility_water') Tiền nước
                                        @elseif($item->type == 'service') Dịch vụ
                                        @elseif($item->type == 'late_fee') Phạt trễ hạn
                                        @else Khác @endif
                                    </td>
                                    <td>
                                        {{ $item->description }}
                                        <!-- Nếu là tiện ích có hình ảnh chỉ số -->
                                        @if(in_array($item->type, ['utility_electric', 'utility_water']) && $item->metadata && isset($item->metadata['evidence_image_url']))
                                            <br><a href="{{ asset('storage/' . $item->metadata['evidence_image_url']) }}" target="_blank" class="text-info" style="font-size: 12px;"><i class="fa fa-image"></i> Xem ảnh đồng hồ</a>
                                        @endif
                                    </td>
                                    <td class="text-center">{{ $item->quantity }}</td>
                                    <td class="text-right">{{ number_format($item->unit_price, 0, ',', '.') }} đ</td>
                                    <td class="text-right font-weight-bold">{{ number_format($item->total, 0, ',', '.') }} đ</td>
                                </tr>
                                @endforeach
                            </tbody>
                            <tfoot>
                                <tr>
                                    <td colspan="5" class="text-right font-weight-bold">Tổng cộng:</td>
                                    <td class="text-right font-weight-bold text-danger h5 mb-0">{{ number_format($invoice->total_amount, 0, ',', '.') }} đ</td>
                                </tr>
                                @if($invoice->status != 'unpaid')
                                <tr>
                                    <td colspan="5" class="text-right font-weight-bold">Đã thanh toán:</td>
                                    <td class="text-right font-weight-bold text-success">{{ number_format($invoice->totalPaid(), 0, ',', '.') }} đ</td>
                                </tr>
                                <tr>
                                    <td colspan="5" class="text-right font-weight-bold">Còn nợ:</td>
                                    <td class="text-right font-weight-bold text-danger">{{ number_format($invoice->remainingAmount(), 0, ',', '.') }} đ</td>
                                </tr>
                                @endif
                            </tfoot>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- Cột Thanh toán -->
        <div class="col-lg-4">
            <div class="card">
                <div class="card-header bg-primary text-white">
                    <h5 class="mb-0 text-white">Thanh Toán Hóa Đơn</h5>
                </div>
                <div class="card-body">
                    @if(in_array($invoice->status, ['unpaid', 'partial', 'overdue']))
                        
                        <!-- Thanh toán VNPay -->
                        <div class="mb-4 text-center">
                            <p class="text-muted small">Thanh toán an toàn và tiện lợi qua cổng VNPAY. Tiền sẽ được giữ trong hệ thống Escrow và chủ trọ chỉ nhận được sau 24h để đảm bảo quyền lợi cho bạn.</p>
                            <button type="button" class="btn btn-primary btn-lg w-100" id="btnPayVnpay">
                                <img src="https://vnpay.vn/s1/statics.vnpay.vn/2023/9/06ncktiwd6dc1694418196384.png" height="24" class="mr-2" alt="vnpay"> Thanh Toán VNPAY
                            </button>
                        </div>

                        <hr>

                        <!-- Chuyển khoản thủ công VietQR -->
                        <div class="qr-container mt-4">
                            <h6 class="mb-3">Hoặc quét mã QR chuyển khoản</h6>
                            <!-- Sử dụng ảnh placeholder VietQR, hệ thống thật sẽ gọi API VietQR với số TK của chủ trọ -->
                            @php
                                // Mock thông tin bank chủ trọ (Thực tế lấy từ wallet hoặc profile)
                                $bankId = 'mbbank';
                                $accountNo = '0123456789';
                                $accountName = 'CHU TRO';
                                $desc = urlencode($invoice->invoice_code);
                                $amount = (int) $invoice->remainingAmount();
                                $qrUrl = "https://img.vietqr.io/image/{$bankId}-{$accountNo}-compact2.png?amount={$amount}&addInfo={$desc}&accountName={$accountName}";
                            @endphp
                            <img src="{{ $qrUrl }}" alt="VietQR" class="img-fluid rounded shadow-sm">
                            <p class="mt-2 mb-0 small text-muted">Nội dung CK: <strong>{{ $invoice->invoice_code }}</strong></p>
                        </div>
                    @else
                        <div class="text-center p-4">
                            <i class="fa fa-check-circle text-success" style="font-size: 48px;"></i>
                            <h5 class="mt-3 text-success">Hóa đơn đã được thanh toán hoàn tất</h5>
                        </div>
                    @endif
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
        // Cấu hình CSRF Token cho tất cả Ajax requests
        $.ajaxSetup({
            headers: {
                'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content'),
                'Accept': 'application/json'
            }
        });

        $('#btnPayVnpay').click(function () {
            let btn = $(this);
            btn.prop('disabled', true).html('<i class="fa fa-spinner fa-spin"></i> Đang xử lý...');

            $.ajax({
                url: "{{ route('tenant.invoices.payment-url', $invoice->id) }}",
                type: 'GET',
                success: function (response) {
                    if (response.data && response.data.payment_url) {
                        // Chuyển hướng người dùng sang VNPAY
                        window.location.href = response.data.payment_url;
                    } else {
                        Swal.fire('Lỗi', 'Không nhận được đường dẫn thanh toán', 'error');
                        btn.prop('disabled', false).html('Thanh Toán VNPAY');
                    }
                },
                error: function (xhr) {
                    btn.prop('disabled', false).html('Thanh Toán VNPAY');
                    let msg = xhr.responseJSON ? xhr.responseJSON.message : 'Lỗi kết nối máy chủ';
                    Swal.fire('Thanh toán thất bại', msg, 'error');
                }
            });
        });
    });
</script>
@endsection
