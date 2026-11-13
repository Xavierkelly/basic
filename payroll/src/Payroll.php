<?php
declare(strict_types=1);

final class Payroll
{
    /** Start/end/pay dates of the pay period containing $date (Y-m-d), using the company's week start and pay delay. */
    public static function periodFor(array $company, string $date): array
    {
        $d = new DateTimeImmutable($date);
        $back = ((int) $d->format('N') - (int) $company['period_start_weekday'] + 7) % 7;
        $start = $d->modify("-$back days");
        $end = $start->modify('+6 days');
        $pay = $end->modify('+' . (int) $company['pay_date_offset_days'] . ' days');
        return ['start' => $start->format('Y-m-d'), 'end' => $end->format('Y-m-d'), 'pay' => $pay->format('Y-m-d')];
    }

    public static function effectiveMode(array $company, array $emp): string
    {
        return $emp['time_entry_mode'] ?: $company['time_entry_mode'];
    }

    public static function overtimeOn(array $company, array $emp): bool
    {
        return $emp['overtime_enabled'] === null ? (bool) $company['overtime_enabled'] : (bool) $emp['overtime_enabled'];
    }

    /** UTC bounds for local calendar dates [start 00:00, end+1 00:00). */
    public static function utcRange(string $tz, string $start, string $end): array
    {
        $z = new DateTimeZone($tz);
        $utc = new DateTimeZone('UTC');
        $a = (new DateTime($start . ' 00:00:00', $z))->setTimezone($utc)->format('Y-m-d H:i:s');
        $b = (new DateTime($end . ' 00:00:00', $z))->modify('+1 day')->setTimezone($utc)->format('Y-m-d H:i:s');
        return [$a, $b];
    }

    public static function clockHours(array $company, int $employeeId, string $start, string $end): float
    {
        [$a, $b] = self::utcRange($company['timezone'], $start, $end);
        $sec = (int) Db::value(
            'SELECT COALESCE(SUM(TIMESTAMPDIFF(SECOND, clock_in, clock_out)), 0) FROM time_entries
             WHERE company_id = ? AND employee_id = ? AND clock_out IS NOT NULL AND clock_in >= ? AND clock_in < ?',
            [$company['id'], $employeeId, $a, $b]
        );
        return round($sec / 3600, 2);
    }

    public static function createPeriod(array $company, string $anyDate): int
    {
        $p = self::periodFor($company, $anyDate);
        $existing = Db::value('SELECT id FROM pay_periods WHERE company_id = ? AND start_date = ?', [$company['id'], $p['start']]);
        if ($existing) {
            return (int) $existing;
        }
        return Db::tx(function () use ($company, $p) {
            $pid = Db::insert('pay_periods', [
                'company_id' => $company['id'], 'start_date' => $p['start'], 'end_date' => $p['end'], 'pay_date' => $p['pay'],
            ]);
            foreach (Db::all('SELECT * FROM employees WHERE company_id = ? AND active = 1 ORDER BY first_name, last_name', [$company['id']]) as $emp) {
                self::addLine($company, $pid, $emp, $p);
            }
            self::recalc($company, $pid);
            return $pid;
        });
    }

    public static function addLine(array $company, int $periodId, array $emp, ?array $period = null): int
    {
        $period ??= Db::one('SELECT start_date AS start, end_date AS end FROM pay_periods WHERE id = ?', [$periodId]);
        $hours = self::effectiveMode($company, $emp) === 'clock'
            ? self::clockHours($company, (int) $emp['id'], $period['start'], $period['end'])
            : 0.0;
        return Db::insert('paychecks', [
            'company_id' => $company['id'], 'pay_period_id' => $periodId, 'employee_id' => $emp['id'],
            'branch_id' => $emp['default_branch_id'], 'department_id' => $emp['default_department_id'],
            'worker_type' => $emp['worker_type'], 'hours' => $hours, 'rate' => $emp['pay_rate'],
            'salary' => $emp['weekly_salary'], 'payout_method' => $emp['payout_method'],
        ]);
    }

    /** Pull clocked hours again for clock-mode employees on unlocked lines. */
    public static function refreshClockHours(array $company, int $periodId): int
    {
        $period = Db::one('SELECT * FROM pay_periods WHERE id = ? AND company_id = ?', [$periodId, $company['id']]);
        $n = 0;
        $rows = Db::all(
            'SELECT pc.id, e.* , pc.id AS pc_id FROM paychecks pc JOIN employees e ON e.id = pc.employee_id
             WHERE pc.pay_period_id = ? AND pc.company_id = ? AND pc.locked = 0',
            [$periodId, $company['id']]
        );
        $seen = [];
        foreach ($rows as $r) {
            if (self::effectiveMode($company, $r) !== 'clock' || isset($seen[$r['id']])) {
                continue; // only the first line per employee receives clocked hours
            }
            $seen[$r['id']] = true;
            $h = self::clockHours($company, (int) $r['id'], $period['start_date'], $period['end_date']);
            $n += Db::exec('UPDATE paychecks SET hours = ? WHERE id = ?', [$h, $r['pc_id']]);
        }
        self::recalc($company, $periodId);
        return $n;
    }

