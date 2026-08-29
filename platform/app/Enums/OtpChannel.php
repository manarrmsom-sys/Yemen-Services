<?php

namespace App\Enums;

enum OtpChannel: string
{
    case WhatsApp = 'whatsapp';
    case Sms = 'sms';
}
