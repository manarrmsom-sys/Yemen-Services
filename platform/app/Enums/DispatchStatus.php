<?php

namespace App\Enums;

enum DispatchStatus: string
{
    case Queued = 'queued';
    case Sent = 'sent';
    case Delivered = 'delivered';
    case Accepted = 'accepted';
    case Declined = 'declined';
    case Expired = 'expired';
    case Failed = 'failed';
}
