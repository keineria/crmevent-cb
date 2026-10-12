<?php

return [
    'paths' => ['api/*'],

    'allowed_methods' => ['POST', 'OPTIONS'],

    // Same list as config/crm.php: the sites that embed the newsletter form.
    'allowed_origins' => array_values(array_filter(array_map('trim', explode(',', (string) env('CRM_FORM_ORIGINS', 'https://colombiabirdfair.com,https://www.colombiabirdfair.com'))))),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['Content-Type', 'Accept', 'X-Requested-With'],

    'exposed_headers' => [],

    'max_age' => 3600,

    'supports_credentials' => false,
];
