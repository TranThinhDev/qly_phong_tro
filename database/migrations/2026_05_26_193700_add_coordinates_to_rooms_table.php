<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Thêm 2 cột tọa độ riêng biệt vào bảng rooms để tính khoảng cách
     * bằng công thức Haversine trực tiếp trên MySQL (nhanh hơn parse JSON).
     */
    public function up(): void
    {
        Schema::table('rooms', function (Blueprint $table) {
            // decimal(10,8): tổng 10 chữ số, 8 số sau dấu phẩy
            // => đủ chính xác đến ~1mm, phù hợp với tọa độ lat/lng Việt Nam
            $table->decimal('latitude', 10, 8)->nullable()->after('latlng');

            // decimal(11,8): lng cần thêm 1 chữ số vì có thể tới 3 chữ số trước dấu phẩy
            $table->decimal('longitude', 11, 8)->nullable()->after('latitude');

            // Index riêng lẻ để MySQL có thể lọc nhanh theo từng chiều
            // (composite index kém hiệu quả hơn cho range query kiểu bán kính)
            $table->index('latitude', 'idx_rooms_latitude');
            $table->index('longitude', 'idx_rooms_longitude');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('rooms', function (Blueprint $table) {
            $table->dropIndex('idx_rooms_latitude');
            $table->dropIndex('idx_rooms_longitude');
            $table->dropColumn(['latitude', 'longitude']);
        });
    }
};
