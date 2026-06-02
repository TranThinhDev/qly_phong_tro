<?php

namespace App\Mail;

use App\Models\Invoice;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class OverdueInvoiceMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public Invoice $invoice
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "[Thông báo quá hạn] Hóa đơn tháng {$this->invoice->billing_month} đã quá hạn thanh toán",
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.overdue_invoice',
            with: [
                'invoice' => $this->invoice,
            ]
        );
    }
}
