<?php
$u = Auth::require('employee');
$co = Auth::company();
$cid = (int) $co['id'];
if (!Auth::can('manager')) {
    redirect($u['employee_id'] ? 'clock' : 'stub');
}
$title = 'Home';
require __DIR__ . '/_top.php';
$today = (new DateTime('now', new DateTimeZone($co['timezone'])))->format('Y-m-d');
$cur = Payroll::periodFor($co, $today);
$clockedIn = Db::all(
    'SELECT te.clock_in, te.location_missing, e.first_name, e.last_name FROM time_entries te JOIN employees e ON e.id = te.employee_id
     WHERE te.company_id = ? AND te.clock_out IS NULL ORDER BY te.clock_in',
    [$cid]
);
$empCount = (int) Db::value('SELECT COUNT(*) FROM employees WHERE company_id = ? AND active = 1', [$cid]);
$open = Db::all(
    "SELECT pp.*, COUNT(pc.id) n, SUM(pc.status='paid') paid, COALESCE(SUM(pc.net),0) net, COALESCE(SUM(pc.net - pc.sent),0) left_amt
     FROM pay_periods pp LEFT JOIN paychecks pc ON pc.pay_period_id = pp.id
     WHERE pp.company_id = ? GROUP BY pp.id ORDER BY pp.start_date DESC LIMIT 4",
    [$cid]
);
$sub = Db::one('SELECT * FROM subscriptions WHERE company_id = ?', [$cid]);
?>
<div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
  <h1 class="h4 mb-0">This week: <?= e(fmt_date($cur['start'], 'M j')) ?> – <?= e(fmt_date($cur['end'], 'M j')) ?></h1>
  <?php if ($u['employee_id']): ?><a class="btn btn-success" href="<?= e(url('clock')) ?>">Open time clock</a><?php endif; ?>
</div>
<?php if ($sub && $sub['status'] === 'trialing' && $sub['trial_ends_at'] && Auth::can('owner')): ?>
  <div class="alert alert-info py-2">Free trial until <?= e(fmt_date($sub['trial_ends_at'])) ?>. <a href="<?= e(url('billing')) ?>">Set up billing</a></div>
<?php endif; ?>
<?php if ($empCount === 0 && Auth::can('admin')): ?>
  <div class="card mb-3"><div class="card-body">
    <h2 class="h5">Get started</h2>
    <p class="mb-2">Add your people, or bring over your existing payroll spreadsheet.</p>
    <a class="btn btn-primary me-2 mb-2" href="<?= e(url('employee_edit')) ?>">Add an employee</a>
    <a class="btn btn-outline-primary mb-2" href="<?= e(url('import')) ?>">Import spreadsheet</a>
  </div></div>
<?php endif; ?>
<div class="row g-3">
  <div class="col-12 col-lg-5">
    <div class="card h-100"><div class="card-body">
      <h2 class="h6 text-secondary text-uppercase">Clocked in now (<?= count($clockedIn) ?>)</h2>
      <?php if (!$clockedIn): ?><p class="mb-0 text-secondary">Nobody is clocked in.</p><?php endif; ?>
      <ul class="list-group list-group-flush">
        <?php foreach ($clockedIn as $c): ?>
          <li class="list-group-item px-0 d-flex justify-content-between"><span><?= e(employee_name($c)) ?><?= $c['location_missing'] ? ' <span class="badge text-bg-warning">no location</span>' : '' ?></span>
            <span class="text-secondary">since <?= e(local_dt($c['clock_in'], $co['timezone'], 'D g:i a')) ?></span></li>
        <?php endforeach; ?>
      </ul>
    </div></div>
  </div>
  <div class="col-12 col-lg-7">
    <div class="card h-100"><div class="card-body">
      <div class="d-flex justify-content-between align-items-center mb-2">
        <h2 class="h6 text-secondary text-uppercase mb-0">Recent pay periods</h2>
        <?php if (Auth::can('admin')): ?><a href="<?= e(url('periods')) ?>">All periods</a><?php endif; ?>
      </div>
      <?php if (!$open): ?><p class="text-secondary mb-0">No pay periods yet.</p><?php else: ?>
      <div class="table-responsive"><table class="table table-sm align-middle mb-0">
        <thead><tr><th>Week</th><th>Pay date</th><th class="text-end">Paid</th><th class="text-end">Net</th><th class="text-end">Left</th></tr></thead>
        <tbody>
        <?php foreach ($open as $p): ?>
          <tr class="text-nowrap"><td><?= Auth::can('admin') ? '<a href="' . e(url('period', ['id' => $p['id']])) . '">' : '' ?><?= e(fmt_date($p['start_date'], 'M j')) ?>–<?= e(fmt_date($p['end_date'], 'M j')) ?><?= Auth::can('admin') ? '</a>' : '' ?></td>
            <td><?= e(fmt_date($p['pay_date'], 'M j')) ?></td><td class="text-end"><?= (int) $p['paid'] ?>/<?= (int) $p['n'] ?></td>
            <td class="text-end">$<?= money($p['net']) ?></td><td class="text-end">$<?= money($p['left_amt']) ?></td></tr>
        <?php endforeach; ?>
        </tbody></table></div>
      <?php endif; ?>
    </div></div>
  </div>
</div>
<?php require __DIR__ . '/_bottom.php';
