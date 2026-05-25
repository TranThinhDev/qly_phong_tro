@extends('layouts.app')

@section('style')
    {{-- SweetAlert2 CSS --}}
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <style>
        /* ── Countdown badge ── */
        .checkout-timer-wrap {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .countdown-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: #fff3cd;
            border: 1.5px solid #ffc107;
            border-radius: 8px;
            padding: 6px 14px;
            font-size: 1.1rem;
            font-weight: 700;
            color: #856404;
            transition: background 0.3s, color 0.3s, border-color 0.3s;
        }
        .countdown-badge.danger {
            background: #f8d7da;
            border-color: #dc3545;
            color: #842029;
            animation: pulse-danger 1s ease-in-out infinite;
        }
        @keyframes pulse-danger {
            0%, 100% { transform: scale(1); }
            50%       { transform: scale(1.04); }
        }
        /* ── Summary card ── */
        .checkout-summary-card {
            border-radius: 12px;
            border: 1px solid #e2e8f0;
            box-shadow: 0 2px 16px rgba(0,0,0,.06);
        }
        .checkout-summary-card .card-header {
            background: linear-gradient(135deg, #2c7be5, #1a56db);
            color: #fff;
            border-radius: 12px 12px 0 0;
            padding: 16px 20px;
        }
        /* ── Pay button ── */
        #btn-pay {
            transition: opacity .25s, transform .1s;
        }
        #btn-pay:disabled {
            opacity: .55;
            cursor: not-allowed;
        }
        #btn-pay:not(:disabled):hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 14px rgba(220,53,69,.35);
        }
    </style>
@endsection

@section('content')
    <section class="agent-section property-section">
        <div class="container">
            <div class="row justify-content-center">
                <div class="col-xl-6 col-lg-8 col-md-10">

                    <div class="checkout-summary-card card mb-4">
                        {{-- Header --}}
                        <div class="card-header d-flex align-items-center justify-content-between flex-wrap gap-2">
                            <h5 class="mb-0">
                                <i class="fas fa-shopping-cart me-2"></i>Xác nhận đặt cọc
                            </h5>
                            {{-- ── Countdown ── --}}
                            <div class="checkout-timer-wrap">
                                <i class="fas fa-hourglass-half text-warning"></i>
                                <span id="countdown-label" class="text-white-50 small">Thời gian giữ chỗ:</span>
                                <span class="countdown-badge" id="countdown-badge">
                                    <span id="countdown">{{ gmdate('i:s', $timeLeftInSeconds) }}</span>
                                </span>
                            </div>
                        </div>

                        {{-- Body --}}
                        <div class="card-body p-4">

                            {{-- Thông tin phòng --}}
                            <div class="d-flex align-items-center gap-3 mb-4 pb-3 border-bottom">
                                @if(isset($room) && $room->main_img)
                                    <img src="{{ asset('images/main_room/' . $room->main_img) }}"
                                         alt="{{ $room->name ?? '' }}"
                                         class="rounded"
                                         style="width:72px;height:72px;object-fit:cover;">
                                @endif
                                <div>
                                    <h6 class="mb-1 fw-bold">{{ $room->name ?? '—' }}</h6>
                                    <p class="mb-0 text-muted small">
                                        <i class="fas fa-map-marker-alt me-1"></i>
                                        {{ $room->detail_address ?? '' }}
                                    </p>
                                </div>
                            </div>

                            {{-- Chi tiết thanh toán --}}
                            <table class="table table-borderless mb-0">
                                <tbody>
                                    <tr>
                                        <td class="text-muted ps-0">Giá thuê / tháng</td>
                                        <td class="text-end fw-semibold">
                                            {{ number_format($room->price ?? 0) }} đ
                                        </td>
                                    </tr>
                                    <tr>
                                        <td class="text-muted ps-0">Tiền đặt cọc</td>
                                        <td class="text-end fw-bold text-danger fs-5">
                                            {{ number_format($room->deposit_amount ?? 0) }} VNĐ
                                        </td>
                                    </tr>
                                </tbody>
                            </table>

                            <div class="alert alert-warning mt-3 mb-0 py-2 small">
                                <i class="fas fa-info-circle me-1"></i>
                                Tiền đặt cọc sẽ được thanh toán qua <strong>VNPAY</strong>.
                                Vui lòng hoàn tất trước khi hết thời gian giữ chỗ.
                            </div>
                        </div>

                        {{-- Footer: form thanh toán --}}
                        <div class="card-footer bg-transparent p-4 pt-0">
                            <form method="POST" action="{{ route('vnpay.payment') }}" id="form-checkout">
                                @csrf
                                <input type="hidden" name="room_id" value="{{ $room->id ?? '' }}">
                                <input type="hidden" name="booking_id" value="{{ $booking->id ?? '' }}">

                                <button type="submit"
                                        class="btn btn-danger w-100 fw-bold py-3"
                                        id="btn-pay">
                                    <i class="fas fa-credit-card me-2"></i>
                                    Thanh toán qua VNPAY &nbsp;
                                    <span class="fw-normal small">
                                        ({{ number_format($room->deposit_amount ?? 0) }} VNĐ)
                                    </span>
                                </button>
                            </form>
                        </div>
                    </div>

                    {{-- Nút quay lại --}}
                    <div class="text-center">
                        <a href="{{ route('home') }}" class="text-muted small">
                            <i class="fas fa-arrow-left me-1"></i>Quay về trang chủ
                        </a>
                    </div>

                </div>
            </div>
        </div>
    </section>
