<?php

namespace App\Console\Commands;

use App\Models\Invoice;
use App\Services\WalletService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ReleaseEscrowFunds extends Command
{
    /**
     * Tên lệnh artisan.
     * Cách chạy: php artisan escrow:release-funds
     */
    protected $signature = 'escrow:release-funds';

    /**
     * Mô tả chức năng.
     */
    protected $description = 'Tự động giải phóng tiền (từ pending sang available) cho chủ trọ sau 24h kể từ khi khách thanh toán, nếu không có tranh chấp.';

    /**
     * Execute the console command.
     */
    public function handle(WalletService $walletService)
    {
        $this->info('Đang kiểm tra các hóa đơn cần giải phóng tiền...');

        // ── 1. Tìm các hóa đơn hợp lệ ──────────────────────────────────────
        // - status = 'paid' (Đã thanh toán)
        // - is_fund_released = false (Chưa giải phóng)
        // - updated_at <= 24 giờ trước (Đã qua thời gian chờ bảo lưu)
        $invoices = Invoice::where('status', 'paid')
            ->where('is_fund_released', false)
            ->where('updated_at', '<=', now()->subHours(24))
            ->get();

        if ($invoices->isEmpty()) {
            $this->info('Không có hóa đơn nào cần giải phóng lúc này.');
            return;
        }

        $successCount = 0;
        $failCount    = 0;

        foreach ($invoices as $invoice) {
            try {
                DB::transaction(function () use ($invoice, $walletService) {
                    
                    // ── 2. Kiểm tra tranh chấp (Dispute) ────────────────────
                    // TODO: Mở comment khi có model Dispute (Module 6)
                    // $hasOpenDispute = \App\Models\Dispute::where('invoice_id', $invoice->id)
                    //     ->where('status', 'open')
                    //     ->exists();
                    $hasOpenDispute = false; // Placeholder tạm thời

                    if ($hasOpenDispute) {
                        Log::info("[ReleaseEscrowFunds] Bỏ qua hóa đơn {$invoice->id} vì đang có tranh chấp.");
                        return; // Bỏ qua, chờ xử lý tranh chấp xong
                    }

                    // ── 3. Lấy thông tin chủ trọ ────────────────────────────
                    // Cần khóa (lock) invoice để tránh xử lý đúp
                    $lockedInvoice = Invoice::lockForUpdate()->find($invoice->id);
                    
                    // Re-check để đảm bảo chưa ai giải phóng trong lúc chờ lock
                    if ($lockedInvoice->is_fund_released) {
                        return; 
                    }

                    $contract = $lockedInvoice->contract()->with('room')->first();
                    $landlordId = $contract ? $contract->room->chutro_id : null;

                    if (! $landlordId) {
                        Log::warning("[ReleaseEscrowFunds] Hóa đơn {$lockedInvoice->id} không có chủ trọ hợp lệ.");
                        return;
                    }

                    // ── 4. Giải phóng tiền vào Available Balance ───────────
                    $walletService->releaseFundsToAvailable(
                        $landlordId,
                        (float) $lockedInvoice->total_amount,
                        $lockedInvoice,
                        "Giải phóng tiền hóa đơn {$lockedInvoice->invoice_code} sau 24h"
                    );

                    // ── 5. Cập nhật cờ trên hóa đơn ────────────────────────
                    $lockedInvoice->forceFill(['is_fund_released' => true])->save();

                    Log::info("[ReleaseEscrowFunds] Giải phóng thành công hóa đơn {$lockedInvoice->id}");
                });

                $successCount++;
                
            } catch (\Throwable $e) {
                $failCount++;
                Log::error("[ReleaseEscrowFunds] Lỗi giải phóng hóa đơn {$invoice->id}", [
                    'message' => $e->getMessage(),
                    'trace'   => $e->getTraceAsString(),
                ]);
            }
        }

        $this->info("Hoàn tất! Thành công: {$successCount}, Lỗi: {$failCount}.");
    }
}
