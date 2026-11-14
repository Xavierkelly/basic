<?php
// Creates the tables and loads tax tables: php bin/install.php  (safe to re-run)
declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

foreach (['schema.sql', 'tax_2026.sql'] as $f) {
    $sql = preg_replace('/^\s*--.*$/m', '', file_get_contents(APP_ROOT . "/sql/$f"));
    foreach (preg_split('/;\s*(\n|$)/', $sql) as $stmt) {
        if (trim($stmt) !== '') {
            Db::pdo()->exec($stmt);
        }
    }
    echo "Loaded $f\n";
}
