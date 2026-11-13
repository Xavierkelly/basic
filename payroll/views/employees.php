<?php
Auth::require('manager');
$cid = Auth::companyId();
$showInactive = !empty($_GET['all']);
$rows = Db::all(
    'SELECT e.*, b.name AS branch, d.name AS dept, tp.w4_entered, tp.ytd_verified, tp.tax_state
     FROM employees e LEFT JOIN tags b ON b.id = e.default_branch_id LEFT JOIN tags d ON d.id = e.default_department_id
     LEFT JOIN employee_tax_profiles tp ON tp.employee_id = e.id
     WHERE e.company_id = ?' . ($showInactive ? '' : ' AND e.active = 1') . ' ORDER BY e.first_name, e.last_name',
    [$cid]
);
$title = 'Employees';
require __DIR__ . '/_top.php';
?>
<div class="d-flex flex-wrap align-items-center gap-2 mb-3">
  <h1 class="h4 mb-0 me-auto">Employees (<?= count($rows) ?>)</h1>
  <a class="btn btn-sm btn-outline-secondary" href="<?= e(url('employees', $showInactive ? [] : ['all' => 1])) ?>"><?= $showInactive ? 'Hide inactive' : 'Show inactive' ?></a>
  <?php if (Auth::can('admin')): ?><a class="btn btn-sm btn-primary" href="<?= e(url('employee_edit')) ?>">Add employee</a><?php endif; ?>
</div>
<input class="form-control mb-2" type="search" placeholder="Search name, branch, department" data-filter-table="#empTable">
<div class="card"><div class="table-responsive"><table class="table table-hover align-middle mb-0" id="empTable">
  <thead><tr><th>Name</th><th>Type</th><th>Branch</th><th>Department</th><th class="text-end">Rate</th><th>Tax setup</th></tr></thead>
  <tbody>
  <?php foreach ($rows as $r): ?>
    <tr class="<?= $r['active'] ? '' : 'text-secondary' ?>">
      <td><?= Auth::can('admin') ? '<a href="' . e(url('employee_edit', ['id' => $r['id']])) . '">' . e(employee_name($r)) . '</a>' : e(employee_name($r)) ?><?= $r['active'] ? '' : ' (inactive)' ?></td>
      <td><?= e($r['worker_type']) ?></td><td><?= e($r['branch']) ?></td><td><?= e($r['dept']) ?></td>
      <td class="text-end">$<?= money($r['pay_rate']) ?></td>
      <td class="small"><?php $st = TaxEngine::setupStatus($r['worker_type'], $r['w4_entered'] === null ? null : $r); ?>
        <span class="badge <?= str_contains($st, 'missing') || str_contains($st, 'verify') ? 'text-bg-warning' : 'text-bg-light' ?>"><?= e($st) ?></span></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table></div></div>
<?php require __DIR__ . '/_bottom.php';
