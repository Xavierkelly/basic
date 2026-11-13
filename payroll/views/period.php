<?php
Auth::require('admin');
$co = Auth::company();
$cid = (int) $co['id'];
$pid = (int) ($_GET['id'] ?? 0);
$period = Db::one('SELECT * FROM pay_periods WHERE id = ? AND company_id = ?', [$pid, $cid]);
if (!$period) {
    http_response_code(404);
    exit('Pay period not found.');
}
if (is_post()) {
    $do = post('do');
    if ($do === 'refresh') {
        Payroll::refreshClockHours($co, $pid);
        flash('Clocked hours pulled in and pay recalculated.');
    } elseif ($do === 'recalc') {
        Payroll::recalc($co, $pid);
        flash('Recalculated.');
    } elseif ($do === 'add') {
        $emp = Db::one('SELECT * FROM employees WHERE id = ? AND company_id = ?', [(int) post('employee_id'), $cid]);
        if ($emp) {
            Payroll::addLine($co, $pid, $emp);
            Payroll::recalc($co, $pid);
            flash('Added ' . employee_name($emp) . '.');
        }
    } elseif ($do === 'delete_period') {
        if ((int) Db::value("SELECT COUNT(*) FROM paychecks WHERE pay_period_id = ? AND locked = 1", [$pid]) === 0) {
            Db::exec('DELETE FROM pay_periods WHERE id = ? AND company_id = ?', [$pid, $cid]);
            audit('delete', 'pay_period', $pid);
            flash('Pay period removed.');
            redirect('periods');
        }
        flash('This period has paid checks, so it cannot be removed.', 'danger');
    }
    redirect('period', ['id' => $pid]);
}
$rows = Db::all(
    'SELECT pc.*, e.first_name, e.last_name, e.payout_handle FROM paychecks pc JOIN employees e ON e.id = pc.employee_id
     WHERE pc.pay_period_id = ? ORDER BY e.first_name, e.last_name, pc.id',
    [$pid]
);
$t = ['hours' => 0, 'gross' => 0, 'taxes' => 0, 'deductions' => 0, 'net' => 0, 'sent' => 0];
foreach ($rows as $r) {
    foreach ($t as $k => $_) {
        $t[$k] += (float) $r[$k];
    }
}
$branches = Tags::list($cid, 'branch');
$depts = Tags::list($cid, 'department');
$allEmps = Db::all('SELECT id, first_name, last_name FROM employees WHERE company_id = ? AND active = 1 ORDER BY first_name, last_name', [$cid]);
$hasPaid = array_filter($rows, fn($r) => $r['locked']);
$errors = array_filter($rows, fn($r) => str_starts_with((string) $r['calc_status'], 'ERROR'));
$title = 'Pay period';
require __DIR__ . '/_top.php';
$numInput = fn($r, $f, $w = '5.5rem') => $r['locked']
    ? '<span>' . e(number_format((float) $r[$f], 2)) . '</span>'
    : '<input class="form-control form-control-sm text-end" style="width:' . $w . '" inputmode="decimal" data-f="' . $f . '" value="' . e($r[$f] + 0) . '" aria-label="' . e($f) . '">';
?>
<div class="d-flex flex-wrap align-items-center gap-2 mb-2">
  <h1 class="h4 mb-0 me-auto"><?= e(fmt_date($period['start_date'], 'M j')) ?> – <?= e(fmt_date($period['end_date'], 'M j, Y')) ?>
    <small class="text-secondary fs-6">· pay date <?= e(fmt_date($period['pay_date'], 'D M j')) ?></small></h1>
  <form method="post" class="d-flex gap-2 flex-wrap"><?= csrf_field() ?>
    <button class="btn btn-sm btn-outline-primary" name="do" value="refresh" title="Pull hours from the time clock for clock-in employees">Pull clocked hours</button>
    <button class="btn btn-sm btn-outline-secondary" name="do" value="recalc">Recalculate</button>
    <?php if (!$hasPaid): ?><button class="btn btn-sm btn-outline-danger" name="do" value="delete_period" data-confirm="Remove this whole pay period?">Remove period</button><?php endif; ?>
  </form>
