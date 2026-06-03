<?php

namespace App\Services;

use App\Models\Wallet;
use App\Models\WalletTransaction;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * WalletService – Động cơ tài chính trung tâm
 *
 * ╔═══════════════════════════════════════════════════════════╗
 * ║  NGUYÊN TẮC BẮT BUỘC (KHÔNG ĐƯỢC VI PHẠM)               ║
 * ║                                                           ║
 * ║  1. TRANSACTION WRAPPER: Mọi public method đều bọc trong ║
 * ║     DB::transaction(). Caller có thể lồng vào transaction ║
 * ║     cha — Laravel xử lý savepoint tự động.               ║
 * ║                                                           ║
 * ║  2. PESSIMISTIC LOCK: lockForUpdate() trên wallet luôn    ║
 * ║     là thao tác ĐẦU TIÊN trong mỗi transaction. Tránh    ║
 * ║     race condition khi nhiều request cùng lúc.            ║
 * ║                                                           ║
 * ║  3. DOUBLE-ENTRY BOOKKEEPING: Mỗi thay đổi số dư TẠO RA  ║
 * ║     ít nhất 1 bản ghi WalletTransaction với balance_      ║
 * ║     before và balance_after chính xác.                    ║
 * ║                                                           ║
 * ║  4. FORCE FILL: Dùng $wallet->forceFill()->save() để cập  ║
 * ║     nhật balance vì các trường này KHÔNG trong $fillable. ║
 * ║     Đây là đặc quyền duy nhất của service này.           ║
 * ║                                                           ║
 * ║  5. FROZEN CHECK: Mọi method kiểm tra isFrozen() trước    ║
 * ║     khi xử lý. Ví đóng băng từ chối mọi giao dịch.      ║
 * ║                                                           ║
 * ║  6. POSITIVE AMOUNT: Mọi method reject amount <= 0 ngay   ║
 * ║     đầu để tránh logic lỗi im lặng.                      ║
 * ╚═══════════════════════════════════════════════════════════╝
 *
 * Luồng tiền chuẩn trong hệ thống:
 * ─────────────────────────────────────────────────────────────
 *   Tenant nạp tiền  → topUp()            → available_balance ↑
 *   Ký hợp đồng/cọc → addPendingFunds()   → pending_balance ↑
 *                                            available_balance ↓
 *   Hoàn thành cọc  → releaseFunds()      → pending_balance ↓
 *                                            available_balance ↑ (chủ trọ)
 *   Thanh toán HĐ   → deductAvailable()   → available_balance ↓
 *   Rút tiền        → deductAvailable()   → available_balance ↓
 *   Hoàn tiền       → refundToTenant()    → pending_balance ↓
 *                                            available_balance ↑ (tenant)
 */
class WalletService
{
    // ── Hằng số loại giao dịch (đồng bộ với ENUM trong migration) ─────────
    public const TYPE_TOP_UP         = 'top_up';
    public const TYPE_DEPOSIT_ESCROW = 'deposit_escrow';
    public const TYPE_RELEASE_FUND   = 'release_fund';
    public const TYPE_REFUND_TENANT  = 'refund_tenant';
    public const TYPE_PAY_INVOICE    = 'pay_invoice';
    public const TYPE_WITHDRAWAL     = 'withdrawal';

    /** Các loại hợp lệ cho deductAvailableFunds() */
    private const VALID_DEBIT_TYPES = [
        self::TYPE_WITHDRAWAL,
        self::TYPE_PAY_INVOICE,
        self::TYPE_DEPOSIT_ESCROW, // Khi trừ available để giữ cọc
    ];

    // ══════════════════════════════════════════════════════════════════════
    // PUBLIC API
    // ══════════════════════════════════════════════════════════════════════

    /**
     * Lấy ví hiện có hoặc tạo mới nếu chưa có.
     *
     * KHÔNG chạy trong transaction riêng — thường được gọi bởi
     * caller đã có transaction cha, hoặc dùng độc lập an toàn.
     *
     * @param  int $userId
     * @return Wallet
     */
    public function getOrCreateWallet(int $userId): Wallet
    {
        return Wallet::firstOrCreate(
            ['user_id' => $userId],
            ['is_frozen' => false]
        );
    }

