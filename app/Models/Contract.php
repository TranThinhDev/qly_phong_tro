<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Model Contract – Hợp đồng thuê phòng
 *
 * ┌─────────────────────────────────────────────────────────┐
 * │  AN TOÀN DỮ LIỆU                                        │
 * │                                                         │
 * │  1. $fillable (whitelist) thay vì $guarded = [] –       │
 * │     chỉ cho phép mass-assign các trường an toàn, chặn   │
 * │     tenant tự ý inject 'landlord_id' hay 'status'.      │
 * │                                                         │
 * │  2. Clickwrap immutability được thực thi bằng           │
 * │     isClickwrapImmutable() + ContractObserver::          │
 * │     updating(). DB không có cơ chế này nên PHẢI xử lý  │
 * │     ở tầng application.                                  │
 * │                                                         │
 * │  3. $hidden ẩn 'tenant_ip' & 'tenant_user_agent' khỏi  │
 * │     JSON response mặc định – chỉ dùng nội bộ.           │
 * │                                                         │
 * │  4. SoftDeletes: xóa mềm giữ lịch sử hợp đồng, không   │
 * │     bao giờ DELETE cứng.                                 │
 * └─────────────────────────────────────────────────────────┘
 *
 * @property int         $id
 * @property string      $contract_code
 * @property int         $tenant_id
 * @property int         $landlord_id
 * @property int|null    $room_id
 * @property string      $start_date
 * @property string|null $end_date
 * @property float       $monthly_rent
 * @property float       $deposit_amount
 * @property string      $status           draft|active|expired|terminated
 * @property string|null $terms_content
 * @property \Carbon\Carbon|null $signed_at   [IMMUTABLE]
 * @property string|null $tenant_ip           [IMMUTABLE]
 * @property string|null $tenant_user_agent   [IMMUTABLE]
 * @property string|null $notes
 */
class Contract extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'contracts';

    // ── Mass Assignment Protection ────────────────────────────────────────
    // Dùng $fillable (whitelist) để chống Mass Assignment.
    // KHÔNG đưa 'status', 'signed_at', 'tenant_ip', 'tenant_user_agent'
    // vào đây – các trường này phải được set qua method riêng biệt.
    protected $fillable = [
        'contract_code',
        'tenant_id',
        'landlord_id',
        'room_id',
        'start_date',
        'end_date',
        'monthly_rent',
        'deposit_amount',
        'terms_content',
        'notes',
    ];

    // ── Hidden (không expose ra JSON/array mặc định) ──────────────────────
    // tenant_ip & tenant_user_agent là PII, chỉ dùng cho audit nội bộ
    protected $hidden = [
        'tenant_ip',
        'tenant_user_agent',
    ];

    // ── Type Casting ──────────────────────────────────────────────────────
    protected $casts = [
        'signed_at'      => 'datetime',    // Carbon instance, dễ so sánh & format
        'start_date'     => 'date',
        'end_date'       => 'date',
        'monthly_rent'   => 'decimal:2',
        'deposit_amount' => 'decimal:2',
    ];

    // ══════════════════════════════════════════════════════════════════════
    // RELATIONSHIPS
    // ══════════════════════════════════════════════════════════════════════

    /**
     * Khách thuê (tenant) của hợp đồng.
     * Contract belongsTo User (tenant_id → users.id)
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(User::class, 'tenant_id', 'id');
    }

    /**
     * Chủ trọ (landlord) của hợp đồng.
     * Contract belongsTo User (landlord_id → users.id)
     */
    public function landlord(): BelongsTo
    {
        return $this->belongsTo(User::class, 'landlord_id', 'id');
    }

    /**
     * Phòng trọ liên quan đến hợp đồng.
     * Contract belongsTo Room (room_id → rooms.id)
     * Nullable: room có thể đã bị xóa (room_id = NULL ở DB).
     */
    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class, 'room_id', 'id');
    }

    /**
     * Tất cả giao dịch tài chính của hợp đồng.
     * Contract hasMany Transaction (contracts.id → transactions.contract_id)
     */
    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class, 'contract_id', 'id');
    }

    /**
     * Ví tạm (escrow) của hợp đồng – quan hệ 1-1.
     * Contract hasOne PendingWallet (contracts.id → pending_wallets.contract_id)
     */
    public function pendingWallet(): HasOne
    {
        return $this->hasOne(PendingWallet::class, 'contract_id', 'id');
    }

    // ══════════════════════════════════════════════════════════════════════
    // CLICKWRAP / IMMUTABILITY HELPERS
    // ══════════════════════════════════════════════════════════════════════

    /**
     * Kiểm tra hợp đồng đã được ký (Clickwrap) chưa.
     */
    public function isSigned(): bool
    {
        return $this->signed_at !== null;
    }

    /**
     * Ghi nhận chữ ký điện tử Clickwrap.
     * Chỉ ghi được 1 lần – nếu đã ký thì ném exception.
     *
     * Cách dùng:
     *   $contract->recordClickwrap($request->ip(), $request->userAgent());
     *
     * @throws \LogicException Nếu hợp đồng đã được ký trước đó
     */
    public function recordClickwrap(string $ip, string $userAgent): bool
    {
        if ($this->isSigned()) {
            throw new \LogicException(
                "Hợp đồng #{$this->id} đã được ký lúc {$this->signed_at}. Không thể ký lại."
            );
        }

        // Dùng update() thay vì fill() để bypass $fillable guard
        // vì ba trường này cố tình KHÔNG nằm trong $fillable
        return $this->update([
            'signed_at'          => now(),
            'tenant_ip'          => $ip,
            'tenant_user_agent'  => $userAgent,
        ]);
    }

    /**
     * Cập nhật trạng thái hợp đồng một cách có kiểm soát.
     * Chỉ cho phép chuyển trạng thái hợp lệ theo state machine.
     *
     * @throws \InvalidArgumentException Nếu chuyển trạng thái không hợp lệ
     */
    public function transitionTo(string $newStatus): bool
    {
        $allowedTransitions = [
            'draft'           => ['pending_payment', 'active'],
            'pending_payment' => ['active', 'draft'], // Có thể quay lại draft nếu thanh toán lỗi
            'active'          => ['expired', 'terminated'],
            'expired'         => [],
            'terminated'      => [],
        ];

        $current = $this->status;

        if (!in_array($newStatus, $allowedTransitions[$current] ?? [], true)) {
            throw new \InvalidArgumentException(
                "Không thể chuyển trạng thái từ '{$current}' sang '{$newStatus}'."
            );
        }

        $this->status = $newStatus;
        return $this->save();
    }

    // ══════════════════════════════════════════════════════════════════════
    // SCOPES
    // ══════════════════════════════════════════════════════════════════════

    /** Lọc hợp đồng đang hiệu lực */
    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    /** Lọc hợp đồng đã được ký */
    public function scopeSigned($query)
    {
        return $query->whereNotNull('signed_at');
    }
}