    /** Year-to-date SS and Medicare wages paid before $payDate (same calendar year). */
    public static function ytdWages(array $company, int $employeeId, string $payDate, ?array $profile): array
    {
        $year = (int) substr($payDate, 0, 4);
        $from = $company['ytd_start_date'] && (int) substr($company['ytd_start_date'], 0, 4) === $year
            ? $company['ytd_start_date'] : "$year-01-01";
        $row = Db::one(
            "SELECT COALESCE(SUM(pc.ss_wages),0) ss, COALESCE(SUM(pc.medicare_wages),0) med
             FROM paychecks pc JOIN pay_periods pp ON pp.id = pc.pay_period_id
             WHERE pc.company_id = ? AND pc.employee_id = ? AND pc.status = 'paid' AND pc.worker_type = 'W2'
               AND pp.pay_date >= ? AND pp.pay_date < ?",
            [$company['id'], $employeeId, $from, $payDate]
        );
        $ss = (float) $row['ss'];
        $med = (float) $row['med'];
        if ($profile && (int) $profile['ytd_prior_year'] === $year) {
            $ss += (float) $profile['ytd_prior_ss_wages'];
            $med += (float) $profile['ytd_prior_medicare_wages'];
        }
        return [$ss, $med];
    }

    /** Recalculate pay, taxes and net for every unlocked line in a period. */
    public static function recalc(array $company, int $periodId): void
    {
        $period = Db::one('SELECT * FROM pay_periods WHERE id = ? AND company_id = ?', [$periodId, $company['id']]);
        if (!$period) {
            throw new RuntimeException('Pay period not found.');
        }
        $lines = Db::all(
            'SELECT pc.*, e.overtime_enabled AS emp_ot FROM paychecks pc JOIN employees e ON e.id = pc.employee_id
             WHERE pc.pay_period_id = ? ORDER BY pc.id',
            [$periodId]
        );
        $year = (int) substr($period['pay_date'], 0, 4);
        $engine = null;
        $engineError = null;
        try {
            $engine = TaxEngine::forYear($year, (int) $company['periods_per_year']);
        } catch (RuntimeException $e) {
            $engineError = $e->getMessage();
        }

        // 1) pay amounts for unlocked lines
        $updates = [];
        foreach ($lines as &$l) {
            if ($l['locked']) {
                continue;
            }
            $hours = (float) $l['hours'];
            $rate = (float) $l['rate'];
            $otOn = $l['emp_ot'] === null ? (bool) $company['overtime_enabled'] : (bool) $l['emp_ot'];
            $thr = (float) $company['overtime_threshold_hours'];
            $ot = $otOn ? max(0.0, $hours - $thr) : 0.0;
            $reg = $hours - $ot;
            $l['ot_hours'] = $ot;
            $l['hourly_pay'] = round2($reg * $rate);
            $l['ot_pay'] = round2($ot * $rate * (float) $company['overtime_multiplier']);
            $l['gross'] = round2($l['hourly_pay'] + $l['ot_pay'] + (float) $l['salary'] + (float) $l['commissions']);
            $taxable = max(0.0, round2((float) $l['gross'] - (float) $l['pretax_deductions']));
            $l['ss_wages'] = $l['worker_type'] === 'W2' ? $taxable : 0.0;
            $l['medicare_wages'] = $l['ss_wages'];
            $l['_taxable'] = $taxable;
        }
        unset($l);

        // 2) taxes, grouped by employee (all lines on the same pay date are combined)
        $byEmp = [];
        foreach ($lines as $i => $l) {
            $byEmp[$l['employee_id']][] = $i;
        }
        foreach ($byEmp as $empId => $idxs) {
            $first = $lines[$idxs[0]];
            if ($first['worker_type'] === '1099') {
                foreach ($idxs as $i) {
                    if ($lines[$i]['locked'] || $lines[$i]['manual_taxes']) {
                        continue;
                    }
                    $lines[$i]['federal_tax'] = TaxEngine::contractor((float) $lines[$i]['gross'], (float) $company['rate_1099_withholding']);
                    $lines[$i]['ss_tax'] = $lines[$i]['medicare_tax'] = $lines[$i]['addl_medicare_tax'] = $lines[$i]['state_tax'] = 0;
                    $lines[$i]['taxes'] = $lines[$i]['federal_tax'];
                    $lines[$i]['calc_status'] = '1099';
                }
                continue;
            }
            $profile = Db::one('SELECT * FROM employee_tax_profiles WHERE employee_id = ?', [$empId]);
            $status = TaxEngine::setupStatus('W2', $profile);
            $result = null;
            $err = $engineError;
            if (!$err) {
                try {
                    [$ytdSs, $ytdMed] = self::ytdWages($company, (int) $empId, $period['pay_date'], $profile);
                    $in = [];
                    foreach ($idxs as $i) {
                        $w = $lines[$i]['locked'] ? (float) $lines[$i]['ss_wages'] : $lines[$i]['_taxable'];
                        $in[] = ['key' => $i, 'wages' => $w];
                    }
                    $result = $engine->w2($in, $profile ?: TaxEngine::defaultProfile(), $ytdSs, $ytdMed);
                } catch (RuntimeException $e) {
                    $err = $e->getMessage();
                }
            }
            foreach ($idxs as $i) {
                if ($lines[$i]['locked'] || $lines[$i]['manual_taxes']) {
                    continue;
                }
                if ($err) {
                    $lines[$i]['federal_tax'] = $lines[$i]['ss_tax'] = $lines[$i]['medicare_tax'] = $lines[$i]['addl_medicare_tax'] = $lines[$i]['state_tax'] = 0;
                    $lines[$i]['taxes'] = 0;
                    $lines[$i]['calc_status'] = 'ERROR: ' . $err;
                    continue;
                }
                $r = $result[$i];
                $lines[$i]['federal_tax'] = $r['federal'];
                $lines[$i]['ss_tax'] = $r['ss'];
                $lines[$i]['medicare_tax'] = $r['medicare'];
                $lines[$i]['addl_medicare_tax'] = $r['addl_medicare'];
                $lines[$i]['state_tax'] = $r['state'];
                $lines[$i]['taxes'] = $r['total'];
                $lines[$i]['calc_status'] = $status;
            }
        }

        // 3) net and save
        foreach ($lines as $l) {
            if ($l['locked']) {
                continue;
            }
            $net = round2((float) $l['gross'] - (float) $l['taxes'] - (float) $l['deductions']);
            Db::update('paychecks', [
                'ot_hours' => $l['ot_hours'], 'hourly_pay' => $l['hourly_pay'], 'ot_pay' => $l['ot_pay'], 'gross' => $l['gross'],
                'ss_wages' => $l['ss_wages'], 'medicare_wages' => $l['medicare_wages'],
                'federal_tax' => $l['federal_tax'], 'ss_tax' => $l['ss_tax'], 'medicare_tax' => $l['medicare_tax'],
                'addl_medicare_tax' => $l['addl_medicare_tax'], 'state_tax' => $l['state_tax'], 'taxes' => $l['taxes'],
                'net' => $net, 'calc_status' => $l['calc_status'],
            ], ['id' => $l['id']]);
        }
    }