@endsection

@section('js')
    {{-- SweetAlert2 JS --}}
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>

    <script>
        $(document).ready(function () {

            // ── 1. Nhận giá trị từ Blade ──────────────────────────────────────────
            var timeLeft = parseInt("{{ $timeLeftInSeconds }}", 10);
            var $badge   = $('#countdown-badge');
            var $display = $('#countdown');
            var $btnPay  = $('#btn-pay');

            // ── 2. Hàm format số giây → MM:SS ────────────────────────────────────
            function formatTime(seconds) {
                var m = Math.floor(seconds / 60);
                var s = seconds % 60;
                return String(m).padStart(2, '0') + ':' + String(s).padStart(2, '0');
            }

            // ── 3. Guard: xử lý trường hợp trang được render sau khi hết giờ ─────
            // Kịch bản: user mở tab → bỏ đó 15 phút → quay lại (server đã hết hold)
            // hoặc cronjob đã nhả phòng trước khi user load trang checkout.
            if (timeLeft <= 0) {
                // a) Hiển thị 00:00 và badge đỏ ngay lập tức
                $display.text('00:00');
                $badge.addClass('danger');

                // b) Disable nút thanh toán — không cho submit form
                $btnPay.prop('disabled', true);

                // c) Hiển thị thông báo hết giờ ngay khi trang load xong
                Swal.fire({
                    icon: 'warning',
                    title: 'Đã hết thời gian giữ phòng',
                    text: 'Phiên giữ chỗ đã hết hạn. Vui lòng quay lại và thực hiện đặt phòng lại từ đầu.',
                    confirmButtonText: 'Về trang chủ',
                    confirmButtonColor: '#2c7be5',
                    allowOutsideClick: false,
                    allowEscapeKey: false,
                }).then(function (result) {
                    if (result.isConfirmed) {
                        window.location.href = "{{ route('home') }}";
                    }
                });

                // d) Dừng sớm — không khởi động timer
                return;
            }

            // ── 4. Render giá trị ban đầu ngay lập tức (tránh giật khi setInterval chưa tick)
            $display.text(formatTime(timeLeft));

            // ── 5. setInterval đếm ngược mỗi giây ────────────────────────────────
            var timer = setInterval(function () {

                timeLeft--;

                // Hiển thị MM:SS
                $display.text(formatTime(timeLeft));

                // Đổi badge sang màu đỏ khi ≤ 60 giây còn lại
                if (timeLeft <= 60) {
                    $badge.addClass('danger');
                }

                // ── 6. Xử lý khi đếm về 0 ───────────────────────────────────────
                if (timeLeft <= 0) {

                    // a) Dừng timer
                    clearInterval(timer);

                    // b) Cập nhật hiển thị về 00:00
                    $display.text('00:00');

                    // c) Disable nút thanh toán
                    $btnPay.prop('disabled', true);

                    // d) Hiển thị SweetAlert2 thông báo hết giờ
                    Swal.fire({
                        icon: 'warning',
                        title: 'Hết thời gian giữ chỗ',
                        text: 'Phòng đã được trả lại. Vui lòng thực hiện lại thao tác đặt phòng.',
                        confirmButtonText: 'OK',
                        confirmButtonColor: '#2c7be5',
                        allowOutsideClick: false,
                        allowEscapeKey: false,
                    }).then(function (result) {
                        // e) Khi user bấm OK → redirect về trang chủ
                        if (result.isConfirmed) {
                            window.location.href = "{{ route('home') }}";
                        }
                    });
                }

            }, 1000); // mỗi 1000 ms = 1 giây

        });
    </script>
@endsection

