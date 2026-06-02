<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Model PaymentTransaction – Lịch sử giao dịch thanh toán hóa đơn
 *
 * ┌─────────────────────────────────────────────────────────┐
 * │  AN TOÀN DỮ LIỆU                                        │
 * │                                                         │
 * │  1. 'status' và 'paid_at' KHÔNG nằm trong $fillable.    │
 * │     Chỉ được cập nhật qua markAsSuccess() / markAsFailed│
 * │     để đảm bảo business logic chạy đúng (cập nhật       │
 * │     invoice.status song song).                          │
 * │                                                         │
 * │  2. transaction_code UNIQUE ở DB: idempotency – nếu     │
 * │     VNPay gửi IPN 2 lần thì lần 2 sẽ fail ở DB-level.  │
 * │                                                         │
 * │  3. gateway_response: lưu raw payload để audit,         │
 * │     không bao giờ xóa sau khi xử lý.                   │
 * └─────────────────────────────────────────────────────────┘
 *
 * @property int         $id
 * @property int|null    $invoice_id
 * @property string      $transaction_code
 * @property float       $amount
 * @property string      $payment_method   vnpay|momo|bank_transfer|cash|other
 * @property string      $status           pending|success|failed
 * @property \Carbon\Carbon|null $paid_at
 * @property string|null $note
 * @property array|null  $gateway_response
 */
class PaymentTransaction extends Model
{
    use HasFactory;

    protected $table = 'payment_transactions';

    // ── Mass Assignment Protection ────────────────────────────────────────
    // 'status' và 'paid_at' cố tình KHÔNG nằm trong $fillable.
    protected $fillable = [
        'invoice_id',
        'transaction_code',
        'amount',
        'payment_method',
        'note',
        'gateway_response',
    ];

    // ── Type Casting ──────────────────────────────────────────────────────
    protected $casts = [
        'amount'           => 'decimal:2',
        'paid_at'          => 'datetime',
        'gateway_response' => 'array',   // JSON ↔ PHP array tự động
    ];

    // ══════════════════════════════════════════════════════════════════════
    // RELATIONSHIPS
    // ══════════════════════════════════════════════════════════════════════

    /**
     * Hóa đơn gắn với giao dịch này.
     * PaymentTransaction belongsTo Invoice (invoice_id → invoices.id)
     * Nullable: giao dịch vẫn tồn tại nếu hóa đơn bị xóa mềm.
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'invoice_id', 'id');
    }

    // ══════════════════════════════════════════════════════════════════════
    // BUSINESS LOGIC METHODS
    // ══════════════════════════════════════════════════════════════════════

    /**
     * Đánh dấu giao dịch thanh toán thành công.
     *
     * Cách dùng (thường gọi từ InvoiceService sau khi verify VNPay):
     *   $paymentTransaction->markAsSuccess($vnpayResponse);
     *
     * @param  array|null $gatewayResponse Raw response từ cổng thanh toán
     * @throws \LogicException Nếu giao dịch không ở trạng thái pending
     */
    public function markAsSuccess(?array $gatewayResponse = null): bool
    {
        if ($this->status !== 'pending') {
            throw new \LogicException(
                "Không thể xác nhận thành công giao dịch #{$this->id} đang ở trạng thái '{$this->status}'."
            );
        }

        $data = [
            'status'  => 'success',
            'paid_at' => now(),
        ];

        if ($gatewayResponse !== null) {
            $data['gateway_response'] = $gatewayResponse;
        }

        $this->forceFill($data)->save();

        return true;
    }

    /**
     * Đánh dấu giao dịch thất bại.
     *
     * @param  string|null $reason Lý do thất bại (ghi vào cột note)
     * @param  array|null  $gatewayResponse Raw response từ cổng
     * @throws \LogicException Nếu giao dịch đã thành công
     */
    public function markAsFailed(?string $reason = null, ?array $gatewayResponse = null): bool
    {
        if ($this->status === 'success') {
            throw new \LogicException(
                "Không thể đánh dấu thất bại giao dịch #{$this->id} đã thành công."
            );
        }

        $data = ['status' => 'failed'];

        if ($reason !== null) {
            $data['note'] = $reason;
        }

        if ($gatewayResponse !== null) {
            $data['gateway_response'] = $gatewayResponse;
        }

        $this->forceFill($data)->save();

        return true;
    }

    /**
     * Kiểm tra giao dịch đã thành công chưa.
     */
    public function isSuccess(): bool
    {
        return $this->status === 'success';
    }

    // ══════════════════════════════════════════════════════════════════════
    // SCOPES
    // ══════════════════════════════════════════════════════════════════════

    /** Lọc giao dịch thành công */
    public function scopeSuccessful($query)
    {
        return $query->where('status', 'success');
    }

    /** Lọc giao dịch đang chờ xử lý */
    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    /** Lọc theo phương thức thanh toán */
    public function scopeByMethod($query, string $method)
    {
        return $query->where('payment_method', $method);
    }
}
