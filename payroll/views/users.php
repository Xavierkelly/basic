<?php
$me = Auth::require('admin');
$cid = Auth::companyId();
$newPassword = null;
if (is_post()) {
    $do = post('do');
    if ($do === 'add') {
        $email = strtolower((string) post('email'));
        $role = in_array(post('role'), ['admin', 'manager', 'employee'], true) ? post('role') : 'employee';
        if ($role === 'admin' && !Auth::can('owner')) {
            $role = 'manager';
        }
        $empId = (int) post('employee_id') ?: null;
        if ($empId && !Db::value('SELECT id FROM employees WHERE id = ? AND company_id = ?', [$empId, $cid])) {
            $empId = null;
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            flash('Enter a valid email.', 'danger');
        } elseif (Db::value('SELECT id FROM users WHERE email = ?', [$email])) {
            flash('That email already has a login.', 'danger');
        } else {
            $newPassword = substr(strtr(base64_encode(random_bytes(9)), '+/', 'Kp'), 0, 12);
            $id = Db::insert('users', [
                'company_id' => $cid, 'name' => post('name') ?: $email, 'email' => $email,
                'password_hash' => password_hash($newPassword, PASSWORD_DEFAULT), 'role' => $role, 'employee_id' => $empId,
            ]);
            audit('add', 'user', $id, ['role' => $role]);
            $_SESSION['new_pw'] = [$email, $newPassword];
            redirect('users');
        }
    } else {
        $target = Db::one('SELECT * FROM users WHERE id = ? AND company_id = ?', [(int) post('id'), $cid]);
        $protected = !$target || $target['role'] === 'owner' || (int) $target['id'] === (int) $me['id'];
        if ($protected && $do !== 'link') {
            flash('That login cannot be changed here.', 'danger');
        } elseif ($do === 'reset') {
            $pw = substr(strtr(base64_encode(random_bytes(9)), '+/', 'Kp'), 0, 12);
            Db::update('users', ['password_hash' => password_hash($pw, PASSWORD_DEFAULT)], ['id' => $target['id']]);
            $_SESSION['new_pw'] = [$target['email'], $pw];
        } elseif ($do === 'toggle') {
            Db::update('users', ['active' => $target['active'] ? 0 : 1], ['id' => $target['id']]);
        } elseif ($do === 'role') {
            $role = in_array(post('role'), ['admin', 'manager', 'employee'], true) ? post('role') : 'employee';
            if ($role === 'admin' && !Auth::can('owner')) {
                flash('Only the owner can make admins.', 'danger');
            } else {
                Db::update('users', ['role' => $role], ['id' => $target['id']]);
            }
        } elseif ($do === 'link' && $target) {
            $empId = (int) post('employee_id') ?: null;
            if ($empId && !Db::value('SELECT id FROM employees WHERE id = ? AND company_id = ?', [$empId, $cid])) {
                $empId = null;
            }
            Db::update('users', ['employee_id' => $empId], ['id' => $target['id']]);
        }
        redirect('users');
    }
}
if (!empty($_SESSION['new_pw'])) {
    $newPassword = $_SESSION['new_pw'];
    unset($_SESSION['new_pw']);
}
$users = Db::all('SELECT u.*, e.first_name, e.last_name FROM users u LEFT JOIN employees e ON e.id = u.employee_id WHERE u.company_id = ? ORDER BY u.active DESC, u.name', [$cid]);
$emps = Db::all('SELECT id, first_name, last_name FROM employees WHERE company_id = ? AND active = 1 ORDER BY first_name, last_name', [$cid]);
$empOpts = function ($sel) use ($emps) {
    $h = '<option value="">Not linked</option>';
    foreach ($emps as $e) {
        $h .= '<option value="' . (int) $e['id'] . '"' . ((int) $sel === (int) $e['id'] ? ' selected' : '') . '>' . e(employee_name($e)) . '</option>';
    }
    return $h;
};
$roles = Auth::can('owner') ? ['admin', 'manager', 'employee'] : ['manager', 'employee'];
$title = 'Users & roles';
require __DIR__ . '/_top.php';
?>
<h1 class="h4">Users &amp; roles</h1>
<p class="text-secondary small mb-3">Admins run payroll. Managers handle timesheets and see reports. Employees clock in and see their own pay stubs. Link a login to an employee so they can clock in.</p>
<?php if ($newPassword): ?>
  <div class="alert alert-warning">Temporary password for <strong><?= e($newPassword[0]) ?></strong>: <code class="fs-5"><?= e($newPassword[1]) ?></code><br>
  Share it with them privately. It won't be shown again; they can change it under My account.</div>
