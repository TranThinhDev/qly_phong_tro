<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('room_occupants', function (Blueprint $table) {
            $table->id();

            // ── Khóa ngoại ───────────────────────────────────────────────
            // contracts.id là BIGINT (dùng $table->id()) → unsignedBigInteger
            $table->unsignedBigInteger('contract_id');
            $table->foreign('contract_id')->references('id')->on('contracts')->onDelete('cascade');

            // rooms.id là UNSIGNED INT (dùng $table->increments()) → unsignedInteger
            $table->unsignedInteger('room_id');
            $table->foreign('room_id')->references('id')->on('rooms')->onDelete('cascade');

            // users.id là UNSIGNED INT (dùng $table->increments()) → unsignedInteger
            $table->unsignedInteger('user_id')->nullable();
            $table->foreign('user_id')->references('id')->on('users')->onDelete('set null');

            // Thông tin cá nhân
            $table->string('full_name');
            $table->string('phone')->nullable();
            $table->string('identity_card_number');
            $table->date('dob')->nullable();
            $table->string('hometown')->nullable();

            // Đánh dấu người đại diện phòng
            $table->boolean('is_representative')->default(false);

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('room_occupants');
    }
};
