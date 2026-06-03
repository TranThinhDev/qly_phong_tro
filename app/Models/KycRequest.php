<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Model KycRequest – Yêu cầu xác minh danh tính (KYC)
 *
 * ┌─────────────────────────────────────────────────────────┐
 * │  AN TOÀN DỮ LIỆU                                        │
 * │                                                         │
 * │  1. $hidden ẩn tất cả *_url khỏi JSON response: file    │
 * │     paths không bao giờ được expose ra ngoài. Dùng       │
 * │     KycController::download() để stream có kiểm soát.   │
 * │                                                         │
 * │  2. 'status', 'reviewed_by', 'verified_at' KHÔNG trong  │
 * │     $fillable. Chỉ được thay đổi qua approve()/reject() │
 * │     để đảm bảo business rules và immutability.          │
 * │                                                         │
 * │  3. verified_at IMMUTABLE: approve() kiểm tra trước khi │
 * │     ghi, ném LogicException nếu đã có giá trị.          │
 * │                                                         │
 * │  4. File lưu trong storage/app/private_kyc/{user_id}/   │
 * │     – KHÔNG phải storage/app/public/ – không bao giờ    │
 * │     accessible qua URL trực tiếp.                        │
 * └─────────────────────────────────────────────────────────┘
 *
 * @property int         $id
 * @property int         $user_id
 * @property string      $id_card_front_url    [PRIVATE]
 * @property string      $id_card_back_url     [PRIVATE]
 * @property string|null $ownership_proof_url  [PRIVATE]
 * @property string      $status               pending|verified|rejected
 * @property string|null $admin_notes
 * @property int|null    $reviewed_by          [IMMUTABLE after set]
 * @property \Carbon\Carbon|null $verified_at  [IMMUTABLE]
 */
class KycRequest extends Model
{
    use HasFactory;

    protected $table = 'kyc_requests';

    // ── Mass Assignment Protection ────────────────────────────────────────
    // 'status', 'reviewed_by', 'verified_at' bị loại ra khỏi $fillable.
    // Chỉ thay đổi qua approve() và reject() để bảo đảm business logic.
    // '*_url' cũng không thể mass-assign từ request – phải qua KycService.
    protected $fillable = [
        'user_id',
        'id_card_front_url',
        'id_card_back_url',
        'ownership_proof_url',
    ];

    // ── Hidden (không expose ra JSON/array mặc định) ──────────────────────
    // Đường dẫn file là thông tin nhạy cảm – KHÔNG cho phép serialization
    protected $hidden = [
        'id_card_front_url',
        'id_card_back_url',
        'ownership_proof_url',
    ];

    // ── Type Casting ──────────────────────────────────────────────────────
    protected $casts = [
        'verified_at' => 'datetime',
    ];

    // ══════════════════════════════════════════════════════════════════════
    // RELATIONSHIPS
    // ══════════════════════════════════════════════════════════════════════

    /**
     * Người dùng gửi yêu cầu KYC.
     * KycRequest belongsTo User (user_id → users.id)
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }

    /**
     * Admin đã xét duyệt KYC này.
     * KycRequest belongsTo User (reviewed_by → users.id)
     * Nullable: chưa có admin xử lý khi status = 'pending'.
     */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by', 'id');
    }

    // ══════════════════════════════════════════════════════════════════════
    // BUSINESS LOGIC METHODS
    // ══════════════════════════════════════════════════════════════════════

    /**
     * Admin phê duyệt yêu cầu KYC.
     *
     * Chuyển status → 'verified', ghi verified_at (IMMUTABLE – 1 lần).
     * Chỉ hoạt động khi status = 'pending'.
     *
     * @param  int         $adminId  ID của admin thực hiện duyệt
     * @param  string|null $notes    Ghi chú của admin (tùy chọn)
     * @throws \LogicException Nếu KYC không ở trạng thái 'pending'
     */
    public function approve(int $adminId, ?string $notes = null): bool
    {
        if ($this->status !== 'pending') {
            throw new \LogicException(
                "Không thể phê duyệt KYC #{$this->id} đang ở trạng thái '{$this->status}'."
            );
        }

        // forceFill() để bypass $fillable guard cho các trường audit
        return $this->forceFill([
            'status'      => 'verified',
            'reviewed_by' => $adminId,
            'verified_at' => now(),  // IMMUTABLE: chỉ ghi 1 lần tại đây
            'admin_notes' => $notes,
        ])->save();
    }

    /**
     * Admin từ chối yêu cầu KYC.
     *
     * Chuyển status → 'rejected', bắt buộc phải có lý do từ chối.
     * Chỉ hoạt động khi status = 'pending'.
     *
     * @param  int    $adminId  ID của admin thực hiện từ chối
     * @param  string $reason   Lý do từ chối (bắt buộc)
     * @throws \LogicException Nếu KYC không ở trạng thái 'pending'
     */
    public function reject(int $adminId, string $reason): bool
    {
        if ($this->status !== 'pending') {
            throw new \LogicException(
                "Không thể từ chối KYC #{$this->id} đang ở trạng thái '{$this->status}'."
            );
        }

        return $this->forceFill([
            'status'      => 'rejected',
            'reviewed_by' => $adminId,
            'admin_notes' => $reason,
        ])->save();
    }

    /**
     * Kiểm tra KYC đã được xác minh thành công.
     */
    public function isVerified(): bool
    {
        return $this->status === 'verified';
    }

    /**
     * Kiểm tra KYC đang chờ xử lý.
     */
    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    // ══════════════════════════════════════════════════════════════════════
    // SCOPES
    // ══════════════════════════════════════════════════════════════════════

    /** Lọc KYC đang chờ xét duyệt */
    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    /** Lọc KYC đã được xác minh */
    public function scopeVerified($query)
    {
        return $query->where('status', 'verified');
    }

    /** Lọc KYC bị từ chối */
    public function scopeRejected($query)
    {
        return $query->where('status', 'rejected');
    }
}