    /**
     * Nạp tiền vào `pending_balance` (Escrow Hold).
     *
     * Dùng khi: Tenant ký hợp đồng và số tiền cọc bị giữ lại
     * trong escrow cho đến khi chủ trọ xác nhận.
     *
     * Đây là thao tác một chiều: pending_balance tăng lên.
     * Tiền KHÔNG bị trừ từ available — kịch bản này chỉ dùng
     * khi tiền đến từ bên ngoài (VNPay gateway) vào escrow.
     * Nếu muốn chuyển từ available → pending, dùng
     * transferAvailableToPending().
     *
     * Bất biến: balance_after = balance_before + amount
     *
     * @param  int    $userId
     * @param  float  $amount      Số tiền dương (VNĐ)
     * @param  Model  $reference   Nguồn gốc (Contract, PendingWallet, v.v.)
     * @param  string $description Mô tả hiển thị cho user
     * @return WalletTransaction   Bản ghi nhật ký đã tạo
     *
     * @throws \InvalidArgumentException Nếu amount <= 0
     * @throws \RuntimeException         Nếu ví bị đóng băng
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException Nếu ví không tồn tại
     */
    public function addPendingFunds(
        int $userId,
        float $amount,
        Model $reference,
        string $description
    ): WalletTransaction {
        $this->assertPositiveAmount($amount, __METHOD__);

        return DB::transaction(function () use ($userId, $amount, $reference, $description): WalletTransaction {

            // ── 1. Pessimistic lock ────────────────────────────────────────
            // Đây PHẢI là thao tác đầu tiên trong transaction để tránh race condition.
            // firstOrFail() ném ModelNotFoundException nếu user chưa có ví.
            $wallet = Wallet::where('user_id', $userId)
                ->lockForUpdate()
                ->firstOrFail();

            // ── 2. Frozen guard ────────────────────────────────────────────
            $this->assertWalletNotFrozen($wallet);

            // ── 3. Snapshot số dư TRƯỚC khi thay đổi ─────────────────────
            $balanceBefore = (float) $wallet->pending_balance;
            $balanceAfter  = round($balanceBefore + $amount, 2);

            // ── 4. Cập nhật pending_balance (dùng forceFill vì không trong $fillable) ─
            $wallet->forceFill(['pending_balance' => $balanceAfter])->save();

            // ── 5. Double-Entry: Ghi nhật ký bất biến ─────────────────────
            $txn = WalletTransaction::create([
                'wallet_id'      => $wallet->id,
                'type'           => self::TYPE_DEPOSIT_ESCROW,
                'amount'         => $amount,
                'balance_before' => $balanceBefore,
                'balance_after'  => $balanceAfter,
                'reference_type' => get_class($reference),
                'reference_id'   => $reference->getKey(),
                'description'    => $description,
            ]);

            Log::info('[WalletService::addPendingFunds] Thành công', [
                'wallet_id'      => $wallet->id,
                'user_id'        => $userId,
                'amount'         => $amount,
                'balance_before' => $balanceBefore,
                'balance_after'  => $balanceAfter,
                'txn_id'         => $txn->id,
                'reference'      => get_class($reference) . '#' . $reference->getKey(),
            ]);

            return $txn;
        });
    }

