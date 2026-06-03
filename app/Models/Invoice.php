<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Model Invoice – Hóa đơn thanh toán hàng tháng
 *
 * ┌─────────────────────────────────────────────────────────┐
 * │  AN TOÀN DỮ LIỆU                                        │
 * │                                                         │
 * │  1. $fillable (whitelist): 'status' và 'late_fee'       │
 * │     KHÔNG nằm trong $fillable – chỉ được thay đổi       │
 * │     qua các method có kiểm soát (markAsPaid, v.v.).     │
 * │                                                         │
 * │  2. SoftDeletes: không bao giờ xóa cứng hóa đơn.        │
 * │                                                         │
 * │  3. total_amount & late_fee: DECIMAL(15,2) ở DB,        │
 * │     cast sang 'decimal:2' ở Model – không dùng float.   │
 * │                                                         │
 * │  4. State machine: chỉ cho phép chuyển trạng thái       │
 * │     theo luồng hợp lệ qua transitionTo().               │
 * └─────────────────────────────────────────────────────────┘
 *
 * @property int         $id
 * @property string      $invoice_code    Mã hóa đơn, e.g. INV-06/2026-00001
 * @property int|null    $contract_id
 * @property int         $tenant_id
 * @property string      $billing_month   Format MM/YYYY, e.g. '06/2026'
 * @property float       $total_amount
 * @property float       $late_fee
 * @property string      $status          unpaid|partial|paid|overdue|cancelled
 * @property \Carbon\Carbon $due_date
 * @property string|null $notes
 */
