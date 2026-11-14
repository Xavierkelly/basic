<?php
Auth::require('manager');
$co = Auth::company();
$cid = (int) $co['id'];
$f = [
    'from' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['from'] ?? '') ? $_GET['from'] : date('Y-01-01'),
    'to' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['to'] ?? '') ? $_GET['to'] : '',
    'branch' => (int) ($_GET['branch'] ?? 0), 'dept' => (int) ($_GET['dept'] ?? 0), 'emp' => (int) ($_GET['emp'] ?? 0),
    'status' => (string) ($_GET['status'] ?? ''), 'type' => (string) ($_GET['type'] ?? ''),
];
$rows = Report::lines($cid, $f);
if (!empty($_GET['csv'])) {
    Report::csv($rows, 'payroll-report-' . ($f['from'] ?: 'all') . '.csv');
}
$t = Report::totals($rows);
$branches = Tags::list($cid, 'branch');
$depts = Tags::list($cid, 'department');
$emps = Db::all('SELECT id, first_name, last_name FROM employees WHERE company_id = ? ORDER BY first_name, last_name', [$cid]);
$title = 'Reports';
require __DIR__ . '/_top.php';
?>
<h1 class="h4 mb-3">Payroll report</h1>
<form class="card mb-3" method="get"><div class="card-body row g-2 align-items-end">
  <input type="hidden" name="p" value="reports">
  <div class="col-6 col-md-2"><label class="form-label small">Weeks from</label><input type="date" name="from" class="form-control form-control-sm" value="<?= e($f['from']) ?>"></div>
  <div class="col-6 col-md-2"><label class="form-label small">to</label><input type="date" name="to" class="form-control form-control-sm" value="<?= e($f['to']) ?>"></div>
  <div class="col-6 col-md-2"><label class="form-label small">Branch</label><select name="branch" class="form-select form-select-sm"><?= Tags::options($branches, $f['branch'], 'All') ?></select></div>
  <div class="col-6 col-md-2"><label class="form-label small">Department</label><select name="dept" class="form-select form-select-sm"><?= Tags::options($depts, $f['dept'], 'All') ?></select></div>
  <div class="col-6 col-md-2"><label class="form-label small">Employee</label><select name="emp" class="form-select form-select-sm"><option value="">All</option>
    <?php foreach ($emps as $e): ?><option value="<?= (int) $e['id'] ?>"<?= $f['emp'] === (int) $e['id'] ? ' selected' : '' ?>><?= e(employee_name($e)) ?></option><?php endforeach; ?></select></div>
  <div class="col-3 col-md-1"><label class="form-label small">Status</label><select name="status" class="form-select form-select-sm"><option value="">All</option>
    <?php foreach (['paid' => 'Paid', 'pending' => 'Pending', 'did_not_work' => 'Did not work'] as $k => $v): ?><option value="<?= $k ?>"<?= $f['status'] === $k ? ' selected' : '' ?>><?= $v ?></option><?php endforeach; ?></select></div>
  <div class="col-3 col-md-1"><label class="form-label small">Type</label><select name="type" class="form-select form-select-sm"><option value="">All</option>
    <?php foreach (['W2', '1099'] as $k): ?><option<?= $f['type'] === $k ? ' selected' : '' ?>><?= $k ?></option><?php endforeach; ?></select></div>
  <div class="col-12 d-flex gap-2"><button class="btn btn-sm btn-primary">Apply</button>
    <a class="btn btn-sm btn-outline-secondary" href="<?= e('index.php?' . http_build_query(array_filter(['p' => 'reports'] + $f) + ['csv' => 1])) ?>">Download CSV</a></div>
</div></form>
<div class="row row-cols-2 row-cols-md-4 row-cols-xl-8 g-2 mb-3 text-center">
  <?php foreach (['records' => 'Records', 'employees' => 'Employees', 'hours' => 'Hours', 'gross' => 'Gross', 'taxes' => 'Taxes', 'deductions' => 'Deductions', 'net' => 'Net', 'left' => 'Left to send'] as $k => $label): ?>
    <div class="col"><div class="card"><div class="card-body p-2"><div class="small text-secondary"><?= $label ?></div>
      <div class="fw-semibold"><?= in_array($k, ['records', 'employees'], true) ? (int) $t[$k] : ($k === 'hours' ? number_format($t[$k], 2) : '$' . money($t[$k])) ?></div></div></div></div>
  <?php endforeach; ?>
</div>
<p class="small text-secondary">Taxes: federal $<?= money($t['federal_tax']) ?> · Social Security $<?= money($t['ss_tax']) ?> · Medicare $<?= money($t['medicare_tax'] + $t['addl_medicare_tax']) ?> · state $<?= money($t['state_tax']) ?></p>
<div class="card"><div class="table-responsive"><table class="table table-sm table-striped align-middle mb-0">
  <thead><tr><th>Week</th><th>Status</th><th>Employee</th><th>Branch</th><th>Dept</th><th>Type</th><th class="text-end">Hours</th><th class="text-end">Gross</th><th class="text-end">Taxes</th><th class="text-end">Deduct.</th><th class="text-end">Net</th><th class="text-end">Left</th></tr></thead>
  <tbody>
  <?php foreach (array_slice($rows, 0, 1000) as $r): ?>
    <tr><td class="text-nowrap"><?= e(fmt_date($r['start_date'], 'M j')) ?>–<?= e(fmt_date($r['end_date'], 'M j')) ?></td>
      <td><?= e(str_replace('_', ' ', $r['status'])) ?></td><td class="text-nowrap"><?= e(employee_name($r)) ?></td><td><?= e($r['branch']) ?></td><td><?= e($r['dept']) ?></td><td><?= e($r['worker_type']) ?></td>
      <td class="text-end"><?= number_format((float) $r['hours'], 2) ?></td><td class="text-end"><?= money($r['gross']) ?></td><td class="text-end"><?= money($r['taxes']) ?></td>
      <td class="text-end"><?= money($r['deductions']) ?></td><td class="text-end"><?= money($r['net']) ?></td><td class="text-end"><?= money((float) $r['net'] - (float) $r['sent']) ?></td></tr>
  <?php endforeach; ?>
  </tbody>
</table></div></div>
<?php if (count($rows) > 1000): ?><p class="small text-secondary mt-2">Showing the first 1,000 lines. The CSV has all <?= count($rows) ?>.</p><?php endif; ?>
<?php require __DIR__ . '/_bottom.php';