    /**
     * Chuyển tiền từ `pending_balance` sang `available_balance` (Giải phóng Escrow).
     *
     * Dùng khi: Chủ trọ xác nhận nhận cọc → số tiền cọc được
     * chuyển từ escrow (pending) sang số dư khả dụng của chủ trọ.
     *
     * Bất biến:
     *   pending_balance_after  = pending_balance_before  - amount
     *   available_balance_after = available_balance_before + amount
     *   balance_before/after trong WalletTransaction theo dõi available_balance
     *
     * @param  int    $userId
     * @param  float  $amount
     * @param  Model  $reference
     * @param  string $description
     * @return WalletTransaction
     *
     * @throws \InvalidArgumentException Nếu amount <= 0
     * @throws \RuntimeException         Nếu pending_balance không đủ hoặc ví bị đóng băng
     */
    public function releaseFundsToAvailable(
        int $userId,
        float $amount,
        Model $reference,
        string $description
    ): WalletTransaction {
        $this->assertPositiveAmount($amount, __METHOD__);

        return DB::transaction(function () use ($userId, $amount, $reference, $description): WalletTransaction {

            // ── 1. Pessimistic lock ────────────────────────────────────────
            $wallet = Wallet::where('user_id', $userId)
                ->lockForUpdate()
                ->firstOrFail();

            // ── 2. Frozen guard ────────────────────────────────────────────
            $this->assertWalletNotFrozen($wallet);

            // ── 3. Kiểm tra đủ pending_balance ────────────────────────────
            $pendingBalance = (float) $wallet->pending_balance;
            if ($pendingBalance < $amount) {
                throw new \RuntimeException(
                    sprintf(
                        'Số dư escrow không đủ để giải phóng. '
                        . 'Yêu cầu: %s VNĐ | Hiện có: %s VNĐ (wallet #%d).',
                        number_format($amount, 2),
                        number_format($pendingBalance, 2),
                        $wallet->id
                    )
                );
            }

            // ── 4. Snapshot số dư TRƯỚC ───────────────────────────────────
            // WalletTransaction.balance_before/after theo dõi available_balance
            // vì đây là số dư "tăng" — quan trọng hơn với chủ trọ nhận tiền.
            $availBefore = (float) $wallet->available_balance;
            $availAfter  = round($availBefore + $amount, 2);
            $pendAfter   = round($pendingBalance - $amount, 2);

            // ── 5. Cập nhật cả hai balance trong 1 lần save ───────────────
            $wallet->forceFill([
                'pending_balance'   => $pendAfter,
                'available_balance' => $availAfter,
            ])->save();

            // ── 6. Double-Entry ────────────────────────────────────────────
            $txn = WalletTransaction::create([
                'wallet_id'      => $wallet->id,
                'type'           => self::TYPE_RELEASE_FUND,
                'amount'         => $amount,
                'balance_before' => $availBefore, // available BEFORE release
                'balance_after'  => $availAfter,  // available AFTER release
                'reference_type' => get_class($reference),
                'reference_id'   => $reference->getKey(),
                'description'    => $description,
            ]);

            Log::info('[WalletService::releaseFundsToAvailable] Thành công', [
                'wallet_id'       => $wallet->id,
                'user_id'         => $userId,
                'amount'          => $amount,
                'pending_before'  => $pendingBalance,
                'pending_after'   => $pendAfter,
                'avail_before'    => $availBefore,
                'avail_after'     => $availAfter,
                'txn_id'          => $txn->id,
            ]);

            return $txn;
        });
    }

    /**
     * Trừ tiền từ `available_balance` (Chi tiêu / Rút tiền).
     *
     * Dùng khi:
     *   - Tenant thanh toán hóa đơn từ ví        → type = 'pay_invoice'
     *   - User rút tiền ra tài khoản ngân hàng    → type = 'withdrawal'
     *   - Trừ available để giữ cọc tạm            → type = 'deposit_escrow'
     *
     * Bất biến: balance_after = balance_before - amount (balance_after >= 0)
     *
     * @param  int         $userId
     * @param  float       $amount
     * @param  string      $type        Loại giao dịch (xem VALID_DEBIT_TYPES)
     * @param  string      $description
     * @param  Model|null  $reference   Nguồn gốc tùy chọn (Invoice, WithdrawalRequest...)
     * @return WalletTransaction
     *
     * @throws \InvalidArgumentException Nếu amount <= 0 hoặc type không hợp lệ
     * @throws \RuntimeException         Nếu số dư không đủ (Insufficient funds) hoặc ví bị đóng băng
     */
    public function deductAvailableFunds(
        int $userId,
        float $amount,
        string $type,
        string $description,
        ?Model $reference = null
    ): WalletTransaction {
        $this->assertPositiveAmount($amount, __METHOD__);
        $this->assertValidDebitType($type);

        return DB::transaction(function () use ($userId, $amount, $type, $description, $reference): WalletTransaction {

            // ── 1. Pessimistic lock ────────────────────────────────────────
            $wallet = Wallet::where('user_id', $userId)
                ->lockForUpdate()
                ->firstOrFail();

            // ── 2. Frozen guard ────────────────────────────────────────────
            $this->assertWalletNotFrozen($wallet);

            // ── 3. Overdraft prevention (CRITICAL) ────────────────────────
            // Kiểm tra sau khi đã có lock — số dư lấy từ DB tươi (fresh).
            $balanceBefore = (float) $wallet->available_balance;
            if ($balanceBefore < $amount) {
                Log::warning('[WalletService::deductAvailableFunds] Insufficient funds', [
                    'wallet_id'     => $wallet->id,
                    'user_id'       => $userId,
                    'requested'     => $amount,
                    'available'     => $balanceBefore,
                    'type'          => $type,
                ]);

                throw new \RuntimeException(
                    sprintf(
                        'Số dư khả dụng không đủ. '
                        . 'Yêu cầu: %s VNĐ | Khả dụng: %s VNĐ.',
                        number_format($amount, 2),
                        number_format($balanceBefore, 2)
                    )
                );
            }

            // ── 4. Tính toán số dư sau giao dịch ──────────────────────────
            $balanceAfter = round($balanceBefore - $amount, 2);

            // ── 5. Cập nhật available_balance ─────────────────────────────
            $wallet->forceFill(['available_balance' => $balanceAfter])->save();

            // ── 6. Double-Entry ────────────────────────────────────────────
            $txn = WalletTransaction::create([
                'wallet_id'      => $wallet->id,
                'type'           => $type,
                'amount'         => $amount,
                'balance_before' => $balanceBefore,
                'balance_after'  => $balanceAfter,
                'reference_type' => $reference ? get_class($reference) : null,
                'reference_id'   => $reference?->getKey(),
                'description'    => $description,
            ]);

            Log::info('[WalletService::deductAvailableFunds] Thành công', [
                'wallet_id'      => $wallet->id,
                'user_id'        => $userId,
                'type'           => $type,
                'amount'         => $amount,
                'balance_before' => $balanceBefore,
                'balance_after'  => $balanceAfter,
                'txn_id'         => $txn->id,
                'reference'      => $reference
                    ? get_class($reference) . '#' . $reference->getKey()
                    : 'none',
            ]);

            return $txn;
        });
    }

