<?php
$u = Auth::require('employee');
$co = Auth::company();
$emp = Auth::employee();
$title = 'Clock';
require __DIR__ . '/_top.php';
if (!$emp): ?>
  <div class="alert alert-info">Your login is not linked to an employee yet, so there is nothing to clock. An admin can link it under Users &amp; roles.</div>
<?php else:
    $open = TimeClock::openEntry((int) $co['id'], (int) $emp['id']);
    $mode = Payroll::effectiveMode($co, $emp);
    $period = Payroll::periodFor($co, (new DateTime('now', new DateTimeZone($co['timezone'])))->format('Y-m-d'));
    $weekHours = Payroll::clockHours($co, (int) $emp['id'], $period['start'], $period['end']);
?>
<div class="text-center py-3 clock-wrap">
  <h1 class="h4 mb-1">Hi, <?= e($emp['first_name']) ?></h1>
  <p class="text-secondary mb-4" id="clockStatus"
     data-open="<?= $open ? '1' : '0' ?>"
     data-since="<?= $open ? e((new DateTime($open['clock_in'], new DateTimeZone('UTC')))->format(DATE_ATOM)) : '' ?>">
    <?= $open ? 'Clocked in since ' . e(local_dt($open['clock_in'], $co['timezone'], 'g:i a')) : 'You are clocked out' ?>
  </p>
  <?php if ($mode === 'weekly_total'): ?>
    <div class="alert alert-secondary mx-auto" style="max-width:420px">Your hours are entered by your manager each week, so you don't need to clock in.</div>
  <?php else: ?>
    <div class="display-5 fw-semibold mb-4 font-monospace" id="elapsed"><?= $open ? '' : '0:00:00' ?></div>
    <button id="punchBtn" class="btn btn-lg rounded-circle punch-btn <?= $open ? 'btn-danger' : 'btn-success' ?>">
      <?= $open ? 'Clock out' : 'Clock in' ?>
    </button>
    <p class="small text-secondary mt-3 mb-0" id="locNote">Your location is saved with each clock in and out.</p>
  <?php endif; ?>
  <p class="mt-4 mb-0">This week (<?= e(fmt_date($period['start'], 'M j')) ?>–<?= e(fmt_date($period['end'], 'M j')) ?>): <strong><?= number_format($weekHours, 2) ?> hours</strong></p>
</div>
<?php endif;
require __DIR__ . '/_bottom.php';
