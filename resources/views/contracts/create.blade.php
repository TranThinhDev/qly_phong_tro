@extends('layouts.app')

@section('content')
<div class="container py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2 class="mb-0 fw-bold text-primary">Tạo Hợp Đồng Thuê Phòng</h2>
        <a href="{{ url('/landlord/contracts') }}" class="btn btn-outline-secondary">
            <i class="fas fa-arrow-left"></i> Trở về danh sách
        </a>
    </div>

    <form id="createContractForm">
        @csrf
        <div class="row">
            <!-- ┌────────────────────────────────────────────────────────┐
                 │ CARD 1: CHỌN PHÒNG & THÔNG TIN GIÁ (AUTOFILL)          │
                 └────────────────────────────────────────────────────────┘ -->
            <div class="col-md-6 mb-4">
                <div class="card h-100 shadow-sm border-0">
                    <div class="card-header bg-primary text-white">
                        <h5 class="card-title mb-0"><i class="fas fa-door-open me-2"></i>1. Chọn Phòng & Dịch Vụ</h5>
                    </div>
                    <div class="card-body bg-light">
                        <div class="mb-3">
                            <label for="room_id" class="form-label fw-semibold">Chọn phòng trống <span class="text-danger">*</span></label>
                            <select name="room_id" id="room_id" class="form-select border-primary" required>
                                <option value="">-- Vui lòng chọn phòng --</option>
                                <!-- Giả định biến $rooms được truyền từ Controller -->
                                @if(isset($rooms))
                                    @foreach($rooms as $room)
                                        <option value="{{ $room->id }}" 
                                                data-price="{{ $room->price }}"
                                                data-electricity="{{ $room->electric }}"
                                                data-water="{{ $room->water }}">
                                            {{ $room->name }} ({{ number_format($room->price, 0, ',', '.') }}đ)
                                        </option>
                                    @endforeach
                                @endif
                            </select>
                            <div class="invalid-feedback error-room_id fw-bold"></div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-semibold text-muted">Giá thuê phòng/tháng (VNĐ)</label>
                            <!-- Input readonly hiển thị giá đẹp -->
                            <input type="text" id="monthly_rent_display" class="form-control bg-white text-primary fw-bold" readonly placeholder="Tự động điền">
                            <!-- Input ẩn gửi giá trị thực tế lên server -->
                            <input type="hidden" name="monthly_rent" id="monthly_rent">
                            <div class="invalid-feedback error-monthly_rent fw-bold"></div>
                        </div>

                        <div class="row">
                            <div class="col-6 mb-3">
                                <label class="form-label fw-semibold text-muted">Giá điện (VNĐ/kWh)</label>
                                <input type="text" id="electricity_price" class="form-control bg-white" readonly placeholder="...">
                            </div>
                            <div class="col-6 mb-3">
                                <label class="form-label fw-semibold text-muted">Giá nước (VNĐ/khối)</label>
                                <input type="text" id="water_price" class="form-control bg-white" readonly placeholder="...">
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-6 mb-3">
                                <label for="electric_index" class="form-label fw-semibold">Số điện đầu kỳ</label>
                                <input type="number" name="electric_index" id="electric_index" class="form-control" placeholder="Ví dụ: 1250">
                            </div>
                            <div class="col-6 mb-3">
                                <label for="water_index" class="form-label fw-semibold">Số nước đầu kỳ</label>
                                <input type="number" name="water_index" id="water_index" class="form-control" placeholder="Ví dụ: 300">
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ┌────────────────────────────────────────────────────────┐
                 │ CARD 2: THÔNG TIN KHÁCH THUÊ VÀ ĐỊNH DANH (CCCD)       │
                 └────────────────────────────────────────────────────────┘ -->
            <div class="col-md-6 mb-4">
                <div class="card h-100 shadow-sm border-0">
                    <div class="card-header bg-success text-white">
                        <h5 class="card-title mb-0"><i class="fas fa-user-check me-2"></i>2. Thông tin Khách Thuê</h5>
                    </div>
                    <div class="card-body">
                        <div class="alert alert-info py-2 small">
                            <i class="fas fa-info-circle me-1"></i> Nếu Email/SĐT chưa tồn tại, hệ thống sẽ <strong>tự động tạo tài khoản</strong> và gửi email thông báo cho khách.
                        </div>

                        <div class="mb-3">
                            <label for="tenant_name" class="form-label fw-semibold">Họ và Tên Khách <span class="text-danger">*</span></label>
                            <input type="text" name="tenant_name" id="tenant_name" class="form-control" placeholder="Nhập tên người đại diện ký hợp đồng" required>
                            <div class="invalid-feedback error-tenant_name fw-bold"></div>
                        </div>

                        <div class="row">
                            <div class="col-6 mb-3">
                                <label for="tenant_phone" class="form-label fw-semibold">Số điện thoại <span class="text-danger">*</span></label>
                                <input type="text" name="tenant_phone" id="tenant_phone" class="form-control" placeholder="09xxxx..." required>
                                <div class="invalid-feedback error-tenant_phone fw-bold"></div>
                            </div>
                            <div class="col-6 mb-3">
                                <label for="tenant_email" class="form-label fw-semibold">Email <span class="text-danger">*</span></label>
                                <input type="email" name="tenant_email" id="tenant_email" class="form-control" placeholder="abc@gmail.com" required>
                                <div class="invalid-feedback error-tenant_email fw-bold"></div>
                            </div>
                        </div>
                        
                        <hr class="text-muted">
                        
                        <div class="mb-3">
                            <label for="identity_card_number" class="form-label fw-semibold text-muted">Số CCCD / CMND (Không bắt buộc)</label>
                            <input type="text" name="identity_card_number" id="identity_card_number" class="form-control" placeholder="Nhập 12 số CCCD">
                        </div>
                        
                        <div class="row">
                            <div class="col-6 mb-3">
                                <label for="identity_date" class="form-label fw-semibold text-muted">Ngày cấp</label>
                                <input type="date" name="identity_date" id="identity_date" class="form-control">
                            </div>
                            <div class="col-6 mb-3">
                                <label for="identity_place" class="form-label fw-semibold text-muted">Nơi cấp</label>
                                <input type="text" name="identity_place" id="identity_place" class="form-control" placeholder="Cục CS QLHC...">
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ┌────────────────────────────────────────────────────────┐
                 │ CARD 3: THỜI HẠN & TIỀN CỌC (TÍNH TOÁN REAL-TIME)      │
                 └────────────────────────────────────────────────────────┘ -->
            <div class="col-md-6 mb-4">
                <div class="card h-100 shadow-sm border-0">
                    <div class="card-header bg-warning text-dark">
                        <h5 class="card-title mb-0"><i class="fas fa-calendar-alt me-2"></i>3. Thời Hạn & Tiền Cọc</h5>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-6 mb-3">
                                <label for="start_date" class="form-label fw-semibold">Ngày bắt đầu <span class="text-danger">*</span></label>
                                <input type="date" name="start_date" id="start_date" class="form-control" required value="{{ date('Y-m-d') }}">
                                <div class="invalid-feedback error-start_date fw-bold"></div>
                            </div>
                            <div class="col-6 mb-3">
                                <label for="duration_months" class="form-label fw-semibold">Thời hạn thuê (Tháng)</label>
                                <select name="duration_months" id="duration_months" class="form-select">
                                    <option value="6">6 Tháng</option>
                                    <option value="12" selected>12 Tháng (1 Năm)</option>
                                    <option value="24">24 Tháng (2 Năm)</option>
                                </select>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label for="deposit_type" class="form-label fw-semibold">Mức tiền cọc <span class="text-danger">*</span></label>
                            <select id="deposit_type" class="form-select border-warning">
                                <option value="1">1 Tháng tiền phòng</option>
                                <option value="2">2 Tháng tiền phòng</option>
                                <option value="3">3 Tháng tiền phòng</option>
                                <option value="custom">Tùy chỉnh (Nhập tay)</option>
                            </select>
                        </div>

                        <div class="mb-3 p-3 bg-light rounded border border-warning">
                            <label for="deposit_amount" class="form-label fw-bold text-danger mb-1">TỔNG TIỀN CỌC KHÁCH PHẢI ĐÓNG (VNĐ)</label>
                            <input type="number" name="deposit_amount" id="deposit_amount" class="form-control form-control-lg text-danger fw-bold" readonly required placeholder="0">
                            <div class="invalid-feedback error-deposit_amount fw-bold"></div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ┌────────────────────────────────────────────────────────┐
                 │ CARD 4: ĐIỀU KHOẢN BỔ SUNG & NÚT XÁC NHẬN SUBMIT       │
                 └────────────────────────────────────────────────────────┘ -->
            <div class="col-md-6 mb-4">
                <div class="card h-100 shadow-sm border-0">
                    <div class="card-header bg-dark text-white">
                        <h5 class="card-title mb-0"><i class="fas fa-file-signature me-2"></i>4. Điều Khoản & Xác Nhận</h5>
                    </div>
                    <div class="card-body d-flex flex-column">
                        <div class="mb-3 flex-grow-1">
                            <label for="terms_content" class="form-label fw-semibold">Điều khoản bổ sung (Ngoài HĐ mẫu)</label>
                            <textarea name="terms_content" id="terms_content" class="form-control" rows="4" placeholder="Nhập các thỏa thuận riêng (nuôi pet, giữ xe, nội thất...)"></textarea>
                        </div>
                        <div class="mb-4">
                            <label for="notes" class="form-label fw-semibold text-muted">Ghi chú nội bộ (Khách không thấy)</label>
                            <input type="text" name="notes" id="notes" class="form-control" placeholder="Ghi chú riêng cho chủ trọ...">
                        </div>

                        <!-- SUBMIT BUTTON -->
                        <div class="d-grid gap-2 mt-auto">
                            <button type="submit" id="btnSubmit" class="btn btn-primary btn-lg fw-bold shadow">
                                <i class="fas fa-paper-plane me-2"></i> TẠO HỢP ĐỒNG NHÁP & GỬI EMAIL
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </form>
</div>
@endsection

