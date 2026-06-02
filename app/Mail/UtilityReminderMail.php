<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class UtilityReminderMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public User $landlord,
        public int $missingRoomsCount,
        public int $month,
        public int $year
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "[Nhắc nhở] Cập nhật chỉ số điện/nước tháng {$this->month}/{$this->year}",
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.utility_reminder',
            with: [
                'landlord'          => $this->landlord,
                'missingRoomsCount' => $this->missingRoomsCount,
                'month'             => $this->month,
                'year'              => $this->year,
            ]
        );
    }
}
