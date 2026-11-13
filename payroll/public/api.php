<?php
declare(strict_types=1);

// JSON endpoints used by assets/app.js (fetch/Ajax). All writes need the CSRF header.
require dirname(__DIR__) . '/src/bootstrap.php';

$_SERVER['HTTP_ACCEPT'] = 'application/json';
$u = Auth::require('employee');
if (!is_post()) {
    json_out(['ok' => false, 'error' => 'POST only.'], 405);
}
csrf_check();

$co = Auth::company();
$cid = (int) $co['id'];
$in = json_decode(file_get_contents('php://input') ?: '[]', true) ?: [];
$action = (string) ($_GET['action'] ?? '');
if ($action !== 'punch' && !Billing::inGoodStanding(Billing::subscription($cid))) {
    json_out(['ok' => false, 'error' => 'Your subscription has ended, so changes are paused until it is restarted.'], 402);
}

/** Load a row that must belong to the signed-in company. */
function owned(string $table, mixed $id): array
{
    $row = Db::one("SELECT * FROM $table WHERE id = ? AND company_id = ?", [(int) $id, Auth::companyId()]);
    if (!$row) {
        json_out(['ok' => false, 'error' => 'Not found.'], 404);
    }
    return $row;
}

function paycheck_json(int $id): array
{
    $r = Db::one('SELECT * FROM paychecks WHERE id = ?', [$id]);
    $r['left'] = round2((float) $r['net'] - (float) $r['sent']);
    return $r;
}

function period_totals(int $pid): array
{
    return Db::one(
        "SELECT COALESCE(SUM(hours),0) hours, COALESCE(SUM(hourly_pay+ot_pay),0) hourly, COALESCE(SUM(salary),0) salary,
                COALESCE(SUM(commissions),0) commissions, COALESCE(SUM(gross),0) gross, COALESCE(SUM(taxes),0) taxes,
                COALESCE(SUM(deductions),0) deductions, COALESCE(SUM(net),0) net, COALESCE(SUM(sent),0) sent,
                COALESCE(SUM(net-sent),0) left_amt
         FROM paychecks WHERE pay_period_id = ?",
        [$pid]
    );
}

