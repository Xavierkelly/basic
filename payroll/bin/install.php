<?php
// Creates the tables and loads tax tables: php bin/install.php  (safe to re-run)
declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

Setup::installSchema(Db::pdo(), fn (string $m) => print("$m\n"));
