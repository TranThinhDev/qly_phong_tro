<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migration: create_contracts_table
 *
 * Bảng trung tâm của module Contract & Deposit.
 * Lưu trữ hợp đồng thuê phòng giữa chủ trọ (landlord) và
 * khách thuê (tenant), bao gồm điều khoản tài chính và
 * bằng chứng chữ ký điện tử kiểu Clickwrap.
 *
 * Ràng buộc an toàn dữ liệu:
 * ─────────────────────────────────────────────────────────
 * 1. FK → users (tenant_id, landlord_id): CASCADE DELETE đảm
 *    bảo không có hợp đồng "mồ côi" khi user bị xóa.
 * 2. FK → rooms: RESTRICT (SET NULL) giữ lịch sử hợp đồng
 *    kể cả khi phòng bị xóa (room_id thành NULL).
 * 3. Clickwrap (signed_at, tenant_ip, tenant_user_agent):
 *    - Mặc định NULL (chưa ký).
 *    - Chỉ ghi 1 lần; tầng application dùng Model Observer
 *      để chặn overwrite (Immutable after first write).
 * 4. status dùng ENUM để DB-level constraint, không cho phép
 *    giá trị tùy tiện.
 * 5. amount / deposit_amount dùng DECIMAL(15,2) thay vì FLOAT
 *    để tránh lỗi làm tròn số tiền.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contracts', function (Blueprint $table) {

            // ── Khóa chính ───────────────────────────────────────────────
            $table->id();                                   // BIGINT UNSIGNED AUTO_INCREMENT

            // ── Mã hợp đồng (duy nhất, sinh từ application) ─────────────
            $table->string('contract_code', 50)->unique()
                  ->comment('Mã hợp đồng duy nhất, e.g. CT-2026-00001');

            // ── Liên kết người dùng ──────────────────────────────────────
            // unsignedInteger khớp với $table->increments('id') ở bảng users
            $table->unsignedInteger('tenant_id')
                  ->comment('Khách thuê – FK → users.id');
            $table->foreign('tenant_id')
                  ->references('id')->on('users')
                  ->onDelete('cascade');   // Xóa user → xóa hợp đồng liên quan

            $table->unsignedInteger('landlord_id')
                  ->comment('Chủ trọ – FK → users.id');
            $table->foreign('landlord_id')
                  ->references('id')->on('users')
                  ->onDelete('cascade');

            // ── Liên kết phòng ───────────────────────────────────────────
            $table->unsignedInteger('room_id')->nullable()
                  ->comment('FK → rooms.id. Nullable để giữ lịch sử nếu phòng bị xóa');
            $table->foreign('room_id')
                  ->references('id')->on('rooms')
                  ->onDelete('set null');  // Xóa phòng → room_id = NULL, hợp đồng vẫn tồn tại

            // ── Thời hạn hợp đồng ────────────────────────────────────────
            $table->date('start_date')->comment('Ngày bắt đầu thuê');
            $table->date('end_date')->nullable()
                  ->comment('Ngày kết thúc hợp đồng. Null nếu hợp đồng không kỳ hạn');

            // ── Tài chính ─────────────────────────────────────────────────
            // DECIMAL(15,2): tối đa 999.999.999.999,99 VNĐ, không lỗi làm tròn
            $table->decimal('monthly_rent', 15, 2)
                  ->comment('Tiền thuê hàng tháng (VNĐ)');
            $table->decimal('deposit_amount', 15, 2)->default(0)
                  ->comment('Số tiền đặt cọc (VNĐ)');

            // ── Trạng thái hợp đồng ──────────────────────────────────────
            // ENUM: DB-level constraint, chặn giá trị không hợp lệ
            $table->enum('status', ['draft', 'active', 'expired', 'terminated'])
                  ->default('draft')
                  ->comment('draft=nháp, active=hiệu lực, expired=hết hạn, terminated=chấm dứt');

            // ── Nội dung hợp đồng ─────────────────────────────────────────
            $table->longText('terms_content')->nullable()
                  ->comment('Nội dung điều khoản hợp đồng (HTML/Markdown)');

            // ── Clickwrap Agreement (Chữ ký điện tử) ─────────────────────
            // Ba trường này là IMMUTABLE: chỉ được ghi 1 lần khi tenant xác nhận.
            // Tầng application (ContractObserver) phải kiểm tra: nếu signed_at
            // đã có giá trị thì KHÔNG cho phép cập nhật nhóm trường này.
            $table->timestamp('signed_at')->nullable()
                  ->comment('[IMMUTABLE] Thời điểm tenant bấm "Tôi đồng ý". Null = chưa ký');
            $table->string('tenant_ip', 45)->nullable()
                  ->comment('[IMMUTABLE] IP của tenant lúc ký (IPv4 max 15, IPv6 max 45)');
            $table->text('tenant_user_agent')->nullable()
                  ->comment('[IMMUTABLE] User-Agent string của trình duyệt tenant lúc ký');

            // ── Ghi chú nội bộ ───────────────────────────────────────────
            $table->text('notes')->nullable()
                  ->comment('Ghi chú nội bộ của chủ trọ');

            // ── Timestamps ───────────────────────────────────────────────
            $table->timestamps();                           // created_at, updated_at
            $table->softDeletes();                         // deleted_at (xóa mềm)

            // ── Composite index ──────────────────────────────────────────
            // Tối ưu truy vấn "tất cả hợp đồng của tenant X theo trạng thái"
            $table->index(['tenant_id', 'status'], 'idx_contracts_tenant_status');
            $table->index(['landlord_id', 'status'], 'idx_contracts_landlord_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contracts');
    }
};
