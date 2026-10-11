<?php

$formOrigins = array_filter(array_map('trim', explode(',', (string) env('CRM_FORM_ORIGINS', 'https://colombiabirdfair.com,https://www.colombiabirdfair.com'))));

return [
    /*
     * Sites allowed to post the newsletter form (CORS) and to receive the visitor back after signing up.
     */
    'form_origins' => array_values($formOrigins),

    'form_hosts' => array_values(array_filter(array_map(fn ($origin) => parse_url($origin, PHP_URL_HOST), $formOrigins))),
];
