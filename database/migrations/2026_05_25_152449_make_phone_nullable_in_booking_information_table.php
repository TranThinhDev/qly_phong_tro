<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Cột `phone` trong booking_information được định nghĩa NOT NULL ở migration gốc
     * nhưng user có thể chưa điền SĐT trong profile → gây SQLSTATE[23000] khi tạo booking.
     * Cột `message` cũng được đổi thành nullable vì luồng booking mới không dùng field này.
     *
     * Dùng raw SQL thay cho ->change() vì project chưa cài doctrine/dbal.
     */
    public function up(): void
    {
        DB::statement('ALTER TABLE booking_information MODIFY COLUMN phone VARCHAR(255) NULL');
        DB::statement('ALTER TABLE booking_information MODIFY COLUMN message TEXT NULL');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Lưu ý: rollback có thể thất bại nếu tồn tại bản ghi có phone = NULL
        DB::statement("UPDATE booking_information SET phone = '' WHERE phone IS NULL");
        DB::statement("UPDATE booking_information SET message = '' WHERE message IS NULL");
        DB::statement('ALTER TABLE booking_information MODIFY COLUMN phone VARCHAR(255) NOT NULL');
        DB::statement('ALTER TABLE booking_information MODIFY COLUMN message TEXT NOT NULL');
    }
};
