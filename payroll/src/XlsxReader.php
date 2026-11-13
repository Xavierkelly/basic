<?php
declare(strict_types=1);

/** Minimal .xlsx reader (cached cell values only) using the built-in zip and XML extensions. */
final class XlsxReader
{
    private ZipArchive $zip;
    private array $strings = [];
    /** @var array<string, string> sheet name => zip path */
    private array $sheets = [];

    public function __construct(string $path)
    {
        $this->zip = new ZipArchive();
        if ($this->zip->open($path) !== true) {
            throw new RuntimeException('That file is not a readable .xlsx workbook.');
        }
        $this->loadStrings();
        $this->loadSheets();
    }

    private function xml(string $name): ?SimpleXMLElement
    {
        $raw = $this->zip->getFromName($name);
        if ($raw === false) {
            return null;
        }
        $x = simplexml_load_string($raw, SimpleXMLElement::class, LIBXML_NONET | LIBXML_COMPACT | LIBXML_PARSEHUGE);
        return $x === false ? null : $x;
    }

    private function loadStrings(): void
    {
        $x = $this->xml('xl/sharedStrings.xml');
        if (!$x) {
            return;
        }
        foreach ($x->si as $si) {
            if (isset($si->t)) {
                $this->strings[] = (string) $si->t;
            } else {
                $s = '';
                foreach ($si->r as $r) {
                    $s .= (string) $r->t;
                }
                $this->strings[] = $s;
            }
        }
    }

    private function loadSheets(): void
    {
        $wb = $this->xml('xl/workbook.xml');
        $rels = $this->xml('xl/_rels/workbook.xml.rels');
        if (!$wb || !$rels) {
            throw new RuntimeException('That workbook is missing its sheet list.');
        }
        $targets = [];
        foreach ($rels->Relationship as $r) {
            $t = (string) $r['Target'];
            $targets[(string) $r['Id']] = str_starts_with($t, '/') ? ltrim($t, '/') : 'xl/' . $t;
        }
        foreach ($wb->sheets->sheet as $s) {
            $rid = (string) $s->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'];
            if (isset($targets[$rid])) {
                $this->sheets[(string) $s['name']] = $targets[$rid];
            }
        }
    }

    /** @return list<string> */
    public function sheetNames(): array
    {
        return array_keys($this->sheets);
    }

    /**
     * Cells of a sheet as [rowNumber => [ 'A' => value, ... ]]. Numbers come back as float, booleans as bool.
     * @return array<int, array<string, mixed>>
     */
    public function rows(string $sheet, int $maxRow = 5000): array
    {
        $path = $this->sheets[$sheet] ?? null;
        $x = $path ? $this->xml($path) : null;
        if (!$x) {
            return [];
        }
        $out = [];
        foreach ($x->sheetData->row as $row) {
            $rn = (int) $row['r'];
            if ($rn > $maxRow) {
                break;
            }
            foreach ($row->c as $c) {
                $ref = (string) $c['r'];
                $col = preg_replace('/\d+/', '', $ref);
                $t = (string) $c['t'];
                $v = isset($c->v) ? (string) $c->v : null;
                $val = match ($t) {
                    's' => $v === null ? null : ($this->strings[(int) $v] ?? null),
                    'b' => $v === '1',
                    'str', 'e' => $v,
                    'inlineStr' => isset($c->is->t) ? (string) $c->is->t : null,
                    default => $v === null || $v === '' ? null : (float) $v,
                };
                if ($val !== null && $val !== '') {
                    $out[$rn][$col] = $val;
                }
            }
        }
        return $out;
    }

    public static function date(mixed $serial): ?string
    {
        if (is_float($serial) || is_int($serial)) {
            return (new DateTimeImmutable('1899-12-30'))->modify('+' . (int) floor((float) $serial) . ' days')->format('Y-m-d');
        }
        if (is_string($serial) && preg_match('/^\d{4}-\d{2}-\d{2}/', $serial)) {
            return substr($serial, 0, 10);
        }
        return null;
    }
}
