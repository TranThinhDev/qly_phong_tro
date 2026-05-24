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
        Schema::table('booking_information', function (Blueprint $table) {
            $table->string('booking_code')->unique();
            $table->enum('booking_type', ['deposit', 'appointment']);
            $table->decimal('deposit_amount', 15, 2)->nullable();
            $table->string('payment_method')->nullable();
            $table->string('transaction_id')->nullable();
            $table->dateTime('appointment_date')->nullable();
            $table->enum('refund_status', ['requested', 'refunded', 'rejected'])->nullable();
            $table->text('refund_reason')->nullable();
            $table->enum('status', ['pending', 'paid', 'cancelled', 'completed'])->default('pending');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('booking_information', function (Blueprint $table) {
            $table->dropColumn([
                'booking_code',
                'booking_type',
                'deposit_amount',
                'payment_method',
                'transaction_id',
                'appointment_date',
                'refund_status',
                'refund_reason',
                'status'
            ]);
        });
    }
};
