<?php
$u = Auth::require('employee');
$co = Auth::company();
$cid = (int) $co['id'];
$isMgr = Auth::can('manager');
$empId = $isMgr ? (int) ($_GET['emp'] ?? 0) : (int) $u['employee_id'];
$emps = $isMgr ? Db::all('SELECT id, first_name, last_name FROM employees WHERE company_id = ? ORDER BY first_name, last_name', [$cid]) : [];
$lastPaid = $empId ? Db::one(
    "SELECT pp.start_date, pp.end_date FROM paychecks pc JOIN pay_periods pp ON pp.id = pc.pay_period_id
     WHERE pc.company_id = ? AND pc.employee_id = ? ORDER BY pp.start_date DESC LIMIT 1",
    [$cid, $empId]
) : null;
$from = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['from'] ?? '') ? $_GET['from'] : ($lastPaid['start_date'] ?? '');
$to = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['to'] ?? '') ? $_GET['to'] : ($lastPaid['end_date'] ?? '');
$emp = $empId ? Db::one(
    'SELECT e.*, b.name AS branch, d.name AS dept FROM employees e LEFT JOIN tags b ON b.id = e.default_branch_id LEFT JOIN tags d ON d.id = e.default_department_id
     WHERE e.id = ? AND e.company_id = ?',
    [$empId, $cid]
) : null;
$rows = $emp && $from && $to ? Report::lines($cid, ['emp' => $empId, 'from' => $from, 'to' => $to]) : [];
$t = Report::totals($rows);
$ytd = ['gross' => 0, 'taxes' => 0, 'net' => 0];
$lifetime = 0.0;
if ($emp && $to) {
    $y = Report::totals(Report::lines($cid, ['emp' => $empId, 'from' => substr($to, 0, 4) . '-01-01', 'to' => $to]));
    $ytd = ['gross' => $y['gross'], 'taxes' => $y['taxes'], 'net' => $y['net']];
    $lifetime = Report::totals(Report::lines($cid, ['emp' => $empId, 'to' => $to]))['gross']
        + (float) Db::value('SELECT COALESCE(prior_gross_before_app,0) FROM employee_tax_profiles WHERE employee_id = ?', [$empId]);
}
$title = 'Pay stub';
require __DIR__ . '/_top.php';
?>
<form class="row g-2 align-items-end mb-3 d-print-none" method="get">
  <input type="hidden" name="p" value="stub">
  <?php if ($isMgr): ?><div class="col-12 col-md-4"><label class="form-label small">Employee</label><select name="emp" class="form-select" onchange="this.form.from.value='';this.form.to.value='';this.form.submit()"><option value="">Choose…</option>
    <?php foreach ($emps as $e): ?><option value="<?= (int) $e['id'] ?>"<?= $empId === (int) $e['id'] ? ' selected' : '' ?>><?= e(employee_name($e)) ?></option><?php endforeach; ?></select></div><?php endif; ?>
  <div class="col-6 col-md-3"><label class="form-label small">Weeks from</label><input type="date" name="from" class="form-control" value="<?= e($from) ?>"></div>
  <div class="col-6 col-md-3"><label class="form-label small">to</label><input type="date" name="to" class="form-control" value="<?= e($to) ?>"></div>
  <div class="col-12 col-md-2 d-flex gap-2"><button class="btn btn-primary">Show</button><?php if ($rows): ?><button type="button" class="btn btn-outline-secondary" onclick="window.print()">Print</button><?php endif; ?></div>
</form>
<?php if (!$emp): ?>
  <p class="text-secondary"><?= $isMgr ? 'Choose an employee.' : 'Your login is not linked to an employee yet.' ?></p>
<?php elseif (!$rows): ?>
  <p class="text-secondary">No pay in that date range.</p>
