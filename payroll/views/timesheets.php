<?php
$u = Auth::require('employee');
$co = Auth::company();
$cid = (int) $co['id'];
$isMgr = Auth::can('manager');
$tz = $co['timezone'];
$today = (new DateTime('now', new DateTimeZone($tz)))->format('Y-m-d');
$week = Payroll::periodFor($co, preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['week'] ?? '') ? $_GET['week'] : $today);
[$a, $b] = Payroll::utcRange($tz, $week['start'], $week['end']);
$empFilter = $isMgr ? (int) ($_GET['emp'] ?? 0) : (int) $u['employee_id'];
$employees = $isMgr ? Db::all('SELECT id, first_name, last_name FROM employees WHERE company_id = ? AND active = 1 ORDER BY first_name, last_name', [$cid]) : [];

$sql = 'SELECT te.*, e.first_name, e.last_name FROM time_entries te JOIN employees e ON e.id = te.employee_id
        WHERE te.company_id = ? AND te.clock_in >= ? AND te.clock_in < ?';
$params = [$cid, $a, $b];
if ($empFilter || !$isMgr) {
    $sql .= ' AND te.employee_id = ?';
    $params[] = $empFilter;
}
$rows = Db::all($sql . ' ORDER BY e.first_name, e.last_name, te.clock_in', $params);
$byEmp = [];
foreach ($rows as $r) {
    $byEmp[$r['employee_id']]['name'] = employee_name($r);
    $byEmp[$r['employee_id']]['rows'][] = $r;
}
$prev = (new DateTime($week['start']))->modify('-7 days')->format('Y-m-d');
$next = (new DateTime($week['start']))->modify('+7 days')->format('Y-m-d');
$map = fn($lat, $lng) => $lat !== null ? 'https://www.google.com/maps?q=' . rawurlencode($lat . ',' . $lng) : null;
$localInput = fn($utc) => $utc ? local_dt($utc, $tz, 'Y-m-d\TH:i') : '';

$title = 'Time';
require __DIR__ . '/_top.php';
?>
<div class="d-flex flex-wrap align-items-center gap-2 mb-3">
  <h1 class="h4 mb-0 me-auto">Timesheets</h1>
  <a class="btn btn-outline-secondary btn-sm" href="<?= e(url('timesheets', ['week' => $prev, 'emp' => $empFilter ?: null])) ?>">&larr; Prev</a>
  <span class="fw-semibold"><?= e(fmt_date($week['start'], 'M j')) ?> – <?= e(fmt_date($week['end'], 'M j, Y')) ?></span>
  <a class="btn btn-outline-secondary btn-sm" href="<?= e(url('timesheets', ['week' => $next, 'emp' => $empFilter ?: null])) ?>">Next &rarr;</a>
</div>
<?php if ($isMgr): ?>
<form class="row g-2 mb-3" method="get">
  <input type="hidden" name="p" value="timesheets"><input type="hidden" name="week" value="<?= e($week['start']) ?>">
  <div class="col-auto"><select name="emp" class="form-select form-select-sm" onchange="this.form.submit()">
    <option value="">Everyone</option>
    <?php foreach ($employees as $e): ?><option value="<?= (int) $e['id'] ?>"<?= $empFilter === (int) $e['id'] ? ' selected' : '' ?>><?= e(employee_name($e)) ?></option><?php endforeach; ?>
  </select></div>
  <div class="col-auto"><button type="button" class="btn btn-sm btn-primary" data-te-new>Add time</button></div>
  <?php if ($rows): ?><div class="col-auto"><button type="button" class="btn btn-sm btn-outline-success" data-te-approve-all>Approve all shown</button></div><?php endif; ?>
</form>
<?php endif; ?>

