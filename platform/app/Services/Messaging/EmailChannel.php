<?php

namespace App\Services\Messaging;

use App\Jobs\SendEmailMessage;

class EmailChannel implements MessageChannel
{
    public function send(string $recipient, string $body, array $context = []): string
    {
        SendEmailMessage::dispatch(
            $recipient,
            $body,
            $context['subject'] ?? config('app.name').' verification code',
        );

        return 'email-'.sha1($recipient.$body.microtime());
    }

    public function name(): string
    {
        return 'email';
    }
}