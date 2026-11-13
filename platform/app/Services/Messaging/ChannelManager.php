<?php

namespace App\Services\Messaging;

use App\Enums\OtpChannel;
use InvalidArgumentException;

class ChannelManager
{
    public function __construct(
        private readonly WhatsAppCloudChannel $whatsapp,
        private readonly HttpSmsChannel $sms,
        private readonly EmailChannel $email,
    ) {}

    public function for(OtpChannel|string $channel): MessageChannel
    {
        $value = $channel instanceof OtpChannel ? $channel->value : $channel;

        if (! config("platform.otp.channels.{$value}", false)) {
            throw new InvalidArgumentException("Messaging channel [{$value}] is disabled.");
        }

        return match ($value) {
            'email' => $this->email,
            'whatsapp' => $this->whatsapp,
            'sms' => $this->sms,
            default => throw new InvalidArgumentException("Unknown messaging channel [{$value}]."),
        };
    }
}
