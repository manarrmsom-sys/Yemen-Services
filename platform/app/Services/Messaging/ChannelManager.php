<?php

namespace App\Services\Messaging;

use App\Enums\OtpChannel;
use InvalidArgumentException;

class ChannelManager
{
    public function __construct(
        private readonly WhatsAppCloudChannel $whatsapp,
        private readonly HttpSmsChannel $sms,
    ) {}

    public function for(OtpChannel|string $channel): MessageChannel
    {
        $value = $channel instanceof OtpChannel ? $channel->value : $channel;

        return match ($value) {
            'whatsapp' => $this->whatsapp,
            'sms' => $this->sms,
            default => throw new InvalidArgumentException("Unknown messaging channel [{$value}]."),
        };
    }
}