    /**
     * Nạp tiền vào `available_balance` từ cổng thanh toán (VNPay, v.v.).
     *
     * Dùng khi: VNPay callback xác nhận giao dịch nạp tiền thành công.
     *
     * Bất biến: balance_after = balance_before + amount
     *
     * @param  int    $userId
     * @param  float  $amount
     * @param  Model  $reference  Giao dịch nguồn (PaymentTransaction, Transaction, v.v.)
     * @param  string $description
     * @return WalletTransaction
     *
     * @throws \InvalidArgumentException Nếu amount <= 0
     * @throws \RuntimeException         Nếu ví bị đóng băng
     */
    public function topUp(
        int $userId,
        float $amount,
        Model $reference,
        string $description
    ): WalletTransaction {
        $this->assertPositiveAmount($amount, __METHOD__);

        return DB::transaction(function () use ($userId, $amount, $reference, $description): WalletTransaction {

            // ── 1. Pessimistic lock – getOrCreate pattern ─────────────────
            // Dùng firstOrCreate vì đây là lần nạp tiền đầu tiên có thể
            // xảy ra trước khi admin duyệt KYC (edge case).
            // lockForUpdate() áp dụng sau khi row đã tồn tại.
            $wallet = Wallet::firstOrCreate(
                ['user_id' => $userId],
                ['is_frozen' => false]
            );

            // Re-fetch với lock để đảm bảo đọc số dư mới nhất
            $wallet = Wallet::where('user_id', $userId)
                ->lockForUpdate()
                ->firstOrFail();

            // ── 2. Frozen guard ────────────────────────────────────────────
            $this->assertWalletNotFrozen($wallet);

            // ── 3. Snapshot và tính toán ──────────────────────────────────
            $balanceBefore = (float) $wallet->available_balance;
            $balanceAfter  = round($balanceBefore + $amount, 2);

            // ── 4. Cập nhật available_balance ─────────────────────────────
            $wallet->forceFill(['available_balance' => $balanceAfter])->save();

            // ── 5. Double-Entry ────────────────────────────────────────────
            $txn = WalletTransaction::create([
                'wallet_id'      => $wallet->id,
                'type'           => self::TYPE_TOP_UP,
                'amount'         => $amount,
                'balance_before' => $balanceBefore,
                'balance_after'  => $balanceAfter,
                'reference_type' => get_class($reference),
                'reference_id'   => $reference->getKey(),
                'description'    => $description,
            ]);

            Log::info('[WalletService::topUp] Nạp tiền thành công', [
                'wallet_id'      => $wallet->id,
                'user_id'        => $userId,
                'amount'         => $amount,
                'balance_before' => $balanceBefore,
                'balance_after'  => $balanceAfter,
                'txn_id'         => $txn->id,
            ]);

            return $txn;
        });
    }