<?php if (!$byEmp): ?><p class="text-secondary">No time recorded this week.</p><?php endif; ?>
<?php foreach ($byEmp as $empId => $g):
    $total = 0;
    foreach ($g['rows'] as $r) {
        $total += $r['clock_out'] ? (strtotime($r['clock_out']) - strtotime($r['clock_in'])) / 3600 : 0;
    } ?>
  <div class="card mb-3"><div class="card-body p-2 p-sm-3">
    <div class="d-flex justify-content-between"><h2 class="h6 mb-2"><?= e($g['name']) ?></h2><span class="fw-semibold"><?= number_format($total, 2) ?> h</span></div>
    <div class="table-responsive"><table class="table table-sm align-middle mb-0">
      <thead><tr><th>Day</th><th>In</th><th>Out</th><th class="text-end">Hours</th><th>Location</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($g['rows'] as $r):
          $h = $r['clock_out'] ? (strtotime($r['clock_out']) - strtotime($r['clock_in'])) / 3600 : null; ?>
        <tr data-te='<?= e(json_encode(['id' => (int) $r['id'], 'employee_id' => (int) $r['employee_id'], 'clock_in' => $localInput($r['clock_in']), 'clock_out' => $localInput($r['clock_out']), 'note' => $r['note']])) ?>'>
          <td><?= e(local_dt($r['clock_in'], $tz, 'D M j')) ?></td>
          <td><?= e(local_dt($r['clock_in'], $tz, 'g:i a')) ?></td>
          <td><?= $r['clock_out'] ? e(local_dt($r['clock_out'], $tz, 'g:i a')) : '<span class="badge text-bg-success">on the clock</span>' ?></td>
          <td class="text-end"><?= $h === null ? '' : number_format($h, 2) ?></td>
          <td class="small">
            <?php if ($l = $map($r['in_lat'], $r['in_lng'])): ?><a href="<?= e($l) ?>" target="_blank" rel="noopener">in</a><?php endif; ?>
            <?php if ($l = $map($r['out_lat'], $r['out_lng'])): ?> · <a href="<?= e($l) ?>" target="_blank" rel="noopener">out</a><?php endif; ?>
            <?php if ($r['location_missing']): ?><span class="badge text-bg-warning">missing</span><?php endif; ?>
            <?php if ($r['source'] === 'manual'): ?><span class="badge text-bg-secondary">manual</span><?php endif; ?>
            <?php if ($r['note']): ?><div class="text-secondary"><?= e($r['note']) ?></div><?php endif; ?>
          </td>
          <td class="text-end text-nowrap">
            <?php if ($r['approved_at']): ?><span class="badge text-bg-success">approved</span><?php elseif ($isMgr && $r['clock_out']): ?><button class="btn btn-sm btn-outline-success" data-te-approve="<?= (int) $r['id'] ?>">Approve</button><?php endif; ?>
            <?php if ($isMgr): ?><button class="btn btn-sm btn-link" data-te-edit>Edit</button><?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  </div></div>
<?php endforeach; ?>

<?php if ($isMgr): ?>
<div class="modal fade" id="teModal" tabindex="-1" aria-hidden="true"><div class="modal-dialog"><form class="modal-content" id="teForm">
  <div class="modal-header"><h2 class="modal-title h5">Time entry</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
  <div class="modal-body">
    <input type="hidden" name="id">
    <div class="mb-2"><label class="form-label">Employee</label><select name="employee_id" class="form-select" required>
      <?php foreach ($employees as $e): ?><option value="<?= (int) $e['id'] ?>"><?= e(employee_name($e)) ?></option><?php endforeach; ?></select></div>
    <div class="mb-2"><label class="form-label">Clock in</label><input type="datetime-local" name="clock_in" class="form-control" required></div>
    <div class="mb-2"><label class="form-label">Clock out</label><input type="datetime-local" name="clock_out" class="form-control"></div>
    <div class="mb-2"><label class="form-label">Note</label><input name="note" class="form-control" maxlength="255"></div>
  </div>
  <div class="modal-footer"><button type="button" class="btn btn-outline-danger me-auto" data-te-delete>Delete</button>
    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary">Save</button></div>
</form></div></div>
<?php endif;
require __DIR__ . '/_bottom.php';
