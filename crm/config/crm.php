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
];
