<?php

namespace App\Mail;

use App\Models\Invoice;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Mailable: SendInvoiceMail
 *
 * Gửi email hóa đơn hàng tháng đến khách thuê với:
 *   - PDF hóa đơn đính kèm (đã được sinh bởi GenerateInvoicePdfAndSendEmailJob)
 *   - Nội dung email dạng Markdown (template: emails.invoice)
 *
 * Implements ShouldQueue → email được gửi qua queue (không block main thread).
 * SerializesModels → Invoice model được serialize an toàn qua queue.
 */
class SendInvoiceMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    /**
     * @param Invoice $invoice    Hóa đơn cần gửi (lazy-loaded khi serialize)
     * @param string  $pdfPath    Đường dẫn tuyệt đối đến file PDF trong storage
     * @param string|null $electricityEvidenceImageUrl URL ảnh đồng hồ điện
     * @param string|null $waterEvidenceImageUrl URL ảnh đồng hồ nước
     */
    public function __construct(
        public readonly Invoice $invoice,
        public readonly string  $pdfPath,
        public readonly ?string $electricityEvidenceImageUrl = null,
        public readonly ?string $waterEvidenceImageUrl = null,
    ) {}

    /**
     * Subject của email.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: sprintf(
                '[%s] Hóa đơn tiền thuê phòng tháng %s',
                config('app.name'),
                $this->invoice->billing_month
            ),
        );
    }

    /**
     * Template email: resources/views/emails/invoice.blade.php
     */
    public function content(): Content
    {
        return new Content(
            markdown: 'emails.invoice',
            with: [
                'invoice'                     => $this->invoice->load(['items', 'contract.room', 'tenant']),
                'electricityEvidenceImageUrl' => $this->electricityEvidenceImageUrl,
                'waterEvidenceImageUrl'       => $this->waterEvidenceImageUrl,
                'paymentDeadline'             => $this->invoice->due_date->format('d/m/Y'),
            ],
        );
    }

    /**
     * Đính kèm file PDF.
     *
     * @return array<int, Attachment>
     */
    public function attachments(): array
    {
        $filename = 'HoaDon-' . $this->invoice->invoice_code . '.pdf';

        return [
            Attachment::fromPath($this->pdfPath)
                ->as($filename)
                ->withMime('application/pdf'),
        ];
    }
}
