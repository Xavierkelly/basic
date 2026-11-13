<?php
$u = Auth::require('owner');
$co = Auth::company();
$sub = Billing::subscription((int) $co['id']);
if (is_post()) {
    try {
        if (post('do') === 'subscribe') {
            if (!Square::configured()) {
                throw new RuntimeException('Square billing is not set up on this server yet.');
            }
            header('Location: ' . Square::checkoutUrl($co, $u));
            exit;
        }
        if (post('do') === 'cancel') {
            Square::cancel($sub);
            audit('cancel', 'subscription', (int) $co['id']);
            flash('Your subscription will end at the close of the current billing period.');
        }
    } catch (RuntimeException $e) {
        flash($e->getMessage(), 'danger');
    }
    redirect('billing');
}
$statusLabel = ['trialing' => 'Free trial', 'active' => 'Active', 'past_due' => 'Payment problem', 'canceled' => 'Canceled'][$sub['status'] ?? 'trialing'] ?? 'Unknown';
$price = (int) cfg('square.plan_price_cents');
$title = 'Billing';
require __DIR__ . '/_top.php';
?>
<h1 class="h4">Billing</h1>
<?php if (!empty($_GET['done'])): ?>
  <div class="alert alert-success">Thanks! Square is confirming your payment. This page updates within a minute or two.</div>
<?php endif; ?>
<div class="card" style="max-width:560px"><div class="card-body">
  <p class="mb-1">Plan: <strong><?= e(cfg('square.plan_name', 'Basic')) ?></strong><?= $price ? ' · $' . money($price / 100) . ' / month' : '' ?></p>
  <p class="mb-1">Status: <strong><?= e($statusLabel) ?></strong></p>
  <?php if (($sub['status'] ?? '') === 'trialing' && $sub['trial_ends_at']): ?><p class="mb-1">Trial ends <?= e(fmt_date($sub['trial_ends_at'])) ?>.</p><?php endif; ?>
  <?php if ($sub['current_period_end']): ?><p class="mb-1">Paid through <?= e(fmt_date($sub['current_period_end'])) ?>.</p><?php endif; ?>
  <?php if (!Billing::inGoodStanding($sub)): ?><p class="text-danger mb-1">The account is read-only until the subscription is restarted.</p><?php endif; ?>
  <div class="d-flex gap-2 mt-3">
    <?php if (in_array($sub['status'] ?? 'trialing', ['trialing', 'canceled', 'past_due'], true)): ?>
      <form method="post"><?= csrf_field() ?><button class="btn btn-primary" name="do" value="subscribe">Subscribe with Square</button></form>
    <?php endif; ?>
    <?php if (($sub['status'] ?? '') === 'active' && $sub['square_subscription_id']): ?>
      <form method="post"><?= csrf_field() ?><button class="btn btn-outline-danger" name="do" value="cancel" data-confirm="Cancel your subscription? You keep access until the end of the paid period.">Cancel subscription</button></form>
    <?php endif; ?>
  </div>
  <p class="small text-secondary mt-3 mb-0">Card details are entered on Square's secure checkout page and never stored here.</p>
</div></div>
<?php require __DIR__ . '/_bottom.php';
