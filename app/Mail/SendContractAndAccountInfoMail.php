<?php

namespace App\Mail;

use App\Models\Contract;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class SendContractAndAccountInfoMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public $contract;
    public $user;
    public $plainPassword;

    /**
     * Create a new message instance.
     */
    public function __construct(Contract $contract, User $user, ?string $plainPassword = null)
    {
        $this->contract = $contract;
        $this->user = $user;
        $this->plainPassword = $plainPassword;
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Thông tin tài khoản và Hợp đồng thuê phòng của bạn',
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            markdown: 'emails.contract_account_info',
        );
    }

    /**
     * Get the attachments for the message.
     *
     * @return array<int, \Illuminate\Mail\Mailables\Attachment>
     */
    public function attachments(): array
    {
        return [];
    }
}
