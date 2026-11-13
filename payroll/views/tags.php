<?php
Auth::require('admin');
$cid = Auth::companyId();
if (is_post()) {
    $kind = post('kind') === 'department' ? 'department' : 'branch';
    if (post('do') === 'add') {
        Tags::findOrCreate($cid, $kind, (string) post('name'));
        flash('Added.');
    } elseif (post('do') === 'rename') {
        $name = trim((string) post('name'));
        if ($name !== '') {
            try {
                Db::exec('UPDATE tags SET name = ? WHERE id = ? AND company_id = ?', [$name, (int) post('id'), $cid]);
                flash('Renamed.');
            } catch (PDOException) {
                flash('That name is already used.', 'danger');
            }
        }
    } elseif (post('do') === 'delete') {
        $id = (int) post('id');
        Db::tx(function () use ($id, $cid) {
            Db::exec('UPDATE employees SET default_branch_id = NULL WHERE default_branch_id = ? AND company_id = ?', [$id, $cid]);
            Db::exec('UPDATE employees SET default_department_id = NULL WHERE default_department_id = ? AND company_id = ?', [$id, $cid]);
            Db::exec('DELETE FROM tags WHERE id = ? AND company_id = ?', [$id, $cid]);
        });
        flash('Removed. Past paychecks keep their history.');
    }
    redirect('tags');
}
$title = 'Branches & departments';
require __DIR__ . '/_top.php';
?>
<h1 class="h4 mb-1">Branches &amp; departments</h1>
<p class="text-secondary">These are simple tags for filtering reports and pay stubs.</p>
<div class="row g-3">
<?php foreach (['branch' => 'Branches', 'department' => 'Departments'] as $kind => $label):
    $tags = Tags::list($cid, $kind); ?>
  <div class="col-12 col-md-6"><div class="card"><div class="card-body">
    <h2 class="h6 text-secondary text-uppercase"><?= $label ?></h2>
    <?php foreach ($tags as $id => $name): ?>
      <form method="post" class="d-flex gap-2 mb-2">
        <?= csrf_field() ?><input type="hidden" name="kind" value="<?= $kind ?>"><input type="hidden" name="id" value="<?= $id ?>">
        <input name="name" class="form-control form-control-sm" value="<?= e($name) ?>" aria-label="Name">
        <button class="btn btn-sm btn-outline-primary" name="do" value="rename">Save</button>
        <button class="btn btn-sm btn-outline-danger" name="do" value="delete" data-confirm="Remove <?= e($name) ?>?">Remove</button>
      </form>
    <?php endforeach; ?>
    <form method="post" class="d-flex gap-2 mt-3">
      <?= csrf_field() ?><input type="hidden" name="kind" value="<?= $kind ?>">
      <input name="name" class="form-control form-control-sm" placeholder="New <?= $kind ?>" required>
      <button class="btn btn-sm btn-primary" name="do" value="add">Add</button>
    </form>
  </div></div></div>
<?php endforeach; ?>
</div>
<?php require __DIR__ . '/_bottom.php';
