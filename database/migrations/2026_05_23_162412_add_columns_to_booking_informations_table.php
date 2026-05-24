<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('booking_information', function (Blueprint $table) {

            if (!Schema::hasColumn('booking_information', 'booking_code')) {
                $table->string('booking_code')->nullable();
            }

            if (!Schema::hasColumn('booking_information', 'booking_type')) {
                $table->enum('booking_type', ['deposit', 'appointment']);
            }

            if (!Schema::hasColumn('booking_information', 'deposit_amount')) {
                $table->decimal('deposit_amount', 15, 2)->nullable();
            }

            if (!Schema::hasColumn('booking_information', 'payment_method')) {
                $table->string('payment_method')->nullable();
            }

            if (!Schema::hasColumn('booking_information', 'transaction_id')) {
                $table->string('transaction_id')->nullable();
            }

            if (!Schema::hasColumn('booking_information', 'appointment_date')) {
                $table->dateTime('appointment_date')->nullable();
            }

            if (!Schema::hasColumn('booking_information', 'refund_status')) {
                $table->enum('refund_status', ['requested', 'refunded', 'rejected'])->nullable();
            }

            if (!Schema::hasColumn('booking_information', 'refund_reason')) {
                $table->text('refund_reason')->nullable();
            }

            if (!Schema::hasColumn('booking_information', 'status')) {
                $table->enum('status', ['pending', 'paid', 'cancelled', 'completed'])
                    ->default('pending');
            }
        });

        // Generate unique booking codes
        $rows = DB::table('booking_information')
            ->whereNull('booking_code')
            ->orWhere('booking_code', '')
            ->get();

        foreach ($rows as $row) {
            DB::table('booking_information')
                ->where('id', $row->id)
                ->update([
                    'booking_code' => 'BK-' . strtoupper(Str::random(10))
                ]);
        }

        // Add unique index nếu chưa có
        Schema::table('booking_information', function (Blueprint $table) {
            try {
                $table->unique('booking_code');
            } catch (\Exception $e) {
            }
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
