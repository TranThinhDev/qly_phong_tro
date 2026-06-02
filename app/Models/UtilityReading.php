<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Model UtilityReading – Chỉ số điện/nước theo tháng
 *
 * ┌─────────────────────────────────────────────────────────┐
 * │  AN TOÀN DỮ LIỆU                                        │
 * │                                                         │
 * │  1. $fillable (whitelist): 'status' KHÔNG nằm trong     │
 * │     $fillable – chỉ được thay đổi qua finalize()        │
 * │     để đảm bảo business rule (không sửa sau finalize).  │
 * │                                                         │
 * │  2. UNIQUE (room_id, month, year) ở DB + kiểm tra ở     │
 * │     InvoiceService đảm bảo không nhập trùng chỉ số.     │
 * │                                                         │
 * │  3. finalize() là phương thức một chiều – không thể     │
 * │     revert từ 'finalized' về 'draft'.                   │
 * └─────────────────────────────────────────────────────────┘
 *
 * @property int         $id
 * @property int         $room_id
 * @property int         $month           1–12
 * @property int         $year            e.g. 2026
 * @property int         $electricity_index  Chỉ số điện cuối kỳ (kWh)
 * @property int         $water_index        Chỉ số nước cuối kỳ (m³)
 * @property string|null $evidence_image_url URL ảnh chụp đồng hồ
 * @property string      $status          draft|finalized
 */
class UtilityReading extends Model
{
    use HasFactory;

    protected $table = 'utility_readings';

    // ── Mass Assignment Protection ────────────────────────────────────────
    // 'status' cố tình KHÔNG nằm trong $fillable.
    // Chỉ được chuyển sang 'finalized' qua phương thức finalize().
    protected $fillable = [
        'room_id',
        'month',
        'year',
        'electricity_index',
        'water_index',
        'evidence_image_url',
    ];

    // ── Type Casting ──────────────────────────────────────────────────────
    protected $casts = [
        'month'             => 'integer',
        'year'              => 'integer',
        'electricity_index' => 'integer',
        'water_index'       => 'integer',
    ];

    // ══════════════════════════════════════════════════════════════════════
    // RELATIONSHIPS
    // ══════════════════════════════════════════════════════════════════════

    /**
     * Phòng trọ thuộc chỉ số này.
     * UtilityReading belongsTo Room (room_id → rooms.id)
     */
    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class, 'room_id', 'id');
    }

    // ══════════════════════════════════════════════════════════════════════
    // BUSINESS LOGIC METHODS
    // ══════════════════════════════════════════════════════════════════════

    /**
     * Chốt chỉ số – chuyển từ 'draft' sang 'finalized'.
     * Hành động một chiều: sau khi finalized không thể revert.
     *
     * Thường được gọi từ InvoiceService::generateForContract()
     * ngay sau khi hóa đơn được sinh thành công.
     *
     * @throws \LogicException Nếu chỉ số đã được finalized trước đó
     */
    public function finalize(): bool
    {
        if ($this->status === 'finalized') {
            throw new \LogicException(
                "Chỉ số phòng #{$this->room_id} tháng {$this->month}/{$this->year} đã được chốt."
            );
        }

        $this->forceFill(['status' => 'finalized'])->save();

        return true;
    }

    /**
     * Kiểm tra chỉ số đã được chốt chưa.
     */
    public function isFinalized(): bool
    {
        return $this->status === 'finalized';
    }

    // ══════════════════════════════════════════════════════════════════════
    // SCOPES
    // ══════════════════════════════════════════════════════════════════════

    /** Lọc chỉ số theo tháng và năm */
    public function scopeForMonth($query, int $month, int $year)
    {
        return $query->where('month', $month)->where('year', $year);
    }

    /** Lọc chỉ số chưa chốt (draft) */
    public function scopeDraft($query)
    {
        return $query->where('status', 'draft');
    }

    /** Lọc chỉ số đã chốt */
    public function scopeFinalized($query)
    {
        return $query->where('status', 'finalized');
    }
}
