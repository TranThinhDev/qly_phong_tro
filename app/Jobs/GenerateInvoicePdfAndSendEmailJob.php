<?php

namespace App\Jobs;

use App\Mail\SendInvoiceMail;
use App\Models\Invoice;
use App\Models\UtilityReading;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

/**
 * Job: GenerateInvoicePdfAndSendEmailJob
 *
 * Nhiệm vụ: Sinh file PDF cho hóa đơn và gửi email đến khách thuê.
 *
 * ┌─────────────────────────────────────────────────────────┐
 * │  LÝ DO DÙNG JOB THAY VÌ XỬ LÝ TRỰC TIẾP               │
 * │                                                         │
 * │  1. PDF generation (DomPDF) tốn nhiều RAM/CPU.          │
 * │  2. Gửi email phụ thuộc vào SMTP server bên ngoài       │
 * │     → có thể fail tạm thời → cần retry tự động.        │
 * │  3. Không block HTTP request hay Scheduler process.     │
 * │                                                         │
 * │  LUỒNG XỬ LÝ:                                           │
 * │   handle() → load Invoice → generate PDF → save to      │
 * │   Storage → send email (Mailable) → delete temp PDF     │
 * └─────────────────────────────────────────────────────────┘
 */
class GenerateInvoicePdfAndSendEmailJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Số lần retry khi job fail (mặc định Laravel = 3).
     * Tăng lên 5 vì việc gửi email có thể fail tạm thời do SMTP.
     */
    public int $tries = 5;

    /**
     * Thời gian chờ tối đa (giây) trước khi coi job là timeout.
     * DomPDF render hóa đơn phức tạp có thể mất 10-15s.
     */
    public int $timeout = 60;

    /**
     * Thời gian backoff giữa các lần retry (giây).
     * [10, 30, 60, 120, 300] = exponential backoff
     */
    public function backoff(): array
    {
        return [10, 30, 60, 120, 300];
    }

    /**
     * @param int $invoiceId ID của hóa đơn cần xử lý.
     *                       Dùng ID thay vì model để tránh race condition
     *                       khi model thay đổi giữa dispatch và execute.
     */
    public function __construct(
        private readonly int $invoiceId,
    ) {}

    /**
     * Xử lý job chính.
     */
    public function handle(): void
    {
        // ── 1. Load Invoice với đầy đủ quan hệ cần thiết ─────────────────
        $invoice = Invoice::with([
            'items',
            'tenant',
            'contract.room',
            'contract.landlord',
        ])->find($this->invoiceId);

        if (! $invoice) {
            // Invoice đã bị xóa → bỏ qua, không retry
            Log::warning("[InvoicePdfJob] Invoice #{$this->invoiceId} không tồn tại. Bỏ qua.");
            $this->delete();
            return;
        }

        if (! $invoice->tenant) {
            Log::error("[InvoicePdfJob] Invoice #{$invoice->id} không có tenant. Bỏ qua.");
            $this->delete();
            return;
        }

        Log::info("[InvoicePdfJob] Bắt đầu xử lý invoice #{$invoice->invoice_code}");

        // ── 2. Tìm ảnh bằng chứng điện/nước (nếu có) ────────────────────
        $electricityEvidenceImagePath = null;
        $waterEvidenceImagePath       = null;

        if ($invoice->contract?->room) {
            $room = $invoice->contract->room;

            [$billMonth, $billYear] = explode('/', $invoice->billing_month); // e.g. ["06","2026"]

            $reading = UtilityReading::where('room_id', $room->id)
                ->where('month', (int) $billMonth)
                ->where('year',  (int) $billYear)
                ->where(function($q) {
                    $q->whereNotNull('electricity_evidence_image_url')
                      ->orWhereNotNull('water_evidence_image_url');
                })
                ->first();

            if ($reading) {
                if ($reading->electricity_evidence_image_url) {
                    $electricityEvidenceImagePath = Storage::disk('public')->path($reading->electricity_evidence_image_url);
                    if (! file_exists($electricityEvidenceImagePath)) {
                        $electricityEvidenceImagePath = null;
                    }
                }
                
                if ($reading->water_evidence_image_url) {
                    $waterEvidenceImagePath = Storage::disk('public')->path($reading->water_evidence_image_url);
                    if (! file_exists($waterEvidenceImagePath)) {
                        $waterEvidenceImagePath = null;
                    }
                }
            }
        }

        // ── 3. Sinh PDF bằng DomPDF ───────────────────────────────────────
        $pdf = Pdf::loadView('pdf.invoice', [
            'invoice'                      => $invoice,
            'electricityEvidenceImagePath' => $electricityEvidenceImagePath,
            'waterEvidenceImagePath'       => $waterEvidenceImagePath,
        ])
        ->setPaper('a4', 'portrait')
        ->setOption('defaultFont', 'DejaVu Sans')
        ->setOption('isHtml5ParserEnabled', true)
        ->setOption('isRemoteEnabled', false); // Tắt remote resources vì lý do bảo mật

        // ── 4. Lưu PDF vào Storage ────────────────────────────────────────
        // Path: storage/app/public/invoices/{year}/{month}/{invoice_code}.pdf
        [$billMonth, $billYear] = explode('/', $invoice->billing_month);
        $pdfStoragePath = "invoices/{$billYear}/{$billMonth}/{$invoice->invoice_code}.pdf";

        Storage::disk('public')->put($pdfStoragePath, $pdf->output());

        $pdfAbsolutePath = Storage::disk('public')->path($pdfStoragePath);

        Log::info("[InvoicePdfJob] PDF sinh thành công: {$pdfStoragePath}");

        // ── 5. Gửi email ──────────────────────────────────────────────────
        // Dùng Mail::to() → trigger SendInvoiceMail Mailable
        // SendInvoiceMail cũng implements ShouldQueue nên sẽ vào queue email
        Mail::to($invoice->tenant->email)
            ->send(new SendInvoiceMail(
                invoice:          $invoice,
                pdfPath:          $pdfAbsolutePath,
                evidenceImageUrl: $evidenceImageUrl,
            ));

        Log::info("[InvoicePdfJob] Email đã gửi đến {$invoice->tenant->email}");
    }

    /**
     * Xử lý khi job fail sau tất cả các lần retry.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error("[InvoicePdfJob] Job FAILED sau {$this->tries} lần retry cho invoice #{$this->invoiceId}", [
            'message' => $exception->getMessage(),
            'trace'   => $exception->getTraceAsString(),
        ]);
    }
}
