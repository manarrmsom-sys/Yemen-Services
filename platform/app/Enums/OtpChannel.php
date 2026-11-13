<?php

namespace App\Enums;

enum OtpChannel: string
{
    case Email = 'email';
    case WhatsApp = 'whatsapp';
    case Sms = 'sms';
}
