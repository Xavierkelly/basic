<?php
// Pre-flight check for a server deploy: php bin/check.php
// Prints OK / WARN / FAIL for each item and exits 1 if anything failed.
// It never prints passwords, tokens or keys.
declare(strict_types=1);

$fails = 0;
$warns = 0;
function report(string $level, string $msg): void
{
    global $fails, $warns;
    if ($level === 'FAIL') {
        $fails++;
    } elseif ($level === 'WARN') {
        $warns++;
    }
    printf("%-4s  %s\n", $level, $msg);
}

$root = dirname(__DIR__);

// 1. PHP version and extensions
version_compare(PHP_VERSION, '8.1.0', '>=')
    ? report('OK', 'PHP ' . PHP_VERSION)
    : report('FAIL', 'PHP ' . PHP_VERSION . ' is too old; choose PHP 8.1 or newer in Cloudways Server → Settings & Packages');

$need = [
    'pdo_mysql' => 'database',
    'mbstring' => 'text handling',
    'zip' => 'spreadsheet import (.xlsx)',
    'curl' => 'Square billing',
    'json' => 'Ajax endpoints',
];
foreach ($need as $ext => $why) {
    extension_loaded($ext)
        ? report('OK', "PHP extension $ext")
        : report('FAIL', "PHP extension $ext is missing (needed for $why)");
}

// 2. Config file
$configFile = $root . '/config.php';
if (!is_file($configFile)) {
    report('FAIL', 'config.php not found. Run: cp config.example.php config.php, then edit it');
    printf("\n%d failed, %d warnings\n", $fails, $warns);
    exit(1);
}
$config = require $configFile;
report('OK', 'config.php found');

$base = (string) ($config['base_url'] ?? '');
if ($base === '' || str_contains($base, 'your-app.cloudwaysapps.com')) {
    report('FAIL', 'base_url is still the example value; set it to your app URL');
} elseif (!str_starts_with($base, 'https://')) {
    report('WARN', "base_url ($base) is not https://. Turn on SSL; phones need HTTPS for location and Add to Home Screen");
} elseif (str_ends_with($base, '/')) {
    report('WARN', 'base_url should not end with a slash');
} else {
    report('OK', "base_url $base");
}

// 3. Database
$db = $config['db'] ?? [];
if (($db['pass'] ?? '') === 'change-me') {
    report('FAIL', 'db.pass is still the example value; copy the password from Cloudways Application → Access Details');
}
$pdo = null;
try {
    $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $db['host'] ?? '', $db['port'] ?? 3306, $db['name'] ?? '');
    $pdo = new PDO($dsn, $db['user'] ?? '', $db['pass'] ?? '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $ver = (string) $pdo->query('SELECT VERSION()')->fetchColumn();
    report('OK', "connected to database '" . ($db['name'] ?? '') . "' ($ver)");
} catch (Throwable $e) {
    // PDO messages can include the user name but never the password.
    report('FAIL', 'cannot connect to the database: ' . $e->getMessage());
}

if ($pdo) {
    $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    $expected = ['companies', 'employees', 'users', 'subscriptions', 'tags', 'employee_tax_profiles',
        'time_entries', 'pay_periods', 'paychecks', 'square_events', 'audit_log', 'login_attempts',
        'tax_brackets', 'tax_constants'];
    $missing = array_diff($expected, $tables);
    $missing
        ? report('FAIL', 'tables missing: ' . implode(', ', $missing) . '. Run: php bin/install.php')
        : report('OK', 'all ' . count($expected) . ' tables present');

    if (!in_array('tax_brackets', $missing, true)) {
        $year = (int) gmdate('Y');
        $n = (int) $pdo->query('SELECT COUNT(*) FROM tax_brackets WHERE tax_year = ' . $year)->fetchColumn();
        $n > 0
            ? report('OK', "$year withholding tables loaded")
            : report('WARN', "no $year withholding tables; add sql/tax_$year.sql (see README, Yearly upkeep)");
    }
    if (!in_array('companies', $missing, true)) {
        $c = (int) $pdo->query('SELECT COUNT(*) FROM companies')->fetchColumn();
        report('OK', "$c compan" . ($c === 1 ? 'y' : 'ies') . ' signed up');
    }
}

// 4. Square (optional until billing is turned on)
$sq = $config['square'] ?? [];
$keys = ['access_token', 'location_id', 'plan_variation_id', 'webhook_signature_key', 'webhook_url'];
$blank = array_filter($keys, fn ($k) => trim((string) ($sq[$k] ?? '')) === '');
if (count($blank) === count($keys)) {
    report('WARN', 'Square is not set up yet (billing off). Fill the square section of config.php when ready');
} elseif ($blank) {
    report('FAIL', 'Square is half set up; still blank: ' . implode(', ', $blank));
} else {
    $env = $sq['environment'] ?? '';
    in_array($env, ['sandbox', 'production'], true)
        ? report('OK', "Square environment: $env")
        : report('FAIL', "square.environment must be 'sandbox' or 'production'");
    ((int) ($sq['plan_price_cents'] ?? 0)) > 0
        ? report('OK', 'Square plan price set')
        : report('FAIL', 'square.plan_price_cents must match the plan price, e.g. 2900 for $29.00');
    $expectHook = rtrim($base, '/') . '/square-webhook.php';
    $sq['webhook_url'] === $expectHook
        ? report('OK', 'Square webhook_url matches base_url')
        : report('WARN', "square.webhook_url is not $expectHook; it must exactly match the URL registered in Square");
}

// 5. Upload limits for the spreadsheet import (the Kelly Rentals workbook is about 7 MB)
$bytes = function (string $v): int {
    $n = (int) $v;
    return match (strtolower(substr(trim($v), -1))) {
        'g' => $n * 1024 ** 3, 'm' => $n * 1024 ** 2, 'k' => $n * 1024, default => $n,
    };
};
$up = min($bytes((string) ini_get('upload_max_filesize')), $bytes((string) ini_get('post_max_size')));
$up >= 16 * 1024 ** 2
    ? report('OK', 'upload limit ' . round($up / 1024 ** 2) . ' MB')
    : report('WARN', 'upload limit is ' . round($up / 1024 ** 2) . ' MB (CLI value; the web limit can differ). Set upload_max_filesize and post_max_size to 32M in Application Settings → PHP FPM, or import over SSH with bin/import.php');

// 6. config.php must not be reachable from the web
is_file($root . '/public/config.php')
    ? report('FAIL', 'public/config.php exists; config must live in payroll/, not public/')
    : report('OK', 'config.php is outside the web root');

printf("\n%d failed, %d warnings\n", $fails, $warns);
exit($fails > 0 ? 1 : 0);
