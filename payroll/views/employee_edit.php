<?php
Auth::require('admin');
$co = Auth::company();
$cid = (int) $co['id'];
$id = (int) ($_GET['id'] ?? 0);
$emp = $id ? Db::one('SELECT * FROM employees WHERE id = ? AND company_id = ?', [$id, $cid]) : null;
if ($id && !$emp) {
    http_response_code(404);
    exit('Employee not found.');
}
$tp = ($emp ? Db::one('SELECT * FROM employee_tax_profiles WHERE employee_id = ?', [$id]) : null) ?: TaxEngine::defaultProfile() + [
    'ytd_prior_year' => null, 'ytd_prior_ss_wages' => 0, 'ytd_prior_medicare_wages' => 0, 'ytd_verified' => 0, 'prior_gross_before_app' => 0,
];
$errors = [];
if (is_post()) {
    $chk = fn($k) => !empty($_POST[$k]) ? 1 : 0;
    $nullInt = fn($k) => ($_POST[$k] ?? '') === '' ? null : (int) $_POST[$k];
    $tri = fn($k) => ($_POST[$k] ?? '') === '' ? null : (int) $_POST[$k];
    $row = [
        'first_name' => mb_substr((string) post('first_name'), 0, 80),
        'last_name' => mb_substr((string) post('last_name'), 0, 80),
        'email' => post('email') ?: null,
        'phone' => post('phone') ?: null,
        'worker_type' => post('worker_type') === '1099' ? '1099' : 'W2',
        'default_branch_id' => isset(Tags::list($cid, 'branch')[(int) post('branch')]) ? (int) post('branch') : null,
        'default_department_id' => isset(Tags::list($cid, 'department')[(int) post('department')]) ? (int) post('department') : null,
        'pay_rate' => num(post('pay_rate')),
        'weekly_salary' => num(post('weekly_salary')),
        'payout_method' => post('payout_method') ?: null,
        'payout_handle' => post('payout_handle') ?: null,
        'time_entry_mode' => in_array(post('time_entry_mode'), ['clock', 'weekly_total'], true) ? post('time_entry_mode') : null,
        'overtime_enabled' => $tri('overtime_enabled'),
        'active' => $chk('active'),
        'hired_on' => post('hired_on') ?: null,
        'notes' => mb_substr((string) post('notes'), 0, 500) ?: null,
    ];
    $state = post('tax_state');
    $prof = [
        'w4_entered' => $chk('w4_entered'),
        'filing_status' => in_array(post('filing_status'), ['Single', 'Married filing jointly', 'Head of household'], true) ? post('filing_status') : 'Single',
        'multiple_jobs' => $chk('multiple_jobs'),
        'other_income' => num(post('other_income')),
        'deductions' => num(post('deductions')),
        'extra_withholding' => num(post('extra_withholding')),
        'federal_exempt' => $chk('federal_exempt'),
        'children_under_17' => $nullInt('children_under_17'),
        'other_dependents' => $nullInt('other_dependents'),
        'credit_override' => post('credit_override') === '' ? null : num(post('credit_override')),
        'tax_state' => isset(TaxEngine::STATES[$state]) ? $state : 'TX',
        'il_allowances_1' => (int) post('il_allowances_1'),
        'il_allowances_2' => (int) post('il_allowances_2'),
        'il_extra' => num(post('il_extra')),
        'il_exempt' => $chk('il_exempt'),
        'ytd_prior_year' => post('ytd_prior_year') === '' ? null : (int) post('ytd_prior_year'),
        'ytd_prior_ss_wages' => num(post('ytd_prior_ss_wages')),
        'ytd_prior_medicare_wages' => num(post('ytd_prior_medicare_wages')),
        'ytd_verified' => $chk('ytd_verified'),
        'prior_gross_before_app' => num(post('prior_gross_before_app')),
    ];
    if ($row['first_name'] === '') {
        $errors[] = 'First name is required.';
    }
    if ($row['email'] && !filter_var($row['email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'That email does not look right.';
    }
    if (!$errors) {
        Db::tx(function () use (&$id, $row, $prof, $cid) {
            if ($id) {
                Db::update('employees', $row, ['id' => $id, 'company_id' => $cid]);
            } else {
                $id = Db::insert('employees', $row + ['company_id' => $cid]);
            }
            $exists = Db::value('SELECT employee_id FROM employee_tax_profiles WHERE employee_id = ?', [$id]);
            $exists ? Db::update('employee_tax_profiles', $prof, ['employee_id' => $id])
                    : Db::insert('employee_tax_profiles', $prof + ['employee_id' => $id]);
        });
        audit($emp ? 'edit' : 'add', 'employee', $id);
        flash('Saved ' . trim($row['first_name'] . ' ' . $row['last_name']) . '.');
        redirect('employees');
    }
    $emp = $row + ['id' => $id];
    $tp = $prof + $tp;
}
$emp ??= ['first_name' => '', 'last_name' => '', 'email' => '', 'phone' => '', 'worker_type' => 'W2', 'default_branch_id' => null,
    'default_department_id' => null, 'pay_rate' => '', 'weekly_salary' => '', 'payout_method' => '', 'payout_handle' => '',
    'time_entry_mode' => null, 'overtime_enabled' => null, 'active' => 1, 'hired_on' => '', 'notes' => ''];
$branches = Tags::list($cid, 'branch');
$depts = Tags::list($cid, 'department');
$sel = fn($a, $b) => (string) $a === (string) $b ? ' selected' : '';
$chk = fn($v) => $v ? ' checked' : '';
$title = $id ? 'Edit employee' : 'Add employee';
require __DIR__ . '/_top.php';
?>
<h1 class="h4 mb-3"><?= e($title) ?></h1>
<?php foreach ($errors as $er): ?><div class="alert alert-danger py-2"><?= e($er) ?></div><?php endforeach; ?>
<form method="post" class="row g-3">
  <?= csrf_field() ?>
  <div class="col-12 col-lg-6">
    <div class="card"><div class="card-body row g-2">
      <h2 class="h6 text-secondary text-uppercase">Person</h2>
      <div class="col-6"><label class="form-label">First name</label><input name="first_name" class="form-control" required value="<?= e($emp['first_name']) ?>"></div>
      <div class="col-6"><label class="form-label">Last name</label><input name="last_name" class="form-control" value="<?= e($emp['last_name']) ?>"></div>
      <div class="col-sm-6"><label class="form-label">Email</label><input type="email" name="email" class="form-control" value="<?= e($emp['email']) ?>"></div>
      <div class="col-sm-6"><label class="form-label">Phone</label><input name="phone" class="form-control" value="<?= e($emp['phone']) ?>"></div>
      <div class="col-sm-6"><label class="form-label">Worker type</label><select name="worker_type" class="form-select">
        <option value="W2"<?= $sel($emp['worker_type'], 'W2') ?>>W-2 employee</option><option value="1099"<?= $sel($emp['worker_type'], '1099') ?>>1099 contractor</option></select></div>
      <div class="col-sm-6"><label class="form-label">Hired on</label><input type="date" name="hired_on" class="form-control" value="<?= e($emp['hired_on']) ?>"></div>
      <div class="col-sm-6"><label class="form-label">Branch</label><select name="branch" class="form-select"><?= Tags::options($branches, $emp['default_branch_id']) ?></select></div>
      <div class="col-sm-6"><label class="form-label">Department</label><select name="department" class="form-select"><?= Tags::options($depts, $emp['default_department_id']) ?></select></div>
      <div class="col-12 small"><a href="<?= e(url('tags')) ?>">Add branches or departments</a></div>
      <div class="col-12 form-check ms-2"><input class="form-check-input" type="checkbox" name="active" id="active" value="1"<?= $chk($emp['active']) ?>><label class="form-check-label" for="active">Active</label></div>
    </div></div>
    <div class="card mt-3"><div class="card-body row g-2">
      <h2 class="h6 text-secondary text-uppercase">Pay</h2>
      <div class="col-6"><label class="form-label">Hourly rate</label><input inputmode="decimal" name="pay_rate" class="form-control" value="<?= e($emp['pay_rate']) ?>"></div>
      <div class="col-6"><label class="form-label">Weekly salary</label><input inputmode="decimal" name="weekly_salary" class="form-control" value="<?= e($emp['weekly_salary']) ?>"></div>
      <div class="col-6"><label class="form-label">Hours entry</label><select name="time_entry_mode" class="form-select">
        <option value="">Company default (<?= $co['time_entry_mode'] === 'clock' ? 'clock in/out' : 'weekly total' ?>)</option>
        <option value="clock"<?= $sel($emp['time_entry_mode'], 'clock') ?>>Clock in/out</option>
        <option value="weekly_total"<?= $sel($emp['time_entry_mode'], 'weekly_total') ?>>Weekly total typed in</option></select></div>
      <div class="col-6"><label class="form-label">Overtime</label><select name="overtime_enabled" class="form-select">
        <option value="">Company default (<?= $co['overtime_enabled'] ? 'on' : 'off' ?>)</option>
        <option value="1"<?= $sel($emp['overtime_enabled'], '1') ?>>On</option>
        <option value="0"<?= $sel($emp['overtime_enabled'], '0') ?>>Off</option></select></div>
      <div class="col-6"><label class="form-label">Payout method</label><input name="payout_method" class="form-control" list="payoutList" value="<?= e($emp['payout_method']) ?>">
        <datalist id="payoutList"><option>Zelle</option><option>Cash App</option><option>Cash</option><option>Check</option><option>Direct deposit</option></datalist></div>
      <div class="col-6"><label class="form-label">Payout handle</label><input name="payout_handle" class="form-control" placeholder="phone or $cashtag" value="<?= e($emp['payout_handle']) ?>"></div>
      <div class="col-12"><label class="form-label">Notes</label><textarea name="notes" class="form-control" rows="2"><?= e($emp['notes']) ?></textarea></div>
    </div></div>
  </div>
  <div class="col-12 col-lg-6">
    <div class="card"><div class="card-body row g-2">
      <h2 class="h6 text-secondary text-uppercase">Tax withholding (W-2 only)</h2>
      <div class="col-12 form-check ms-2"><input class="form-check-input" type="checkbox" name="w4_entered" id="w4" value="1"<?= $chk($tp['w4_entered']) ?>><label class="form-check-label" for="w4">W-4 entered (otherwise withholding uses Single with no adjustments)</label></div>
      <div class="col-sm-6"><label class="form-label">Filing status</label><select name="filing_status" class="form-select">
        <?php foreach (['Single', 'Married filing jointly', 'Head of household'] as $f): ?><option<?= $sel($tp['filing_status'], $f) ?>><?= $f ?></option><?php endforeach; ?></select></div>
      <div class="col-sm-6 d-flex align-items-end"><div class="form-check"><input class="form-check-input" type="checkbox" name="multiple_jobs" id="mj" value="1"<?= $chk($tp['multiple_jobs']) ?>><label class="form-check-label" for="mj">Multiple jobs (Step 2c)</label></div></div>
      <div class="col-6"><label class="form-label">Children under 17</label><input type="number" min="0" name="children_under_17" class="form-control" value="<?= e($tp['children_under_17']) ?>"></div>
      <div class="col-6"><label class="form-label">Other dependents</label><input type="number" min="0" name="other_dependents" class="form-control" value="<?= e($tp['other_dependents']) ?>"></div>
      <div class="col-6"><label class="form-label">Other income / yr (4a)</label><input inputmode="decimal" name="other_income" class="form-control" value="<?= e($tp['other_income']) ?>"></div>
      <div class="col-6"><label class="form-label">Deductions / yr (4b)</label><input inputmode="decimal" name="deductions" class="form-control" value="<?= e($tp['deductions']) ?>"></div>
      <div class="col-6"><label class="form-label">Extra per check (4c)</label><input inputmode="decimal" name="extra_withholding" class="form-control" value="<?= e($tp['extra_withholding']) ?>"></div>
      <div class="col-6"><label class="form-label">Step 3 credit override</label><input inputmode="decimal" name="credit_override" class="form-control" placeholder="auto" value="<?= e($tp['credit_override']) ?>"></div>
      <div class="col-12 form-check ms-2"><input class="form-check-input" type="checkbox" name="federal_exempt" id="fx" value="1"<?= $chk($tp['federal_exempt']) ?>><label class="form-check-label" for="fx">Exempt from federal income tax withholding</label></div>
      <div class="col-sm-6"><label class="form-label">Tax state</label><select name="tax_state" class="form-select">
        <?php foreach (TaxEngine::STATES as $k => $label): ?><option value="<?= $k ?>"<?= $sel($tp['tax_state'], $k) ?>><?= e($label) ?></option><?php endforeach; ?></select></div>
      <div class="col-sm-6 small text-secondary d-flex align-items-end">Illinois fields apply only when the state is Illinois.</div>
      <div class="col-4"><label class="form-label">IL allowances 1</label><input type="number" min="0" name="il_allowances_1" class="form-control" value="<?= e($tp['il_allowances_1']) ?>"></div>
      <div class="col-4"><label class="form-label">IL allowances 2</label><input type="number" min="0" name="il_allowances_2" class="form-control" value="<?= e($tp['il_allowances_2']) ?>"></div>
      <div class="col-4"><label class="form-label">Extra IL / check</label><input inputmode="decimal" name="il_extra" class="form-control" value="<?= e($tp['il_extra']) ?>"></div>
      <div class="col-12 form-check ms-2"><input class="form-check-input" type="checkbox" name="il_exempt" id="ilx" value="1"<?= $chk($tp['il_exempt']) ?>><label class="form-check-label" for="ilx">Exempt from Illinois withholding</label></div>
    </div></div>
    <div class="card mt-3"><div class="card-body row g-2">
      <h2 class="h6 text-secondary text-uppercase">Wages paid before this app</h2>
      <p class="small text-secondary mb-1">Used for the Social Security wage cap and Additional Medicare. Enter this year's taxable wages paid before you started running payroll here.</p>
      <div class="col-4"><label class="form-label">Year</label><input type="number" name="ytd_prior_year" class="form-control" value="<?= e($tp['ytd_prior_year'] ?? date('Y')) ?>"></div>
      <div class="col-4"><label class="form-label">SS wages</label><input inputmode="decimal" name="ytd_prior_ss_wages" class="form-control" value="<?= e($tp['ytd_prior_ss_wages']) ?>"></div>
      <div class="col-4"><label class="form-label">Medicare wages</label><input inputmode="decimal" name="ytd_prior_medicare_wages" class="form-control" value="<?= e($tp['ytd_prior_medicare_wages']) ?>"></div>
      <div class="col-6"><label class="form-label">Lifetime gross before app</label><input inputmode="decimal" name="prior_gross_before_app" class="form-control" value="<?= e($tp['prior_gross_before_app']) ?>"></div>
      <div class="col-6 d-flex align-items-end"><div class="form-check"><input class="form-check-input" type="checkbox" name="ytd_verified" id="yv" value="1"<?= $chk($tp['ytd_verified']) ?>><label class="form-check-label" for="yv">YTD wages verified</label></div></div>
    </div></div>
  </div>
  <div class="col-12 d-flex gap-2 sticky-actions">
    <button class="btn btn-primary">Save</button>
    <a class="btn btn-outline-secondary" href="<?= e(url('employees')) ?>">Cancel</a>
  </div>
</form>
<?php require __DIR__ . '/_bottom.php';
