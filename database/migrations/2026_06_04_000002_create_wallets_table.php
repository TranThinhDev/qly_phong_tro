<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migration: create_wallets_table
 *
 * Ví điện tử của người dùng trong hệ thống.
 * Mỗi user chỉ có DUY NHẤT một wallet (UNIQUE constraint).
 *
 * Thiết kế dual-balance (hai số dư tách biệt):
 * ─────────────────────────────────────────────────────────
 * ┌──────────────────────┬──────────────────────────────────┐
 * │ available_balance    │ Số dư khả dụng, có thể rút/dùng  │
 * │ pending_balance      │ Đang bị giữ trong escrow (cọc)    │
 * └──────────────────────┴──────────────────────────────────┘
 *
 * Ràng buộc an toàn dữ liệu:
 * ─────────────────────────────────────────────────────────
 * 1. UNIQUE(user_id): mỗi user chỉ có 1 ví, tránh race condition
 *    tạo ví trùng lặp ngay cả khi có concurrent request.
 * 2. DECIMAL(15,2): không bao giờ dùng FLOAT cho tiền tệ.
 * 3. DEFAULT 0 cho cả hai balance: wallet mới tạo luôn bắt đầu
 *    từ số 0, không có giá trị NULL.
 * 4. Mọi thay đổi balance PHẢI đi qua WalletService với
 *    DB::transaction() + lockForUpdate() – không update trực tiếp.
 * 5. is_frozen: admin có thể đóng băng ví để điều tra gian lận.
 *    Khi frozen = true, WalletService từ chối mọi giao dịch.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wallets', function (Blueprint $table) {

            // ── Khóa chính ───────────────────────────────────────────────
            $table->id();

            // ── Chủ ví (1 user – 1 wallet) ──────────────────────────────
            // UNIQUE constraint đảm bảo không bao giờ có 2 ví cùng user_id
            $table->unsignedInteger('user_id')->unique()
                  ->comment('FK → users.id. UNIQUE: mỗi user chỉ có 1 ví');
            $table->foreign('user_id')
                  ->references('id')->on('users')
                  ->onDelete('cascade');  // Xóa user → xóa ví

            // ── Số dư khả dụng ───────────────────────────────────────────
            // Số tiền user có thể sử dụng ngay (nạp thành công, đã giải phóng)
            $table->decimal('available_balance', 12, 2)->default(0)
                  ->comment('Số dư khả dụng (VNĐ). Chỉ thay đổi qua WalletService');

            // ── Số dư đang giữ (Escrow/Pending) ─────────────────────────
            // Tiền đang bị giữ để đảm bảo (cọc hợp đồng chưa giải phóng)
            $table->decimal('pending_balance', 12, 2)->default(0)
                  ->comment('Số dư đang giữ trong escrow (VNĐ). Không thể rút');

            // ── Trạng thái đóng băng ─────────────────────────────────────
            $table->boolean('is_frozen')->default(false)
                  ->comment('true = ví bị đóng băng, từ chối mọi giao dịch');

            // ── Timestamps ───────────────────────────────────────────────
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wallets');
    }
};
