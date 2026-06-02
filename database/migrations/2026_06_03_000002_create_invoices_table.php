<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migration: create_invoices_table
 *
 * Bảng hóa đơn trung tâm của Module 3 – Auto-Billing.
 * Mỗi hóa đơn tương ứng với một kỳ thanh toán (billing_month)
 * của một hợp đồng đang active.
 *
 * Ràng buộc an toàn dữ liệu:
 * ─────────────────────────────────────────────────────────
 * 1. UNIQUE (contract_id, billing_month): tuyệt đối không được
 *    sinh 2 hóa đơn cho cùng hợp đồng trong cùng tháng.
 *    InvoiceService dùng constraint này để idempotency.
 * 2. FK → contracts: SET NULL giữ lịch sử hóa đơn kể cả khi
 *    hợp đồng bị xóa mềm.
 * 3. FK → users (tenant_id): CASCADE – khách thuê bị xóa thì
 *    hóa đơn cũng không còn ý nghĩa.
 * 4. total_amount và late_fee dùng DECIMAL(15,2) – tuyệt đối
 *    không dùng FLOAT cho tiền tệ.
 * 5. billing_month lưu dạng VARCHAR 'MM/YYYY' (e.g. '06/2026')
 *    theo yêu cầu nghiệp vụ; dễ hiển thị, dễ query exact-match.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table) {

            // ── Khóa chính ───────────────────────────────────────────────
            $table->id();

            // ── Mã hóa đơn (duy nhất, sinh từ application) ───────────────
            $table->string('invoice_code', 50)->unique()
                  ->comment('Mã hóa đơn, e.g. INV-06/2026-00001');

            // ── Liên kết hợp đồng ─────────────────────────────────────────
            $table->unsignedBigInteger('contract_id')->nullable()
                  ->comment('FK → contracts.id. Nullable để giữ lịch sử nếu hợp đồng bị xóa');
            $table->foreign('contract_id')
                  ->references('id')->on('contracts')
                  ->onDelete('set null');

            // ── Khách thuê ─────────────────────────────────────────────────
            // Denormalize tenant_id để query nhanh, không phải join contracts
            $table->unsignedInteger('tenant_id')
                  ->comment('FK → users.id (khách thuê)');
            $table->foreign('tenant_id')
                  ->references('id')->on('users')
                  ->onDelete('cascade');

            // ── Kỳ thanh toán ─────────────────────────────────────────────
            // Format 'MM/YYYY' theo yêu cầu, e.g. '06/2026'
            $table->string('billing_month', 7)
                  ->comment('Kỳ thanh toán, format MM/YYYY, e.g. 06/2026');

            // ── Tài chính ─────────────────────────────────────────────────
            $table->decimal('total_amount', 15, 2)->default(0)
                  ->comment('Tổng tiền hóa đơn (VNĐ) = SUM(invoice_items.total)');
            $table->decimal('late_fee', 15, 2)->default(0)
                  ->comment('Phí phạt chậm trả (VNĐ), tính thêm khi overdue');

            // ── Trạng thái ────────────────────────────────────────────────
            $table->enum('status', ['unpaid', 'partial', 'paid', 'overdue', 'cancelled'])
                  ->default('unpaid')
                  ->comment('unpaid=chưa trả, partial=trả một phần, paid=đã trả, overdue=quá hạn, cancelled=hủy');

            // ── Hạn thanh toán ────────────────────────────────────────────
            $table->date('due_date')
                  ->comment('Hạn thanh toán, mặc định ngày 10 của tháng billing');

            // ── Ghi chú ───────────────────────────────────────────────────
            $table->text('notes')->nullable()
                  ->comment('Ghi chú nội bộ của chủ trọ');

            // ── Timestamps ───────────────────────────────────────────────
            $table->timestamps();
            $table->softDeletes();

            // ── Ràng buộc duy nhất: 1 hóa đơn / hợp đồng / tháng ───────
            // Dùng unique constraint làm lưới an toàn thứ 2 sau tầng Service
            $table->unique(['contract_id', 'billing_month'], 'uniq_invoice_contract_month');

            // ── Index truy vấn nhanh ──────────────────────────────────────
            $table->index(['tenant_id', 'status'], 'idx_invoices_tenant_status');
            $table->index(['status', 'due_date'],  'idx_invoices_status_due');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};