    public static function markPaid(array $company, int $paycheckId, ?float $sent, ?string $method): array
    {
        $pc = Db::one('SELECT * FROM paychecks WHERE id = ? AND company_id = ?', [$paycheckId, $company['id']]);
        if (!$pc) {
            throw new RuntimeException('Paycheck not found.');
        }
        if ($pc['locked']) {
            throw new RuntimeException('This paycheck is already paid.');
        }
        self::recalc($company, (int) $pc['pay_period_id']);
        $pc = Db::one('SELECT * FROM paychecks WHERE id = ?', [$paycheckId]);
        if (str_starts_with((string) $pc['calc_status'], 'ERROR')) {
            throw new RuntimeException('Fix the tax problem first: ' . substr($pc['calc_status'], 7));
        }
        Db::update('paychecks', [
            'status' => 'paid', 'locked' => 1, 'paid_at' => gmdate('Y-m-d H:i:s'),
            'sent' => $sent ?? $pc['net'], 'payout_method' => $method ?: $pc['payout_method'],
        ], ['id' => $paycheckId]);
        // Other lines for this employee on the same date may need re-splitting now that this one is frozen.
        self::recalc($company, (int) $pc['pay_period_id']);
        return Db::one('SELECT * FROM paychecks WHERE id = ?', [$paycheckId]);
    }

    public static function undoPaid(array $company, int $paycheckId): void
    {
        Db::exec("UPDATE paychecks SET status = 'pending', locked = 0, paid_at = NULL, sent = 0 WHERE id = ? AND company_id = ?", [$paycheckId, $company['id']]);
        $pid = (int) Db::value('SELECT pay_period_id FROM paychecks WHERE id = ?', [$paycheckId]);
        if ($pid) {
            self::recalc($company, $pid);
        }
    }
}
