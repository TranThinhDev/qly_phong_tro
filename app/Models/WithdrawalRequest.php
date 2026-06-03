<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Model WithdrawalRequest – Yêu cầu rút tiền từ ví
 *
 * ┌─────────────────────────────────────────────────────────┐
 * │  AN TOÀN DỮ LIỆU                                        │
 * │                                                         │
 * │  1. Khi tạo WithdrawalRequest, WalletService phải LOCK  │
 * │     wallet và NGAY LẬP TỨC trừ available_balance để     │
 * │     giữ chỗ (pending hold). Tránh user rút 2 lần trong  │
 * │     cùng lúc (race condition).                           │
 * │                                                         │
 * │  2. 'status', 'processed_by', 'proof_image_url' KHÔNG   │
 * │     trong $fillable – chỉ thay đổi qua approve()/       │
 * │     reject() để đảm bảo business rules.                 │
 * │                                                         │
 * │  3. proof_image_url là đường dẫn PRIVATE (storage nội   │
 * │     bộ) – không phải URL công khai. Dùng $hidden để     │
 * │     không expose trong API response.                    │
 * │                                                         │
 * │  4. bank_account_number lưu dạng string (không cast số) │
 * │     để bảo toàn leading zeros.                          │
 * └─────────────────────────────────────────────────────────┘
 *
 * @property int         $id
 * @property int         $wallet_id
 * @property float       $amount
 * @property string      $bank_code
 * @property string      $bank_account_number
 * @property string      $bank_account_name
 * @property string      $status                pending|approved|rejected
 * @property string|null $proof_image_url       [PRIVATE]
 * @property int|null    $processed_by
 * @property string|null $admin_notes
 */
class WithdrawalRequest extends Model
{
    use HasFactory;

    protected $table = 'withdrawal_requests';

    // ── Mass Assignment Protection ────────────────────────────────────────
    // 'status', 'processed_by', 'proof_image_url', 'admin_notes' không trong
    // $fillable – chỉ approve() và reject() mới được set các trường này.
    protected $fillable = [
        'wallet_id',
        'amount',
        'bank_code',
        'bank_account_number',
        'bank_account_name',
    ];

    // ── Hidden (không expose ra JSON mặc định) ────────────────────────────
    protected $hidden = [
        'proof_image_url',  // Đường dẫn file nội bộ, không expose
    ];

    // ── Type Casting ──────────────────────────────────────────────────────
    protected $casts = [
        'amount' => 'decimal:2',
    ];

    // ══════════════════════════════════════════════════════════════════════
    // RELATIONSHIPS
    // ══════════════════════════════════════════════════════════════════════

    /**
     * Ví đã tạo yêu cầu rút tiền.
     * WithdrawalRequest belongsTo Wallet (wallet_id → wallets.id)
     */
    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class, 'wallet_id', 'id');
    }

    /**
     * Tiện ích: lấy User chủ ví thông qua relationship chain.
     * WithdrawalRequest → Wallet → User
     */
    public function user(): BelongsTo
    {
        return $this->wallet->user();
    }

    /**
     * Admin đã xử lý yêu cầu rút tiền.
     * WithdrawalRequest belongsTo User (processed_by → users.id)
     * Nullable: chưa có admin xử lý khi status = 'pending'.
     */
    public function processor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'processed_by', 'id');
    }

    // ══════════════════════════════════════════════════════════════════════
    // BUSINESS LOGIC METHODS
    // ══════════════════════════════════════════════════════════════════════

    /**
     * Admin duyệt yêu cầu rút tiền.
     *
     * Chuyển status → 'approved', ghi processed_by và proof_image_url.
     * Chỉ hoạt động khi status = 'pending'.
     *
     * LƯU Ý: Sau khi gọi method này, WalletService phải hoàn tất việc
     * cập nhật pending_balance (trừ số tiền đang giữ chỗ).
     *
     * @param  int         $adminId       ID admin duyệt
     * @param  string|null $proofImageUrl Đường dẫn ảnh chứng minh (private storage)
     * @param  string|null $notes         Ghi chú của admin
     * @throws \LogicException Nếu yêu cầu không ở trạng thái 'pending'
     */
    public function approve(int $adminId, ?string $proofImageUrl = null, ?string $notes = null): bool
    {
        if ($this->status !== 'pending') {
            throw new \LogicException(
                "Không thể duyệt yêu cầu rút tiền #{$this->id} đang ở trạng thái '{$this->status}'."
            );
        }

        return $this->forceFill([
            'status'          => 'approved',
            'processed_by'    => $adminId,
            'proof_image_url' => $proofImageUrl,
            'admin_notes'     => $notes,
        ])->save();
    }

    /**
     * Admin từ chối yêu cầu rút tiền.
     *
     * Chuyển status → 'rejected'. Sau khi gọi method này, WalletService
     * phải hoàn lại số tiền về available_balance cho user.
     *
     * @param  int    $adminId ID admin từ chối
     * @param  string $reason  Lý do từ chối (bắt buộc)
     * @throws \LogicException Nếu yêu cầu không ở trạng thái 'pending'
     */
    public function reject(int $adminId, string $reason): bool
    {
        if ($this->status !== 'pending') {
            throw new \LogicException(
                "Không thể từ chối yêu cầu rút tiền #{$this->id} đang ở trạng thái '{$this->status}'."
            );
        }

        return $this->forceFill([
            'status'       => 'rejected',
            'processed_by' => $adminId,
            'admin_notes'  => $reason,
        ])->save();
    }

    /**
     * Kiểm tra yêu cầu đang chờ xử lý.
     */
    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    // ══════════════════════════════════════════════════════════════════════
    // SCOPES
    // ══════════════════════════════════════════════════════════════════════

    /** Lọc yêu cầu đang chờ duyệt */
    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    /** Lọc yêu cầu đã duyệt */
    public function scopeApproved($query)
    {
        return $query->where('status', 'approved');
    }

    /** Lọc yêu cầu bị từ chối */
    public function scopeRejected($query)
    {
        return $query->where('status', 'rejected');
    }
}