</div>
<?php if ($errors): ?><div class="alert alert-danger py-2"><?= count($errors) ?> paycheck(s) have a tax problem. Hover the red badge for details, and fix the employee's tax settings.</div><?php endif; ?>
<div class="row row-cols-2 row-cols-md-6 g-2 mb-3 text-center" id="totals">
  <?php foreach (['hours' => 'Hours', 'gross' => 'Gross', 'taxes' => 'Taxes', 'deductions' => 'Deductions', 'net' => 'Net pay', 'left_amt' => 'Left to send'] as $k => $label):
      $v = $k === 'left_amt' ? $t['net'] - $t['sent'] : $t[$k]; ?>
    <div class="col"><div class="card"><div class="card-body p-2"><div class="small text-secondary"><?= $label ?></div>
      <div class="fw-semibold" data-total="<?= $k ?>"><?= $k === 'hours' ? number_format($v, 2) : '$' . money($v) ?></div></div></div></div>
  <?php endforeach; ?>
</div>
<div class="card"><div class="table-responsive payroll-grid"><table class="table table-sm align-middle mb-0">
  <thead class="table-light"><tr>
    <th class="sticky-col">Employee</th><th>Status</th><th>Branch</th><th>Dept</th><th class="text-end">Hours</th><th class="text-end">Rate</th>
    <th class="text-end">Salary</th><th class="text-end">Comm.</th><th class="text-end">Gross</th><th class="text-end">Taxes</th>
    <th class="text-end">Deduct.</th><th class="text-end">Net</th><th class="text-end">Left</th><th>Notes</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($rows as $r):
      $isErr = str_starts_with((string) $r['calc_status'], 'ERROR');
      $tip = sprintf("Federal %s\nSocial Security %s\nMedicare %s\nAddl. Medicare %s\nState %s", money($r['federal_tax']), money($r['ss_tax']), money($r['medicare_tax']), money($r['addl_medicare_tax']), money($r['state_tax'])); ?>
    <tr data-pc="<?= (int) $r['id'] ?>" class="<?= $r['locked'] ? 'table-success' : '' ?>">
      <td class="sticky-col"><div class="fw-semibold text-nowrap"><?= e(employee_name($r)) ?></div>
        <div class="small text-nowrap"><span class="badge text-bg-light"><?= e($r['worker_type']) ?></span>
          <span<?= $r['calc_status'] === '1099' ? ' hidden' : '' ?> class="badge <?= $isErr ? 'text-bg-danger' : (str_contains((string) $r['calc_status'], 'missing') ? 'text-bg-warning' : 'text-bg-light') ?>" title="<?= e($r['calc_status']) ?>" data-calc><?= e($isErr ? 'tax error' : ($r['manual_taxes'] ? 'manual tax' : (string) $r['calc_status'])) ?></span></div></td>
      <td><?php if ($r['locked']): ?><span class="badge text-bg-<?= $r['status'] === 'paid' ? 'success' : 'secondary' ?>"><?= $r['status'] === 'paid' ? 'Paid' : 'Locked' ?></span><?php else: ?>
        <select class="form-select form-select-sm" style="min-width:8.5rem" data-f="status" aria-label="Status"><option value="pending"<?= $r['status'] === 'pending' ? ' selected' : '' ?>>Pending</option><option value="did_not_work"<?= $r['status'] === 'did_not_work' ? ' selected' : '' ?>>Did not work</option></select><?php endif; ?></td>
      <td><?php if ($r['locked']): ?><?= e($branches[(int) $r['branch_id']] ?? '') ?><?php else: ?><select class="form-select form-select-sm" style="min-width:9rem" data-f="branch_id" aria-label="Branch"><?= Tags::options($branches, $r['branch_id']) ?></select><?php endif; ?></td>
      <td><?php if ($r['locked']): ?><?= e($depts[(int) $r['department_id']] ?? '') ?><?php else: ?><select class="form-select form-select-sm" style="min-width:9rem" data-f="department_id" aria-label="Department"><?= Tags::options($depts, $r['department_id']) ?></select><?php endif; ?></td>
      <td class="text-end"><?= $numInput($r, 'hours') ?><?= (float) $r['ot_hours'] > 0 ? '<div class="small text-secondary" data-ot>' . number_format((float) $r['ot_hours'], 2) . ' OT</div>' : '' ?></td>
      <td class="text-end"><?= $numInput($r, 'rate', '5rem') ?></td>
      <td class="text-end"><?= $numInput($r, 'salary') ?></td>
      <td class="text-end"><?= $numInput($r, 'commissions') ?></td>
      <td class="text-end" data-v="gross"><?= money($r['gross']) ?></td>
      <td class="text-end" data-v="taxes" title="<?= e($tip) ?>"><?= money($r['taxes']) ?></td>
      <td class="text-end"><?= $numInput($r, 'deductions') ?></td>
      <td class="text-end fw-semibold" data-v="net"><?= money($r['net']) ?></td>
      <td class="text-end" data-v="left"><?= money((float) $r['net'] - (float) $r['sent']) ?></td>
      <td><?php if ($r['locked']): ?><span class="small"><?= e($r['notes']) ?></span><?php else: ?><input class="form-control form-control-sm" style="min-width:9rem" data-f="notes" value="<?= e($r['notes']) ?>" aria-label="Notes"><?php endif; ?></td>
      <td class="text-nowrap text-end">
        <?php if ($r['locked']): ?>
          <span class="small text-secondary"><?= e($r['payout_method']) ?></span>
          <button class="btn btn-sm btn-link text-danger" data-unpay><?= $r['status'] === 'paid' ? 'Undo paid' : 'Unlock' ?></button>
        <?php else: ?>
          <button class="btn btn-sm btn-success" data-pay data-net="<?= e($r['net']) ?>" data-method="<?= e($r['payout_method']) ?>" data-handle="<?= e($r['payout_handle']) ?>">Mark paid</button>
          <button class="btn btn-sm btn-link text-secondary" data-remove aria-label="Remove line">&times;</button>
        <?php endif; ?>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table></div></div>
