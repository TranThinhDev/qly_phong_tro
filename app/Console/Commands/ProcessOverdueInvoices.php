<?php

namespace App\Console\Commands;

use App\Models\Invoice;
use App\Mail\OverdueInvoiceMail;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class ProcessOverdueInvoices extends Command
{
    /**
     * Chạy vào ngày 5 hàng tháng (hoặc chạy daily để kiểm tra)
     */
    protected $signature = 'billing:process-overdue';
    protected $description = 'Kiểm tra hóa đơn quá hạn, áp dụng phí phạt và gửi email nhắc nhở cho khách thuê';

    public function handle()
    {
        $this->info("Bắt đầu kiểm tra và xử lý hóa đơn quá hạn...");
        
        // Lấy tất cả hóa đơn chưa thanh toán hoặc thanh toán 1 phần và đã qua due_date
        $overdueInvoices = Invoice::with('tenant')
            ->whereIn('status', ['unpaid', 'partial'])
            ->whereDate('due_date', '<', now()->toDateString())
            ->get();

        $processedCount = 0;

        foreach ($overdueInvoices as $invoice) {
            // Dùng transaction và lockForUpdate để đảm bảo an toàn
            try {
                DB::transaction(function () use ($invoice) {
                    $lockedInvoice = Invoice::lockForUpdate()->find($invoice->id);
                    
                    // Double check sau khi lock
                    if (!in_array($lockedInvoice->status, ['unpaid', 'partial']) || 
                        $lockedInvoice->due_date->greaterThanOrEqualTo(now()->startOfDay())) {
                        return; // Không thoả điều kiện nữa
                    }

                    // Chuyển sang trạng thái overdue
                    $lockedInvoice->transitionTo('overdue');

                    // Tính phí phạt: ví dụ 5% của tổng tiền chưa đóng
                    // Hoặc có thể cấu hình lấy từ settings.
                    $penaltyRate = config('billing.late_fee_rate', 0.05);
                    
                    // Tính trên tổng tiền gốc (trừ đi số tiền đã đóng)
                    $remainingAmount = $lockedInvoice->remainingAmount();
                    $lateFee = round($remainingAmount * $penaltyRate, 2);

                    if ($lateFee > 0) {
                        $lockedInvoice->applyLateFee($lateFee);
                    }

                    // Gửi email thông báo cho tenant
                    if ($lockedInvoice->tenant && $lockedInvoice->tenant->email) {
                        Mail::to($lockedInvoice->tenant->email)->send(
                            new OverdueInvoiceMail($lockedInvoice)
                        );
                    }
                });

                $processedCount++;
                $this->line("Đã xử lý quá hạn cho Hóa đơn #{$invoice->invoice_code}");
            } catch (\Exception $e) {
                $this->error("Lỗi khi xử lý Hóa đơn #{$invoice->id}: " . $e->getMessage());
                Log::error("[ProcessOverdueInvoices] Lỗi", [
                    'invoice_id' => $invoice->id,
                    'message' => $e->getMessage()
                ]);
            }
        }

        $this->info("Đã hoàn thành! Xử lý {$processedCount} hóa đơn quá hạn.");
        Log::info("[ProcessOverdueInvoices] Hoàn thành xử lý {$processedCount} hóa đơn.");
        
        return Command::SUCCESS;
    }
}
