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
            
            // Các khóa ngoại (Foreign Keys)
            $table->foreignId('contract_id')->constrained('contracts')->cascadeOnDelete();
            $table->foreignId('room_id')->constrained('rooms')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            
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
