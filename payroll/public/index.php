<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

$page = preg_replace('/[^a-z_]/', '', (string) ($_GET['p'] ?? 'home'));
$public = ['login', 'signup', 'logout', 'offline'];
$pages = ['home', 'clock', 'timesheets', 'employees', 'employee_edit', 'tags', 'users', 'periods', 'period',
    'stub', 'reports', 'settings', 'import', 'account', 'billing'];

if (!in_array($page, $public, true) && !in_array($page, $pages, true)) {
    http_response_code(404);
    $page = 'home';
}
if (!in_array($page, $public, true)) {
    Auth::require('employee');
}
if (is_post()) {
    csrf_check();
}

header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');

require APP_ROOT . "/views/$page.php";
