<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RoomOccupant extends Model
{
    use HasFactory;

    protected $fillable = [
        'contract_id',
        'room_id',
        'user_id',
        'full_name',
        'phone',
        'identity_card_number',
        'dob',
        'hometown',
        'is_representative',
    ];

    // Cast dữ liệu về đúng định dạng
    protected $casts = [
        'dob' => 'date',
        'is_representative' => 'boolean',
    ];

    /**
     * Quan hệ với Hợp đồng (Contract)
     */
    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    /**
     * Quan hệ với Phòng (Room)
     */
    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    /**
     * Quan hệ với Tài khoản người dùng (User)
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