<?php endif; ?>
<div class="card mb-3"><div class="card-body">
  <h2 class="h6 text-secondary text-uppercase">Add a login</h2>
  <form method="post" class="row g-2">
    <?= csrf_field() ?><input type="hidden" name="do" value="add">
    <div class="col-12 col-md-3"><input name="name" class="form-control" placeholder="Name"></div>
    <div class="col-12 col-md-3"><input name="email" type="email" class="form-control" placeholder="Email" required></div>
    <div class="col-6 col-md-2"><select name="role" class="form-select"><?php foreach ($roles as $r): ?><option value="<?= $r ?>"<?= $r === 'employee' ? ' selected' : '' ?>><?= ucfirst($r) ?></option><?php endforeach; ?></select></div>
    <div class="col-6 col-md-2"><select name="employee_id" class="form-select"><?= $empOpts(null) ?></select></div>
    <div class="col-12 col-md-2"><button class="btn btn-primary w-100">Add</button></div>
  </form>
</div></div>
<div class="card"><div class="table-responsive"><table class="table align-middle mb-0">
  <thead><tr><th>Name</th><th>Role</th><th>Employee</th><th>Last sign-in</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($users as $x): $locked = $x['role'] === 'owner' || (int) $x['id'] === (int) $me['id']; ?>
    <tr class="<?= $x['active'] ? '' : 'text-secondary' ?>">
      <td><?= e($x['name']) ?><div class="small text-secondary"><?= e($x['email']) ?></div></td>
      <td><?php if ($locked): ?><?= e(ucfirst($x['role'])) ?><?php else: ?>
        <form method="post" class="d-inline"><?= csrf_field() ?><input type="hidden" name="do" value="role"><input type="hidden" name="id" value="<?= (int) $x['id'] ?>">
          <select name="role" class="form-select form-select-sm" onchange="this.form.submit()" aria-label="Role"><?php foreach ($roles as $r): ?><option value="<?= $r ?>"<?= $x['role'] === $r ? ' selected' : '' ?>><?= ucfirst($r) ?></option><?php endforeach; ?></select></form><?php endif; ?></td>
      <td><form method="post"><?= csrf_field() ?><input type="hidden" name="do" value="link"><input type="hidden" name="id" value="<?= (int) $x['id'] ?>">
          <select name="employee_id" class="form-select form-select-sm" onchange="this.form.submit()" aria-label="Linked employee"><?= $empOpts($x['employee_id']) ?></select></form></td>
      <td class="small"><?= $x['last_login_at'] ? e(fmt_date($x['last_login_at'])) : 'never' ?></td>
      <td class="text-end text-nowrap"><?php if (!$locked): ?>
        <form method="post" class="d-inline"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $x['id'] ?>">
          <button class="btn btn-sm btn-outline-secondary" name="do" value="reset">Reset password</button>
          <button class="btn btn-sm btn-outline-<?= $x['active'] ? 'danger' : 'success' ?>" name="do" value="toggle"><?= $x['active'] ? 'Disable' : 'Enable' ?></button></form><?php endif; ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table></div></div>
<?php require __DIR__ . '/_bottom.php';
