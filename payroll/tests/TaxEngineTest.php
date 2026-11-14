<?php
// Checks the tax engine against every W-2 check paid from the workbook's Oct 5 – Dec 6 2026 tabs.
// Fixtures are anonymized: employee names are replaced with E1, E2, ...

/** Load 2026 brackets/constants from the SQL seed file so tests need no database. */
function engine_from_seed(string $sqlFile, int $periods): TaxEngine
{
    $sql = file_get_contents($sqlFile);
    $br = [];
    preg_match_all("/\(2026,'([^']+)',([\d.]+),([\d.]+),([\d.]+)\)/", $sql, $m, PREG_SET_ORDER);
    foreach ($m as $r) {
        $br[$r[1]][] = [(float) $r[2], (float) $r[3], (float) $r[4]];
    }
    $c = [];
    preg_match_all("/\(2026,'([a-z0-9_]+)',([\d.]+)\)/", $sql, $m, PREG_SET_ORDER);
    foreach ($m as $r) {
        $c[$r[1]] = (float) $r[2];
    }
    return new TaxEngine($br, $c, $periods);
}

$engine = engine_from_seed(__DIR__ . '/../sql/tax_2026.sql', 52);
$cases = json_decode(file_get_contents(__DIR__ . '/fixtures_withholding.json'), true);
check(count($cases) > 100, 'fixtures loaded');

// "Live" rows are still formulas in the sheet, so they reflect the employee's current W-4 and must match exactly.
// "Frozen" rows were snapshotted when marked Paid. We replay them with the credit in force at the time; a few can still
// differ when an employee's two lines on one tab were paid at different times, so we require nearly all to match.
$frozenTotal = 0;
$frozenOk = 0;
foreach ($cases as $case) {
    $p = $case['profile'] + TaxEngine::defaultProfile();
    if ($case['frozen']) {
        // Use the W-4 credit that applied when the check was paid (dependents were added later for some people).
        if ($p['w4_entered']) {
            $p['credit_override'] = (float) $case['credit_T'];
        }
        $lines = array_map(fn($i, $l) => ['key' => $i, 'wages' => (float) $l['wages']['fed']], array_keys($case['lines']), $case['lines']);
        $got = $engine->w2($lines, $p, (float) $case['ytd_ss'], (float) $case['ytd_med']);
        foreach ($case['lines'] as $i => $l) {
            $frozenTotal++;
            $frozenOk += abs($got[$i]['total'] - (float) $l['expect']['taxes']) < 0.005 ? 1 : 0;
        }
        continue;
    }
    check(abs($engine->annualCredit($p) * ($p['w4_entered'] ? 1 : 0) - (float) $case['credit_T']) < 0.005, "{$case['case']}: W-4 step 3 credit");
    $lines = [];
    foreach ($case['lines'] as $i => $l) {
        $lines[] = ['key' => $i, 'wages' => (float) $l['wages']['fed']];
    }
    $got = $engine->w2($lines, $p, (float) $case['ytd_ss'], (float) $case['ytd_med']);
    foreach ($case['lines'] as $i => $l) {
        foreach (['federal', 'ss', 'medicare', 'addl_medicare', 'state'] as $k) {
            $want = (float) $l['expect'][$k];
            check(abs($got[$i][$k] - $want) < 0.005, sprintf('%s line %d %s: got %.2f want %.2f', $case['case'], $i + 1, $k, $got[$i][$k], $want));
        }
        check(abs($got[$i]['total'] - (float) $l['expect']['taxes']) < 0.005, sprintf('%s line %d total: got %.2f want %.2f', $case['case'], $i + 1, $got[$i]['total'], $l['expect']['taxes']));
    }
}

echo "Paid snapshot checks reproduced: $frozenOk of $frozenTotal\n";
check($frozenOk / max(1, $frozenTotal) > 0.9, 'nearly all paid snapshots reproduce');

// Hand-checked scenarios beyond the workbook data.
$base = TaxEngine::defaultProfile();
// Social Security stops at the wage cap.
$r = $engine->w2([['key' => 0, 'wages' => 1000.0]], $base, 184000.0, 184000.0);
check($r[0]['ss'] == 31.0, 'SS only on the $500 left under the cap');
// Additional Medicare kicks in above $200k YTD.
$r = $engine->w2([['key' => 0, 'wages' => 1000.0]], $base, 199500.0, 199500.0);
check($r[0]['addl_medicare'] == 4.5, 'Additional Medicare on $500 over threshold');
// Illinois with one basic allowance.
$il = ['tax_state' => 'IL', 'il_allowances_1' => 1] + $base;
$r = $engine->w2([['key' => 0, 'wages' => 1000.0]], $il, 0, 0);
check($r[0]['state'] == round((1000 - 2925 / 52) * 0.0495, 2), 'IL withholding after allowance');
// Exempt employee with a W-4 pays no federal income tax.
$r = $engine->w2([['key' => 0, 'wages' => 1000.0]], ['w4_entered' => 1, 'federal_exempt' => 1] + $base, 0, 0);
check($r[0]['federal'] == 0.0, 'Federal exempt');
// 1099 withholding.
check(TaxEngine::contractor(1000.0, 0.24) == 240.0, '1099 backup withholding');
// Unsupported state is an error, not silent zero.
try {
    $engine->w2([['key' => 0, 'wages' => 100.0]], ['tax_state' => 'CA'] + $base, 0, 0);
    check(false, 'CA should throw');
} catch (RuntimeException) {
    check(true, 'CA throws');
}
