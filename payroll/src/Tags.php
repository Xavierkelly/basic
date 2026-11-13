<?php
declare(strict_types=1);

final class Tags
{
    /** @return array<int, string> id => name */
    public static function list(int $companyId, string $kind): array
    {
        $out = [];
        foreach (Db::all('SELECT id, name FROM tags WHERE company_id = ? AND kind = ? ORDER BY name', [$companyId, $kind]) as $r) {
            $out[(int) $r['id']] = $r['name'];
        }
        return $out;
    }

    public static function findOrCreate(int $companyId, string $kind, ?string $name): ?int
    {
        $name = trim(preg_replace('/\s+/', ' ', (string) $name));
        if ($name === '') {
            return null;
        }
        $id = Db::value('SELECT id FROM tags WHERE company_id = ? AND kind = ? AND name = ?', [$companyId, $kind, $name]);
        return $id ? (int) $id : Db::insert('tags', ['company_id' => $companyId, 'kind' => $kind, 'name' => $name]);
    }

    public static function options(array $tags, mixed $selected, string $empty = '—'): string
    {
        $h = '<option value="">' . e($empty) . '</option>';
        foreach ($tags as $id => $name) {
            $h .= '<option value="' . $id . '"' . ((int) $selected === $id ? ' selected' : '') . '>' . e($name) . '</option>';
        }
        return $h;
    }
}
