<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migration: create_kyc_requests_table
 *
 * Bảng lưu yêu cầu xác minh danh tính (KYC) của người dùng.
 * Chủ trọ cần upload CMND/CCCD mặt trước, mặt sau và giấy tờ
 * chứng minh quyền sở hữu bất động sản để được duyệt.
 *
 * Ràng buộc an toàn dữ liệu:
 * ─────────────────────────────────────────────────────────
 * 1. File URL là đường dẫn NỘI BỘ trong storage/app/private_kyc
 *    – KHÔNG phải URL public. Controller phải stream file qua
 *    Auth middleware, không bao giờ expose trực tiếp.
 * 2. status dùng ENUM: DB-level constraint chặn giá trị rác.
 * 3. verified_at là IMMUTABLE sau khi admin duyệt – tầng
 *    application (KycRequest::approve()) kiểm tra trước khi ghi.
 * 4. FK → users: CASCADE DELETE – user bị xóa thì KYC cũng xóa.
 * 5. reviewed_by là NULLABLE FK → users để biết admin nào duyệt.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kyc_requests', function (Blueprint $table) {

            // ── Khóa chính ───────────────────────────────────────────────
            $table->id();

            // ── Người dùng gửi yêu cầu KYC ──────────────────────────────
            // Dùng unsignedInteger để khớp với users.id (increments)
            $table->unsignedInteger('user_id')
                  ->comment('FK → users.id. Người gửi yêu cầu KYC');
            $table->foreign('user_id')
                  ->references('id')->on('users')
                  ->onDelete('cascade');  // Xóa user → xóa KYC request

            // ── Đường dẫn file (lưu trong storage private, KHÔNG public) ─
            // Giá trị ví dụ: "private_kyc/5/uuid-front.jpg"
            $table->string('id_card_front_url')
                  ->comment('[PRIVATE] Đường dẫn CMND/CCCD mặt trước trong storage nội bộ');
            $table->string('id_card_back_url')
                  ->comment('[PRIVATE] Đường dẫn CMND/CCCD mặt sau trong storage nội bộ');
            $table->string('ownership_proof_url')->nullable()
                  ->comment('[PRIVATE] Giấy tờ chứng minh quyền sở hữu (sổ đỏ, hợp đồng mua bán). Nullable nếu chưa upload');

            // ── Trạng thái xét duyệt ─────────────────────────────────────
            // ENUM: chặn giá trị tùy tiện ở tầng DB
            $table->enum('status', ['pending', 'verified', 'rejected'])
                  ->default('pending')
                  ->comment('pending=chờ duyệt, verified=đã xác minh, rejected=từ chối');

            // ── Thông tin xét duyệt ──────────────────────────────────────
            $table->text('admin_notes')->nullable()
                  ->comment('Ghi chú của admin khi duyệt hoặc từ chối');

            // reviewed_by: Admin đã xét duyệt (nullable = chưa có admin xử lý)
            $table->unsignedInteger('reviewed_by')->nullable()
                  ->comment('FK → users.id. Admin đã duyệt/từ chối KYC này');
            $table->foreign('reviewed_by')
                  ->references('id')->on('users')
                  ->onDelete('set null');  // Admin bị xóa → reviewed_by = NULL

            // verified_at: IMMUTABLE – chỉ ghi 1 lần khi status → verified
            $table->timestamp('verified_at')->nullable()
                  ->comment('[IMMUTABLE] Thời điểm admin phê duyệt KYC. Null = chưa duyệt');

            // ── Timestamps ───────────────────────────────────────────────
            $table->timestamps();

            // ── Index ─────────────────────────────────────────────────────
            // Tối ưu query "danh sách KYC pending" và "KYC của user X"
            $table->index(['user_id', 'status'], 'idx_kyc_user_status');
            $table->index('status', 'idx_kyc_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kyc_requests');
    }
};