class Invoice extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'invoices';

    // ── Mass Assignment Protection ────────────────────────────────────────
    // 'status' và 'late_fee' cố tình KHÔNG nằm trong $fillable.
    // total_amount được tính lại bởi InvoiceService::recalculate()
    // nên cũng không nên mass-assign từ request.
    protected $fillable = [
        'invoice_code',
        'contract_id',
        'tenant_id',
        'billing_month',
        'due_date',
        'notes',
    ];

    // ── Type Casting ──────────────────────────────────────────────────────
    protected $casts = [
        'due_date'     => 'date',
        'total_amount' => 'decimal:2',
        'late_fee'     => 'decimal:2',
    ];

    // ══════════════════════════════════════════════════════════════════════
    // RELATIONSHIPS
    // ══════════════════════════════════════════════════════════════════════

    /**
     * Hợp đồng gắn với hóa đơn này.
     * Invoice belongsTo Contract (contract_id → contracts.id)
     * Nullable: hóa đơn vẫn tồn tại nếu hợp đồng bị xóa mềm.
     */
    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class, 'contract_id', 'id');
    }

    /**
     * Khách thuê của hóa đơn.
     * Invoice belongsTo User (tenant_id → users.id)
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(User::class, 'tenant_id', 'id');
    }

    /**
     * Các dòng chi tiết của hóa đơn.
     * Invoice hasMany InvoiceItem (invoices.id → invoice_items.invoice_id)
     */
    public function items(): HasMany
    {
        return $this->hasMany(InvoiceItem::class, 'invoice_id', 'id');
    }

    /**
     * Lịch sử thanh toán của hóa đơn.
     * Invoice hasMany PaymentTransaction (invoices.id → payment_transactions.invoice_id)
     */
    public function paymentTransactions(): HasMany
    {
        return $this->hasMany(PaymentTransaction::class, 'invoice_id', 'id');
    }

    /**
     * Chỉ lấy các giao dịch thanh toán thành công.
     */
    public function successfulPayments(): HasMany
    {
        return $this->hasMany(PaymentTransaction::class, 'invoice_id', 'id')
                    ->where('status', 'success');
    }

    // ══════════════════════════════════════════════════════════════════════
    // STATE MACHINE
    // ══════════════════════════════════════════════════════════════════════

    /**
     * Chuyển trạng thái hóa đơn theo state machine có kiểm soát.
     *
     * Luồng hợp lệ:
     *   unpaid  → partial | paid | overdue | cancelled
     *   partial → paid | overdue | cancelled
     *   overdue → paid | cancelled
     *   paid    → [terminal, không cho phép chuyển]
     *   cancelled → [terminal]
     *
     * @throws \InvalidArgumentException Nếu chuyển trạng thái không hợp lệ
     */
    public function transitionTo(string $newStatus): bool
    {
        $allowedTransitions = [
            'unpaid'    => ['partial', 'paid', 'overdue', 'cancelled'],
            'partial'   => ['paid', 'overdue', 'cancelled'],
            'overdue'   => ['paid', 'cancelled'],
            'paid'      => [],
            'cancelled' => [],
        ];

        $current = $this->status;

        if (! in_array($newStatus, $allowedTransitions[$current] ?? [], true)) {
            throw new \InvalidArgumentException(
                "Không thể chuyển trạng thái hóa đơn từ '{$current}' sang '{$newStatus}'."
            );
        }

        $this->forceFill(['status' => $newStatus]);

        return $this->save();
    }

    // ══════════════════════════════════════════════════════════════════════
    // BUSINESS LOGIC METHODS
    // ══════════════════════════════════════════════════════════════════════

    /**
     * Tính lại total_amount từ tổng các dòng items.
     * Gọi sau khi thêm/sửa/xóa InvoiceItem.
     */
    public function recalculateTotal(): bool
    {
        // Bỏ qua item late_fee để cộng riêng bằng cột late_fee, tránh tính đúp
        $subtotal = $this->items()->where('type', '!=', 'late_fee')->sum('total');
        
        $this->forceFill([
            'total_amount' => $subtotal + (float) $this->late_fee,
        ]);

        return $this->save();
    }

    /**
     * Áp dụng phí phạt trễ hạn và cập nhật total_amount.
     *
     * @param float $lateFeeAmount Số tiền phạt (VNĐ)
     */
    public function applyLateFee(float $lateFeeAmount): bool
    {
        // 1. Cập nhật cột late_fee
        $this->forceFill([
            'late_fee' => $lateFeeAmount,
        ])->save();

        // 2. Tạo hoặc cập nhật InvoiceItem cho khoản phạt này
        $this->items()->updateOrCreate(
            ['type' => 'late_fee'],
            [
                'description' => 'Phí phạt trễ hạn thanh toán',
                'quantity'    => 1,
                'unit_price'  => $lateFeeAmount,
                'total'       => $lateFeeAmount,
            ]
        );

        // 3. Tính lại tổng
        return $this->recalculateTotal();
    }

    /**
     * Tính tổng số tiền đã thanh toán thành công.
     */
    public function totalPaid(): float
    {
        return (float) $this->successfulPayments()->sum('amount');
    }

    /**
     * Tính số tiền còn lại cần thanh toán.
     */
    public function remainingAmount(): float
    {
        return max(0, (float) $this->total_amount - $this->totalPaid());
    }

    /**
     * Kiểm tra hóa đơn đã quá hạn chưa.
     */
    public function isOverdue(): bool
    {
        return ! in_array($this->status, ['paid', 'cancelled'], true)
            && $this->due_date->isPast();
    }

    /**
     * Kiểm tra hóa đơn đã thanh toán đủ chưa.
     */
    public function isPaid(): bool
    {
        return $this->status === 'paid';
    }

    // ══════════════════════════════════════════════════════════════════════
    // SCOPES
    // ══════════════════════════════════════════════════════════════════════

    /** Lọc hóa đơn chưa thanh toán */
    public function scopeUnpaid($query)
    {
        return $query->where('status', 'unpaid');
    }

    /** Lọc hóa đơn quá hạn */
    public function scopeOverdue($query)
    {
        return $query->where('status', 'overdue');
    }

    /** Lọc hóa đơn đã thanh toán */
    public function scopePaid($query)
    {
        return $query->where('status', 'paid');
    }

    /** Lọc theo kỳ thanh toán, ví dụ: scopeForBillingMonth('06/2026') */
    public function scopeForBillingMonth($query, string $billingMonth)
    {
        return $query->where('billing_month', $billingMonth);
    }

    /** Lọc hóa đơn sắp đến hạn (trong N ngày tới) */
    public function scopeDueSoon($query, int $daysAhead = 3)
    {
        return $query->whereIn('status', ['unpaid', 'partial'])
                     ->whereDate('due_date', '<=', now()->addDays($daysAhead))
                     ->whereDate('due_date', '>=', now());
    }
}