    /**
     * Hoàn tiền cọc về `available_balance` của tenant (từ pending_balance).
     *
     * Dùng khi: Hợp đồng bị hủy, tiền cọc được trả lại cho tenant.
     *
     * Bất biến:
     *   pending_balance_after  = pending_balance_before  - amount
     *   available_balance_after = available_balance_before + amount
     *
     * @param  int    $userId      ID của tenant nhận hoàn tiền
     * @param  float  $amount
     * @param  Model  $reference
     * @param  string $description
     * @return WalletTransaction
     *
     * @throws \RuntimeException Nếu pending_balance không đủ hoặc ví bị đóng băng
     */
    public function refundToTenant(
        int $userId,
        float $amount,
        Model $reference,
        string $description
    ): WalletTransaction {
        $this->assertPositiveAmount($amount, __METHOD__);

        return DB::transaction(function () use ($userId, $amount, $reference, $description): WalletTransaction {

            // ── 1. Pessimistic lock ────────────────────────────────────────
            $wallet = Wallet::where('user_id', $userId)
                ->lockForUpdate()
                ->firstOrFail();

            // ── 2. Frozen guard ────────────────────────────────────────────
            // Hoàn tiền vẫn bị chặn nếu ví frozen — admin phải unfreeze trước.
            $this->assertWalletNotFrozen($wallet);

            // ── 3. Kiểm tra đủ pending_balance để hoàn ────────────────────
            $pendingBefore = (float) $wallet->pending_balance;
            if ($pendingBefore < $amount) {
                throw new \RuntimeException(
                    sprintf(
                        'Không đủ pending balance để hoàn tiền. '
                        . 'Yêu cầu: %s VNĐ | Pending: %s VNĐ (wallet #%d).',
                        number_format($amount, 2),
                        number_format($pendingBefore, 2),
                        $wallet->id
                    )
                );
            }

            // ── 4. Snapshot và tính toán ──────────────────────────────────
            $availBefore  = (float) $wallet->available_balance;
            $availAfter   = round($availBefore + $amount, 2);
            $pendingAfter = round($pendingBefore - $amount, 2);

            // ── 5. Cập nhật cả hai balance ────────────────────────────────
            $wallet->forceFill([
                'pending_balance'   => $pendingAfter,
                'available_balance' => $availAfter,
            ])->save();

            // ── 6. Double-Entry ────────────────────────────────────────────
            $txn = WalletTransaction::create([
                'wallet_id'      => $wallet->id,
                'type'           => self::TYPE_REFUND_TENANT,
                'amount'         => $amount,
                'balance_before' => $availBefore,  // available BEFORE refund
                'balance_after'  => $availAfter,   // available AFTER refund
                'reference_type' => get_class($reference),
                'reference_id'   => $reference->getKey(),
                'description'    => $description,
            ]);

            Log::info('[WalletService::refundToTenant] Hoàn tiền thành công', [
                'wallet_id'      => $wallet->id,
                'user_id'        => $userId,
                'amount'         => $amount,
                'pending_before' => $pendingBefore,
                'pending_after'  => $pendingAfter,
                'avail_before'   => $availBefore,
                'avail_after'    => $availAfter,
                'txn_id'         => $txn->id,
            ]);

            return $txn;
        });
    }

    /**
     * Chuyển tiền từ `available_balance` sang `pending_balance` của chính user đó.
     *
     * Dùng khi: Tenant muốn giữ cọc từ số dư ví thay vì thanh toán qua gateway.
     *
     * Bất biến:
     *   available_balance_after = available_balance_before - amount
     *   pending_balance_after   = pending_balance_before   + amount
     *
     * @throws \RuntimeException Nếu available_balance không đủ hoặc ví frozen
     */
    public function transferAvailableToPending(
        int $userId,
        float $amount,
        Model $reference,
        string $description
    ): WalletTransaction {
        $this->assertPositiveAmount($amount, __METHOD__);

        return DB::transaction(function () use ($userId, $amount, $reference, $description): WalletTransaction {

            // ── 1. Pessimistic lock ────────────────────────────────────────
            $wallet = Wallet::where('user_id', $userId)
                ->lockForUpdate()
                ->firstOrFail();

            // ── 2. Guards ─────────────────────────────────────────────────
            $this->assertWalletNotFrozen($wallet);

            $availBefore = (float) $wallet->available_balance;
            if ($availBefore < $amount) {
                throw new \RuntimeException(
                    sprintf(
                        'Số dư khả dụng không đủ để chuyển vào escrow. '
                        . 'Yêu cầu: %s VNĐ | Khả dụng: %s VNĐ.',
                        number_format($amount, 2),
                        number_format($availBefore, 2)
                    )
                );
            }

            // ── 3. Tính toán ──────────────────────────────────────────────
            $pendingBefore = (float) $wallet->pending_balance;
            $availAfter    = round($availBefore - $amount, 2);
            $pendingAfter  = round($pendingBefore + $amount, 2);

            // ── 4. Cập nhật ───────────────────────────────────────────────
            $wallet->forceFill([
                'available_balance' => $availAfter,
                'pending_balance'   => $pendingAfter,
            ])->save();

            // ── 5. Double-Entry – ghi nhận phần available giảm ───────────
            $txn = WalletTransaction::create([
                'wallet_id'      => $wallet->id,
                'type'           => self::TYPE_DEPOSIT_ESCROW,
                'amount'         => $amount,
                'balance_before' => $availBefore,   // available BEFORE
                'balance_after'  => $availAfter,    // available AFTER
                'reference_type' => get_class($reference),
                'reference_id'   => $reference->getKey(),
                'description'    => $description,
            ]);

            Log::info('[WalletService::transferAvailableToPending]', [
                'wallet_id'      => $wallet->id,
                'user_id'        => $userId,
                'amount'         => $amount,
                'avail_before'   => $availBefore,
                'avail_after'    => $availAfter,
                'pending_before' => $pendingBefore,
                'pending_after'  => $pendingAfter,
                'txn_id'         => $txn->id,
            ]);

            return $txn;
        });
    }

