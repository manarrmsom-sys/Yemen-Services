<?php

return [
    // Placeholder for first-party integration credentials pulled from config/platform.php.
    // Kept so packages expecting config('services.*') do not break.
    'whatsapp' => [
        'token' => env('WHATSAPP_ACCESS_TOKEN'),
        'phone_number_id' => env('WHATSAPP_PHONE_NUMBER_ID'),
    ],
];
