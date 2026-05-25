<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Thêm cột deposit_amount vào bảng rooms để chủ trọ
     * có thể tự cài mức tiền cọc giữ chỗ cho phòng của mình.
     * Nullable để tương thích với phòng không yêu cầu đặt cọc.
     */
    public function up(): void
    {
        Schema::table('rooms', function (Blueprint $table) {
            $table->decimal('deposit_amount', 15, 2)
                  ->nullable()
                  ->after('is_deposit_required')
                  ->comment('Số tiền cọc giữ chỗ chủ trọ yêu cầu (VNĐ). Null nếu không yêu cầu cọc.');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('rooms', function (Blueprint $table) {
            $table->dropColumn('deposit_amount');
        });
    }
};
