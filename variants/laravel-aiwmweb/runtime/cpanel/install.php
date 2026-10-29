<?php

declare(strict_types=1);

// Standalone bootstrap: Laravel cannot start until its deployment credentials
// and first APP_KEY exist. This file is never served after installation.
ini_set('display_errors', '0');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'");
$appRoot = dirname(__DIR__).'/app';
$lockPath = $appRoot.'/storage/installed.lock';
if (is_file($lockPath)) {
    http_response_code(404);
    exit;
}
$https = ($_SERVER['HTTPS'] ?? '') === 'on' || ($_SERVER['SERVER_PORT'] ?? '') === '443';
// Require hosting-owner Directory Privacy, rather than a reusable secret in ZIP.
$ownerAuthenticated = ! empty($_SERVER['REMOTE_USER']);
if (! $https || ! $ownerAuthenticated) {
    http_response_code(403);
    echo 'Enable HTTPS and cPanel Directory Privacy for this public directory before installation. / فعّل HTTPS وحماية الدليل من cPanel أولاً.';
    exit;
}
session_name('aimw_install');
session_set_cookie_params(['httponly' => true, 'secure' => true, 'samesite' => 'Strict', 'path' => '/']);
session_start();
$_SESSION['csrf'] ??= bin2hex(random_bytes(32));
$error = '';
$checks = [];
$checks['PHP >= 8.3'] = PHP_VERSION_ID >= 80300;
foreach (['pdo_mysql', 'mbstring', 'openssl', 'fileinfo', 'tokenizer', 'ctype', 'xml', 'dom', 'curl', 'session', 'zip', 'gd'] as $extension) {
    $checks['PHP '.$extension] = extension_loaded($extension);
}
$checks['Private application / التطبيق خارج المجلد العام'] = realpath($_SERVER['DOCUMENT_ROOT'] ?? '') === realpath(__DIR__)
    && ! str_starts_with((string) realpath($appRoot), realpath(__DIR__).DIRECTORY_SEPARATOR);
