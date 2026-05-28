@extends('layouts.app')

@section('style')
    <style>
        /* ── Tone & Theme chung (Cao cấp, Hiện đại) ── */
        .contract-signing-section {
            background-color: #f8fafc;
            padding: 40px 0;
            min-height: calc(100vh - 120px);
        }

        /* ── Trình xem PDF (Bên trái) ── */
        .pdf-viewer-card {
            border: none;
            border-radius: 16px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.05);
            background: #ffffff;
            overflow: hidden;
            display: flex;
            flex-direction: column;
            height: 720px; /* Chiều cao cố định phù hợp cho trải nghiệm đọc */
        }
        .pdf-viewer-header {
            background: #ffffff;
            border-bottom: 1px solid #edf2f7;
            padding: 16px 24px;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .pdf-iframe-container {
            flex-grow: 1;
            position: relative;
            background: #e2e8f0;
        }
        .pdf-iframe {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            border: none;
        }

        /* ── Bảng tóm tắt & Khối ký kết (Bên phải) ── */
        .summary-card {
            border: none;
            border-radius: 16px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.05);
            background: #ffffff;
            position: sticky;
            top: 20px;
        }
        .summary-header {
            background: linear-gradient(135deg, #2563eb, #1d4ed8);
            color: #ffffff;
            border-radius: 16px 16px 0 0;
            padding: 20px 24px;
        }
        .summary-title {
            font-size: 1.15rem;
            font-weight: 700;
            letter-spacing: 0.5px;
            margin: 0;
        }
        .contract-badge {
            font-size: 0.75rem;
            padding: 6px 12px;
            border-radius: 9999px;
            background: rgba(255, 255, 255, 0.2);
            color: #ffffff;
            font-weight: 600;
            letter-spacing: 0.5px;
        }
        .summary-body {
            padding: 24px;
        }
        
        /* Layout Grid của bảng tóm tắt */
        .info-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 16px;
            margin-bottom: 24px;
        }
        .info-item {
            background: #f8fafc;
            border: 1px solid #f1f5f9;
            border-radius: 12px;
            padding: 14px 18px;
            transition: all 0.2s ease-in-out;
        }
        .info-item:hover {
            transform: translateY(-2px);
            border-color: #cbd5e1;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.02);
        }
        .info-label {
            font-size: 0.8rem;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 4px;
            font-weight: 600;
        }
        .info-value {
            font-size: 1.2rem;
            font-weight: 700;
            color: #0f172a;
        }
        .info-value.highlight-rent {
            color: #2563eb;
        }
        .info-value.highlight-deposit {
            color: #ef4444;
        }

        /* ── Clickwrap Area ── */
        .clickwrap-box {
            background: #fffbeb;
            border: 1px solid #fef3c7;
            border-radius: 12px;
            padding: 16px;
            margin-bottom: 24px;
        }
        .clickwrap-checkbox-label {
            font-size: 0.875rem;
            color: #92400e;
            line-height: 1.6;
            cursor: pointer;
            user-select: none;
            display: flex;
            gap: 12px;
            align-items: flex-start;
        }
        .clickwrap-checkbox-label input[type="checkbox"] {
            margin-top: 4px;
            width: 18px;
            height: 18px;
            cursor: pointer;
            accent-color: #d97706; /* Màu cam vàng hài hòa */
        }
        .clickwrap-details {
            font-size: 0.75rem;
            color: #b45309;
            margin-top: 8px;
            display: block;
            border-top: 1px dashed rgba(217, 119, 6, 0.2);
            padding-top: 8px;
        }

        /* ── Nút Ký & Trạng thái Loading ── */
        .btn-sign {
            padding: 16px;
            font-weight: 700;
            font-size: 1.05rem;
            border-radius: 12px;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            box-shadow: 0 4px 14px rgba(37, 99, 235, 0.2);
        }
        .btn-sign:not(:disabled):hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(37, 99, 235, 0.35);
        }
        .btn-sign:disabled {
            background-color: #cbd5e1 !important;
            border-color: #cbd5e1 !important;
            color: #64748b !important;
            box-shadow: none !important;
            cursor: not-allowed;
        }

        /* ── Loading Overlay Toàn màn hình ── */
        .loading-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(15, 23, 42, 0.85); /* Glassmorphic dark overlay */
            backdrop-filter: blur(8px);
            z-index: 99999;
            display: none;
            align-items: center;
            justify-content: center;
            color: #ffffff;
            flex-direction: column;
            text-align: center;
        }
        .loading-spinner {
            width: 60px;
            height: 60px;
            border: 5px solid rgba(255, 255, 255, 0.1);
            border-top: 5px solid #3b82f6;
            border-radius: 50%;
            animation: spin 1s linear infinite;
            margin-bottom: 20px;
        }
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        .loading-text {
            font-size: 1.25rem;
            font-weight: 700;
            margin-bottom: 8px;
        }
        .loading-subtext {
            font-size: 0.9rem;
            color: #94a3b8;
        }

        /* Responsive */
        @media (max-width: 991.98px) {
            .pdf-viewer-card {
                height: 500px;
                margin-bottom: 24px;
            }
            .summary-card {
                position: static;
            }
        }
    </style>
