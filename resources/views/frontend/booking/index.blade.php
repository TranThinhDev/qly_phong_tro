@extends('layouts.app')

@section('style')
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <style>
        /* ── Table styling ── */
        .booking-table-wrap {
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 2px 16px rgba(0, 0, 0, .07);
        }
        .booking-table-wrap .table thead th {
            background: #2c7be5;
            color: #fff;
            font-weight: 600;
            white-space: nowrap;
            border: none;
        }
        .booking-table-wrap .table tbody tr:hover {
            background-color: #f0f6ff;
        }

        /* ── Refund badge ── */
        .badge-refund {
            font-size: .78rem;
            font-weight: 600;
            padding: 5px 10px;
            border-radius: 20px;
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }
        .badge-requested  { background: #fff3cd; color: #856404; border: 1px solid #ffc107; }
        .badge-refunded   { background: #d1e7dd; color: #0a3622; border: 1px solid #198754; }
        .badge-rejected   { background: #f8d7da; color: #842029; border: 1px solid #dc3545; }

        /* ── Modal evidence preview ── */
        #evidence-preview {
            display: none;
            max-width: 100%;
            max-height: 180px;
            border-radius: 8px;
            object-fit: cover;
            margin-top: 8px;
            border: 1px solid #dee2e6;
        }
    </style>
@endsection

@section('content')
    {{-- ─────────────────────────────────────────────────────────────
         SECTION: Danh sách đơn đặt phòng
    ───────────────────────────────────────────────────────────────── --}}
    <section class="agent-section property-section">
        <div class="container">

            {{-- Page header --}}
            <div class="filter-panel mb-4">
                <div class="top-panel">
                    <h2>
                        <i class="fas fa-list-alt me-2 text-primary"></i>
                        Quản lý đơn đặt phòng
                    </h2>
                </div>
            </div>

            @if ($bookings->isEmpty())
                {{-- Empty state --}}
                <div class="text-center py-5">
                    <i class="fas fa-inbox fa-3x text-muted mb-3"></i>
                    <p class="text-muted">Bạn chưa có đơn đặt phòng nào.</p>
                    <a href="{{ route('home') }}" class="btn btn-primary btn-pill">
                        <i class="fas fa-search me-1"></i> Tìm phòng ngay
                    </a>
                </div>
            @else
                {{-- Table --}}
                <div class="booking-table-wrap">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Mã đặt phòng</th>
                                    <th>Phòng</th>
                                    <th>Tiền cọc</th>
                                    <th>Trạng thái</th>
                                    <th>Ngày đặt</th>
                                    <th class="text-center">Thao tác</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($bookings as $index => $booking)
                                    <tr>
                                        {{-- STT --}}
                                        <td class="text-muted small">{{ $index + 1 }}</td>

                                        {{-- Mã đơn --}}
                                        <td>
                                            <span class="fw-semibold text-primary">
                                                {{ $booking->booking_code ?? '#' . $booking->id }}
                                            </span>
                                        </td>

                                        {{-- Phòng --}}
                                        <td>
                                            @if ($booking->room)
                                                <a href="{{ route('Room_show', $booking->room->id) }}"
                                                   class="text-dark fw-semibold text-decoration-none">
                                                    {{ $booking->room->name }}
                                                </a>
                                                <div class="text-muted small">
                                                    <i class="fas fa-map-marker-alt me-1"></i>
                                                    {{ $booking->room->detail_address }}
                                                </div>
                                            @else
                                                <span class="text-muted">—</span>
                                            @endif
                                        </td>

                                        {{-- Tiền cọc --}}
                                        <td class="fw-bold text-danger">
                                            {{ $booking->deposit_amount
                                                ? number_format($booking->deposit_amount) . ' VNĐ'
                                                : '—' }}
                                        </td>

                                        {{-- Trạng thái booking --}}
                                        <td>
                                            @php
                                                $statusMap = [
                                                    'pending'   => ['label' => 'Chờ thanh toán', 'class' => 'bg-warning text-dark'],
                                                    'paid'      => ['label' => 'Đã thanh toán',  'class' => 'bg-success text-white'],
                                                    'cancelled' => ['label' => 'Đã huỷ',         'class' => 'bg-secondary text-white'],
                                                    'confirmed' => ['label' => 'Đã xác nhận',    'class' => 'bg-info text-dark'],
                                                ];
                                                $st = $statusMap[$booking->status] ?? ['label' => $booking->status, 'class' => 'bg-light text-dark'];
                                            @endphp
                                            <span class="badge {{ $st['class'] }} rounded-pill px-3 py-2">
                                                {{ $st['label'] }}
                                            </span>
                                        </td>

                                        {{-- Ngày đặt --}}
                                        <td class="text-muted small">
                                            {{ $booking->created_at->format('d/m/Y H:i') }}
                                        </td>

                                        {{-- ══════════════════════════════════════════════════
                                             CỘT THAO TÁC — Logic hoàn tiền
                                        ═══════════════════════════════════════════════════ --}}
                                        <td class="text-center">

                                            @if (
                                                $booking->status === 'paid' &&
                                                is_null($booking->refund_status) &&
                                                $booking->created_at->diffInHours(now()) < 48
                                            )
                                                {{-- ① Đủ điều kiện: hiển thị nút mở modal hoàn tiền --}}
                                                <button
                                                    type="button"
                                                    class="btn btn-sm btn-outline-danger fw-semibold"
                                                    data-bs-toggle="modal"
                                                    data-bs-target="#refundModal-{{ $booking->id }}"
                                                    title="Yêu cầu hoàn tiền trong vòng 48h">
                                                    <i class="fas fa-undo-alt me-1"></i>
                                                    Yêu cầu hoàn tiền
                                                </button>

                                            @elseif ($booking->refund_status === 'requested')
                                                {{-- ② Đang chờ xử lý --}}
                                                <span class="badge-refund badge-requested">
                                                    <i class="fas fa-clock"></i> Đang xử lý
                                                </span>

                                            @elseif ($booking->refund_status === 'refunded')
                                                {{-- ③ Đã hoàn tiền --}}
                                                <span class="badge-refund badge-refunded">
                                                    <i class="fas fa-check-circle"></i> Đã hoàn tiền
                                                </span>

                                            @elseif ($booking->refund_status === 'rejected')
                                                {{-- ④ Bị từ chối --}}
                                                <span class="badge-refund badge-rejected">
                                                    <i class="fas fa-times-circle"></i> Bị từ chối
                                                </span>

                                            @else
                                                {{-- ⑤ Không đủ điều kiện / đã quá 48h --}}
                                                <span class="text-muted small">—</span>

                                            @endif

                                        </td>
                                        {{-- ══════════════════════════════════════════════════ --}}

                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>

                {{-- Pagination --}}
                @if ($bookings->hasPages())
                    <div class="d-flex justify-content-center mt-4">
                        {{ $bookings->links() }}
                    </div>
                @endif

            @endif
        </div>
    </section>
@endsection

{{-- ─────────────────────────────────────────────────────────────
     SECTION: Modal hoàn tiền — render động theo từng booking
     (chỉ render cho các booking đủ điều kiện)
───────────────────────────────────────────────────────────────── --}}
@section('modal')

    @foreach ($bookings as $booking)
        @if (
            $booking->status === 'paid' &&
            is_null($booking->refund_status) &&
            $booking->created_at->diffInHours(now()) < 48
        )
            {{-- Modal ID động: refundModal-{booking_id} --}}
            <div class="modal fade"
                 id="refundModal-{{ $booking->id }}"
                 tabindex="-1"
                 aria-labelledby="refundModalLabel-{{ $booking->id }}"
                 aria-hidden="true">

                <div class="modal-dialog modal-dialog-centered">
                    <div class="modal-content">

                        {{-- Header --}}
                        <div class="modal-header border-0 pb-0">
                            <h5 class="modal-title fw-bold text-danger"
                                id="refundModalLabel-{{ $booking->id }}">
                                <i class="fas fa-undo-alt me-2"></i>
                                Yêu cầu hoàn tiền
                            </h5>
                            <button type="button"
                                    class="btn-close"
                                    data-bs-dismiss="modal"
                                    aria-label="Close"></button>
                        </div>

                        {{-- Sub-header info --}}
                        <div class="px-4 pt-2 pb-0">
                            <p class="text-muted small mb-0">
                                Mã đơn:
                                <strong class="text-dark">
                                    {{ $booking->booking_code ?? '#' . $booking->id }}
                                </strong>
                                &nbsp;|&nbsp;
                                Tiền cọc:
                                <strong class="text-danger">
                                    {{ number_format($booking->deposit_amount) }} VNĐ
                                </strong>
                            </p>
                            <p class="text-warning small mt-1 mb-0">
                                <i class="fas fa-exclamation-triangle me-1"></i>
                                Thời hạn yêu cầu trong 48 giờ kể từ khi đặt phòng.
                            </p>
                        </div>

                        {{-- ── Form hoàn tiền ─────────────────────────────────── --}}
                        {{--
                            enctype="multipart/form-data" bắt buộc để upload evidence_image.
                            Form submit tới web route (không phải API route) để Laravel
                            có thể xử lý file upload và session flash message tiện hơn.
                        --}}
                        <form method="POST"
                              action="{{ route('booking.refund.request') }}"
                              enctype="multipart/form-data"
                              id="refundForm-{{ $booking->id }}">
                            @csrf
                            {{-- Truyền booking_id --}}
                            <input type="hidden" name="booking_id" value="{{ $booking->id }}">

                            <div class="modal-body pt-3">

                                {{-- Lý do hoàn tiền --}}
                                <div class="mb-3">
                                    <label for="reason-{{ $booking->id }}" class="form-label fw-semibold">
                                        <i class="fas fa-comment-alt me-1 text-secondary"></i>
                                        Lý do yêu cầu hoàn tiền
                                        <span class="text-danger">*</span>
                                    </label>
                                    <textarea
                                        class="form-control @error('reason') is-invalid @enderror"
                                        id="reason-{{ $booking->id }}"
                                        name="reason"
                                        rows="4"
                                        maxlength="500"
                                        placeholder="Mô tả chi tiết lý do bạn muốn hoàn tiền đặt cọc..."
                                        required>{{ old('reason') }}</textarea>
                                    <div class="d-flex justify-content-between mt-1">
                                        @error('reason')
                                            <div class="invalid-feedback d-block">{{ $message }}</div>
                                        @enderror
                                        <small class="text-muted ms-auto">Tối đa 500 ký tự</small>
                                    </div>
                                </div>

                                {{-- Ảnh bằng chứng --}}
                                <div class="mb-2">
                                    <label for="evidence_image-{{ $booking->id }}" class="form-label fw-semibold">
                                        <i class="fas fa-image me-1 text-secondary"></i>
                                        Ảnh bằng chứng
                                        <span class="text-muted fw-normal small">(không bắt buộc)</span>
                                    </label>
                                    <input
                                        type="file"
                                        class="form-control @error('evidence_image') is-invalid @enderror"
                                        id="evidence_image-{{ $booking->id }}"
                                        name="evidence_image"
                                        accept="image/*"
                                        data-preview="evidence-preview-{{ $booking->id }}">
                                    @error('evidence_image')
                                        <div class="invalid-feedback">{{ $message }}</div>
                                    @enderror
                                    {{-- Preview ảnh khi chọn file --}}
                                    <img id="evidence-preview-{{ $booking->id }}"
                                         src="#"
                                         alt="Xem trước ảnh bằng chứng"
                                         class="evidence-preview"
                                         style="display:none; max-width:100%; max-height:180px;
                                                border-radius:8px; object-fit:cover;
                                                margin-top:8px; border:1px solid #dee2e6;">
                                </div>

                            </div>

                            {{-- Footer --}}
                            <div class="modal-footer border-0 pt-0">
                                <button type="button"
                                        class="btn btn-secondary"
                                        data-bs-dismiss="modal">
                                    Huỷ
                                </button>
                                <button type="submit"
                                        class="btn btn-danger fw-semibold"
                                        id="btn-refund-submit-{{ $booking->id }}">
                                    <i class="fas fa-paper-plane me-1"></i>
                                    Gửi yêu cầu hoàn tiền
                                </button>
                            </div>

                        </form>
                        {{-- ── End Form ──────────────────────────────────────── --}}

                    </div>
                </div>
            </div>
            {{-- End Modal refundModal-{{ $booking->id }} --}}

        @endif
    @endforeach

@endsection

@section('js')
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>

    {{-- Toast các lỗi validation từ server (nếu có) --}}
    @error('reason')
        <script>
            makeToast("{{ $message }}", "red");
        </script>
    @enderror
    @error('evidence_image')
        <script>
            makeToast("{{ $message }}", "red");
        </script>
    @enderror

    @if (session('error'))
        <script>
            makeToast("{{ session('error') }}", "red");
        </script>
    @endif

    {{-- ── Hiển thị kết quả VNPay Sandbox sau khi thanh toán xong ── --}}
    @if (session('vnpay_success'))
    <script>
        $(document).ready(function () {
            Swal.fire({
                icon: 'success',
                title: 'Đặt cọc thành công! 🎉',
                html: '<div class="text-start">' +
                      '<p class="mb-1"><i class="fas fa-hashtag text-primary me-1"></i> Mã đơn: <strong>{{ session('vnpay_booking_code') }}</strong></p>' +
                      '<p class="mb-1"><i class="fas fa-money-bill text-success me-1"></i> Số tiền: <strong>{{ number_format(session('vnpay_amount')) }} VNĐ</strong></p>' +
                      '<p class="mb-0"><i class="fas fa-university text-info me-1"></i> Ngân hàng: <strong>{{ session('vnpay_bank') }}</strong></p>' +
                      '</div>',
                confirmButtonText: 'Hoàn tất',
                confirmButtonColor: '#198754',
                allowOutsideClick: false,
            });
        });
    </script>
    @endif

    <script>
        $(document).ready(function () {

            // ── 1. Preview ảnh bằng chứng khi user chọn file ─────────────────
            //    Dùng event delegation để xử lý tất cả input[type=file] trong vòng lặp
            $(document).on('change', 'input[type="file"][data-preview]', function () {
                var previewId = $(this).data('preview');
                var $preview  = $('#' + previewId);
                var file      = this.files[0];

                if (file && file.type.startsWith('image/')) {
                    var reader = new FileReader();
                    reader.onload = function (e) {
                        $preview.attr('src', e.target.result).show();
                    };
                    reader.readAsDataURL(file);
                } else {
                    $preview.hide().attr('src', '#');
                }
            });

            // ── 2. Client-side validation form hoàn tiền trước khi submit ────
            $(document).on('submit', 'form[id^="refundForm-"]', function (e) {
                var $textarea = $(this).find('textarea[name="reason"]');
                var reason    = $.trim($textarea.val());

                if (reason.length < 10) {
                    e.preventDefault();
                    makeToast('Vui lòng nhập lý do hoàn tiền ít nhất 10 ký tự.', 'orange');
                    $textarea.focus();
                    return false;
                }
            });

            // ── 3. Mở lại modal nếu có lỗi validation từ server ─────────────
            @if ($errors->any() && old('booking_id'))
                var bookingId = "{{ old('booking_id') }}";
                var $modal    = $('#refundModal-' + bookingId);
                if ($modal.length) {
                    var bsModal = new bootstrap.Modal($modal[0]);
                    bsModal.show();
                }
            @endif

        });
    </script>

@endsection
