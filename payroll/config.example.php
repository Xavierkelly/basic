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
        'plan_name' => 'Basic Payroll monthly',
        'plan_price_cents' => 0,             // must match the plan variation's price, e.g. 2900 for $29.00
        'webhook_signature_key' => '',
        'webhook_url' => '',                 // must exactly match the URL registered in Square
    ],
];