@endsection

@section('content')
    <section class="contract-signing-section">
        <div class="container">
            <!-- Đường dẫn Breadcrumb & Header -->
            <div class="row mb-4">
                <div class="col-12">
                    <div class="d-flex align-items-center justify-content-between flex-wrap gap-3">
                        <div>
                            <span class="text-muted small">MÃ HỢP ĐỒNG: {{ $contract->contract_code }}</span>
                            <h2 class="fw-extrabold text-slate-900 mt-1 mb-0">
                                Ký Hợp Đồng Điện Tử & Đặt Cọc
                            </h2>
                        </div>
                        <a href="{{ route('home') }}" class="btn btn-outline-secondary btn-sm rounded-pill px-3">
                            <i class="fas fa-arrow-left me-1"></i> Quay về trang chủ
                        </a>
                    </div>
                </div>
            </div>

            <!-- Layout chính: Chia 2 phần -->
            <div class="row">
                <!-- Cột trái: PDF Iframe Viewer -->
                <div class="col-lg-7">
                    <div class="pdf-viewer-card card">
                        <div class="pdf-viewer-header">
                            <div class="d-flex align-items-center gap-2">
                                <i class="fas fa-file-pdf text-danger fs-4"></i>
                                <span class="fw-bold text-slate-800">Tải tài liệu PDF hợp đồng chính thức</span>
                            </div>
                            <a href="{{ route('contracts.download_pdf', $contract->id) }}" 
                               class="btn btn-sm btn-outline-primary rounded-pill px-3"
                               title="Tải xuống PDF hợp đồng để lưu trữ">
                                <i class="fas fa-download me-1"></i> Tải xuống PDF
                            </a>
                        </div>
                        <div class="pdf-iframe-container">
                            <!-- Sử dụng ?inline=1 để hiển thị trực tiếp trong iframe thay vì bắt tải về -->
                            <iframe src="{{ route('contracts.download_pdf', [$contract->id, 'inline' => 1]) }}" class="pdf-iframe"></iframe>
                        </div>
                    </div>
                </div>

                <!-- Cột phải: Bảng tóm tắt thông tin & Clickwrap Agreement -->
                <div class="col-lg-5">
                    <div class="summary-card card">
                        <!-- Header tóm tắt -->
                        <div class="summary-header d-flex justify-content-between align-items-center">
                            <h5 class="summary-title mb-0">TÓM TẮT HỢP ĐỒNG</h5>
                            <span class="contract-badge">PHÒNG {{ $contract->room->name ?? '—' }}</span>
                        </div>

                        <div class="summary-body">
                            <!-- Bảng thông tin chi tiết các biểu phí -->
                            <div class="info-grid">
                                <!-- Giá thuê -->
                                <div class="info-item">
                                    <div class="info-label">Giá thuê hàng tháng</div>
                                    <div class="info-value highlight-rent">
                                        {{ number_format($contract->monthly_rent, 0, ',', '.') }} <span class="small font-normal">VNĐ/tháng</span>
                                    </div>
                                </div>
                                <!-- Tiền cọc -->
                                <div class="info-item">
                                    <div class="info-label">Số tiền đặt cọc giữ phòng</div>
                                    <div class="info-value highlight-deposit">
                                        {{ number_format($contract->deposit_amount, 0, ',', '.') }} <span class="small font-normal">VNĐ</span>
                                    </div>
                                </div>
                                <!-- Tiền điện -->
                                <div class="info-item">
                                    <div class="info-label">Định mức / Giá điện</div>
                                    <div class="info-value">
                                        {{ number_format($contract->room->electric ?? 0, 0, ',', '.') }} <span class="small font-normal">VNĐ/kWh</span>
                                    </div>
                                </div>
                                <!-- Tiền nước -->
                                <div class="info-item">
                                    <div class="info-label">Định mức / Giá nước</div>
                                    <div class="info-value">
                                        {{ number_format($contract->room->water ?? 0, 0, ',', '.') }} <span class="small font-normal">VNĐ/m³</span>
                                    </div>
                                </div>
                            </div>

                            <!-- Khối hộp thông tin bổ sung -->
                            <div class="card p-3 border-0 bg-light rounded-3 mb-4">
                                <div class="row g-2 text-muted small">
                                    <div class="col-6">
                                        <strong>Khách thuê (Bên B):</strong>
                                    </div>
                                    <div class="col-6 text-end">
                                        {{ $contract->tenant->name }}
                                    </div>
                                    <div class="col-6">
                                        <strong>Chủ nhà (Bên A):</strong>
                                    </div>
                                    <div class="col-6 text-end">
                                        {{ $contract->landlord->name }}
                                    </div>
                                    <div class="col-6">
                                        <strong>Ngày bắt đầu thuê:</strong>
                                    </div>
                                    <div class="col-6 text-end">
                                        {{ \Carbon\Carbon::parse($contract->start_date)->format('d/m/Y') }}
                                    </div>
                                    @if($contract->end_date)
                                        <div class="col-6">
                                            <strong>Ngày kết thúc hợp đồng:</strong>
                                        </div>
                                        <div class="col-6 text-end">
                                            {{ \Carbon\Carbon::parse($contract->end_date)->format('d/m/Y') }}
                                        </div>
                                    @endif
                                </div>
                            </div>

                            <!-- Form gửi clickwrap & thanh toán -->
                            <form id="form-agree-pay" method="POST" action="{{ route('contracts.agree_pay', $contract->id) }}">
                                @csrf
                                
                                <!-- Clickwrap Agreement Checkbox -->
                                <div class="clickwrap-box">
                                    <label class="clickwrap-checkbox-label" for="chk-agree">
                                        <input type="checkbox" id="chk-agree">
                                        <div>
                                            <span>
                                                Tôi đã đọc kỹ, hiểu rõ và đồng ý với tất cả điều khoản trong tài liệu Hợp đồng thuê phòng nêu trên.
                                            </span>
                                            <span class="clickwrap-details">
                                                <i class="fas fa-shield-alt me-1"></i>
                                                <strong>BẢO MẬT & PHÁP LÝ:</strong> Hệ thống tự động ghi nhận địa chỉ IP truy cập <strong>{{ Request::ip() }}</strong>, User Agent thiết bị và thời điểm ký số của bạn làm bằng chứng số chống chối bỏ. Mọi thao tác đều có giá trị pháp lý tương đương ký tay.
                                            </span>
                                        </div>
                                    </label>
                                </div>

                                <!-- Button thanh toán -->
                                <button type="submit" class="btn btn-primary btn-sign w-100 py-3" id="btn-submit" disabled>
                                    <i class="fas fa-signature me-2"></i>
                                    Ký Hợp Đồng & Thanh Toán VNPAY
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Toàn bộ Loading Overlay khi người dùng nhấn ký -->
    <div class="loading-overlay" id="loading-overlay">
        <div class="loading-spinner"></div>
        <div class="loading-text">Đang Ghi Nhận Chữ Ký Điện Tử...</div>
        <div class="loading-subtext">Chúng tôi đang thiết lập giao dịch an toàn và chuyển hướng bạn đến cổng thanh toán VNPAY.<br>Vui lòng không tắt hoặc tải lại trang này.</div>
    </div>
