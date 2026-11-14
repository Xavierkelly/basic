<?php
declare(strict_types=1);

/**
 * Imports the Kelly Rentals-style payroll workbook: Tax Settings (employees + W-4 data) and every weekly tab.
 * Historical amounts are imported exactly as they are in the sheet (not recalculated). Paid lines are locked.
 * Running it again skips weeks that already exist, so it is safe to repeat.
 */
final class Importer
{
    private array $log = [];
    private array $empByName = [];
    private ?string $effective = null;

    public function __construct(private array $company, private XlsxReader $x)
    {
    }

    public static function norm(string $name): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/', ' ', $name)));
    }

    /** Upper-case the first letter of each word, leaving the rest alone ("annislay matos" → "Annislay Matos", "McKay" stays). */
    public static function capitalize(string $s): string
    {
        return implode(' ', array_map(fn($w) => mb_strtoupper(mb_substr($w, 0, 1)) . mb_substr($w, 1), explode(' ', $s)));
    }

    public function run(): array
    {
        $cid = (int) $this->company['id'];
        foreach (Db::all('SELECT * FROM employees WHERE company_id = ?', [$cid]) as $e) {
            $this->empByName[self::norm(employee_name($e))] = $e;
        }
        Db::tx(function () {
            if (in_array('Tax Settings', $this->x->sheetNames(), true)) {
                $this->importTaxSettings();
            }
            $weeks = 0;
            foreach ($this->x->sheetNames() as $name) {
                if ($name === 'Payroll Period Template') {
                    continue;
                }
                $rows = $this->x->rows($name, 1200);
                if (($rows[2]['A'] ?? null) === 'Status' && ($rows[2]['B'] ?? null) === 'Employee') {
                    $weeks += $this->importWeek($name, $rows) ? 1 : 0;
                }
            }
            $this->log[] = "Imported $weeks weekly tabs.";
        });
        return $this->log;
    }

    private function header(array $row): array
    {
        $map = [];
        foreach ($row as $col => $label) {
            $map[trim((string) $label)] = $col;
        }
        return $map;
    }

    private function bool(mixed $v): bool
    {
        return $v === true || $v === 1.0 || (is_string($v) && in_array(strtolower($v), ['true', 'yes', '1'], true));
    }

    private function employee(string $name, array $hint = []): array
    {
        $key = self::norm($name);
        if (isset($this->empByName[$key])) {
            return $this->empByName[$key];
        }
        $clean = trim(preg_replace('/\s+/', ' ', $name));
        $parts = explode(' ', $clean, 2);
        $row = [
            'company_id' => $this->company['id'],
            'first_name' => self::capitalize($parts[0]),
            'last_name' => isset($parts[1]) ? self::capitalize($parts[1]) : '',
            'worker_type' => ($hint['worker_type'] ?? 'W2') === '1099' ? '1099' : 'W2',
            'default_branch_id' => $hint['branch_id'] ?? null,
            'default_department_id' => $hint['department_id'] ?? null,
            'pay_rate' => $hint['rate'] ?? 0,
        ] + $this->contact($hint['contact'] ?? null);
        if (!empty($hint['phone'])) {
            $row['phone'] = mb_substr((string) $hint['phone'], 0, 40);
        }
        $row['id'] = Db::insert('employees', $row);
        Db::insert('employee_tax_profiles', ['employee_id' => $row['id']]);
        $this->empByName[$key] = $row + ['email' => $row['email'] ?? null, 'payout_handle' => $row['payout_handle'] ?? null];
        return $this->empByName[$key];
    }

    /** The sheet's Email column often holds payout details like "Zelle: 214…". */
    private function contact(mixed $v): array
    {
        $v = trim((string) $v);
        if ($v === '') {
            return [];
        }
        if (filter_var($v, FILTER_VALIDATE_EMAIL)) {
            return ['email' => $v];
        }
        $method = null;
        if (stripos($v, 'zelle') !== false) {
            $method = 'Zelle';
        } elseif (stripos($v, 'cash app') !== false || stripos($v, 'cashapp') !== false || str_starts_with($v, '$')) {
            $method = 'Cash App';
        }
        return ['payout_handle' => mb_substr($v, 0, 120), 'payout_method' => $method];
    }

    private function importTaxSettings(): void
    {
        $cid = (int) $this->company['id'];
        $rows = $this->x->rows('Tax Settings', 200);
        $this->effective = XlsxReader::date($rows[5]['L'] ?? null);
        $companyUpdate = [];
        if ($this->effective) {
            $companyUpdate['ytd_start_date'] = $this->effective;
        }
        if (isset($rows[8]['B']) && is_float($rows[8]['B'])) {
            $companyUpdate['rate_1099_withholding'] = $rows[8]['B'];
        }
        if (isset($rows[9]['B']) && is_float($rows[9]['B']) && $rows[9]['B'] > 0) {
            $companyUpdate['periods_per_year'] = (int) $rows[9]['B'];
        }
        if ($companyUpdate) {
            Db::update('companies', $companyUpdate, ['id' => $cid]);
            $this->company = $companyUpdate + $this->company;
        }
        $h = $this->header($rows[11] ?? []);
        $g = fn(array $r, string $label) => isset($h[$label]) ? ($r[$h[$label]] ?? null) : null;
        $n = 0;
        foreach ($rows as $rn => $r) {
            if ($rn < 12 || empty($r['A']) || !is_string($r['A'])) {
                continue;
            }
            $existing = isset($this->empByName[self::norm($r['A'])]);
            $emp = $this->employee($r['A'], [
                'worker_type' => (string) $g($r, 'Worker Type'),
                'branch_id' => Tags::findOrCreate($cid, 'branch', (string) $g($r, 'Branch Location')),
            ]);
            if ($existing) {
                continue; // a second row for the same person at another branch; first row's W-4 data wins
            }
            $year = $this->effective ? (int) substr($this->effective, 0, 4) : (int) date('Y');
            $num = fn($v) => is_float($v) ? $v : 0.0;
            $intOrNull = fn($v) => is_float($v) ? (int) $v : null;
            $filing = (string) $g($r, 'Filing status');
            Db::update('employee_tax_profiles', [
                'w4_entered' => $this->bool($g($r, 'W-4 entered?')) ? 1 : 0,
                'filing_status' => in_array($filing, ['Single', 'Married filing jointly', 'Head of household'], true) ? $filing : 'Single',
                'multiple_jobs' => $this->bool($g($r, 'Multiple jobs (2c)')) ? 1 : 0,
                'other_income' => $num($g($r, 'Other income / year (4a)')),
                'deductions' => $num($g($r, 'Deductions / year (4b)')),
                'extra_withholding' => $num($g($r, 'Extra federal / check (4c)')),
                'federal_exempt' => $this->bool($g($r, 'Federal exempt')) ? 1 : 0,
                'children_under_17' => $intOrNull($g($r, 'Qualifying Children Under 17')),
                'other_dependents' => $intOrNull($g($r, 'Other Dependents')),
                'prior_annual_credit' => $num($g($r, 'Prior Annual Credit')),
                'credit_override' => is_float($g($r, 'W-4 annual credit override')) ? $g($r, 'W-4 annual credit override') : null,
                'tax_state' => isset(TaxEngine::STATES[(string) $g($r, 'Tax state')]) ? (string) $g($r, 'Tax state') : 'TX',
                'il_allowances_1' => (int) $num($g($r, 'IL allowances line 1')),
                'il_allowances_2' => (int) $num($g($r, 'IL allowances line 2')),
                'il_extra' => $num($g($r, 'Extra IL / check')),
                'il_exempt' => $this->bool($g($r, 'IL exempt')) ? 1 : 0,
                'ytd_prior_year' => $year,
                'ytd_prior_ss_wages' => $num($r[$this->col($h, 'SS wages before')] ?? null),
                'ytd_prior_medicare_wages' => $num($r[$this->col($h, 'Medicare wages before')] ?? null),
                'ytd_verified' => $this->bool($g($r, 'YTD wages verified?')) ? 1 : 0,
                'prior_gross_before_app' => $num($g($r, 'Prior Gross Before Workbook')),
            ], ['employee_id' => $emp['id']]);
            $n++;
        }
        $this->log[] = "Imported tax settings for $n employees.";
    }

    /** Column letter whose header starts with $prefix (the sheet puts dates in some header names). */
    private function col(array $h, string $prefix): string
    {
        foreach ($h as $label => $col) {
            if (str_starts_with($label, $prefix)) {
                return $col;
            }
        }
        return '-';
    }

    private function periodDates(string $tab, array $rows): ?array
    {
        $pay = XlsxReader::date($rows[1]['V'] ?? null);
        $start = (($rows[1]['A'] ?? '') === 'Period Start') ? XlsxReader::date($rows[1]['B'] ?? null) : null;
        if (!$start) {
            $months = ['jan' => 1, 'feb' => 2, 'mar' => 3, 'apr' => 4, 'may' => 5, 'jun' => 6, 'jul' => 7, 'aug' => 8, 'sep' => 9, 'oct' => 10, 'nov' => 11, 'dec' => 12];
            if (!preg_match('/^\s*([A-Za-z]+)\.?\s*(\d{1,2})/', $tab, $m) || !isset($months[strtolower(substr($m[1], 0, 3))])) {
                return null;
            }
            $year = $pay ? (int) substr($pay, 0, 4) : ($this->effective ? (int) substr($this->effective, 0, 4) : (int) date('Y'));
            $start = sprintf('%04d-%02d-%02d', $year, $months[strtolower(substr($m[1], 0, 3))], (int) $m[2]);
        }
        $end = (new DateTimeImmutable($start))->modify('+6 days')->format('Y-m-d');
        if ($pay !== null && $pay <= $end) {
            // Tabs copied forward sometimes keep the previous week's pay date; fall back to the normal schedule.
            $this->log[] = "\"" . trim($tab) . "\" had pay date $pay, which is before the week ends; using the usual pay day instead.";
            $pay = null;
        }
        $pay ??= (new DateTimeImmutable($end))->modify('+' . (int) $this->company['pay_date_offset_days'] . ' days')->format('Y-m-d');
        return [$start, $end, $pay];
    }

    private function importWeek(string $tab, array $rows): bool
    {
        $cid = (int) $this->company['id'];
        $dates = $this->periodDates($tab, $rows);
        if (!$dates) {
            $this->log[] = "Skipped \"$tab\": could not tell its dates.";
            return false;
        }
        [$start, $end, $pay] = $dates;
        if (Db::value('SELECT id FROM pay_periods WHERE company_id = ? AND start_date = ?', [$cid, $start])) {
            $this->log[] = "Skipped \"$tab\": week of $start is already in the app.";
            return false;
        }
        $h = $this->header($rows[2]);
        $pick = function (array $r, array $labels) use ($h) {
            foreach ($labels as $l) {
                if (isset($h[$l]) && array_key_exists($h[$l], $r)) {
                    return $r[$h[$l]];
                }
            }
            return null;
        };
        $num = fn($v) => is_float($v) ? round($v, 2) : (is_string($v) && is_numeric($v) ? round((float) $v, 2) : 0.0);
        $pid = Db::insert('pay_periods', ['company_id' => $cid, 'start_date' => $start, 'end_date' => $end, 'pay_date' => $pay, 'imported' => 1, 'label' => trim($tab)]);
        $lines = 0;
        $modern = $this->effective && $pay >= $this->effective;
        foreach ($rows as $rn => $r) {
            $name = $rn >= 3 ? ($r[$h['Employee']] ?? null) : null;
            if (!is_string($name) || trim($name) === '') {
                continue;
            }
            $branchId = Tags::findOrCreate($cid, 'branch', (string) $pick($r, ['Branch Location']));
            $deptId = Tags::findOrCreate($cid, 'department', (string) $pick($r, ['Department']));
            $type = (string) $pick($r, ['Worker Type']) === '1099' ? '1099' : 'W2';
            $rate = $num($pick($r, ['Rate']));
            $emp = $this->employee($name, [
                'worker_type' => $type, 'branch_id' => $branchId, 'department_id' => $deptId, 'rate' => $rate,
                'contact' => $pick($r, ['Email']), 'phone' => $pick($r, ['Phone']),
            ]);
            // Keep the employee's defaults current with the latest week seen.
            $upd = array_filter(['default_department_id' => $deptId, 'default_branch_id' => $branchId, 'pay_rate' => $rate ?: null], fn($v) => $v !== null);
            $upd += array_filter($this->contact($pick($r, ['Email'])), fn($v) => $v !== null);
            if ($upd) {
                Db::update('employees', $upd, ['id' => $emp['id']]);
            }
            $statusRaw = strtolower(trim((string) $pick($r, ['Status'])));
            $status = $statusRaw === 'paid' ? 'paid' : ($statusRaw === 'did not work' ? 'did_not_work' : 'pending');
            $gross = $num($pick($r, ['Gross Pay', 'Total Pay']));
            $taxes = $num($pick($r, ['Taxes']));
            $parts = [
                'federal_tax' => $pick($r, ['Federal income tax']), 'ss_tax' => $pick($r, ['Social Security']),
                'medicare_tax' => $pick($r, ['Medicare']), 'addl_medicare_tax' => $pick($r, ['Additional Medicare']),
                'state_tax' => $pick($r, ['State income tax']),
            ];
            // The sheet's script snapshots a row when it is marked Paid ("Paid amounts frozen"); keep those exactly.
            $frozen = trim((string) $pick($r, ['Paid amounts frozen'])) !== '';
            $hasParts = $type === 'W2' && count(array_filter($parts, 'is_float')) === 5;
            $row = [
                'company_id' => $cid, 'pay_period_id' => $pid, 'employee_id' => $emp['id'], 'branch_id' => $branchId,
                'department_id' => $deptId, 'worker_type' => $type, 'status' => $status,
                'hours' => $num($pick($r, ['Hours'])), 'rate' => $rate,
                'hourly_pay' => $num($pick($r, ['Hourly Pay', 'Total Hourly Amount'])),
                'salary' => $num($pick($r, ['Salary'])), 'commissions' => $num($pick($r, ['Commissions'])),
                'gross' => $gross, 'taxes' => $taxes, 'deductions' => $num($pick($r, ['Deductions'])),
                'net' => $num($pick($r, ['Net Pay', 'After Tax Amount'])), 'sent' => $num($pick($r, ['Total Sent'])),
                'ss_wages' => $type === 'W2' ? (is_float($pick($r, ['SS taxable pay'])) ? $num($pick($r, ['SS taxable pay'])) : $gross) : 0,
                'medicare_wages' => $type === 'W2' ? (is_float($pick($r, ['Medicare taxable pay'])) ? $num($pick($r, ['Medicare taxable pay'])) : $gross) : 0,
                'notes' => mb_substr(trim((string) $pick($r, ['Notes'])), 0, 500) ?: null,
                'locked' => ($status === 'paid' || $frozen) ? 1 : 0,
                // Paid history keeps its exact numbers. Unpaid weeks after the switch to real withholding get recalculated.
                'manual_taxes' => ($status === 'paid' || $frozen || !$modern) ? 1 : 0,
                'calc_status' => $status === 'paid' ? 'Imported' : null,
                'paid_at' => $status === 'paid' ? $pay . ' 12:00:00' : null,
            ];
            if ($hasParts) {
                foreach ($parts as $k => $v) {
                    $row[$k] = round($v, 2);
                }
            }
            Db::insert('paychecks', $row);
            $lines++;
        }
        if ($modern) {
            Payroll::recalc($this->company, $pid);
        }
        $this->log[] = sprintf('"%s" → %s to %s, %d lines.', trim($tab), $start, $end, $lines);
        return true;
    }
}
