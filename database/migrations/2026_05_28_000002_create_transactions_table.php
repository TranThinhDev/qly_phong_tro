<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migration: create_transactions_table
 *
 * Bảng ghi lại toàn bộ lịch sử giao dịch tài chính trong hệ thống:
 * thanh toán tiền cọc, tiền thuê, hoàn cọc, v.v.
 *
 * Ràng buộc an toàn dữ liệu:
 * ─────────────────────────────────────────────────────────
 * 1. FK → contracts: RESTRICT (SET NULL) – giữ lịch sử giao dịch
 *    kể cả khi hợp đồng bị xóa mềm.
 * 2. FK → users (payer_id): CASCADE – không có giao dịch mồ côi.
 * 3. transaction_code: UNIQUE để đảm bảo idempotency (không trùng
 *    khi payment gateway gửi callback 2 lần).
 * 4. amount: DECIMAL(15,2) – không bao giờ dùng FLOAT cho tiền tệ.
 * 5. gateway_response: JSON lưu raw payload từ cổng thanh toán
 *    để audit/debug, không được xóa sau khi xử lý.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transactions', function (Blueprint $table) {

            // ── Khóa chính ───────────────────────────────────────────────
            $table->id();

            // ── Mã giao dịch duy nhất ────────────────────────────────────
            // Sinh từ application, dùng để idempotency check với payment gateway
            $table->string('transaction_code', 100)->unique()
                  ->comment('Mã giao dịch duy nhất, dùng kiểm tra idempotency với gateway');

            // ── Liên kết hợp đồng (nullable để hỗ trợ giao dịch độc lập) ─
            $table->unsignedBigInteger('contract_id')->nullable()
                  ->comment('FK → contracts.id. Nullable nếu giao dịch không gắn hợp đồng');
            $table->foreign('contract_id')
                  ->references('id')->on('contracts')
                  ->onDelete('set null');  // Hợp đồng bị xóa → contract_id = NULL

            // ── Người thực hiện giao dịch ────────────────────────────────
            $table->unsignedInteger('payer_id')
                  ->comment('FK → users.id, người trả tiền');
            $table->foreign('payer_id')
                  ->references('id')->on('users')
                  ->onDelete('cascade');

            // ── Phân loại giao dịch ──────────────────────────────────────
            $table->enum('type', [
                'deposit_payment',   // Thanh toán tiền cọc
                'rent_payment',      // Thanh toán tiền thuê
                'deposit_refund',    // Hoàn trả tiền cọc
                'penalty',           // Phạt vi phạm hợp đồng
                'other',             // Khác
            ])->comment('Loại giao dịch');

            // ── Số tiền ──────────────────────────────────────────────────
            $table->decimal('amount', 15, 2)
                  ->comment('Số tiền giao dịch (VNĐ)');
            $table->string('currency', 3)->default('VND')
                  ->comment('Mã tiền tệ ISO 4217');

            // ── Cổng thanh toán ──────────────────────────────────────────
            $table->string('payment_method', 50)->nullable()
                  ->comment('Phương thức: momo, vnpay, bank_transfer, cash, ...');
            $table->string('gateway_transaction_id')->nullable()
                  ->comment('Mã giao dịch phía cổng thanh toán trả về');
            $table->json('gateway_response')->nullable()
                  ->comment('Raw JSON response từ payment gateway, dùng để audit');

            // ── Trạng thái giao dịch ─────────────────────────────────────
            $table->enum('status', ['pending', 'completed', 'failed', 'refunded'])
                  ->default('pending')
                  ->comment('Trạng thái: pending=chờ, completed=thành công, failed=thất bại, refunded=đã hoàn');

            // ── Thông tin bổ sung ─────────────────────────────────────────
            $table->text('note')->nullable()
                  ->comment('Ghi chú giao dịch');
            $table->timestamp('paid_at')->nullable()
                  ->comment('Thời điểm giao dịch hoàn tất thực tế');

            // ── Timestamps ───────────────────────────────────────────────
            $table->timestamps();

            // ── Index ─────────────────────────────────────────────────────
            $table->index(['contract_id', 'type'], 'idx_transactions_contract_type');
            $table->index(['payer_id', 'status'],  'idx_transactions_payer_status');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};
