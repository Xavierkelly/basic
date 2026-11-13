<?php
/** @var string $title */
$u = Auth::user();
$co = Auth::company();
$current = $_GET['p'] ?? 'home';
$nav = [];
if ($u) {
    $nav[] = ['home', 'Home', 'employee'];
    if ($u['employee_id']) {
        $nav[] = ['clock', 'Clock', 'employee'];
    }
    $nav[] = ['timesheets', 'Time', 'employee'];
    $nav[] = ['periods', 'Payroll', 'admin'];
    $nav[] = ['employees', 'Employees', 'manager'];
    $nav[] = ['stub', 'Pay stubs', 'employee'];
    $nav[] = ['reports', 'Reports', 'manager'];
}
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#0d6efd">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="default">
<meta name="apple-mobile-web-app-title" content="<?= e(cfg('app_name', 'Payroll')) ?>">
<meta name="csrf-token" content="<?= e(csrf_token()) ?>">
<title><?= e(($title ?? 'Payroll') . ' · ' . cfg('app_name', 'Payroll')) ?></title>
<link rel="manifest" href="manifest.webmanifest">
<link rel="icon" href="icons/icon-192.png">
<link rel="apple-touch-icon" href="icons/apple-touch-icon.png">
<link href="assets/vendor/bootstrap/bootstrap.min.css?v=5.3.3" rel="stylesheet">
<link href="assets/app.css?v=1" rel="stylesheet">
</head>
<body class="bg-body-tertiary">
<?php if ($u): ?>
<nav class="navbar navbar-expand-lg bg-white border-bottom sticky-top">
  <div class="container-fluid">
    <a class="navbar-brand fw-semibold" href="<?= e(url('home')) ?>"><?= e($co['name'] ?? cfg('app_name')) ?></a>
    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#mainNav" aria-controls="mainNav" aria-expanded="false" aria-label="Menu">
      <span class="navbar-toggler-icon"></span>
    </button>
    <div class="collapse navbar-collapse" id="mainNav">
      <ul class="navbar-nav me-auto">
        <?php foreach ($nav as [$p, $label, $role]): if (!Auth::can($role)) continue; ?>
          <li class="nav-item"><a class="nav-link<?= $current === $p ? ' active fw-semibold' : '' ?>" href="<?= e(url($p)) ?>"><?= e($label) ?></a></li>
        <?php endforeach; ?>
      </ul>
      <ul class="navbar-nav">
        <li class="nav-item dropdown">
          <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false"><?= e($u['name']) ?></a>
          <ul class="dropdown-menu dropdown-menu-end">
            <?php if (Auth::can('admin')): ?>
              <li><a class="dropdown-item" href="<?= e(url('settings')) ?>">Company settings</a></li>
              <li><a class="dropdown-item" href="<?= e(url('users')) ?>">Users &amp; roles</a></li>
              <li><a class="dropdown-item" href="<?= e(url('tags')) ?>">Branches &amp; departments</a></li>
              <li><a class="dropdown-item" href="<?= e(url('import')) ?>">Import spreadsheet</a></li>
              <?php if (Auth::can('owner')): ?><li><a class="dropdown-item" href="<?= e(url('billing')) ?>">Billing</a></li><?php endif; ?>
              <li><hr class="dropdown-divider"></li>
            <?php endif; ?>
            <li><a class="dropdown-item" href="<?= e(url('account')) ?>">My account</a></li>
            <li><form method="post" action="<?= e(url('logout')) ?>"><?= csrf_field() ?><button class="dropdown-item">Sign out</button></form></li>
          </ul>
        </li>
      </ul>
    </div>
  </div>
</nav>
<?php endif; ?>
<main class="container-xxl py-3 px-3">
<?php if ($u && ($sub = Billing::subscription((int) $u['company_id'])) && !Billing::inGoodStanding($sub) && ($_GET['p'] ?? '') !== 'billing'): ?>
  <div class="alert alert-warning py-2">Your subscription has ended, so the account is read-only. Clocking in still works.
    <?php if (Auth::can('owner')): ?><a href="<?= e(url('billing')) ?>">Restart it</a><?php endif; ?></div>
<?php endif; ?>
<?php if ($f = flash()): ?>
  <div class="alert alert-<?= e($f['type']) ?> alert-dismissible fade show" role="alert"><?= e($f['msg']) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button></div>
<?php endif; ?>
