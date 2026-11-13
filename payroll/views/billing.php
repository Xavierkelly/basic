<?php
Auth::require('owner');
$sub = Db::one('SELECT * FROM subscriptions WHERE company_id = ?', [Auth::companyId()]);
$title = 'Billing';
require __DIR__ . '/_top.php';
?>
<h1 class="h4">Billing</h1>
<div class="card" style="max-width:560px"><div class="card-body">
  <p class="mb-1">Plan: <strong><?= e(ucfirst($sub['plan'] ?? 'basic')) ?></strong></p>
  <p class="mb-1">Status: <strong><?= e(ucfirst(str_replace('_', ' ', $sub['status'] ?? 'trialing'))) ?></strong></p>
  <?php if (($sub['status'] ?? '') === 'trialing'): ?><p>Trial ends <?= e(fmt_date($sub['trial_ends_at'])) ?>.</p><?php endif; ?>
  <p class="text-secondary small mb-0">Card payments through Square are coming next.</p>
</div></div>
<?php require __DIR__ . '/_bottom.php';
