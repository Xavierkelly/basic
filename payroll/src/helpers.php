<?php
declare(strict_types=1);

function cfg(string $key, mixed $default = null): mixed
{
    $v = $GLOBALS['config'];
    foreach (explode('.', $key) as $k) {
        if (!is_array($v) || !array_key_exists($k, $v)) {
            return $default;
        }
        $v = $v[$k];
    }
    return $v;
}

function e(mixed $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function money(mixed $n): string
{
    return number_format((float) $n, 2);
}

function url(string $page, array $params = []): string
{
    return 'index.php?' . http_build_query(['p' => $page] + $params);
}

function redirect(string $page, array $params = []): never
{
    header('Location: ' . url($page, $params));
    exit;
}

function flash(?string $msg = null, string $type = 'success'): ?array
{
    if ($msg !== null) {
        $_SESSION['flash'] = ['msg' => $msg, 'type' => $type];
        return null;
    }
    $f = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $f;
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">';
}

function csrf_check(): void
{
    $sent = $_POST['_csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if (!is_string($sent) || !hash_equals(csrf_token(), $sent)) {
        http_response_code(419);
        if (str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json')) {
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'error' => 'Your session expired. Reload the page and try again.']);
        } else {
            echo 'Your session expired. Go back, reload the page and try again.';
        }
        exit;
    }
}

function is_post(): bool
{
    return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
}

function post(string $key, mixed $default = ''): mixed
{
    $v = $_POST[$key] ?? $default;
    return is_string($v) ? trim($v) : $v;
}

function num(mixed $v): float
{
    if ($v === null || $v === '') {
        return 0.0;
    }
    return (float) str_replace([',', '$'], '', (string) $v);
}

function round2(float $v): float
{
    // Spreadsheet-style rounding: half away from zero, with a guard for binary float noise.
    return round($v + ($v >= 0 ? 1e-9 : -1e-9), 2);
}

function json_out(array $data, int $code = 200): never
{
    http_response_code($code);
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    echo json_encode($data);
    exit;
}

function audit(string $action, string $entity, ?int $entityId = null, array $detail = []): void
{
    $u = Auth::user();
    if (!$u) {
        return;
    }
    Db::insert('audit_log', [
        'company_id' => $u['company_id'],
        'user_id' => $u['id'],
        'action' => $action,
        'entity' => $entity,
        'entity_id' => $entityId,
        'detail' => $detail ? json_encode($detail) : null,
    ]);
}

function employee_name(array $e): string
{
    return trim(($e['first_name'] ?? '') . ' ' . ($e['last_name'] ?? ''));
}

/** Convert a UTC datetime string to the company's local time for display. */
function local_dt(?string $utc, string $tz, string $fmt = 'M j, g:i a'): string
{
    if (!$utc) {
        return '';
    }
    $d = new DateTime($utc, new DateTimeZone('UTC'));
    $d->setTimezone(new DateTimeZone($tz));
    return $d->format($fmt);
}

function fmt_date(?string $d, string $fmt = 'M j, Y'): string
{
    return $d ? date($fmt, strtotime($d)) : '';
}
