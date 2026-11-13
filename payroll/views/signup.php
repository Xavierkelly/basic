<?php
if (Auth::user()) {
    redirect('home');
}
$errors = [];
if (is_post()) {
    $company = (string) post('company');
    $name = (string) post('name');
    $email = strtolower((string) post('email'));
    $pass = (string) ($_POST['password'] ?? '');
    if ($company === '' || $name === '') {
        $errors[] = 'Enter your business name and your name.';
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Enter a valid email.';
    }
    if (strlen($pass) < 8) {
        $errors[] = 'Use a password of at least 8 characters.';
    }
    if (!$errors && Db::value('SELECT id FROM users WHERE email = ?', [$email])) {
        $errors[] = 'That email already has an account. Sign in instead.';
    }
    if (!$errors) {
        $uid = Db::tx(function () use ($company, $name, $email, $pass) {
            $cid = Db::insert('companies', ['name' => $company, 'timezone' => post('timezone') ?: 'America/Chicago']);
            Db::insert('subscriptions', [
                'company_id' => $cid, 'status' => 'trialing',
                'trial_ends_at' => gmdate('Y-m-d H:i:s', time() + 86400 * (int) cfg('trial_days', 14)),
            ]);
            return Db::insert('users', [
                'company_id' => $cid, 'name' => $name, 'email' => $email,
                'password_hash' => password_hash($pass, PASSWORD_DEFAULT), 'role' => 'owner',
            ]);
        });
        Auth::loginAs($uid);
        flash('Your account is ready. Add your employees, or import your payroll spreadsheet.');
        redirect('home');
    }
}
$title = 'Create account';
require __DIR__ . '/_top.php';
?>
<div class="row justify-content-center mt-4">
  <div class="col-12 col-sm-10 col-md-6 col-lg-5">
    <h1 class="h3 mb-3 text-center">Create your payroll account</h1>
    <div class="card shadow-sm"><div class="card-body p-4">
      <?php foreach ($errors as $er): ?><div class="alert alert-danger py-2"><?= e($er) ?></div><?php endforeach; ?>
      <form method="post">
        <?= csrf_field() ?>
        <div class="mb-3"><label class="form-label" for="company">Business name</label>
          <input class="form-control" id="company" name="company" required value="<?= e(post('company')) ?>"></div>
        <div class="mb-3"><label class="form-label" for="name">Your name</label>
          <input class="form-control" id="name" name="name" autocomplete="name" required value="<?= e(post('name')) ?>"></div>
        <div class="mb-3"><label class="form-label" for="email">Email</label>
          <input class="form-control" type="email" id="email" name="email" autocomplete="username" required value="<?= e(post('email')) ?>"></div>
        <div class="mb-3"><label class="form-label" for="password">Password</label>
          <input class="form-control" type="password" id="password" name="password" minlength="8" autocomplete="new-password" required></div>
        <input type="hidden" name="timezone" id="tz">
        <button class="btn btn-primary w-100">Start free trial</button>
      </form>
    </div></div>
    <p class="text-center mt-3 small">Already have an account? <a href="<?= e(url('login')) ?>">Sign in</a></p>
  </div>
</div>
<script>try { document.getElementById('tz').value = Intl.DateTimeFormat().resolvedOptions().timeZone; } catch (e) {}</script>
<?php require __DIR__ . '/_bottom.php';
