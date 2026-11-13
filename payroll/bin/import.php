<?php
// Command-line import: php bin/import.php <company_id> <file.xlsx>
declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

[$_, $companyId, $file] = $argv + [null, null, null];
$co = Db::one('SELECT * FROM companies WHERE id = ?', [(int) $companyId]);
if (!$co || !$file || !is_file($file)) {
    fwrite(STDERR, "Usage: php bin/import.php <company_id> <file.xlsx>\n");
    exit(1);
}
foreach ((new Importer($co, new XlsxReader($file)))->run() as $line) {
    echo $line, "\n";
}
