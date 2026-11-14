<?php
$u = Auth::require('employee');
$err = null;
if (is_post()) {
    $cur = (string) ($_POST['current'] ?? '');
    $new = (string) ($_POST['new'] ?? '');
    if (!password_verify($cur, $u['password_hash'])) {
        $err = 'Your current password is not right.';
    } elseif (strlen($new) < 8) {
        $err = 'Use a new password of at least 8 characters.';
    } else {
        Db::update('users', ['password_hash' => password_hash($new, PASSWORD_DEFAULT)], ['id' => $u['id']]);
        flash('Password changed.');
        redirect('account');
    }
}
$title = 'My account';
require __DIR__ . '/_top.php';
?>
<h1 class="h4 mb-3">My account</h1>
<div class="card" style="max-width:480px"><div class="card-body">
  <p class="mb-1"><strong><?= e($u['name']) ?></strong></p>
  <p class="text-secondary"><?= e($u['email']) ?> · <?= e(ucfirst($u['role'])) ?></p>
  <?php if ($err): ?><div class="alert alert-danger py-2"><?= e($err) ?></div><?php endif; ?>
  <form method="post">
    <?= csrf_field() ?>
    <div class="mb-2"><label class="form-label">Current password</label><input type="password" name="current" class="form-control" autocomplete="current-password" required></div>
    <div class="mb-3"><label class="form-label">New password</label><input type="password" name="new" class="form-control" minlength="8" autocomplete="new-password" required></div>
    <button class="btn btn-primary">Change password</button>
  </form>
</div></div>
<?php require __DIR__ . '/_bottom.php';
