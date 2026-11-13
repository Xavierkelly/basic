<?php
// Plain PHP test runner: php tests/run.php
declare(strict_types=1);

require __DIR__ . '/../src/helpers.php';
require __DIR__ . '/../src/TaxEngine.php';

$fail = 0;
$pass = 0;
function check(bool $ok, string $msg): void
{
    global $fail, $pass;
    if ($ok) {
        $pass++;
    } else {
        $fail++;
        echo "FAIL: $msg\n";
    }
}

foreach (glob(__DIR__ . '/*Test.php') as $file) {
    require $file;
}

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
