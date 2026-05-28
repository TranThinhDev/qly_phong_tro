<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Model Transaction – Giao dịch tài chính
 *
 * ┌─────────────────────────────────────────────────────────┐
 * │  AN TOÀN DỮ LIỆU                                        │
 * │                                                         │
 * │  1. $fillable (whitelist): KHÔNG cho phép mass-assign   │
 * │     'status' từ ngoài – trạng thái chỉ được thay đổi   │
 * │     qua markAsCompleted() / markAsFailed() để đảm bảo  │
 * │     logic business chạy đúng.                           │
 * │                                                         │
 * │  2. 'gateway_response' cast sang 'array': tự động       │
 * │     json_encode/decode, không cần làm thủ công.         │
 * │                                                         │
 * │  3. 'transaction_code' UNIQUE ở DB đảm bảo idempotency  │
 * │     – gateway callback trùng không tạo giao dịch đôi.  │
 * │                                                         │
 * │  4. Record là APPEND-ONLY về mặt nghiệp vụ: không bao  │
 * │     giờ UPDATE amount hay type sau khi tạo. Dùng        │
 * │     $guarded chỉ cho các trường tạo lúc đầu là đủ.     │
 * └─────────────────────────────────────────────────────────┘
 *
 * @property int         $id
 * @property string      $transaction_code
 * @property int|null    $contract_id
 * @property int         $payer_id
 * @property string      $type
 * @property float       $amount
 * @property string      $currency
 * @property string|null $payment_method
 * @property string|null $gateway_transaction_id
 * @property array|null  $gateway_response
 * @property string      $status
 * @property string|null $note
 * @property \Carbon\Carbon|null $paid_at
 */
class Transaction extends Model
{
    use HasFactory;

    protected $table = 'transactions';

    // ── Mass Assignment Protection ────────────────────────────────────────
    // 'status' và 'paid_at' KHÔNG trong $fillable.
    // Chúng chỉ được cập nhật qua method markAsCompleted() / markAsFailed().
    protected $fillable = [
        'transaction_code',
        'contract_id',
        'payer_id',
        'type',
        'amount',
        'currency',
        'payment_method',
        'gateway_transaction_id',
        'gateway_response',
        'note',
    ];

    // ── Type Casting ──────────────────────────────────────────────────────
    protected $casts = [
        'amount'           => 'decimal:2',
        'gateway_response' => 'array',    // JSON ↔ PHP array tự động
        'paid_at'          => 'datetime',
    ];

    // ══════════════════════════════════════════════════════════════════════
    // RELATIONSHIPS
    // ══════════════════════════════════════════════════════════════════════

    /**
     * Hợp đồng liên quan đến giao dịch.
     * Transaction belongsTo Contract (contract_id → contracts.id)
     * Nullable: giao dịch có thể không gắn với hợp đồng cụ thể.
     */
    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class, 'contract_id', 'id');
    }

    /**
     * Người thực hiện giao dịch (tenant).
     * Transaction belongsTo User (payer_id → users.id)
     */
    public function payer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'payer_id', 'id');
    }

    // ══════════════════════════════════════════════════════════════════════
    // BUSINESS LOGIC METHODS
    // ══════════════════════════════════════════════════════════════════════

    /**
     * Đánh dấu giao dịch hoàn tất thành công.
     * Ghi paid_at = now() và status = 'completed'.
     * Chỉ hoạt động khi status đang là 'pending'.
     *
     * @throws \LogicException Nếu giao dịch không ở trạng thái pending
     */
    public function markAsCompleted(?array $gatewayResponse = null): bool
    {
        if ($this->status !== 'pending') {
            throw new \LogicException(
                "Không thể hoàn tất giao dịch #{$this->id} đang ở trạng thái '{$this->status}'."
            );
        }

        $this->forceFill([
            'status'  => 'completed',
            'paid_at' => now(),
        ]);

        if ($gatewayResponse !== null) {
            $this->forceFill(['gateway_response' => $gatewayResponse]);
        }

        return $this->save();
    }

    /**
     * Đánh dấu giao dịch thất bại.
     *
     * @throws \LogicException Nếu giao dịch đã hoàn tất hoặc đã hoàn tiền
     */
    public function markAsFailed(?string $reason = null): bool
    {
        if (in_array($this->status, ['completed', 'refunded'], true)) {
            throw new \LogicException(
                "Không thể đánh dấu thất bại giao dịch #{$this->id} đang ở trạng thái '{$this->status}'."
            );
        }

        $this->forceFill([
            'status' => 'failed',
            'note'   => $reason ?? $this->note,
        ]);

        return $this->save();
    }

    /**
     * Kiểm tra giao dịch đã hoàn tất thành công chưa.
     */
    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }

    // ══════════════════════════════════════════════════════════════════════
    // SCOPES
    // ══════════════════════════════════════════════════════════════════════

    /** Lọc giao dịch đã hoàn tất */
    public function scopeCompleted($query)
    {
        return $query->where('status', 'completed');
    }

    /** Lọc theo loại giao dịch */
    public function scopeOfType($query, string $type)
    {
        return $query->where('type', $type);
    }

    /** Lọc giao dịch thanh toán tiền cọc */
    public function scopeDepositPayments($query)
    {
        return $query->where('type', 'deposit_payment');
    }
}
