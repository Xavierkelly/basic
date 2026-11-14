<?php
Auth::require('admin');
$co = Auth::company();
if (is_post()) {
    $tz = post('timezone');
    Db::update('companies', [
        'name' => mb_substr((string) post('name'), 0, 150) ?: $co['name'],
        'timezone' => in_array($tz, DateTimeZone::listIdentifiers(), true) ? $tz : $co['timezone'],
        'period_start_weekday' => max(1, min(7, (int) post('period_start_weekday'))),
        'pay_date_offset_days' => max(0, min(30, (int) post('pay_date_offset_days'))),
        'time_entry_mode' => post('time_entry_mode') === 'clock' ? 'clock' : 'weekly_total',
        'overtime_enabled' => !empty($_POST['overtime_enabled']) ? 1 : 0,
        'overtime_threshold_hours' => num(post('overtime_threshold_hours')) ?: 40,
        'overtime_multiplier' => num(post('overtime_multiplier')) ?: 1.5,
        'rate_1099_withholding' => max(0, min(1, num(post('rate_1099_withholding')) / 100)),
        'ytd_start_date' => post('ytd_start_date') ?: null,
        'require_location' => !empty($_POST['require_location']) ? 1 : 0,
    ], ['id' => $co['id']]);
    audit('edit', 'company', (int) $co['id']);
    flash('Settings saved.');
    redirect('settings');
}
$days = [1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday'];
$title = 'Company settings';
require __DIR__ . '/_top.php';
?>
<h1 class="h4 mb-3">Company settings</h1>
<form method="post" class="card" style="max-width:720px"><div class="card-body row g-3">
  <?= csrf_field() ?>
  <div class="col-sm-6"><label class="form-label">Business name</label><input name="name" class="form-control" value="<?= e($co['name']) ?>"></div>
  <div class="col-sm-6"><label class="form-label">Time zone</label><select name="timezone" class="form-select">
    <?php foreach (DateTimeZone::listIdentifiers(DateTimeZone::AMERICA) as $z): ?><option<?= $co['timezone'] === $z ? ' selected' : '' ?>><?= e($z) ?></option><?php endforeach; ?></select></div>
  <div class="col-sm-6"><label class="form-label">Pay week starts on</label><select name="period_start_weekday" class="form-select">
    <?php foreach ($days as $n => $d): ?><option value="<?= $n ?>"<?= (int) $co['period_start_weekday'] === $n ? ' selected' : '' ?>><?= $d ?></option><?php endforeach; ?></select></div>
  <div class="col-sm-6"><label class="form-label">Pay date (days after week ends)</label><input type="number" min="0" max="30" name="pay_date_offset_days" class="form-control" value="<?= (int) $co['pay_date_offset_days'] ?>"></div>
  <hr class="my-1">
  <div class="col-sm-6"><label class="form-label">How hours are entered</label><select name="time_entry_mode" class="form-select">
    <option value="weekly_total"<?= $co['time_entry_mode'] === 'weekly_total' ? ' selected' : '' ?>>Weekly total typed in by a manager</option>
    <option value="clock"<?= $co['time_entry_mode'] === 'clock' ? ' selected' : '' ?>>Employees clock in and out</option></select>
    <div class="form-text">You can change this for any one employee on their page.</div></div>
  <div class="col-sm-6 d-flex align-items-center"><div class="form-check"><input class="form-check-input" type="checkbox" name="require_location" id="rl" value="1"<?= $co['require_location'] ? ' checked' : '' ?>><label class="form-check-label" for="rl">Block clock-in if the phone won't share location</label></div></div>
  <hr class="my-1">
  <div class="col-12"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" role="switch" name="overtime_enabled" id="ot" value="1"<?= $co['overtime_enabled'] ? ' checked' : '' ?>><label class="form-check-label" for="ot">Pay overtime (can be changed per employee)</label></div></div>
  <div class="col-6"><label class="form-label">Overtime after (hours / week)</label><input inputmode="decimal" name="overtime_threshold_hours" class="form-control" value="<?= e($co['overtime_threshold_hours']) ?>"></div>
  <div class="col-6"><label class="form-label">Overtime multiplier</label><input inputmode="decimal" name="overtime_multiplier" class="form-control" value="<?= e($co['overtime_multiplier']) ?>"></div>
  <hr class="my-1">
  <div class="col-sm-6"><label class="form-label">1099 withholding %</label><input inputmode="decimal" name="rate_1099_withholding" class="form-control" value="<?= e(round((float) $co['rate_1099_withholding'] * 100, 2)) ?>"><div class="form-text">Normally 0. Backup withholding is 24.</div></div>
  <div class="col-sm-6"><label class="form-label">Count paid checks toward YTD from</label><input type="date" name="ytd_start_date" class="form-control" value="<?= e($co['ytd_start_date']) ?>"><div class="form-text">Earlier wages come from each employee's "wages paid before this app". Leave blank to count all paid checks this year.</div></div>
  <div class="col-12"><button class="btn btn-primary">Save settings</button></div>
</div></form>
<?php require __DIR__ . '/_bottom.php';
