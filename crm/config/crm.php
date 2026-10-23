<?php

$formOrigins = array_filter(array_map('trim', explode(',', (string) env('CRM_FORM_ORIGINS', 'https://colombiabirdfair.com,https://www.colombiabirdfair.com'))));

return [
    /*
     * Sites allowed to post the newsletter form (CORS) and to receive the visitor back after signing up.
     */
    'form_origins' => array_values($formOrigins),

    'form_hosts' => array_values(array_filter(array_map(fn ($origin) => parse_url($origin, PHP_URL_HOST), $formOrigins))),

    /*
     * Check-in QR emails sent per hour in a bulk send. Most mail providers cap a mailbox at around 100 per hour.
     */
    'qr_emails_per_hour' => (int) env('CRM_QR_EMAILS_PER_HOUR', 90),

    /*
     * Attendance certificate emails per hour when a certificate is sent. They go out from the transactional mailbox,
     * like the QR emails, so keep both under the mailbox's hourly limit if they are sent at the same time.
     */
    'certificate_emails_per_hour' => (int) env('CRM_CERTIFICATE_EMAILS_PER_HOUR', 90),

    /*
     * Satisfaction survey emails per hour, sent when each activity ends. Same transactional mailbox as the QR and
     * certificate emails.
     */
    'survey_emails_per_hour' => (int) env('CRM_SURVEY_EMAILS_PER_HOUR', 90),

    /*
     * Campaign emails per hour when Correo de envíos › Difusión has no hourly limit set.
     */
    'campaign_emails_per_hour' => (int) env('CRM_CAMPAIGN_EMAILS_PER_HOUR', 90),

    /*
     * Thursday each past fair started, for the reports of attendees imported from its registration master (their
     * outings and workshops are listed by weekday). A year not listed uses the first Thursday after its last registration.
     */
    'fair_start_dates' => [
        2024 => '2024-02-15',
        2025 => '2025-02-13',
        2026 => '2026-02-12',
    ],

    /*
     * Participant portal ("Inscritos"): read-only lookup by document number behind a human check.
     * With Cloudflare Turnstile keys the check is Turnstile; without them, a simple sum asked by the CRM.
     */
    'portal_help_email' => env('CRM_PORTAL_HELP_EMAIL'),

    'turnstile' => [
        'site_key' => env('CRM_TURNSTILE_SITE_KEY'),
        'secret' => env('CRM_TURNSTILE_SECRET'),
    ],

    /*
     * Participant's QR as a wallet pass, offered in the QR email. Each wallet stays hidden until its keys are set.
     *
     * Apple Wallet: a Pass Type ID certificate (.p12) from developer.apple.com and Apple's WWDR G4 intermediate (.pem).
     * Google Wallet: an issuer ID from pay.google.com/business/console and a service account key (.json) with access to it.
     * Keep the key files outside public/, e.g. storage/app/private/wallet/.
     */
    'wallet' => [
        'event_name' => env('CRM_WALLET_EVENT_NAME', 'Colombia Birdfair'),
        'organization' => env('CRM_WALLET_ORGANIZATION', 'Colombia Birdfair'),
        'background' => env('CRM_WALLET_BACKGROUND', '#2F5D2A'),

        'apple' => [
            'pass_type_id' => env('APPLE_WALLET_PASS_TYPE_ID'),
            'team_id' => env('APPLE_WALLET_TEAM_ID'),
            'certificate' => env('APPLE_WALLET_CERTIFICATE'),
            'certificate_password' => env('APPLE_WALLET_CERTIFICATE_PASSWORD', ''),
            'wwdr' => env('APPLE_WALLET_WWDR_CERTIFICATE'),
        ],

        'google' => [
            'issuer_id' => env('GOOGLE_WALLET_ISSUER_ID'),
            'service_account' => env('GOOGLE_WALLET_SERVICE_ACCOUNT'),
            'class_suffix' => env('GOOGLE_WALLET_CLASS', 'colombia-birdfair'),
        ],
    ],

    /*
     * Stand comercial: a tab of a Google Sheet kept in sync both ways. The service account key can be uploaded in
     * Stand comercial; this path is used when none was uploaded (the Google Wallet key works if Sheets API is on).
     */
    'stands' => [
        'google_service_account' => env('GOOGLE_SHEETS_SERVICE_ACCOUNT', env('GOOGLE_WALLET_SERVICE_ACCOUNT')),
    ],
];