    /**
     * Lấy snapshot số dư hiện tại của user (read-only, không lock).
     *
     * Chỉ dùng để hiển thị — không dùng để ra quyết định tài chính
     * (vì không có lock, số dư có thể thay đổi ngay sau khi đọc).
     *
     * @param  int $userId
     * @return array{
     *   wallet_id: int,
     *   available_balance: float,
     *   pending_balance: float,
     *   total_balance: float,
     *   is_frozen: bool
     * }|null  Null nếu chưa có ví
     */
    public function getBalanceSnapshot(int $userId): ?array
    {
        $wallet = Wallet::where('user_id', $userId)->first();

        if (! $wallet) {
            return null;
        }

        return [
            'wallet_id'         => $wallet->id,
            'available_balance' => (float) $wallet->available_balance,
            'pending_balance'   => (float) $wallet->pending_balance,
            'total_balance'     => $wallet->getTotalBalance(),
            'is_frozen'         => $wallet->isFrozen(),
        ];
    }

    // ══════════════════════════════════════════════════════════════════════
    // PRIVATE GUARDS (Defensive assertions)
    // ══════════════════════════════════════════════════════════════════════

    /**
     * Đảm bảo amount hợp lệ (> 0).
     *
     * Ném exception sớm trước khi mở transaction để tránh
     * giữ DB lock vô ích khi input rõ ràng là sai.
     *
     * @throws \InvalidArgumentException
     */
    private function assertPositiveAmount(float $amount, string $caller): void
    {
        if ($amount <= 0) {
            throw new \InvalidArgumentException(
                sprintf(
                    '[%s] Số tiền phải lớn hơn 0. Nhận được: %s.',
                    class_basename($caller),
                    $amount
                )
            );
        }
    }

    /**
     * Đảm bảo ví không bị đóng băng trước mọi giao dịch.
     *
     * Gọi SAU khi đã lockForUpdate() để đảm bảo trạng thái
     * frozen đọc từ DB tươi nhất, không phải từ cache.
     *
     * @throws \RuntimeException
     */
    private function assertWalletNotFrozen(Wallet $wallet): void
    {
        if ($wallet->isFrozen()) {
            Log::warning('[WalletService] Giao dịch bị chặn: ví bị đóng băng', [
                'wallet_id' => $wallet->id,
                'user_id'   => $wallet->user_id,
            ]);

            throw new \RuntimeException(
                "Ví #{$wallet->id} đang bị đóng băng. "
                . 'Vui lòng liên hệ admin để mở khóa trước khi thực hiện giao dịch.'
            );
        }
    }

    /**
     * Đảm bảo type giao dịch hợp lệ cho deductAvailableFunds().
     *
     * Chỉ cho phép các type là debit — không cho phép credit type
     * (top_up, release_fund) vào hàm trừ tiền để tránh lỗi nghiệp vụ.
     *
     * @throws \InvalidArgumentException
     */
    private function assertValidDebitType(string $type): void
    {
        if (! in_array($type, self::VALID_DEBIT_TYPES, true)) {
            throw new \InvalidArgumentException(
                sprintf(
                    'Loại giao dịch "%s" không hợp lệ cho deductAvailableFunds(). '
                    . 'Chỉ chấp nhận: %s.',
                    $type,
                    implode(', ', self::VALID_DEBIT_TYPES)
                )
            );
        }
    }
}
