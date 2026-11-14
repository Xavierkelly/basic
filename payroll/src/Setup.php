<?php
declare(strict_types=1);

/**
 * First-run setup: shown instead of the app while config.php does not exist.
 *
 * It asks for the database details from the hosting panel, and only writes config.php after
 * those details successfully connect to a database on this same server (host is forced to
 * localhost). So nobody without the server's own database credentials can complete it, and once
 * config.php exists this page is never reachable again.
 */
final class Setup
{
    /** Create tables and load tax tables (safe to re-run). */
    public static function installSchema(PDO $pdo, ?callable $log = null): void
    {
        foreach (['schema.sql', 'tax_2026.sql'] as $f) {
            $sql = preg_replace('/^\s*--.*$/m', '', (string) file_get_contents(APP_ROOT . "/sql/$f"));
            foreach (preg_split('/;\s*(\n|$)/', $sql) as $stmt) {
                if (trim($stmt) !== '') {
                    $pdo->exec($stmt);
                }
            }
            if ($log) {
                $log("Loaded $f");
            }
        }
    }

    /** Build config.php text from config.example.php with the given values. */
    public static function configText(string $baseUrl, string $dbName, string $dbUser, string $dbPass, string $dbHost = 'localhost'): string
    {
        $example = (string) file_get_contents(APP_ROOT . '/config.example.php');
        return strtr($example, [
            "'https://your-app.cloudwaysapps.com'" => var_export($baseUrl, true),
            "'host' => '127.0.0.1'" => "'host' => " . var_export($dbHost, true),
            "'name' => 'payroll'" => "'name' => " . var_export($dbName, true),
            "'user' => 'payroll'" => "'user' => " . var_export($dbUser, true),
            "'pass' => 'change-me'" => "'pass' => " . var_export($dbPass, true),
        ]);
    }

