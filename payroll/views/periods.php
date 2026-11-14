<?php
Auth::require('admin');
$co = Auth::company();
$cid = (int) $co['id'];
if (is_post() && post('do') === 'create') {
    $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) post('date')) ? post('date') : (new DateTime('now', new DateTimeZone($co['timezone'])))->format('Y-m-d');
    $pid = Payroll::createPeriod($co, $date);
    audit('create', 'pay_period', $pid);
    redirect('period', ['id' => $pid]);
}
$periods = Db::all(
    "SELECT pp.*, COUNT(pc.id) n, SUM(pc.status='paid') paid, COALESCE(SUM(pc.gross),0) gross, COALESCE(SUM(pc.net),0) net,
            COALESCE(SUM(pc.net-pc.sent),0) left_amt, COALESCE(SUM(pc.hours),0) hours
     FROM pay_periods pp LEFT JOIN paychecks pc ON pc.pay_period_id = pp.id
     WHERE pp.company_id = ? GROUP BY pp.id ORDER BY pp.start_date DESC",
    [$cid]
);
$today = (new DateTime('now', new DateTimeZone($co['timezone'])))->format('Y-m-d');
$last = Payroll::periodFor($co, (new DateTime($today))->modify('-7 days')->format('Y-m-d'));
$title = 'Payroll';
require __DIR__ . '/_top.php';
?>
<div class="d-flex flex-wrap align-items-end gap-2 mb-3">
  <h1 class="h4 mb-0 me-auto">Pay periods</h1>
  <form method="post" class="d-flex gap-2 align-items-end">
    <?= csrf_field() ?><input type="hidden" name="do" value="create">
    <div><label class="form-label small mb-0" for="d">Week containing</label><input type="date" id="d" name="date" class="form-control form-control-sm" value="<?= e($last['start']) ?>"></div>
    <button class="btn btn-sm btn-primary">Start pay period</button>
  </form>
</div>
<?php if (!$periods): ?><p class="text-secondary">No pay periods yet. Start one above, or import your spreadsheet.</p><?php endif; ?>
<div class="card"><div class="table-responsive"><table class="table table-hover align-middle mb-0">
  <thead><tr><th>Week</th><th>Pay date</th><th class="text-end">Paid</th><th class="text-end">Hours</th><th class="text-end">Gross</th><th class="text-end">Net</th><th class="text-end">Left to send</th></tr></thead>
  <tbody>
  <?php foreach ($periods as $p): ?>
    <tr><td><a href="<?= e(url('period', ['id' => $p['id']])) ?>"><?= e(fmt_date($p['start_date'], 'M j')) ?> – <?= e(fmt_date($p['end_date'], 'M j, Y')) ?></a><?= $p['imported'] ? ' <span class="badge text-bg-light">imported</span>' : '' ?></td>
      <td><?= e(fmt_date($p['pay_date'], 'D M j')) ?></td>
      <td class="text-end"><?= (int) $p['paid'] ?>/<?= (int) $p['n'] ?></td>
      <td class="text-end"><?= number_format((float) $p['hours'], 2) ?></td>
      <td class="text-end">$<?= money($p['gross']) ?></td><td class="text-end">$<?= money($p['net']) ?></td>
      <td class="text-end<?= $p['left_amt'] > 0.004 ? ' fw-semibold text-danger' : '' ?>">$<?= money($p['left_amt']) ?></td></tr>
  <?php endforeach; ?>
  </tbody>
</table></div></div>
<?php require __DIR__ . '/_bottom.php';
