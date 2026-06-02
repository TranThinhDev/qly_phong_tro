<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Model InvoiceItem – Dòng chi tiết của hóa đơn
 *
 * ┌─────────────────────────────────────────────────────────┐
 * │  AN TOÀN DỮ LIỆU                                        │
 * │                                                         │
 * │  1. $fillable: tất cả các trường đều có thể fill vì     │
 * │     InvoiceItem được tạo hoàn toàn qua InvoiceService,  │
 * │     không có mass-assignment từ request người dùng.     │
 * │                                                         │
 * │  2. 'total' = quantity × unit_price: luôn được tính     │
 * │     bởi InvoiceService trước khi lưu, không tính ở DB  │
 * │     để đảm bảo control flow nhất quán.                  │
 * │                                                         │
 * │  3. quantity dùng decimal:4 để hỗ trợ kWh / m³ lẻ.     │
 * └─────────────────────────────────────────────────────────┘
 *
 * @property int    $id
 * @property int    $invoice_id
 * @property string $type         rent|electricity|water|service|late_fee
 * @property string $description
 * @property float  $quantity
 * @property float  $unit_price
 * @property float  $total
 */
class InvoiceItem extends Model
{
    use HasFactory;

    protected $table = 'invoice_items';

    // ── Mass Assignment Protection ────────────────────────────────────────
    protected $fillable = [
        'invoice_id',
        'type',
        'description',
        'quantity',
        'unit_price',
        'total',
    ];

    // ── Type Casting ──────────────────────────────────────────────────────
    protected $casts = [
        'quantity'   => 'decimal:4',
        'unit_price' => 'decimal:2',
        'total'      => 'decimal:2',
    ];

    // ══════════════════════════════════════════════════════════════════════
    // RELATIONSHIPS
    // ══════════════════════════════════════════════════════════════════════

    /**
     * Hóa đơn chứa dòng chi tiết này.
     * InvoiceItem belongsTo Invoice (invoice_id → invoices.id)
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'invoice_id', 'id');
    }

    // ══════════════════════════════════════════════════════════════════════
    // HELPERS
    // ══════════════════════════════════════════════════════════════════════

    /**
     * Tính lại thành tiền từ quantity và unit_price.
     * Tiện dụng khi cần cập nhật một dòng item và sync lại total.
     */
    public function recalculate(): static
    {
        $this->total = round((float) $this->quantity * (float) $this->unit_price, 2);
        return $this;
    }

    // ══════════════════════════════════════════════════════════════════════
    // SCOPES
    // ══════════════════════════════════════════════════════════════════════

    /** Lọc theo loại phí */
    public function scopeOfType($query, string $type)
    {
        return $query->where('type', $type);
    }
}