@push('scripts')
<script>
$(document).ready(function() {

    // ─────────────────────────────────────────────────────────────────
    // 1. UTILITIES: Định dạng tiền tệ
    // ─────────────────────────────────────────────────────────────────
    function formatCurrency(number) {
        if (!number) return '';
        return new Intl.NumberFormat('vi-VN').format(number);
    }

    // ─────────────────────────────────────────────────────────────────
    // 2. LOGIC JAVASCRIPT: Auto-fill dữ liệu khi chọn phòng
    // ─────────────────────────────────────────────────────────────────
    $('#room_id').on('change', function() {
        var selectedOpt = $(this).find('option:selected');
        
        // Lấy data attributes
        var price = selectedOpt.data('price') || 0;
        var elec = selectedOpt.data('electricity') || 0;
        var water = selectedOpt.data('water') || 0;

        // Điền vào các ô tương ứng
        $('#monthly_rent').val(price); // Gửi giá trị thật
        $('#monthly_rent_display').val(formatCurrency(price)); // Hiển thị giá đẹp
        $('#electricity_price').val(formatCurrency(elec));
        $('#water_price').val(formatCurrency(water));

        // Trigger lại việc tính toán cọc khi giá phòng thay đổi
        calculateDeposit();
    });

    // ─────────────────────────────────────────────────────────────────
    // 3. LOGIC JAVASCRIPT: Tự động tính tiền cọc
    // ─────────────────────────────────────────────────────────────────
    $('#deposit_type').on('change', function() {
        calculateDeposit();
    });

    function calculateDeposit() {
        var type = $('#deposit_type').val();
        var price = parseFloat($('#monthly_rent').val()) || 0;
        var $depositInput = $('#deposit_amount');

        if (type === 'custom') {
            // Chế độ Tùy chỉnh: Mở khóa ô input cho phép gõ tự do
            $depositInput.prop('readonly', false).focus();
            $depositInput.val(''); 
        } else {
            // Chế độ Mặc định: Khóa ô input, tự động tính theo số tháng
            $depositInput.prop('readonly', true);
            var months = parseInt(type);
            var totalDeposit = price * months;
            $depositInput.val(totalDeposit);
        }
    }

    // ─────────────────────────────────────────────────────────────────
    // 4. LOGIC JQUERY AJAX: Bắt sự kiện tạo hợp đồng (Submit)
    // ─────────────────────────────────────────────────────────────────
    $('#createContractForm').on('submit', function(e) {
        e.preventDefault(); // Ngăn load lại trang
        
        var $form = $(this);
        var $btn = $('#btnSubmit');
        var originalBtnHtml = $btn.html();

        // Xóa thông báo lỗi màu đỏ cũ (nếu có)
        $('.is-invalid').removeClass('is-invalid');
        $('.invalid-feedback').html('');

        // UX: Vô hiệu hóa nút và hiện Spinner Loading
        $btn.prop('disabled', true);
        $btn.html('<span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span> Đang xử lý tạo tài khoản & Gửi Mail...');

        // Gọi API Backend
        $.ajax({
            url: "{{ route('contracts.draft') }}", // Sửa lại đúng URL Endpoint
            method: "POST",
            data: $form.serialize(),
            success: function(response) {
                // THÀNH CÔNG (200 / 201)
                if (typeof Swal !== 'undefined') {
                    // Nếu project có xài thư viện SweetAlert2
                    Swal.fire({
                        icon: 'success',
                        title: 'Tuyệt vời!',
                        text: response.message || 'Tạo hợp đồng thành công.',
                        confirmButtonText: 'Xem danh sách Hợp đồng',
                        confirmButtonColor: '#0d6efd'
                    }).then(() => {
                        window.location.href = "{{ url('/contracts') }}"; 
                    });
                } else {
                    // Fallback mặc định
                    alert('Thành công: ' + (response.message || 'Hợp đồng nháp đã được tạo và gửi Mail cho khách!'));
                    window.location.href = "{{ url('/contracts') }}";
                }
            },
            error: function(xhr) {
                // THẤT BẠI - Xử lý Validation Errors (422)
                if (xhr.status === 422) {
                    var errors = xhr.responseJSON.errors;
                    $.each(errors, function(key, val) {
                        // Tìm thẻ input bị lỗi, thêm class is-invalid viền đỏ
                        var input = $form.find('[name="'+key+'"]');
                        if (input.length) {
                            input.addClass('is-invalid');
                            // Hiển thị nội dung lỗi
                            $('.error-'+key).html(val[0]);
                        }
                    });
                    
                    if (typeof Swal !== 'undefined') {
                        Swal.fire('Lỗi nhập liệu', 'Vui lòng kiểm tra lại các trường được bôi đỏ trên Form.', 'warning');
                    }
                } 
                // THẤT BẠI - Xử lý lỗi hệ thống (500) hoặc Permission (403)
                else {
                    var errorMsg = xhr.responseJSON ? xhr.responseJSON.error : 'Đã xảy ra lỗi hệ thống. Vui lòng thử lại.';
                    if (typeof Swal !== 'undefined') {
                        Swal.fire('Thất bại!', errorMsg, 'error');
                    } else {
                        alert('Lỗi: ' + errorMsg);
                    }
                }
            },
            complete: function() {
                // Khôi phục lại trạng thái ban đầu cho button dù lỗi hay không
                $btn.prop('disabled', false);
                $btn.html(originalBtnHtml);
            }
        });
    });
});
</script>
@endpush
