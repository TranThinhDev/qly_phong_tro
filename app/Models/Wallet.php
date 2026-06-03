<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Model Wallet – Ví điện tử của người dùng
 *
 * ┌─────────────────────────────────────────────────────────┐
 * │  AN TOÀN DỮ LIỆU (QUAN TRỌNG)                           │
 * │                                                         │
 * │  KHÔNG BAO GIỜ gọi $wallet->update(['balance' => X])    │
 * │  hay $wallet->available_balance = X trực tiếp.          │
 * │                                                         │
 * │  Mọi thay đổi số dư PHẢI đi qua WalletService với:     │
 * │    DB::transaction() + $wallet->lockForUpdate()          │
 * │  để đảm bảo:                                            │
 * │    1. Không race condition (concurrent request)          │
 * │    2. Ghi nhật ký wallet_transactions đầy đủ            │
 * │    3. balance_before / balance_after chính xác           │
 * │                                                         │
 * │  available_balance và pending_balance KHÔNG trong        │
 * │  $fillable chính là lớp bảo vệ cuối cùng.              │
 * └─────────────────────────────────────────────────────────┘
 *
 * @property int    $id
 * @property int    $user_id
 * @property float  $available_balance  [PROTECTED – only via WalletService]
 * @property float  $pending_balance    [PROTECTED – only via WalletService]
 * @property bool   $is_frozen
 */
class Wallet extends Model
{
    use HasFactory;

    protected $table = 'wallets';

    // ── Mass Assignment Protection ────────────────────────────────────────
    // 'available_balance' và 'pending_balance' KHÔNG trong $fillable.
    // Chỉ WalletService mới được dùng forceFill() để cập nhật balance
    // (bên trong DB::transaction() + lockForUpdate()).
    protected $fillable = [
        'user_id',
        'is_frozen',
    ];

    // ── Type Casting ──────────────────────────────────────────────────────
    protected $casts = [
        'available_balance' => 'decimal:2',
        'pending_balance'   => 'decimal:2',
        'is_frozen'         => 'boolean',
    ];

    // ══════════════════════════════════════════════════════════════════════
    // RELATIONSHIPS
    // ══════════════════════════════════════════════════════════════════════

    /**
     * Chủ sở hữu ví.
     * Wallet belongsTo User (user_id → users.id)
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }

    /**
     * Toàn bộ lịch sử giao dịch của ví.
     * Wallet hasMany WalletTransaction (wallets.id → wallet_transactions.wallet_id)
     *
     * Thứ tự mặc định: mới nhất trước (DESC)
     */
    public function transactions(): HasMany
    {
        return $this->hasMany(WalletTransaction::class, 'wallet_id', 'id')
                    ->latest();
    }

    /**
     * Các yêu cầu rút tiền của ví này.
     * Wallet hasMany WithdrawalRequest (wallets.id → withdrawal_requests.wallet_id)
     */
    public function withdrawalRequests(): HasMany
    {
        return $this->hasMany(WithdrawalRequest::class, 'wallet_id', 'id')
                    ->latest();
    }

    // ══════════════════════════════════════════════════════════════════════
    // BUSINESS LOGIC HELPERS
    // ══════════════════════════════════════════════════════════════════════

    /**
     * Kiểm tra ví có bị đóng băng không.
     * WalletService gọi method này trước mọi giao dịch.
     */
    public function isFrozen(): bool
    {
        return (bool) $this->is_frozen;
    }

    /**
     * Kiểm tra ví có đủ số dư khả dụng để thực hiện giao dịch.
     *
     * @param float $amount Số tiền cần kiểm tra
     */
    public function hasSufficientBalance(float $amount): bool
    {
        return (float) $this->available_balance >= $amount;
    }

    /**
     * Lấy tổng số dư (available + pending).
     * Dùng để hiển thị tổng quan cho admin.
     */
    public function getTotalBalance(): float
    {
        return (float) $this->available_balance + (float) $this->pending_balance;
    }

    // ══════════════════════════════════════════════════════════════════════
    // SCOPES
    // ══════════════════════════════════════════════════════════════════════

    /** Lọc ví đang hoạt động (không bị đóng băng) */
    public function scopeActive($query)
    {
        return $query->where('is_frozen', false);
    }

    /** Lọc ví bị đóng băng */
    public function scopeFrozen($query)
    {
        return $query->where('is_frozen', true);
    }
}
