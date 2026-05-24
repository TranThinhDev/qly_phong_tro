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
        Schema::table('rooms', function (Blueprint $table) {
            $table->tinyInteger('status')->default(1)->comment('1: Available, 2: Holding, 3: Booked, 4: Rented');
            $table->timestamp('hold_until')->nullable();
            $table->boolean('is_deposit_required')->default(0);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('rooms', function (Blueprint $table) {
            $table->dropColumn(['status', 'hold_until', 'is_deposit_required']);
        });
    }
};