<?php else: ?>
<div class="card stub"><div class="card-body p-4">
  <div class="d-flex justify-content-between flex-wrap mb-3">
    <div><h1 class="h4 mb-0"><?= e($co['name']) ?></h1><div class="text-secondary">Pay stub</div></div>
    <div class="text-end"><div><?= e(fmt_date($from)) ?> – <?= e(fmt_date($to)) ?></div><div class="small text-secondary">Generated <?= e(date('M j, Y')) ?></div></div>
  </div>
  <div class="row mb-3 small">
    <div class="col-6 col-md-3"><div class="text-secondary">Employee</div><strong><?= e(employee_name($emp)) ?></strong></div>
    <div class="col-6 col-md-3"><div class="text-secondary">Worker type</div><?= e($emp['worker_type']) ?></div>
    <div class="col-6 col-md-3"><div class="text-secondary">Branch</div><?= e(implode(', ', array_unique(array_filter(array_column($rows, 'branch')))) ?: $emp['branch']) ?></div>
    <div class="col-6 col-md-3"><div class="text-secondary">Department</div><?= e(implode(', ', array_unique(array_filter(array_column($rows, 'dept')))) ?: $emp['dept']) ?></div>
  </div>
  <table class="table table-sm">
    <thead><tr><th>Week</th><th>Pay date</th><th class="text-end">Hours</th><th class="text-end">Rate</th><th class="text-end">Hourly</th><th class="text-end">Salary</th><th class="text-end">Comm.</th><th class="text-end">Gross</th></tr></thead>
    <tbody><?php foreach ($rows as $r): ?>
      <tr><td><?= e(fmt_date($r['start_date'], 'M j')) ?>–<?= e(fmt_date($r['end_date'], 'M j')) ?></td><td><?= e(fmt_date($r['pay_date'], 'M j')) ?></td>
        <td class="text-end"><?= number_format((float) $r['hours'], 2) ?><?= (float) $r['ot_hours'] > 0 ? ' (' . number_format((float) $r['ot_hours'], 2) . ' OT)' : '' ?></td>
        <td class="text-end"><?= money($r['rate']) ?></td><td class="text-end"><?= money((float) $r['hourly_pay'] + (float) $r['ot_pay']) ?></td>
        <td class="text-end"><?= money($r['salary']) ?></td><td class="text-end"><?= money($r['commissions']) ?></td><td class="text-end"><?= money($r['gross']) ?></td></tr>
    <?php endforeach; ?></tbody>
  </table>
  <div class="row g-3">
    <div class="col-md-6"><table class="table table-sm mb-0">
      <tr><th colspan="2">Withholding</th></tr>
      <tr><td>Federal income tax</td><td class="text-end"><?= money($t['federal_tax']) ?></td></tr>
      <tr><td>Social Security</td><td class="text-end"><?= money($t['ss_tax']) ?></td></tr>
      <tr><td>Medicare</td><td class="text-end"><?= money($t['medicare_tax'] + $t['addl_medicare_tax']) ?></td></tr>
      <tr><td>State income tax</td><td class="text-end"><?= money($t['state_tax']) ?></td></tr>
      <?php $other = $t['taxes'] - $t['federal_tax'] - $t['ss_tax'] - $t['medicare_tax'] - $t['addl_medicare_tax'] - $t['state_tax'];
      if (abs($other) > 0.004): ?><tr><td>Taxes (entered by hand)</td><td class="text-end"><?= money($other) ?></td></tr><?php endif; ?>
      <tr class="fw-semibold"><td>Total taxes</td><td class="text-end"><?= money($t['taxes']) ?></td></tr>
    </table></div>
    <div class="col-md-6"><table class="table table-sm mb-0">
      <tr><th colspan="2">Summary</th></tr>
      <tr><td>Hours</td><td class="text-end"><?= number_format($t['hours'], 2) ?></td></tr>
      <tr><td>Gross pay</td><td class="text-end"><?= money($t['gross']) ?></td></tr>
      <tr><td>Taxes</td><td class="text-end">−<?= money($t['taxes']) ?></td></tr>
      <tr><td>Deductions</td><td class="text-end">−<?= money($t['deductions']) ?></td></tr>
      <tr class="fw-semibold"><td>Net pay</td><td class="text-end">$<?= money($t['net']) ?></td></tr>
      <tr><td>Sent</td><td class="text-end"><?= money($t['sent']) ?></td></tr>
      <tr><td>Amount left</td><td class="text-end"><?= money($t['left']) ?></td></tr>
    </table></div>
  </div>
  <div class="row mt-3 small text-secondary">
    <div class="col-4">YTD gross <strong class="text-body">$<?= money($ytd['gross']) ?></strong></div>
    <div class="col-4">YTD taxes <strong class="text-body">$<?= money($ytd['taxes']) ?></strong></div>
    <div class="col-4">Lifetime gross <strong class="text-body">$<?= money($lifetime) ?></strong></div>
  </div>
</div></div>
<?php endif;
require __DIR__ . '/_bottom.php';
