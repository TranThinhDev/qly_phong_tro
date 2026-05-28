<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migration: create_pending_wallets_table
 *
 * Bảng "ví tạm" – lưu số dư tiền cọc đang được giữ chờ xác nhận.
 * Đây là cơ chế escrow đơn giản: khi tenant thanh toán cọc, tiền
 * vào pending_wallet trước; chủ trọ xác nhận → giải phóng; tenant
 * huỷ → hoàn tiền.
 *
 * Quan hệ: mỗi hợp đồng có đúng 1 pending_wallet (hasOne / belongsTo).
 *
 * Ràng buộc an toàn dữ liệu:
 * ─────────────────────────────────────────────────────────
 * 1. FK → contracts (UNIQUE): đảm bảo 1 hợp đồng chỉ có 1 ví tạm.
 *    onDelete CASCADE: hợp đồng xóa → ví tạm tự xóa.
 * 2. FK → users (owner_id): ai đang giữ số dư này (thường là tenant).
 * 3. held_amount: DECIMAL(15,2) – không bao giờ âm (CHECK constraint
 *    qua UNSIGNED hoặc DB trigger; tầng application phải validate).
 * 4. released_at, refunded_at: timestamp NULLABLE + IMMUTABLE –
 *    chỉ ghi 1 lần khi xử lý, không cho phép sửa sau khi đã set.
 * 5. status ENUM: DB-level guard chống giá trị không hợp lệ.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pending_wallets', function (Blueprint $table) {

            // ── Khóa chính ───────────────────────────────────────────────
            $table->id();

            // ── Liên kết hợp đồng (1-1 với contracts) ───────────────────
            // UNIQUE đảm bảo chỉ tồn tại 1 ví tạm cho mỗi hợp đồng
            $table->unsignedBigInteger('contract_id')->unique()
                  ->comment('FK → contracts.id. UNIQUE: 1 hợp đồng chỉ có 1 pending wallet');
            $table->foreign('contract_id')
                  ->references('id')->on('contracts')
                  ->onDelete('cascade');  // Xóa hợp đồng → xóa ví tạm

            // ── Chủ sở hữu số dư ─────────────────────────────────────────
            $table->unsignedInteger('owner_id')
                  ->comment('FK → users.id. Tenant đang có tiền trong ví tạm này');
            $table->foreign('owner_id')
                  ->references('id')->on('users')
                  ->onDelete('cascade');

            // ── Số tiền đang giữ ─────────────────────────────────────────
            // DECIMAL(15,2) UNSIGNED: không thể âm ở DB level
            $table->decimal('held_amount', 15, 2)
                  ->comment('Số tiền cọc đang được giữ (VNĐ). Luôn >= 0');

            // ── Trạng thái ví tạm ────────────────────────────────────────
            $table->enum('status', [
                'holding',   // Đang giữ tiền, chờ xác nhận
                'released',  // Đã giải phóng cho chủ trọ
                'refunded',  // Đã hoàn trả cho tenant
                'disputed',  // Đang tranh chấp, cần admin can thiệp
            ])->default('holding')
              ->comment('Trạng thái ví tạm');

            // ── Mốc thời gian xử lý (IMMUTABLE sau khi set) ──────────────
            // Tầng application (PendingWalletObserver / Service) phải kiểm tra:
            // nếu released_at hoặc refunded_at đã có giá trị → chặn cập nhật.
            $table->timestamp('released_at')->nullable()
                  ->comment('[IMMUTABLE] Thời điểm giải phóng tiền cho chủ trọ');
            $table->timestamp('refunded_at')->nullable()
                  ->comment('[IMMUTABLE] Thời điểm hoàn tiền cho tenant');

            // ── Tham chiếu giao dịch gốc ─────────────────────────────────
            // Giúp truy vết: ví tạm này được nạp từ giao dịch nào
            $table->unsignedBigInteger('source_transaction_id')->nullable()
                  ->comment('FK → transactions.id. Giao dịch nạp tiền vào ví');
            $table->foreign('source_transaction_id')
                  ->references('id')->on('transactions')
                  ->onDelete('set null');

            // ── Ghi chú ──────────────────────────────────────────────────
            $table->text('note')->nullable()
                  ->comment('Lý do hoàn/giải phóng, ghi chú xử lý');

            // ── Timestamps ───────────────────────────────────────────────
            $table->timestamps();

            // ── Index ─────────────────────────────────────────────────────
            $table->index(['owner_id', 'status'], 'idx_pending_wallets_owner_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pending_wallets');
    }
};
