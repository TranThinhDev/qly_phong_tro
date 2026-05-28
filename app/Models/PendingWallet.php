<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Model PendingWallet – Ví tiền tạm (Escrow đơn giản)
 *
 * ┌─────────────────────────────────────────────────────────┐
 * │  AN TOÀN DỮ LIỆU                                        │
 * │                                                         │
 * │  1. $fillable (whitelist): 'status', 'released_at',     │
 * │     'refunded_at' KHÔNG nằm trong $fillable. Chúng      │
 * │     chỉ được thay đổi qua release() hoặc refund().      │
 * │                                                         │
 * │  2. Immutability: released_at & refunded_at là write-   │
 * │     once – method kiểm tra trước khi ghi, ném exception │
 * │     nếu đã có giá trị (tương tự Clickwrap ở Contract).  │
 * │                                                         │
 * │  3. held_amount luôn >= 0: validate ở FormRequest và    │
 * │     dựa vào DECIMAL UNSIGNED ở DB.                      │
 * │                                                         │
 * │  4. Quan hệ 1-1 với Contract được đảm bảo bởi           │
 * │     UNIQUE(contract_id) ở DB + hasOne ở Contract model. │
 * └─────────────────────────────────────────────────────────┘
 *
 * @property int         $id
 * @property int         $contract_id
 * @property int         $owner_id
 * @property float       $held_amount
 * @property string      $status           holding|released|refunded|disputed
 * @property \Carbon\Carbon|null $released_at  [IMMUTABLE]
 * @property \Carbon\Carbon|null $refunded_at  [IMMUTABLE]
 * @property int|null    $source_transaction_id
 * @property string|null $note
 */
class PendingWallet extends Model
{
    use HasFactory;

    protected $table = 'pending_wallets';

    // ── Mass Assignment Protection ────────────────────────────────────────
    // 'status', 'released_at', 'refunded_at' bị loại ra khỏi $fillable.
    // Chỉ được thay đổi qua release() và refund() để đảm bảo business rules.
    protected $fillable = [
        'contract_id',
        'owner_id',
        'held_amount',
        'source_transaction_id',
        'note',
    ];

    // ── Type Casting ──────────────────────────────────────────────────────
    protected $casts = [
        'held_amount'  => 'decimal:2',
        'released_at'  => 'datetime',   // Carbon – dễ format & so sánh
        'refunded_at'  => 'datetime',   // Carbon – dễ format & so sánh
    ];

    // ══════════════════════════════════════════════════════════════════════
    // RELATIONSHIPS
    // ══════════════════════════════════════════════════════════════════════

    /**
     * Hợp đồng mà ví tạm này thuộc về.
     * PendingWallet belongsTo Contract (contract_id → contracts.id)
     * Quan hệ nghịch của Contract::hasOne('pendingWallet').
     */
    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class, 'contract_id', 'id');
    }

    /**
     * Tenant sở hữu số dư trong ví tạm.
     * PendingWallet belongsTo User (owner_id → users.id)
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id', 'id');
    }

    /**
     * Giao dịch nguồn đã nạp tiền vào ví.
     * PendingWallet belongsTo Transaction (source_transaction_id → transactions.id)
     */
    public function sourceTransaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class, 'source_transaction_id', 'id');
    }

    // ══════════════════════════════════════════════════════════════════════
    // BUSINESS LOGIC METHODS
    // ══════════════════════════════════════════════════════════════════════

    /**
     * Giải phóng tiền cọc cho chủ trọ (khi hợp đồng xác nhận).
     * Chỉ thực hiện được khi status = 'holding'.
     * released_at là IMMUTABLE – chỉ ghi 1 lần.
     *
     * @param  string|null $note Ghi chú lý do
     * @throws \LogicException Nếu ví không ở trạng thái 'holding'
     */
    public function release(?string $note = null): bool
    {
        if ($this->status !== 'holding') {
            throw new \LogicException(
                "Không thể giải phóng ví #{$this->id} đang ở trạng thái '{$this->status}'."
            );
        }

        return $this->update([
            'status'      => 'released',
            'released_at' => now(),      // IMMUTABLE: chỉ ghi 1 lần tại đây
            'note'        => $note ?? $this->note,
        ]);
    }

    /**
     * Hoàn trả tiền cọc cho tenant (khi huỷ hợp đồng hoặc không đồng ý).
     * Chỉ thực hiện được khi status = 'holding' hoặc 'disputed'.
     * refunded_at là IMMUTABLE – chỉ ghi 1 lần.
     *
     * @param  string|null $note Lý do hoàn tiền
     * @throws \LogicException Nếu ví không ở trạng thái cho phép hoàn tiền
     */
    public function refund(?string $note = null): bool
    {
        if (!in_array($this->status, ['holding', 'disputed'], true)) {
            throw new \LogicException(
                "Không thể hoàn tiền ví #{$this->id} đang ở trạng thái '{$this->status}'."
            );
        }

        return $this->update([
            'status'      => 'refunded',
            'refunded_at' => now(),      // IMMUTABLE: chỉ ghi 1 lần tại đây
            'note'        => $note ?? $this->note,
        ]);
    }

    /**
     * Chuyển ví vào trạng thái tranh chấp để admin can thiệp.
     *
     * @throws \LogicException Nếu ví không ở trạng thái 'holding'
     */
    public function markAsDisputed(?string $reason = null): bool
    {
        if ($this->status !== 'holding') {
            throw new \LogicException(
                "Chỉ ví đang 'holding' mới có thể chuyển sang 'disputed'."
            );
        }

        return $this->update([
            'status' => 'disputed',
            'note'   => $reason ?? $this->note,
        ]);
    }

    /**
     * Kiểm tra ví còn đang giữ tiền không.
     */
    public function isHolding(): bool
    {
        return $this->status === 'holding';
    }

    /**
     * Kiểm tra tiền đã được xử lý (released hoặc refunded).
     */
    public function isSettled(): bool
    {
        return in_array($this->status, ['released', 'refunded'], true);
    }

    // ══════════════════════════════════════════════════════════════════════
    // SCOPES
    // ══════════════════════════════════════════════════════════════════════

    /** Lọc ví đang giữ tiền */
    public function scopeHolding($query)
    {
        return $query->where('status', 'holding');
    }

    /** Lọc ví đang tranh chấp */
    public function scopeDisputed($query)
    {
        return $query->where('status', 'disputed');
    }
}