$checks['Vendor / Composer'] = is_file($appRoot.'/vendor/autoload.php');
$checks['Compiled frontend / الواجهة المبنية'] = is_file(__DIR__.'/build/manifest.json');
$checks['Writable storage'] = is_writable($appRoot.'/storage') && is_writable($appRoot.'/bootstrap/cache');
$checks['Private configuration directory'] = is_writable($appRoot);
$checks['Manifest'] = is_file(dirname(__DIR__).'/MANIFEST.json');
if ($checks['Manifest']) {
    $manifest = json_decode((string) file_get_contents(dirname(__DIR__).'/MANIFEST.json'), true);
    $checks['Payload integrity / سلامة الملفات'] = is_array($manifest['files'] ?? null) && $manifest['files'] !== [];
    foreach (['app/vendor/autoload.php', 'app/bootstrap/app.php', 'public/index.php', 'public/build/manifest.json', 'public/install.php'] as $requiredFile) {
        if (! isset($manifest['files'][$requiredFile])) {
            $checks['Payload integrity / سلامة الملفات'] = false;
        }
    }
    foreach (($manifest['files'] ?? []) as $path => $digest) {
        if (str_contains($path, '..') || ! is_file(dirname(__DIR__).'/'.$path) || ! hash_equals($digest, hash_file('sha256', dirname(__DIR__).'/'.$path))) {
            $checks['Payload integrity / سلامة الملفات'] = false;
            break;
        }
    }
}
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    try {
        if (! hash_equals($_SESSION['csrf'], (string) ($_POST['csrf'] ?? '')) || in_array(false, $checks, true)) {
            throw new RuntimeException('Preflight or CSRF validation failed.');
        }
        $installLock = fopen($appRoot.'/storage/install-process.lock', 'c');
        if (! $installLock || ! flock($installLock, LOCK_EX | LOCK_NB)) {
            throw new RuntimeException('Another installation is active.');
        }
        $envPath = $appRoot.'/.env';
        if (! is_file($envPath)) {
            $url = trim((string) ($_POST['app_url'] ?? ''));
            $parts = parse_url($url);
            if (($parts['scheme'] ?? '') !== 'https' || empty($parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
                throw new RuntimeException('An HTTPS application URL is required.');
            }
            $db = [];
            foreach (['db_host', 'db_name', 'db_user', 'db_password'] as $name) {
                $db[$name] = (string) ($_POST[$name] ?? '');
                if (strpbrk($db[$name], "\r\n\0") !== false || strlen($db[$name]) > 255) {
                    throw new RuntimeException('Invalid database configuration.');
                }
            }
            if (! preg_match('/^[a-zA-Z0-9_.-]+$/D', $db['db_host']) || ! preg_match('/^[a-zA-Z0-9_]+$/D', $db['db_name'])) {
                throw new RuntimeException('Invalid database host or name.');
            }
            $pdo = new PDO('mysql:host='.$db['db_host'].';dbname='.$db['db_name'].';charset=utf8mb4', $db['db_user'], $db['db_password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5]);
            if ((int) $pdo->query('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()')->fetchColumn() !== 0) {
                throw new RuntimeException('Fresh installation requires an empty database. Use the upgrade runbook for existing data.');
            }
            $quote = static function (string $value): string {
                if (strpbrk($value, "\r\n\0") !== false) {
                    throw new RuntimeException('Invalid environment value.');
                }

                return '"'.str_replace(['\\', '"', '$'], ['\\\\', '\\"', '\\$'], $value).'"';
            };
            $values = [
                'APP_NAME' => 'Laravel AIWMWeb', 'APP_ENV' => 'production', 'APP_DEBUG' => 'false',
                'APP_URL' => rtrim($url, '/'), 'APP_KEY' => 'base64:'.base64_encode(random_bytes(32)),
                'DB_CONNECTION' => 'mysql', 'DB_HOST' => $db['db_host'], 'DB_DATABASE' => $db['db_name'],
                'DB_USERNAME' => $db['db_user'], 'DB_PASSWORD' => $db['db_password'],
                'SESSION_DRIVER' => 'database', 'SESSION_SECURE_COOKIE' => 'true', 'CACHE_STORE' => 'database',
                'QUEUE_CONNECTION' => 'database', 'AIMW_RUNTIME_PROFILE' => 'cpanel',
                'CPANEL_AI_ENABLED' => 'false', 'CPANEL_CRON_ENABLED' => 'false', 'CPANEL_PREFLIGHT_PASSED' => 'false',
                'LOG_CHANNEL' => 'single', 'LOG_LEVEL' => 'warning',
            ];
            $envText = '';
            foreach ($values as $key => $value) {
                $envText .= $key.'='.$quote($value)."\n";
            }
            $oldMask = umask(0077);
            $file = fopen($envPath, 'x');
            if (! $file || fwrite($file, $envText) !== strlen($envText)) {
                throw new RuntimeException('Could not persist private environment.');
            }
            fclose($file);
            umask($oldMask);
        }
        // Existing APP_KEY is never generated again, including interrupted setup.
        require $appRoot.'/vendor/autoload.php';
        $app = require $appRoot.'/bootstrap/app.php';
        $app->usePublicPath(__DIR__);
        $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        $email = (string) ($_POST['admin_email'] ?? '');
        $password = (string) ($_POST['admin_password'] ?? '');
        if (! filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 12 || strlen($password) > 255 || $password !== ($_POST['admin_password_confirmation'] ?? null)) {
            throw new RuntimeException('Valid email and matching passwords of at least 12 characters are required.');
        }
        $read = $app->make(App\Services\DatabaseSetupReadService::class);
        if (! $read->status()['complete']) {
            $app->make(App\Services\DatabaseSetupMutationService::class)->apply([
                'tenant_name' => mb_substr((string) ($_POST['tenant_name'] ?? 'Workspace'), 0, 120),
                'admin_name' => mb_substr((string) ($_POST['admin_name'] ?? 'Administrator'), 0, 120),
                'admin_email' => $email, 'admin_password' => $password,
            ]);
        }
        $user = App\Models\User::query()->where('email', strtolower($email))->first();
        if (! $user || ! Illuminate\Support\Facades\Hash::check($password, $user->password) || ! $read->status()['complete']) {
            throw new RuntimeException('Installation authentication validation failed.');
        }
        $app->make('router')->getRoutes()->match(Illuminate\Http\Request::create('/login'));
        if (file_put_contents($lockPath, json_encode(['source_sha' => $manifest['source_sha'], 'installed_at' => gmdate('c')], JSON_THROW_ON_ERROR), LOCK_EX) === false) {
            throw new RuntimeException('Could not lock installer.');
        }
        session_destroy();
        header('Location: /login', true, 303);
        exit;
    } catch (Throwable) {
        // Never log connection exceptions, submitted fields or credentials.
        $error = 'Installation did not complete. Verify preflight, empty database, credentials and matching administrator passwords. Existing configuration is preserved. / لم يكتمل التثبيت. راجع المتطلبات والبيانات؛ الإعدادات الموجودة محفوظة.';
    }
}
$escape = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<!doctype html><html lang="ar" dir="rtl"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>AIMWWeb — التثبيت / Install</title>
<style>body{font:16px system-ui;background:#f3f6f5;color:#143129;max-width:760px;margin:40px auto;padding:20px}main{background:white;padding:28px;border-radius:16px}label{display:block;margin:14px 0}input{display:block;box-sizing:border-box;width:100%;padding:10px;border:1px solid #acbdb6;border-radius:6px}button{padding:14px 24px;background:#147b51;border:0;color:white;border-radius:8px}.error{color:#a21b1b}</style><main>
<h1>تثبيت Laravel AIWMWeb</h1><p>Secure installation · AI and Cron remain disabled / الذكاء الاصطناعي والجدولة معطّلان مبدئيًا</p>
<ul><?php foreach ($checks as $label => $passed) { ?><li><?= $passed ? '✓' : '✗' ?> <?= $escape($label) ?></li><?php } ?></ul>
<?php if ($error !== '') { ?><p class="error" role="alert"><?= $escape($error) ?></p><?php } ?>
<form method="post" autocomplete="off"><input type="hidden" name="csrf" value="<?= $escape($_SESSION['csrf']) ?>">
<?php foreach (['app_url' => 'رابط HTTPS / Application URL', 'db_host' => 'خادم قاعدة البيانات / DB host', 'db_name' => 'اسم قاعدة البيانات / Database', 'db_user' => 'مستخدم قاعدة البيانات / DB user', 'db_password' => 'كلمة مرور قاعدة البيانات / DB password', 'tenant_name' => 'اسم مساحة العمل / Workspace', 'admin_name' => 'اسم المدير / Administrator', 'admin_email' => 'بريد المدير / Admin email', 'admin_password' => 'كلمة مرور المدير / Admin password', 'admin_password_confirmation' => 'تأكيد كلمة المرور / Confirm password'] as $name => $label) { ?>
<label><?= $escape($label) ?><input required name="<?= $name ?>" type="<?= str_contains($name, 'password') ? 'password' : ($name === 'admin_email' ? 'email' : 'text') ?>" maxlength="255"></label><?php } ?>
<button <?= in_array(false, $checks, true) ? 'disabled' : '' ?>>تثبيت آمن / Install</button></form><p>لا توجد إعادة تهيئة أو حذف لبيانات قائمة. No existing data is reseeded or deleted.</p></main></html>
