<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migration: create_invoice_items_table
 *
 * Bảng chi tiết các dòng mục trong một hóa đơn.
 * Mỗi dòng (item) đại diện cho một loại phí:
 *   rent       – tiền thuê phòng
 *   electricity – tiền điện
 *   water      – tiền nước
 *   service    – phí dịch vụ khác (internet, rác, giữ xe…)
 *   late_fee   – phí phạt chậm trả (được tạo tự động khi invoice overdue)
 *
 * Ràng buộc an toàn dữ liệu:
 * ─────────────────────────────────────────────────────────
 * 1. FK → invoices: CASCADE DELETE – hóa đơn bị xóa thì
 *    các dòng chi tiết cũng xóa theo (không có item mồ côi).
 * 2. quantity & unit_price dùng DECIMAL(10,4) để hỗ trợ
 *    định giá điện/nước với nhiều chữ số thập phân
 *    (ví dụ: 0.5 kWh × 3.678 VNĐ/kWh).
 * 3. total = quantity × unit_price được tính & lưu sẵn
 *    để tránh tính lại, đảm bảo nhất quán ngay cả khi
 *    đơn giá thay đổi sau này.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoice_items', function (Blueprint $table) {

            // ── Khóa chính ───────────────────────────────────────────────
            $table->id();

            // ── Liên kết hóa đơn ──────────────────────────────────────────
            $table->unsignedBigInteger('invoice_id')
                  ->comment('FK → invoices.id');
            $table->foreign('invoice_id')
                  ->references('id')->on('invoices')
                  ->onDelete('cascade');  // Item bị xóa theo khi invoice bị xóa

            // ── Loại phí ──────────────────────────────────────────────────
            $table->enum('type', ['rent', 'electricity', 'water', 'service', 'late_fee'])
                  ->comment('rent=tiền thuê, electricity=điện, water=nước, service=dịch vụ, late_fee=phạt trễ');

            // ── Mô tả dòng mục ────────────────────────────────────────────
            // Ví dụ: "Tiền thuê tháng 06/2026", "Điện 120 kWh × 3.500đ"
            $table->string('description')
                  ->comment('Mô tả chi tiết dòng mục, hiển thị trên hóa đơn PDF');

            // ── Số lượng & đơn giá ────────────────────────────────────────
            // DECIMAL(10,4): hỗ trợ đến 4 chữ số thập phân
            // (quantity 0.5 kWh; unit_price 3678.5 VNĐ/kWh)
            $table->decimal('quantity', 10, 4)->default(1)
                  ->comment('Số lượng (kWh, m³, tháng…). Mặc định 1 cho tiền thuê cố định');
            $table->decimal('unit_price', 15, 2)
                  ->comment('Đơn giá (VNĐ)');

            // ── Thành tiền (denormalized) ──────────────────────────────────
            // total = quantity × unit_price, lưu sẵn để không cần tính lại
            $table->decimal('total', 15, 2)
                  ->comment('Thành tiền = quantity × unit_price (VNĐ)');

            // ── Timestamps ───────────────────────────────────────────────
            $table->timestamps();

            // ── Index bổ sung ─────────────────────────────────────────────
            $table->index(['invoice_id', 'type'], 'idx_items_invoice_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_items');
    }
};
