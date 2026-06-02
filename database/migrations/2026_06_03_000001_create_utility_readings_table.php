<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migration: create_utility_readings_table
 *
 * Lưu trữ chỉ số điện/nước theo từng phòng theo từng tháng.
 * Mỗi bản ghi đại diện cho chỉ số đọc được tại đầu/cuối tháng.
 *
 * Ràng buộc an toàn dữ liệu:
 * ─────────────────────────────────────────────────────────
 * 1. UNIQUE (room_id, month, year): đảm bảo không nhập trùng
 *    chỉ số cho cùng một phòng trong cùng một tháng.
 * 2. FK → rooms: SET NULL khi phòng bị xoá mềm để giữ lịch sử.
 * 3. status ENUM: 'draft' (đang nhập) → 'finalized' (đã chốt,
 *    dùng để sinh invoice). Sau khi finalized không cho phép sửa
 *    ở tầng application.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('utility_readings', function (Blueprint $table) {

            // ── Khóa chính ───────────────────────────────────────────────
            $table->id();

            // ── Liên kết phòng ───────────────────────────────────────────
            // Dùng unsignedInteger để khớp kiểu với rooms.id (increments → int)
            $table->unsignedInteger('room_id')
                  ->comment('FK → rooms.id');
            $table->foreign('room_id')
                  ->references('id')->on('rooms')
                  ->onDelete('cascade');

            // ── Kỳ ghi chỉ số ────────────────────────────────────────────
            // Tách month/year thay vì dùng DATE để tránh nhầm lẫn ngày cụ thể.
            // month: 1-12, year: 2020-2099
            $table->tinyInteger('month')->unsigned()
                  ->comment('Tháng ghi chỉ số (1–12)');
            $table->smallInteger('year')->unsigned()
                  ->comment('Năm ghi chỉ số (e.g. 2026)');

            // ── Chỉ số công-tơ ────────────────────────────────────────────
            // Lưu chỉ số CUỐI kỳ (số trên đồng hồ). Sản lượng tiêu thụ =
            // chỉ số tháng này – chỉ số tháng trước (tính tại InvoiceService).
            $table->unsignedInteger('electricity_index')->default(0)
                  ->comment('Chỉ số điện cuối kỳ (kWh)');
            $table->unsignedInteger('water_index')->default(0)
                  ->comment('Chỉ số nước cuối kỳ (m³)');

            // ── Bằng chứng ảnh chụp đồng hồ ──────────────────────────────
            $table->string('evidence_image_url')->nullable()
                  ->comment('URL ảnh chụp đồng hồ (lưu trên Storage/S3)');

            // ── Trạng thái ────────────────────────────────────────────────
            // draft    : đang nhập, có thể sửa
            // finalized: đã chốt, đã sinh invoice, không cho sửa
            $table->enum('status', ['draft', 'finalized'])->default('draft')
                  ->comment('draft=đang nhập, finalized=đã chốt & sinh hóa đơn');

            // ── Timestamps ───────────────────────────────────────────────
            $table->timestamps();

            // ── Ràng buộc duy nhất: 1 bản ghi / phòng / tháng ──────────
            $table->unique(['room_id', 'month', 'year'], 'uniq_reading_room_month_year');

            // ── Index bổ sung để query theo năm/tháng ────────────────────
            $table->index(['year', 'month'], 'idx_readings_year_month');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('utility_readings');
    }
};
