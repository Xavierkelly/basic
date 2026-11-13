<?php
declare(strict_types=1);

final class TimeClock
{
    public static function openEntry(int $companyId, int $employeeId): ?array
    {
        return Db::one(
            'SELECT * FROM time_entries WHERE company_id = ? AND employee_id = ? AND clock_out IS NULL ORDER BY clock_in DESC LIMIT 1',
            [$companyId, $employeeId]
        );
    }

    /** Toggle clock in/out. $loc = ['lat'=>, 'lng'=>, 'acc'=>] or null when the phone gave no location. */
    public static function punch(array $company, array $emp, ?array $loc, int $userId): array
    {
        if ($loc === null && $company['require_location']) {
            throw new RuntimeException('Location is required to clock in or out. Allow location for this site and try again.');
        }
        $lat = $loc['lat'] ?? null;
        $lng = $loc['lng'] ?? null;
        $acc = isset($loc['acc']) ? (int) round((float) $loc['acc']) : null;
        $now = gmdate('Y-m-d H:i:s');
        $open = self::openEntry((int) $company['id'], (int) $emp['id']);
        if ($open) {
            Db::update('time_entries', [
                'clock_out' => $now, 'out_lat' => $lat, 'out_lng' => $lng, 'out_accuracy_m' => $acc,
                'location_missing' => ($open['location_missing'] || $loc === null) ? 1 : 0,
            ], ['id' => $open['id']]);
            return ['state' => 'out', 'entry_id' => (int) $open['id'], 'at' => $now];
        }
        $id = Db::insert('time_entries', [
            'company_id' => $company['id'], 'employee_id' => $emp['id'],
            'branch_id' => $emp['default_branch_id'], 'department_id' => $emp['default_department_id'],
            'clock_in' => $now, 'in_lat' => $lat, 'in_lng' => $lng, 'in_accuracy_m' => $acc,
            'location_missing' => $loc === null ? 1 : 0, 'source' => 'clock', 'created_by' => $userId,
        ]);
        return ['state' => 'in', 'entry_id' => $id, 'at' => $now];
    }

    public static function parseLocation(array $in): ?array
    {
        if (!isset($in['lat'], $in['lng']) || $in['lat'] === '' || $in['lng'] === '') {
            return null;
        }
        $lat = (float) $in['lat'];
        $lng = (float) $in['lng'];
        if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
            return null;
        }
        return ['lat' => $lat, 'lng' => $lng, 'acc' => isset($in['acc']) ? (float) $in['acc'] : null];
    }
}
