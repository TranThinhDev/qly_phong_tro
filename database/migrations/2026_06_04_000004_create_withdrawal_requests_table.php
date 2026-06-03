<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migration: create_withdrawal_requests_table
 *
 * Bảng yêu cầu rút tiền từ ví về tài khoản ngân hàng.
 * Luồng: user gửi yêu cầu → admin xem xét → duyệt/từ chối.
 *
 * Ràng buộc an toàn dữ liệu:
 * ─────────────────────────────────────────────────────────
 * 1. Khi admin tạo withdrawal request, WalletService phải LOCK
 *    wallet và trừ available_balance ngay (pending hold).
 *    Nếu reject → hoàn lại balance. Không để user rút 2 lần.
 * 2. status ENUM: chặn giá trị tùy tiện ở tầng DB.
 * 3. processed_by: nullable FK → users (admin). SET NULL nếu
 *    admin bị xóa để bảo toàn lịch sử request.
 * 4. proof_image_url: ảnh chứng minh đã chuyển khoản thật
 *    (screenshot/bill), lưu trong storage private.
 * 5. bank_account_number: lưu dạng string để tránh mất leading zeros.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('withdrawal_requests', function (Blueprint $table) {

            // ── Khóa chính ───────────────────────────────────────────────
            $table->id();

            // ── Ví yêu cầu rút tiền ──────────────────────────────────────
            $table->unsignedBigInteger('wallet_id')
                  ->comment('FK → wallets.id');
            $table->foreign('wallet_id')
                  ->references('id')->on('wallets')
                  ->onDelete('cascade');

            // ── Số tiền yêu cầu rút ─────────────────────────────────────
            $table->decimal('amount', 15, 2)
                  ->comment('Số tiền yêu cầu rút (VNĐ, > 0)');

            // ── Thông tin ngân hàng thụ hưởng ────────────────────────────
            $table->string('bank_code', 20)
                  ->comment('Mã ngân hàng (VCB, TCB, MB, ACB, ...) theo danh sách chuẩn');
            $table->string('bank_account_number', 30)
                  ->comment('Số tài khoản ngân hàng (string để giữ leading zeros)');
            $table->string('bank_account_name', 200)
                  ->comment('Tên chủ tài khoản ngân hàng (đúng như đăng ký ngân hàng)');

            // ── Trạng thái xử lý ─────────────────────────────────────────
            $table->enum('status', ['pending', 'approved', 'rejected'])
                  ->default('pending')
                  ->comment('pending=chờ duyệt, approved=đã duyệt-chuyển tiền, rejected=từ chối');

            // ── Bằng chứng chuyển khoản ──────────────────────────────────
            // Admin upload ảnh bill/screenshot sau khi đã chuyển khoản thật
            $table->string('proof_image_url')->nullable()
                  ->comment('[PRIVATE] Đường dẫn ảnh xác nhận chuyển khoản trong storage nội bộ');

            // ── Admin xử lý ──────────────────────────────────────────────
            $table->unsignedInteger('processed_by')->nullable()
                  ->comment('FK → users.id. Admin đã xử lý yêu cầu này');
            $table->foreign('processed_by')
                  ->references('id')->on('users')
                  ->onDelete('set null');  // Admin bị xóa → giữ lịch sử, set null

            // ── Ghi chú xử lý ────────────────────────────────────────────
            $table->text('admin_notes')->nullable()
                  ->comment('Lý do từ chối hoặc ghi chú của admin khi xử lý');

            // ── Timestamps ───────────────────────────────────────────────
            $table->timestamps();

            // ── Index ─────────────────────────────────────────────────────
            $table->index(['wallet_id', 'status'], 'idx_withdrawal_wallet_status');
            $table->index('status', 'idx_withdrawal_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('withdrawal_requests');
    }
};