@endsection

@section('js')
    <script>
        $(document).ready(function() {
            const $chkAgree = $('#chk-agree');
            const $btnSubmit = $('#btn-submit');
            const $form = $('#form-agree-pay');
            const $loadingOverlay = $('#loading-overlay');

            // ── 1. Logic Clickwrap: Bật/Tắt nút Ký hợp đồng dựa trên Checkbox ──
            $chkAgree.on('change', function() {
                // Nếu được check thì loại bỏ thuộc tính 'disabled', ngược lại thì gán 'disabled'
                $btnSubmit.prop('disabled', !this.checked);
            });

            // ── 2. Logic Submit: Hiển thị Loading Spinner và chống click đúp ──
            $form.on('submit', function(e) {
                // a. Check lại an toàn
                if (!$chkAgree.is(':checked')) {
                    e.preventDefault();
                    Swal.fire({
                        icon: 'warning',
                        title: 'Chưa đồng ý điều khoản',
                        text: 'Vui lòng đọc và tick chọn đồng ý với điều khoản hợp đồng trước khi tiếp tục.',
                        confirmButtonColor: '#2563eb'
                    });
                    return false;
                }

                // b. Disable các tương tác để tránh click nhiều lần
                $btnSubmit.prop('disabled', true);
                $chkAgree.prop('disabled', true);

                // c. Hiển thị Spinner Overlay toàn màn hình (vô cùng premium)
                $loadingOverlay.css('display', 'flex').hide().fadeIn(300);

                // d. Form tiến hành submit bình thường và backend redirect sang VNPAY
                return true;
            });
        });
    </script>
@endsection
