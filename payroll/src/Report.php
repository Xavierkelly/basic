<?php
declare(strict_types=1);

final class Report
{
    /** Paycheck lines with period, employee and tag names, filtered. Dates filter on the pay period (start >= from, end <= to). */
    public static function lines(int $companyId, array $f): array
    {
        $sql = "SELECT pp.start_date, pp.end_date, pp.pay_date, pc.*, e.first_name, e.last_name, b.name AS branch, d.name AS dept
                FROM paychecks pc
                JOIN pay_periods pp ON pp.id = pc.pay_period_id
                JOIN employees e ON e.id = pc.employee_id
                LEFT JOIN tags b ON b.id = pc.branch_id
                LEFT JOIN tags d ON d.id = pc.department_id
                WHERE pc.company_id = ?";
        $p = [$companyId];
        if (!empty($f['from'])) {
            $sql .= ' AND pp.start_date >= ?';
            $p[] = $f['from'];
        }
        if (!empty($f['to'])) {
            $sql .= ' AND pp.end_date <= ?';
            $p[] = $f['to'];
        }
        foreach (['branch' => 'pc.branch_id', 'dept' => 'pc.department_id', 'emp' => 'pc.employee_id'] as $k => $col) {
            if (!empty($f[$k])) {
                $sql .= " AND $col = ?";
                $p[] = (int) $f[$k];
            }
        }
        if (!empty($f['status']) && in_array($f['status'], ['pending', 'paid', 'did_not_work'], true)) {
            $sql .= ' AND pc.status = ?';
            $p[] = $f['status'];
        }
        if (!empty($f['type']) && in_array($f['type'], ['W2', '1099'], true)) {
            $sql .= ' AND pc.worker_type = ?';
            $p[] = $f['type'];
        }
        return Db::all($sql . ' ORDER BY pp.start_date, e.first_name, e.last_name, pc.id', $p);
    }

    public static function totals(array $rows): array
    {
        $t = ['records' => count($rows), 'hours' => 0, 'hourly_pay' => 0, 'salary' => 0, 'commissions' => 0, 'gross' => 0,
            'federal_tax' => 0, 'ss_tax' => 0, 'medicare_tax' => 0, 'addl_medicare_tax' => 0, 'state_tax' => 0,
            'taxes' => 0, 'deductions' => 0, 'net' => 0, 'sent' => 0, 'left' => 0, 'employees' => 0];
        $emps = [];
        foreach ($rows as $r) {
            foreach (['hours', 'salary', 'commissions', 'gross', 'federal_tax', 'ss_tax', 'medicare_tax', 'addl_medicare_tax', 'state_tax', 'taxes', 'deductions', 'net', 'sent'] as $k) {
                $t[$k] += (float) $r[$k];
            }
            $t['hourly_pay'] += (float) $r['hourly_pay'] + (float) $r['ot_pay'];
            $emps[$r['employee_id']] = true;
        }
        $t['left'] = $t['net'] - $t['sent'];
        $t['employees'] = count($emps);
        return $t;
    }

    public static function csv(array $rows, string $filename): never
    {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . preg_replace('/[^A-Za-z0-9_.-]/', '_', $filename) . '"');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['Period Start', 'Period End', 'Pay Date', 'Status', 'Employee', 'Branch', 'Department', 'Worker Type', 'Hours', 'OT Hours', 'Rate',
            'Hourly Pay', 'OT Pay', 'Salary', 'Commissions', 'Gross Pay', 'Federal', 'Social Security', 'Medicare', 'Addl Medicare', 'State',
            'Taxes', 'Deductions', 'Net Pay', 'Total Sent', 'Total Left', 'Paid By', 'Notes']);
        foreach ($rows as $r) {
            $cells = [$r['start_date'], $r['end_date'], $r['pay_date'], $r['status'], employee_name($r), $r['branch'], $r['dept'], $r['worker_type'],
                $r['hours'], $r['ot_hours'], $r['rate'], $r['hourly_pay'], $r['ot_pay'], $r['salary'], $r['commissions'], $r['gross'],
                $r['federal_tax'], $r['ss_tax'], $r['medicare_tax'], $r['addl_medicare_tax'], $r['state_tax'], $r['taxes'], $r['deductions'],
                $r['net'], $r['sent'], round((float) $r['net'] - (float) $r['sent'], 2), $r['payout_method'], $r['notes']];
            // Neutralize spreadsheet formula injection in text cells.
            $cells = array_map(fn($c) => is_string($c) && preg_match('/^[=+\-@\t\r]/', $c) && !is_numeric($c) ? "'" . $c : $c, $cells);
            fputcsv($out, $cells);
        }
        fclose($out);
        exit;
    }
}
