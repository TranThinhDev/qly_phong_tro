<?php

namespace App\Console\Commands;

use App\Jobs\GenerateInvoicePdfAndSendEmailJob;
use App\Services\InvoiceService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Command: RunMonthlyBilling
 *
 * Sinh hóa đơn tự động cho tất cả hợp đồng active,
 * sau đó dispatch Job sinh PDF + gửi email cho từng hóa đơn.
 *
 * Chạy tự động: ngày 1 hàng tháng lúc 00:00 (xem Console/Kernel.php).
 * Chạy thủ công: php artisan billing:run-monthly
 *                php artisan billing:run-monthly --month=6 --year=2026
 *
 * Output mẫu:
 *   Bắt đầu Auto-Billing kỳ 06/2026...
 *   ✓ Đã sinh 25 hóa đơn. Bỏ qua: 3. Thất bại: 1.
 *   Dispatch 25 jobs sinh PDF + gửi email...
 *   Hoàn tất!
 */
class RunMonthlyBilling extends Command
{
    /**
     * Tên và signature của command.
     *
     * Options:
     *   --month : Tháng cần chạy (1-12). Mặc định = tháng hiện tại.
     *   --year  : Năm cần chạy. Mặc định = năm hiện tại.
     *   --dry-run : Chỉ hiển thị sẽ làm gì, không thực sự sinh hóa đơn.
     */
    protected $signature = 'billing:run-monthly
        {--month= : Tháng cần chạy (1-12). Mặc định = tháng hiện tại}
        {--year=  : Năm cần chạy. Mặc định = năm hiện tại}
        {--dry-run : Dry run: không sinh hóa đơn thật, chỉ in ra số lượng hợp đồng sẽ xử lý}';

    protected $description = 'Tự động sinh hóa đơn hàng tháng cho toàn bộ hợp đồng active và gửi email đến khách thuê';

    public function __construct(
        private readonly InvoiceService $invoiceService,
    ) {
        parent::__construct();
    }

    /**
     * Xử lý command.
     */
    public function handle(): int
    {
        // ── Xác định kỳ thanh toán ────────────────────────────────────────
        $month = (int) ($this->option('month') ?: now()->month);
        $year  = (int) ($this->option('year')  ?: now()->year);

        // Validate month/year
        if ($month < 1 || $month > 12) {
            $this->error('Tháng không hợp lệ. Nhập giá trị từ 1 đến 12.');
            return Command::FAILURE;
        }
        if ($year < 2020 || $year > 2099) {
            $this->error('Năm không hợp lệ.');
            return Command::FAILURE;
        }

        $billingDate  = Carbon::create($year, $month, 1);
        $billingMonth = $billingDate->format('m/Y');

        // ── Dry run mode ──────────────────────────────────────────────────
        if ($this->option('dry-run')) {
            $count = \App\Models\Contract::where('status', 'active')->count();
            $this->info("🔍 [DRY RUN] Kỳ {$billingMonth}: tìm thấy {$count} hợp đồng active.");
            $this->info("   Không có hóa đơn nào được sinh thật.");
            return Command::SUCCESS;
        }

        // ── Bắt đầu xử lý ────────────────────────────────────────────────
        $this->info("⚡ Bắt đầu Auto-Billing kỳ {$billingMonth}...");
        $this->newLine();

        Log::info("[RunMonthlyBilling] Bắt đầu lúc " . now()->toDateTimeString() . " cho kỳ {$billingMonth}");

        $startTime = microtime(true);

        // ── Gọi InvoiceService::generateMonthlyInvoices() ────────────────
        $result = $this->invoiceService->generateMonthlyInvoices($billingDate);

        $generated = $result['generated'];
        $skipped   = $result['skipped'];
        $failed    = $result['failed'];

        // ── In kết quả ───────────────────────────────────────────────────
        $this->line(sprintf(
            '  ✓ <fg=green>Đã sinh:</> %d hóa đơn',
            count($generated)
        ));

        if (count($skipped) > 0) {
            $this->line(sprintf(
                '  ⏭ <fg=yellow>Bỏ qua (đã tồn tại):</> %d hợp đồng',
                count($skipped)
            ));
        }

        if (count($failed) > 0) {
            $this->line(sprintf(
                '  ✗ <fg=red>Thất bại:</> %d hợp đồng',
                count($failed)
            ));
            foreach ($failed as $contractId => $errorMsg) {
                $this->warn("    - Contract #{$contractId}: {$errorMsg}");
            }
        }

        $this->newLine();

        // ── Dispatch Job cho từng hóa đơn vừa sinh ───────────────────────
        if (count($generated) > 0) {
            $this->info("📧 Dispatch " . count($generated) . " jobs sinh PDF + gửi email...");

            $dispatchBar = $this->output->createProgressBar(count($generated));
            $dispatchBar->start();

            foreach ($generated as $invoice) {
                GenerateInvoicePdfAndSendEmailJob::dispatch($invoice->id)
                    ->onQueue('invoices'); // Queue riêng để không block queue chính
                $dispatchBar->advance();
            }

            $dispatchBar->finish();
            $this->newLine();
        }

        // ── Tổng kết ─────────────────────────────────────────────────────
        $elapsed = round(microtime(true) - $startTime, 2);
        $this->newLine();
        $this->info("✅ Hoàn tất kỳ {$billingMonth} trong {$elapsed}s.");

        Log::info("[RunMonthlyBilling] Kết thúc", [
            'billing_month' => $billingMonth,
            'generated'     => count($generated),
            'skipped'       => count($skipped),
            'failed'        => count($failed),
            'elapsed_sec'   => $elapsed,
        ]);

        // Trả về FAILURE nếu có invoice nào sinh lỗi (để cronjob bên ngoài biết)
        return count($failed) === 0 ? Command::SUCCESS : Command::FAILURE;
    }
}
