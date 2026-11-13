<?php
declare(strict_types=1);

/**
 * Employee withholding, reproducing the Kelly Rentals payroll workbook (Oct 2026 onward):
 *  - Federal: IRS Pub. 15-T Worksheet 1A (annual percentage method, 2020+ Form W-4).
 *  - Social Security 6.2% up to the annual wage cap; Medicare 1.45%; Additional Medicare 0.9% over $200k YTD.
 *  - State: TX (none), IL (4.95% after allowances), NONE (no state income tax).
 *  - Several lines for the same employee and pay date are combined before tax, then the tax
 *    is split back across the lines to the cent, the same way the sheet does it.
 */
final class TaxEngine
{
    public const STATES = ['TX' => 'Texas (no state income tax)', 'IL' => 'Illinois', 'NONE' => 'Other state with no income tax'];

    /** @param array<string, list<array{0: float, 1: float, 2: float}>> $brackets schedule => [[lower, base, rate], ...] ascending */
    public function __construct(
        private array $brackets,
        private array $c,
        private int $periods
    ) {
    }

    public static function forYear(int $year, int $periods): self
    {
        $rows = Db::all('SELECT schedule, lower_bound, base_tax, rate FROM tax_brackets WHERE tax_year = ? ORDER BY schedule, lower_bound', [$year]);
        if (!$rows) {
            throw new RuntimeException("No federal tax tables are loaded for $year.");
        }
        $br = [];
        foreach ($rows as $r) {
            $br[$r['schedule']][] = [(float) $r['lower_bound'], (float) $r['base_tax'], (float) $r['rate']];
        }
        $c = [];
        foreach (Db::all('SELECT name, value FROM tax_constants WHERE tax_year = ?', [$year]) as $r) {
            $c[$r['name']] = (float) $r['value'];
        }
        return new self($br, $c, $periods);
    }

    public static function defaultProfile(): array
    {
        return [
            'w4_entered' => 0, 'filing_status' => 'Single', 'multiple_jobs' => 0, 'other_income' => 0, 'deductions' => 0,
            'extra_withholding' => 0, 'federal_exempt' => 0, 'children_under_17' => null, 'other_dependents' => null,
            'prior_annual_credit' => 0, 'credit_override' => null, 'tax_state' => 'TX', 'il_allowances_1' => 0,
            'il_allowances_2' => 0, 'il_extra' => 0, 'il_exempt' => 0,
        ];
    }

    /** W-4 Step 3 annual credit (Tax Settings column M). */
    public function annualCredit(array $p): float
    {
        if ($p['credit_override'] !== null && $p['credit_override'] !== '') {
            return (float) $p['credit_override'];
        }
        $kids = $p['children_under_17'];
        $other = $p['other_dependents'];
        if (($kids === null || $kids === '') && ($other === null || $other === '')) {
            return (float) $p['prior_annual_credit'];
        }
        return (float) $kids * $this->c['child_credit'] + (float) $other * $this->c['other_dependent_credit'];
    }

    /** Annual federal tax for the employee's combined wages for one pay date. */
    public function federalForCheck(float $wages, array $p): float
    {
        $hasForm = (bool) $p['w4_entered'];
        if ($hasForm && $p['federal_exempt']) {
            return 0.0;
        }
        $filing = $hasForm ? $p['filing_status'] : 'Single';
        $multi = $hasForm && $p['multiple_jobs'];
        $std = $multi ? 0.0 : ($filing === 'Married filing jointly' ? $this->c['std_allowance_mfj'] : $this->c['std_allowance_single']);
        $annual = max(0.0, $wages * $this->periods
            + ($hasForm ? (float) $p['other_income'] - (float) $p['deductions'] : 0.0)
            - $std);
        $schedule = $filing . '|' . ($multi ? 'Multiple' : 'Standard');
        $rows = $this->brackets[$schedule] ?? null;
        if (!$rows) {
            throw new RuntimeException("Missing tax table for $schedule.");
        }
        $row = $rows[0];
        foreach ($rows as $r) {
            if ($annual >= $r[0]) {
                $row = $r;
            }
        }
        $annualTax = $row[1] + ($annual - $row[0]) * $row[2];
        $credit = $hasForm ? $this->annualCredit($p) : 0.0;
        return round2(max(0.0, ($annualTax - $credit) / $this->periods) + ($hasForm ? (float) $p['extra_withholding'] : 0.0));
    }

    public function stateForCheck(float $wages, array $p): float
    {
        return match ($p['tax_state']) {
            'TX', 'NONE' => 0.0,
            'IL' => $p['il_exempt'] ? 0.0 : round2(
                max(0.0, $wages - ((int) $p['il_allowances_1'] * $this->c['il_allowance_1'] + (int) $p['il_allowances_2'] * $this->c['il_allowance_2']) / $this->periods)
                * $this->c['il_rate'] + (float) $p['il_extra']
            ),
            default => throw new RuntimeException('State withholding for ' . $p['tax_state'] . ' is not supported yet.'),
        };
    }

    /**
     * Withholding for one W-2 employee and one pay date.
     *
     * @param list<array{key: mixed, wages: float}> $lines taxable wages per line, in display order
     * @param float $ytdSs   Social Security wages already paid this year, before this pay date
     * @param float $ytdMed  Medicare wages already paid this year, before this pay date
     * @return array<int|string, array{federal: float, ss: float, medicare: float, addl_medicare: float, state: float, total: float}>
     */
    public function w2(array $lines, array $p, float $ytdSs, float $ytdMed): array
    {
        $wages = 0.0;
        foreach ($lines as $l) {
            $wages += $l['wages'];
        }
        $totals = [
            'federal' => $this->federalForCheck($wages, $p),
            'ss' => round2(min($wages, max(0.0, $this->c['ss_wage_cap'] - $ytdSs)) * $this->c['ss_rate']),
            'medicare' => round2($wages * $this->c['medicare_rate']),
            'addl_medicare' => round2((max(0.0, $ytdMed + $wages - $this->c['addl_medicare_threshold']) - max(0.0, $ytdMed - $this->c['addl_medicare_threshold'])) * $this->c['addl_medicare_rate']),
            'state' => $this->stateForCheck($wages, $p),
        ];
        $out = [];
        $cum = 0.0;
        foreach ($lines as $l) {
            $prev = $cum;
            $cum += $l['wages'];
            $row = [];
            foreach ($totals as $k => $tax) {
                $row[$k] = $wages == 0.0 ? 0.0 : round2(round2($tax * $cum / $wages) - round2($tax * $prev / $wages));
            }
            $row['total'] = round2(array_sum($row));
            $out[$l['key']] = $row;
        }
        return $out;
    }

    public static function contractor(float $gross, float $rate): float
    {
        return round2($gross * $rate);
    }

    /** Human-readable setup status, like the sheet's "Calculation status" column. */
    public static function setupStatus(string $workerType, ?array $p): string
    {
        if ($workerType === '1099') {
            return '1099';
        }
        if (!$p) {
            return 'Add tax settings';
        }
        return ($p['w4_entered'] ? 'W-4 entered' : 'Single default; W-4 missing') . (!empty($p['ytd_verified']) ? '' : '; verify YTD');
    }
}