try {
    switch ($action) {
        case 'punch': {
            $emp = Auth::employee();
            if (!$emp) {
                json_out(['ok' => false, 'error' => 'Your login is not linked to an employee.'], 400);
            }
            $r = TimeClock::punch($co, $emp, TimeClock::parseLocation($in), (int) $u['id']);
            json_out(['ok' => true] + $r);
        }

        case 'te_save': { // add or edit a time entry (manager+)
            Auth::require('manager');
            $emp = owned('employees', $in['employee_id'] ?? 0);
            $tz = new DateTimeZone($co['timezone']);
            $toUtc = function (?string $local) use ($tz): ?string {
                if (!$local) {
                    return null;
                }
                $d = DateTime::createFromFormat('Y-m-d\TH:i', $local, $tz);
                if (!$d) {
                    throw new RuntimeException('Use a valid date and time.');
                }
                return $d->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
            };
            $inAt = $toUtc($in['clock_in'] ?? null);
            $outAt = $toUtc($in['clock_out'] ?? null);
            if (!$inAt) {
                throw new RuntimeException('Clock-in time is required.');
            }
            if ($outAt && $outAt <= $inAt) {
                throw new RuntimeException('Clock-out must be after clock-in.');
            }
            $row = ['employee_id' => $emp['id'], 'clock_in' => $inAt, 'clock_out' => $outAt, 'note' => mb_substr((string) ($in['note'] ?? ''), 0, 255)];
            if (!empty($in['id'])) {
                $te = owned('time_entries', $in['id']);
                Db::update('time_entries', $row + ['approved_by' => null, 'approved_at' => null], ['id' => $te['id']]);
                audit('edit', 'time_entry', (int) $te['id'], ['before' => [$te['clock_in'], $te['clock_out']], 'after' => [$inAt, $outAt]]);
                $id = (int) $te['id'];
            } else {
                $id = Db::insert('time_entries', $row + [
                    'company_id' => $cid, 'source' => 'manual', 'created_by' => $u['id'],
                    'branch_id' => $emp['default_branch_id'], 'department_id' => $emp['default_department_id'],
                ]);
                audit('add', 'time_entry', $id);
            }
            json_out(['ok' => true, 'id' => $id]);
        }

        case 'te_delete': {
            Auth::require('manager');
            $te = owned('time_entries', $in['id'] ?? 0);
            Db::exec('DELETE FROM time_entries WHERE id = ?', [$te['id']]);
            audit('delete', 'time_entry', (int) $te['id'], ['clock_in' => $te['clock_in'], 'clock_out' => $te['clock_out']]);
            json_out(['ok' => true]);
        }

        case 'te_approve': {
            Auth::require('manager');
            $ids = array_map('intval', (array) ($in['ids'] ?? []));
            $n = 0;
            foreach ($ids as $id) {
                $n += Db::exec(
                    'UPDATE time_entries SET approved_by = ?, approved_at = UTC_TIMESTAMP() WHERE id = ? AND company_id = ? AND clock_out IS NOT NULL',
                    [$u['id'], $id, $cid]
                );
            }
            json_out(['ok' => true, 'approved' => $n]);
        }

        case 'pc_update': { // inline edit on the payroll grid
            Auth::require('admin');
            $pc = owned('paychecks', $in['id'] ?? 0);
            if ($pc['locked']) {
                throw new RuntimeException('This paycheck is paid and locked. Undo "paid" first to change it.');
            }
            $numeric = ['hours', 'rate', 'salary', 'commissions', 'deductions', 'pretax_deductions'];
            $field = (string) ($in['field'] ?? '');
            $value = $in['value'] ?? '';
            if (in_array($field, $numeric, true)) {
                $v = num($value);
                if ($v < 0) {
                    throw new RuntimeException('Amounts cannot be negative.');
                }
                Db::update('paychecks', [$field => $v], ['id' => $pc['id']]);
            } elseif ($field === 'status' && in_array($value, ['pending', 'did_not_work'], true)) {
                Db::update('paychecks', ['status' => $value], ['id' => $pc['id']]);
            } elseif ($field === 'notes') {
                Db::update('paychecks', ['notes' => mb_substr((string) $value, 0, 500)], ['id' => $pc['id']]);
            } elseif ($field === 'payout_method') {
                Db::update('paychecks', ['payout_method' => mb_substr((string) $value, 0, 40)], ['id' => $pc['id']]);
            } elseif (in_array($field, ['branch_id', 'department_id'], true)) {
                $tag = $value === '' ? null : (int) $value;
                if ($tag !== null && !Db::value('SELECT id FROM tags WHERE id = ? AND company_id = ?', [$tag, $cid])) {
                    throw new RuntimeException('Unknown tag.');
                }
                Db::update('paychecks', [$field => $tag], ['id' => $pc['id']]);
            } elseif ($field === 'manual_taxes') {
                Db::update('paychecks', ['manual_taxes' => $value ? 1 : 0], ['id' => $pc['id']]);
            } elseif ($field === 'taxes' && $pc['manual_taxes']) {
                Db::update('paychecks', ['taxes' => num($value)], ['id' => $pc['id']]);
            } else {
                throw new RuntimeException('That field cannot be changed here.');
            }
            Payroll::recalc($co, (int) $pc['pay_period_id']);
            $rows = Db::all('SELECT id FROM paychecks WHERE pay_period_id = ? AND employee_id = ?', [$pc['pay_period_id'], $pc['employee_id']]);
            json_out(['ok' => true, 'rows' => array_map(fn($r) => paycheck_json((int) $r['id']), $rows), 'totals' => period_totals((int) $pc['pay_period_id'])]);
        }

        case 'pc_paid': {
            Auth::require('admin');
            $pc = owned('paychecks', $in['id'] ?? 0);
            $sent = isset($in['sent']) && $in['sent'] !== '' ? num($in['sent']) : null;
            Payroll::markPaid($co, (int) $pc['id'], $sent, isset($in['method']) ? mb_substr((string) $in['method'], 0, 40) : null);
            audit('paid', 'paycheck', (int) $pc['id']);
            $rows = Db::all('SELECT id FROM paychecks WHERE pay_period_id = ? AND employee_id = ?', [$pc['pay_period_id'], $pc['employee_id']]);
            json_out(['ok' => true, 'rows' => array_map(fn($r) => paycheck_json((int) $r['id']), $rows), 'totals' => period_totals((int) $pc['pay_period_id'])]);
        }

        case 'pc_unpaid': {
            Auth::require('admin');
            $pc = owned('paychecks', $in['id'] ?? 0);
            Payroll::undoPaid($co, (int) $pc['id']);
            audit('unpaid', 'paycheck', (int) $pc['id']);
            $rows = Db::all('SELECT id FROM paychecks WHERE pay_period_id = ? AND employee_id = ?', [$pc['pay_period_id'], $pc['employee_id']]);
            json_out(['ok' => true, 'rows' => array_map(fn($r) => paycheck_json((int) $r['id']), $rows), 'totals' => period_totals((int) $pc['pay_period_id'])]);
        }

        case 'pc_delete': {
            Auth::require('admin');
            $pc = owned('paychecks', $in['id'] ?? 0);
            if ($pc['locked']) {
                throw new RuntimeException('Paid paychecks cannot be removed.');
            }
            Db::exec('DELETE FROM paychecks WHERE id = ?', [$pc['id']]);
            audit('delete', 'paycheck', (int) $pc['id']);
            Payroll::recalc($co, (int) $pc['pay_period_id']);
            json_out(['ok' => true, 'totals' => period_totals((int) $pc['pay_period_id'])]);
        }

        default:
            json_out(['ok' => false, 'error' => 'Unknown action.'], 400);
    }
} catch (RuntimeException $e) {
    json_out(['ok' => false, 'error' => $e->getMessage()], 422);
}
