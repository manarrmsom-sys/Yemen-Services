<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use RuntimeException;

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
        $key = config('services.resend.key');

        // Render's free plan blocks outbound SMTP (25/465/587), so production
        // sends over an HTTPS email API. Without a key, keep the SMTP mailer
        // (local development, or a host with SMTP egress).
        if (filled($key)) {
            $this->sendWithResend((string) $key);

            return;
        }

        Mail::send([], [], function ($message): void {
            $message->to($this->recipient)
                ->subject($this->subject)
                ->html(nl2br(e($this->body)));
        });
    }

    /** Deliver through Resend's HTTP API; throws on any non-2xx answer. */
    private function sendWithResend(string $key): void
    {
        $from = config('mail.from.address');
        if (blank($from)) {
            throw new RuntimeException('MAIL_FROM_ADDRESS must be set to send email through Resend.');
        }

        Http::withToken($key)
            ->acceptJson()
            ->post('https://api.resend.com/emails', [
                'from' => sprintf('%s <%s>', config('mail.from.name') ?: config('app.name'), $from),
                'to' => [$this->recipient],
                'subject' => $this->subject,
                'html' => nl2br(e($this->body)),
            ])
            ->throw();
    }
}