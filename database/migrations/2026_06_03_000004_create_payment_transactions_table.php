<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migration: create_payment_transactions_table
 *
 * Bảng lịch sử thanh toán của các hóa đơn.
 * Tách biệt với bảng `transactions` hiện có (dùng cho cọc/hợp đồng)
 * để module Auto-Billing có vòng đời riêng, không ảnh hưởng module cũ.
 *
 * Một hóa đơn có thể có NHIỀU payment_transactions:
 *   – Thanh toán một phần nhiều lần (status='partial')
 *   – Các lần thử thanh toán thất bại + 1 lần thành công
 *
 * Ràng buộc an toàn dữ liệu:
 * ─────────────────────────────────────────────────────────
 * 1. transaction_code UNIQUE: đảm bảo idempotency khi VNPay
 *    gửi IPN/callback trùng lặp.
 * 2. FK → invoices: RESTRICT (SET NULL) – giữ lịch sử giao dịch
 *    kể cả khi hóa đơn bị hủy/xóa mềm.
 * 3. amount: DECIMAL(15,2) – không bao giờ dùng FLOAT cho tiền.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_transactions', function (Blueprint $table) {

            // ── Khóa chính ───────────────────────────────────────────────
            $table->id();

            // ── Liên kết hóa đơn ──────────────────────────────────────────
            $table->unsignedBigInteger('invoice_id')->nullable()
                  ->comment('FK → invoices.id. Nullable để giữ lịch sử khi hóa đơn bị xóa');
            $table->foreign('invoice_id')
                  ->references('id')->on('invoices')
                  ->onDelete('set null');

            // ── Mã giao dịch ──────────────────────────────────────────────
            // Có thể là mã từ VNPay, MoMo, hoặc mã nội bộ (bank_transfer, cash)
            $table->string('transaction_code')->unique()
                  ->comment('Mã giao dịch duy nhất từ cổng thanh toán hoặc sinh nội bộ');

            // ── Số tiền ──────────────────────────────────────────────────
            $table->decimal('amount', 15, 2)
                  ->comment('Số tiền giao dịch (VNĐ)');

            // ── Phương thức thanh toán ────────────────────────────────────
            // vnpay | momo | bank_transfer | cash | other
            $table->string('payment_method', 50)
                  ->comment('Phương thức: vnpay, momo, bank_transfer, cash, other');

            // ── Trạng thái ────────────────────────────────────────────────
            $table->enum('status', ['pending', 'success', 'failed'])
                  ->default('pending')
                  ->comment('pending=chờ xử lý, success=thành công, failed=thất bại');

            // ── Thời điểm thanh toán thực tế ─────────────────────────────
            $table->datetime('paid_at')->nullable()
                  ->comment('Thời điểm giao dịch thành công (từ gateway hoặc xác nhận thủ công)');

            // ── Ghi chú / raw gateway response ───────────────────────────
            $table->text('note')->nullable()
                  ->comment('Ghi chú (lý do thất bại, ghi chú thủ công…)');
            $table->json('gateway_response')->nullable()
                  ->comment('Raw JSON response từ payment gateway, dùng để audit');

            // ── Timestamps ───────────────────────────────────────────────
            $table->timestamps();

            // ── Index truy vấn ────────────────────────────────────────────
            $table->index(['invoice_id', 'status'], 'idx_pay_txn_invoice_status');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_transactions');
    }
};
