<?php
Auth::require('admin');
$co = Auth::company();
$log = null;
$error = null;
if (is_post()) {
    $f = $_FILES['workbook'] ?? null;
    if (!$f || $f['error'] !== UPLOAD_ERR_OK) {
        $error = 'Choose your .xlsx file first.';
    } elseif ($f['size'] > 20 * 1024 * 1024) {
        $error = 'That file is too large (20 MB max).';
    } elseif (strtolower(pathinfo($f['name'], PATHINFO_EXTENSION)) !== 'xlsx') {
        $error = 'Please upload an Excel .xlsx file (Google Sheets: File → Download → Microsoft Excel).';
    } else {
        try {
            $log = (new Importer($co, new XlsxReader($f['tmp_name'])))->run();
            audit('import', 'workbook', null, ['file' => $f['name']]);
        } catch (Throwable $e) {
            $error = 'Import failed, nothing was saved: ' . $e->getMessage();
        }
    }
}
$title = 'Import spreadsheet';
require __DIR__ . '/_top.php';
?>
<h1 class="h4">Import your payroll spreadsheet</h1>
<div class="card mb-3" style="max-width:720px"><div class="card-body">
  <p>Upload the workbook as an Excel file. In Google Sheets choose <strong>File → Download → Microsoft Excel (.xlsx)</strong>.</p>
  <ul class="small">
    <li><strong>Tax Settings</strong> becomes your employees, their W-2/1099 type, W-4 details and wages paid before Oct 16.</li>
    <li>Every weekly tab becomes a pay period. Paid lines are imported exactly as they are and locked.</li>
    <li>Branches and departments become tags. Zelle or Cash App details found in the Email column move to each employee's payout handle.</li>
    <li>Weeks already in the app are skipped, so you can run it again safely.</li>
  </ul>
  <?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>
  <form method="post" enctype="multipart/form-data" class="d-flex flex-wrap gap-2">
    <?= csrf_field() ?>
    <input type="file" name="workbook" accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" class="form-control" style="max-width:380px" required>
    <button class="btn btn-primary" data-busy="Importing…">Import</button>
  </form>
</div></div>
<?php if ($log): ?>
  <div class="alert alert-success"><strong>Done.</strong> Review your <a href="<?= e(url('employees')) ?>">employees</a> and <a href="<?= e(url('periods')) ?>">pay periods</a>.</div>
  <ul class="small"><?php foreach ($log as $l): ?><li><?= e($l) ?></li><?php endforeach; ?></ul>
<?php endif;
require __DIR__ . '/_bottom.php';
