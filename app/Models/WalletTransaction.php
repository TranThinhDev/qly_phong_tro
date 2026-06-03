<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Model WalletTransaction – Nhật ký giao dịch ví (Double-Entry)
 *
 * ┌─────────────────────────────────────────────────────────┐
 * │  AN TOÀN DỮ LIỆU                                        │
 * │                                                         │
 * │  1. APPEND-ONLY: Model này KHÔNG BAO GIỜ được UPDATE    │
 * │     sau khi tạo. Mọi bản ghi là bằng chứng bất biến.   │
 * │                                                         │
 * │  2. balance_before / balance_after: WalletService ghi   │
 * │     tự động – KHÔNG nhận từ input người dùng.           │
 * │     Bất biến: credit: before + amount = after           │
 * │               debit:  before - amount = after           │
 * │                                                         │
 * │  3. Polymorphic 'reference': gắn giao dịch với nguồn    │
 * │     (Invoice, Contract, PendingWallet...) mà không cần  │
 * │     hard-code FK, dễ mở rộng.                           │
 * │                                                         │
 * │  4. balance_before / balance_after KHÔNG trong $fillable │
 * │     – chỉ WalletService mới được dùng create() hoặc     │
 * │     forceFill() cho các trường này.                     │
 * └─────────────────────────────────────────────────────────┘
 *
 * @property int    $id
 * @property int    $wallet_id
 * @property string $type              deposit_escrow|release_fund|withdrawal|refund_tenant|top_up|pay_invoice
 * @property float  $amount
 * @property float  $balance_before   [AUDIT – ghi bởi WalletService]
 * @property float  $balance_after    [AUDIT – ghi bởi WalletService]
 * @property string|null $reference_type
 * @property int|null    $reference_id
 * @property string $description
 */
class WalletTransaction extends Model
{
    use HasFactory;

    protected $table = 'wallet_transactions';

    // ── Mass Assignment Protection ────────────────────────────────────────
    // 'balance_before' và 'balance_after' KHÔNG trong $fillable.
    // WalletService phải dùng create() với array đầy đủ hoặc new + save().
    // Điều này ngăn controller/request tự ý điền giá trị audit.
    protected $fillable = [
        'wallet_id',
        'type',
        'amount',
        'balance_before',
        'balance_after',
        'reference_type',
        'reference_id',
        'description',
    ];

    // ── Type Casting ──────────────────────────────────────────────────────
    protected $casts = [
        'amount'         => 'decimal:2',
        'balance_before' => 'decimal:2',
        'balance_after'  => 'decimal:2',
    ];

    // ── Không cho phép cập nhật sau khi tạo ──────────────────────────────
    // Ghi đè để đảm bảo tính APPEND-ONLY
    public static function boot(): void
    {
        parent::boot();

        // Ném exception nếu có ai cố tình gọi update() trên bản ghi này
        static::updating(function () {
            throw new \LogicException(
                'WalletTransaction là bản ghi bất biến (APPEND-ONLY). '
                . 'Không được phép cập nhật sau khi tạo.'
            );
        });
    }

    // ══════════════════════════════════════════════════════════════════════
    // RELATIONSHIPS
    // ══════════════════════════════════════════════════════════════════════

    /**
     * Ví sở hữu giao dịch này.
     * WalletTransaction belongsTo Wallet (wallet_id → wallets.id)
     */
    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class, 'wallet_id', 'id');
    }

    /**
     * Polymorphic: nguồn gốc giao dịch.
     *
     * Ví dụ sử dụng:
     *   $txn->reference_type = 'App\Models\Invoice'; $txn->reference_id = 42;
     *   → $txn->reference trả về Invoice instance
     *
     * Sử dụng:
     *   WalletTransaction::create([
     *     'reference_type' => Invoice::class,
     *     'reference_id'   => $invoice->id,
     *     ...
     *   ]);
     */
    public function reference(): MorphTo
    {
        return $this->morphTo('reference', 'reference_type', 'reference_id');
    }

    // ══════════════════════════════════════════════════════════════════════
    // HELPERS
    // ══════════════════════════════════════════════════════════════════════

    /**
     * Kiểm tra đây là giao dịch tăng số dư (credit).
     * Dùng để hiển thị màu sắc trên UI.
     */
    public function isCredit(): bool
    {
        return in_array($this->type, [
            'top_up',
            'release_fund',
            'refund_tenant',
        ], true);
    }

    /**
     * Kiểm tra đây là giao dịch giảm số dư (debit).
     */
    public function isDebit(): bool
    {
        return in_array($this->type, [
            'deposit_escrow',
            'withdrawal',
            'pay_invoice',
        ], true);
    }

    // ══════════════════════════════════════════════════════════════════════
    // SCOPES
    // ══════════════════════════════════════════════════════════════════════

    /** Lọc giao dịch theo loại */
    public function scopeOfType($query, string $type)
    {
        return $query->where('type', $type);
    }

    /** Lọc giao dịch tăng số dư */
    public function scopeCredits($query)
    {
        return $query->whereIn('type', ['top_up', 'release_fund', 'refund_tenant']);
    }

    /** Lọc giao dịch giảm số dư */
    public function scopeDebits($query)
    {
        return $query->whereIn('type', ['deposit_escrow', 'withdrawal', 'pay_invoice']);
    }
}
