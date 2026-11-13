<?php

// Domain configuration for the Yemen Services platform.
// Everything tunable by ops lives here and is driven by .env.

return [

    'currency' => env('PRICING_CURRENCY', 'YER'),

    'pricing' => [
        // Platform commission taken from every completed order (percent of subtotal).
        'commission_percent' => (float) env('PLATFORM_COMMISSION_PERCENT', 12),
        'vat_percent' => (float) env('PRICING_VAT_PERCENT', 0),
        // Surcharge applied when the customer picks the "urgent / within the hour" slot.
        'urgent_surcharge_percent' => (float) env('PRICING_URGENT_SURCHARGE_PERCENT', 25),
        'min_order' => (int) env('PRICING_MIN_ORDER_YER', 2000),
        // Rounding step for the customer-facing estimate (avoid odd figures in YER).
        'rounding_step' => (int) env('PRICING_ROUNDING_STEP', 100),
    ],

    'otp' => [
        'length' => (int) env('OTP_LENGTH', 5),
        'ttl_seconds' => (int) env('OTP_TTL_SECONDS', 300),
        'max_attempts' => (int) env('OTP_MAX_ATTEMPTS', 5),
        'resend_cooldown_seconds' => (int) env('OTP_RESEND_COOLDOWN_SECONDS', 60),
        'default_channel' => env('OTP_CHANNEL_DEFAULT', 'email'),
        'channels' => [
            'email' => (bool) env('OTP_CHANNEL_EMAIL_ENABLED', true),
            'whatsapp' => (bool) env('OTP_CHANNEL_WHATSAPP_ENABLED', false),
            'sms' => (bool) env('OTP_CHANNEL_SMS_ENABLED', false),
        ],
    ],

    'mail' => [
        'from_address' => env('MAIL_FROM_ADDRESS'),
        'from_name' => env('MAIL_FROM_NAME', env('APP_NAME', 'Yemen Services')),
    ],

    'whatsapp' => [
        'api_base' => env('WHATSAPP_API_BASE', 'https://graph.facebook.com/v21.0'),
        'phone_number_id' => env('WHATSAPP_PHONE_NUMBER_ID'),
        'access_token' => env('WHATSAPP_ACCESS_TOKEN'),
        // Meta App Secret: verifies X-Hub-Signature-256 on inbound webhooks.
        'app_secret' => env('WHATSAPP_APP_SECRET'),
        'otp_template' => env('WHATSAPP_TEMPLATE_OTP', 'otp_login'),
        // Business number used to build wa.me deep links for the "Gap Play" dispatch.
        'business_number' => env('WHATSAPP_BUSINESS_NUMBER', '967774274848'),
        'webhook_verify_token' => env('WHATSAPP_WEBHOOK_VERIFY_TOKEN'),
    ],

    'sms' => [
        'driver' => env('SMS_DRIVER', 'log'),
        'endpoint' => env('SMS_ENDPOINT'),
        'api_key' => env('SMS_API_KEY'),
        'sender_id' => env('SMS_SENDER_ID', 'YemenSvc'),
    ],

    'n8n' => [
        'dispatch_url' => env('N8N_WEBHOOK_DISPATCH_URL'),
        'secret' => env('N8N_WEBHOOK_SECRET'),
    ],

    'webhooks' => [
        'signing_algo' => env('WEBHOOK_SIGNING_ALGO', 'sha256'),
        'timeout_seconds' => (int) env('WEBHOOK_TIMEOUT_SECONDS', 8),
        'max_attempts' => (int) env('WEBHOOK_MAX_ATTEMPTS', 5),
    ],

    'rate_limits' => [
        'api' => (int) env('RATELIMIT_API', 90),
        'otp_request' => (int) env('RATELIMIT_OTP_REQUEST', 5),
        'booking' => (int) env('RATELIMIT_BOOKING', 20),
        'admin' => (int) env('RATELIMIT_ADMIN', 240),
        'home' => (int) env('RATELIMIT_HOME', 120),
    ],

    // Yemeni governorates — canonical list used for geo tagging / dispatch routing.
    'governorates' => [
        'sanaa', 'aden', 'taiz', 'hodeidah', 'ibb', 'hadhramaut', 'dhamar', 'marib',
        'saada', 'al_bayda', 'lahij', 'al_dhale', 'shabwah', 'al_jawf', 'amran',
        'al_mahwit', 'hajjah', 'raymah', 'al_mahrah', 'socotra', 'abyan', 'amanat_al_asimah',
    ],
];
