<?php
// Copy to config.php (never commit config.php) and fill in real values.
return [
    'app_name' => 'Basic Payroll',
    'base_url' => 'https://your-app.cloudwaysapps.com',   // no trailing slash
    'db' => [
        'host' => '127.0.0.1',
        'port' => 3306,
        'name' => 'payroll',
        'user' => 'payroll',
        'pass' => 'change-me',
    ],
    'trial_days' => 14,
    // Square subscription billing (see README "Billing").
    'square' => [
        'environment' => 'sandbox',          // 'sandbox' or 'production'
        'access_token' => '',
        'location_id' => '',
        'plan_variation_id' => '',           // subscription plan variation id from Square catalog
        'webhook_signature_key' => '',
        'webhook_url' => '',                 // must exactly match the URL registered in Square
    ],
];
