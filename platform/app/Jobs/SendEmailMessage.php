<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;

class SendEmailMessage implements ShouldQueue
{
    use Dispatchable, Queueable, SerializesModels;

    public function __construct(
        public readonly string $recipient,
        public readonly string $body,
        public readonly string $subject,
    ) {}

    public function handle(): void
    {
        Mail::send([], [], function ($message): void {
            $message->to($this->recipient)
                ->subject($this->subject)
                ->html(nl2br(e($this->body)));
        });
    }
}