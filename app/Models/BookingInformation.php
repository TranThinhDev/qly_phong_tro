<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BookingInformation extends Model
{
    use HasFactory;
    protected $table = "booking_information";
    protected $primaryKey = 'id';
    protected $fillable = [
        'rooms_id',
        'message',
        'email',
        'name',
        'phone',
        'booking_code',
        'booking_type',
        'deposit_amount',
        'payment_method',
        'transaction_id',
        'appointment_date',
        'refund_status',
        'refund_reason',
        'status',
    ];

    protected $casts = [
        'appointment_date' => 'datetime',
    ];

    public function getRoom()
    {
        return $this->belongsTo(Room::class, 'rooms_id', 'id');
    }

    /**
     * Mối quan hệ: Hóa đơn Booking này thuộc về 1 Phòng (Room) cụ thể
     */
    public function room()
    {
        // Liên kết với model Room thông qua cột 'rooms_id'
        return $this->belongsTo(Room::class, 'rooms_id');
    }
}
