<?php
if (Auth::user()) {
    redirect('home');
}
$error = null;
if (is_post()) {
    if (Auth::attempt((string) post('email'), (string) ($_POST['password'] ?? ''))) {
        redirect('home');
    }
    $error = 'That email and password did not match, or there were too many tries. Wait a few minutes and try again.';
}
$title = 'Sign in';
require __DIR__ . '/_top.php';
?>
<div class="row justify-content-center mt-4">
  <div class="col-12 col-sm-8 col-md-5 col-lg-4">
    <h1 class="h3 mb-3 text-center"><?= e(cfg('app_name')) ?></h1>
    <div class="card shadow-sm"><div class="card-body p-4">
      <?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>
      <form method="post">
        <?= csrf_field() ?>
        <div class="mb-3"><label class="form-label" for="email">Email</label>
          <input class="form-control form-control-lg" type="email" id="email" name="email" autocomplete="username" required value="<?= e(post('email')) ?>"></div>
        <div class="mb-3"><label class="form-label" for="password">Password</label>
          <input class="form-control form-control-lg" type="password" id="password" name="password" autocomplete="current-password" required></div>
        <button class="btn btn-primary btn-lg w-100">Sign in</button>
      </form>
    </div></div>
    <p class="text-center mt-3 small">New business? <a href="<?= e(url('signup')) ?>">Create an account</a></p>
  </div>
</div>
<?php require __DIR__ . '/_bottom.php';
