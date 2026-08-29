<?php

namespace App\Services\Messaging;

interface MessageChannel
{
    /**
     * Send a transactional message. Return a provider message id on success.
     *
     * @param  array<string, mixed>  $context  template variables / metadata
     */
    public function send(string $toPhone, string $body, array $context = []): string;

    public function name(): string;
}
