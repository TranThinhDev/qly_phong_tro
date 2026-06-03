<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migration: create_wallet_transactions_table
 *
 * Nhật ký giao dịch ví theo nguyên tắc Double-Entry Bookkeeping.
 * Mỗi hành động thay đổi số dư ví PHẢI tạo ra ít nhất 1 bản ghi
 * tại đây với đầy đủ balance_before / balance_after để audit.
 *
 * Ràng buộc an toàn dữ liệu:
 * ─────────────────────────────────────────────────────────
 * 1. APPEND-ONLY: không bao giờ UPDATE hoặc DELETE bản ghi.
 *    Mọi điều chỉnh phải là bản ghi mới (credit/debit bổ sung).
 * 2. balance_before + amount = balance_after (với credit)
 *    balance_before - amount = balance_after (với debit)
 *    → WalletService đảm bảo bất biến này khi ghi.
 * 3. Polymorphic reference (reference_type + reference_id):
 *    gắn giao dịch với nguồn gốc (Invoice, Contract, v.v.)
 *    mà không cần hard-code FK từng bảng.
 * 4. type ENUM: DB-level constraint, không cho phép loại tùy tiện.
 * 5. KHÔNG có softDeletes – bản ghi tài chính KHÔNG được xóa.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wallet_transactions', function (Blueprint $table) {

            // ── Khóa chính ───────────────────────────────────────────────
            $table->id();

            // ── Ví liên quan ─────────────────────────────────────────────
            $table->unsignedBigInteger('wallet_id')
                  ->comment('FK → wallets.id');
            $table->foreign('wallet_id')
                  ->references('id')->on('wallets')
                  ->onDelete('cascade');  // Xóa ví → xóa lịch sử (hiếm khi xảy ra)

            // ── Loại giao dịch ───────────────────────────────────────────
            $table->enum('type', [
                'deposit_escrow',   // Nạp tiền vào escrow (giữ cọc)
                'release_fund',     // Giải phóng cọc → available_balance
                'withdrawal',       // Rút tiền ra ngoài
                'refund_tenant',    // Hoàn tiền cọc về tenant
                'top_up',          // Nạp tiền vào ví qua cổng thanh toán
                'pay_invoice',     // Thanh toán hóa đơn từ ví
            ])->comment('Loại giao dịch trong vòng đời ví');

            // ── Số tiền giao dịch (luôn DƯƠNG) ──────────────────────────
            // Dấu "tăng/giảm" được xác định bởi `type`, không phải bởi
            // việc số tiền âm – tránh nhầm lẫn khi tính toán.
            $table->decimal('amount', 15, 2)
                  ->comment('Số tiền giao dịch (VNĐ, luôn > 0)');

            // ── Double-Entry: Số dư trước và sau giao dịch ───────────────
            // Giá trị này được WalletService ghi tự động, KHÔNG nhận
            // từ client. Đây là bằng chứng audit quan trọng nhất.
            $table->decimal('balance_before', 15, 2)
                  ->comment('[AUDIT] Số dư available_balance TRƯỚC giao dịch');
            $table->decimal('balance_after', 15, 2)
                  ->comment('[AUDIT] Số dư available_balance SAU giao dịch');

            // ── Polymorphic Reference ────────────────────────────────────
            // Gắn giao dịch với nguồn gốc: Invoice, Contract, PendingWallet...
            // Ví dụ: reference_type='App\Models\Invoice', reference_id=42
            $table->string('reference_type')->nullable()
                  ->comment('Tên class model nguồn gốc (morphTo). Ví dụ: App\\Models\\Invoice');
            $table->unsignedBigInteger('reference_id')->nullable()
                  ->comment('ID của bản ghi nguồn gốc tương ứng với reference_type');

            // Composite index cho morphTo queries
            $table->index(['reference_type', 'reference_id'], 'idx_wallet_txn_morphto');

            // ── Mô tả giao dịch ──────────────────────────────────────────
            $table->string('description')
                  ->comment('Mô tả ngắn gọn hiển thị cho user, ví dụ: "Thanh toán hóa đơn INV-06/2026"');

            // ── Timestamps (chỉ created_at, không có updated_at) ─────────
            // Bản ghi này là IMMUTABLE sau khi tạo → dùng timestamps()
            // nhưng convention: không bao giờ gọi update() trên model này.
            $table->timestamps();

            // ── Index ─────────────────────────────────────────────────────
            $table->index(['wallet_id', 'created_at'], 'idx_wallet_txn_history');
            $table->index('type', 'idx_wallet_txn_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wallet_transactions');
    }
};
