<?php

return [

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    // Resend transactional email (docs/resend.com). Used for email-channel
    // OTP and other transactional mail when MAIL_MAILER=resend.
    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'paystack' => [
        'public_key' => env('PAYSTACK_PUBLIC_KEY'),
        'secret_key' => env('PAYSTACK_SECRET_KEY'),
        'webhook_secret' => env('PAYSTACK_WEBHOOK_SECRET'),
        'base_url' => env('PAYSTACK_BASE_URL', 'https://api.paystack.co'),
    ],

    'sms' => [
        'driver' => env('SMS_PROVIDER_DRIVER', 'smartsmssolutions'),
        'key' => env('SMS_PROVIDER_KEY'),
        'sender_id' => env('SMS_PROVIDER_SENDER_ID', 'FirstMkt'),
        'daily_budget_kobo' => (int) env('SMS_DAILY_BUDGET_KOBO', 0),
    ],

    'ai' => [
        'driver' => env('AI_PROVIDER_DRIVER', 'openai'),
        'key' => env('AI_PROVIDER_KEY'),
        'monthly_budget_kobo' => (int) env('AI_MONTHLY_BUDGET_KOBO', 0),
    ],

    'address_lookup' => [
        'key' => env('ADDRESS_LOOKUP_KEY'),
    ],

    'push' => [
        'driver' => env('PUSH_NOTIFICATION_DRIVER', 'web_push'),
        'public_key' => env('PUSH_NOTIFICATION_PUBLIC_KEY'),
        'private_key' => env('PUSH_NOTIFICATION_PRIVATE_KEY'),
    ],

    'affiliate' => [
        // Signs/verifies affiliate tracking tokens; never reuse APP_KEY for this.
        'tracking_signing_key' => env('AFFILIATE_TRACKING_SIGNING_KEY'),
    ],

    // Social login (Sprint 2 Addendum): Continue with Google / Facebook.
    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect' => env('GOOGLE_REDIRECT_URI', '/auth/google/callback'),
    ],

    'facebook' => [
        'client_id' => env('FACEBOOK_CLIENT_ID'),
        'client_secret' => env('FACEBOOK_CLIENT_SECRET'),
        'redirect' => env('FACEBOOK_REDIRECT_URI', '/auth/facebook/callback'),
    ],

    // Support Center contact channels (Sprint 7).
    'support' => [
        'whatsapp' => env('SUPPORT_WHATSAPP_NUMBER', '+2340000000000'),
        'hotline' => env('SUPPORT_HOTLINE_NUMBER', '+2340000000000'),
    ],

];