    public static function handle(string $configFile): never
    {
        header('X-Frame-Options: DENY');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: no-store');

        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
        $host = preg_replace('/[^A-Za-z0-9.\-:]/', '', (string) ($_SERVER['HTTP_HOST'] ?? 'localhost'));
        $guessUrl = ($https ? 'https://' : 'http://') . $host;

        $v = [
            'base_url' => trim((string) ($_POST['base_url'] ?? $guessUrl)),
            'db_host' => strtolower(trim((string) ($_POST['db_host'] ?? 'localhost'))),
            'db_name' => trim((string) ($_POST['db_name'] ?? '')),
            'db_user' => trim((string) ($_POST['db_user'] ?? '')),
        ];
        $error = null;

        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            $pass = trim((string) ($_POST['db_pass'] ?? '')); // copy/paste often adds a space or newline
            $origin = (string) ($_SERVER['HTTP_ORIGIN'] ?? '');
            $originHost = parse_url($origin, PHP_URL_HOST) . (parse_url($origin, PHP_URL_PORT) ? ':' . parse_url($origin, PHP_URL_PORT) : '');
            $sameOrigin = $origin === '' || strcasecmp($originHost, $host) === 0;
            if (!$sameOrigin) {
                $error = 'Please submit this form from the site itself.';
            } elseif (!preg_match('~^https?://[A-Za-z0-9.\-:]+$~', $v['base_url'] = rtrim($v['base_url'], '/'))) {
                $error = 'Site address is the web address of this site, e.g. ' . $guessUrl . ' (leave the pre-filled value). "localhost" goes in Database host.';
            } elseif (!in_array($v['db_host'], ['localhost', '127.0.0.1'], true)) {
                $error = 'Database host must be localhost (or 127.0.0.1): the database has to be on this same server.';
            } elseif ($v['db_name'] === '' || $v['db_user'] === '' || $pass === '') {
                $error = 'Fill in the database name, username and password.';
            } else {
                $pdo = null;
                $reasons = [];
                $hosts = $v['db_host'] === '127.0.0.1' ? ['127.0.0.1', 'localhost'] : ['localhost', '127.0.0.1'];
                foreach ($hosts as $tryHost) {
                    try {
                        $pdo = new PDO(
                            sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', $tryHost, $v['db_name']),
                            $v['db_user'],
                            $pass,
                            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5]
                        );
                        $v['db_host'] = $tryHost;
                        break;
                    } catch (Throwable $e) {
                        // MySQL error text names the user and database but never the password.
                        $reasons[] = $tryHost . ': ' . preg_replace('/^SQLSTATE\[[^\]]*\]\s*(\[\d+\]\s*)?/', '', $e->getMessage());
                    }
                }
                if (!$pdo) {
                    sleep(2); // slow down guessing
                    $error = 'Could not connect to the database. MySQL said: ' . implode(' | ', array_unique($reasons))
                        . '. Copy the details again from Cloudways: Application → Access Details → Database (use the copy icons).';
                }
                if ($pdo) {
                    if (is_file($configFile)) {
                        header('Location: ' . $v['base_url'] . '/');
                        exit;
                    }
                    $text = self::configText($v['base_url'], $v['db_name'], $v['db_user'], $pass, $v['db_host']);
                    $tmp = $configFile . '.' . bin2hex(random_bytes(4)) . '.tmp';
                    if (@file_put_contents($tmp, $text, LOCK_EX) === false || !@rename($tmp, $configFile)) {
                        @unlink($tmp);
                        $error = 'Connected to the database, but could not save config.php (' . (error_get_last()['message'] ?? 'folder not writable') . '). '
                            . 'In Cloudways open Application Settings → General → Reset File/Folders Permissions, click Reset, then try again.';
                    } else {
                        @chmod($configFile, 0640);
                        try {
                            self::installSchema($pdo);
                        } catch (Throwable $e) {
                            $error = 'Saved config.php, but creating the tables failed: ' . $e->getMessage();
                        }
                        if (!$error) {
                            header('Location: ' . $v['base_url'] . '/?p=signup');
                            exit;
                        }
                    }
                }
            }
        }

        http_response_code(200);
        self::render($v, $error);
        exit;
    }

    private static function render(array $v, ?string $error): void
    {
        $h = fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        ?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>Set up · Basic Payroll</title>
<link rel="stylesheet" href="assets/vendor/bootstrap/bootstrap.min.css">
</head>
<body class="bg-body-tertiary">
<main class="container py-5" style="max-width: 540px">
  <h1 class="h3 mb-1">Set up Basic Payroll</h1>
  <p class="text-secondary">One-time step: connect the app to its database. Copy the details from
    Cloudways → this application → <strong>Access Details</strong> → <strong>Database</strong>.</p>
  <?php if ($error): ?><div class="alert alert-danger"><?= $h($error) ?></div><?php endif; ?>
  <form method="post" class="card card-body shadow-sm" autocomplete="off">
    <div class="mb-3">
      <label class="form-label" for="base_url">Site address <span class="text-secondary small">(already filled in)</span></label>
      <input class="form-control" id="base_url" name="base_url" value="<?= $h($v['base_url']) ?>" required>
    </div>
    <div class="mb-3">
      <label class="form-label" for="db_host">Database host</label>
      <input class="form-control" id="db_host" name="db_host" value="<?= $h($v['db_host'] ?: 'localhost') ?>" required>
    </div>
    <div class="mb-3">
      <label class="form-label" for="db_name">Database name</label>
      <input class="form-control" id="db_name" name="db_name" value="<?= $h($v['db_name']) ?>" required>
    </div>
    <div class="mb-3">
      <label class="form-label" for="db_user">Database username</label>
      <input class="form-control" id="db_user" name="db_user" value="<?= $h($v['db_user']) ?>" autocomplete="off" required>
    </div>
    <div class="mb-3">
      <label class="form-label" for="db_pass">Database password</label>
      <input class="form-control" id="db_pass" name="db_pass" type="password" autocomplete="new-password" required>
    </div>
    <button class="btn btn-primary w-100">Connect and create tables</button>
  </form>
  <p class="small text-secondary mt-3">The details are only saved to <code>config.php</code> on this server. This page disappears once setup is done.</p>
</main>
</body>
</html>
<?php
    }
}