<form method="post" class="d-flex gap-2 mt-3" style="max-width:480px">
  <?= csrf_field() ?><input type="hidden" name="do" value="add">
  <select name="employee_id" class="form-select form-select-sm" aria-label="Employee to add">
    <?php foreach ($allEmps as $e): ?><option value="<?= (int) $e['id'] ?>"><?= e(employee_name($e)) ?></option><?php endforeach; ?></select>
  <button class="btn btn-sm btn-outline-primary text-nowrap">Add line</button>
</form>
<p class="small text-secondary mt-2">Changes save as you type. Taxes recalculate automatically. Marking a paycheck paid locks it and counts it toward year-to-date wages. Add a second line for someone who worked at two branches; their taxes are combined, like the spreadsheet.</p>

<div class="modal fade" id="payModal" tabindex="-1" aria-hidden="true"><div class="modal-dialog modal-sm"><form class="modal-content" id="payForm">
  <div class="modal-header"><h2 class="modal-title h5">Mark paid</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
  <div class="modal-body">
    <div class="mb-2"><label class="form-label">Amount sent</label><input name="sent" class="form-control" inputmode="decimal"></div>
    <div class="mb-2"><label class="form-label">Sent by</label><input name="method" class="form-control" list="payoutList2"><datalist id="payoutList2"><option>Zelle</option><option>Cash App</option><option>Cash</option><option>Check</option><option>Direct deposit</option></datalist>
      <div class="form-text" data-handle></div></div>
  </div>
  <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button><button class="btn btn-success">Mark paid</button></div>
</form></div></div>
<?php require __DIR__ . '/_bottom.php';
