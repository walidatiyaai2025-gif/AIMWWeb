<?php

/**
 * AIWMWeb one-time cPanel installer.
 *
 * The owner must read the random challenge from PRIVATE app/storage via
 * cPanel File Manager. No challenge is embedded in the downloadable ZIP.
 * Once installed, a lock file prevents using this entrypoint again.
 */
declare(strict_types=1);

header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store, private');
header('Referrer-Policy: no-referrer');
header('X-Frame-Options: DENY');
header('Content-Security-Policy: default-src \'none\'; style-src \'unsafe-inline\'; form-action \'self\'; base-uri \'none\'');

$root = dirname(__DIR__);
$appRoot = $root.'/app';
$private = $appRoot.'/storage/app/aiwm-install';
$challengePath = $private.'/owner-code.txt';
$installedLock = $private.'/installed.lock';
$envPath = $appRoot.'/.env';

function setupEscape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
function setupFail(string $message): never
{
    http_response_code(422);
    throw new RuntimeException($message);
}
function setupPutEnv(string $existing, string $key, string $value): string
{
    if (! preg_match('/^[A-Z0-9_]+$/D', $key) || preg_match('/[\r\n]/', $value)) {
        setupFail('Invalid environment configuration.');
    }

    $quoted = '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], $value).'"';
    $line = $key.'='.$quoted;
    $pattern = '/^'.preg_quote($key, '/').'=.*$/m';

    if (preg_match($pattern, $existing)) {
        return (string) preg_replace($pattern, str_replace(['\\', '$'], ['\\\\', '\\$'], $line), $existing, 1);
    }

    return rtrim($existing, "\r\n")."\n".$line."\n";
}
function setupPersist(string $path, string $contents): void
{
    $temporary = $path.'.'.bin2hex(random_bytes(8)).'.tmp';

    if (file_put_contents($temporary, $contents, LOCK_EX) === false) {
        setupFail('Cannot write installation settings.');
    }

    chmod($temporary, 0600);

    if (! rename($temporary, $path)) {
        @unlink($temporary);
        setupFail('Cannot activate installation settings.');
    }
}
function setupPage(string $heading, string $content): never
{
    echo '<!doctype html><html lang="ar" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>'.setupEscape($heading).'</title>';
    echo '<style>body{font-family:system-ui,Tahoma,sans-serif;background:#f4f7fb;color:#162b3e;margin:0;padding:5vh 1rem}.card{margin:auto;max-width:710px;background:white;border-radius:16px;padding:32px;box-shadow:0 8px 35px #1232}input{display:block;box-sizing:border-box;padding:12px;border:1px solid #aab6c3;border-radius:8px;width:100%;margin:7px 0 17px}button{background:#087c62;color:white;padding:13px 22px;border:0;border-radius:9px;cursor:pointer}code{overflow-wrap:anywhere;direction:ltr;display:block;background:#eef3f6;padding:12px;border-radius:8px}p{line-height:1.75}.warn{color:#a33120}</style></head><body><div class="card"><h1>'.setupEscape($heading).'</h1>'.$content.'</div></body></html>';
    exit;
}
function setupRequirements(string $appRoot): array
{
    $failures = [];

    if (PHP_VERSION_ID < 80300) {
        $failures[] = 'PHP 8.3 أو أحدث مطلوب.';
    }

    foreach (['pdo_mysql', 'mbstring', 'openssl', 'zip', 'sodium', 'fileinfo'] as $extension) {
        if (! extension_loaded($extension)) {
            $failures[] = 'PHP extension missing: '.$extension;
        }
    }

    foreach ([$appRoot.'/vendor/autoload.php', $appRoot.'/bootstrap/app.php', $appRoot.'/artisan'] as $file) {
        if (! is_file($file)) {
            $failures[] = 'Missing application file: '.basename($file);
        }
    }

    foreach ([$appRoot.'/storage', $appRoot.'/bootstrap/cache'] as $directory) {
        if (! is_dir($directory) || ! is_writable($directory)) {
            $failures[] = 'Not writable: '.$directory;
        }
    }

    return $failures;
}

try {
    if (! is_dir($private) && ! @mkdir($private, 0700, true) && ! is_dir($private)) {
        setupFail('Cannot create private installer directory.');
    }

    if (is_file($installedLock)) {
        http_response_code(410);
        setupPage('التثبيت مكتمل', '<p>تم إغلاق معالج التثبيت نهائيًا. افتح <a href="/admin/system-updates">لوحة التحديثات</a>.</p>');
    }

    $https = ! empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off';

    if (! $https) {
        setupFail('The installation wizard requires a direct HTTPS connection.');
    }

    $failures = setupRequirements($appRoot);

    if ($failures !== []) {
        setupPage('فحص الاستضافة', '<p class="warn">'.implode('</p><p class="warn">', array_map('setupEscape', $failures)).'</p>');
    }

    if (! is_file($challengePath)) {
        setupPersist($challengePath, bin2hex(random_bytes(18))."\n");
    }

    $installed = is_file($envPath);
    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

    if ($method !== 'POST') {
        setupPage('AIWMWeb — التثبيت الأول', '<p>الحزمة كاملة وتشمل Laravel والاعتماديات والواجهة ولوحة التحديثات.</p>'
            .'<p>افتح cPanel File Manager واقرأ الرمز من <code>app/storage/app/aiwm-install/owner-code.txt</code>، ثم أكمل مرة واحدة.</p>'
            .'<form method="post" autocomplete="off"><label>رمز إثبات ملكية الملفات</label><input name="owner_code" required autocomplete="off">'
            .($installed ? '<p>سيتم الحفاظ على ملف .env الحالي وكل بيانات الموقع ولن تُحذف قاعدة البيانات.</p>'
                : '<p>تثبيت جديد: أدخل بيانات قاعدة البيانات التي أنشأتها في cPanel.</p><label>DB Host</label><input name="db_host" value="localhost" required><label>DB Name</label><input name="db_name" required><label>DB User</label><input name="db_user" required><label>DB Password</label><input type="password" name="db_pass"><label>Site URL</label><input name="app_url" value="https://'.setupEscape((string) ($_SERVER['HTTP_HOST'] ?? '')).'" required>')
            .'<label><input style="display:inline;width:auto" type="checkbox" name="backup_confirm" value="yes" required> لدي نسخة احتياطية من الملفات وقاعدة البيانات إن كان الموقع قائمًا</label>'
            .'<button type="submit">تهيئة الموقع ولوحة التحديثات</button></form>');
    }

    $provided = (string) ($_POST['owner_code'] ?? '');
    $expected = trim((string) file_get_contents($challengePath));

    if (! hash_equals($expected, $provided)) {
        setupFail('رمز ملكية الملفات غير صحيح.');
    }

    if (($_POST['backup_confirm'] ?? null) !== 'yes') {
        setupFail('Backup confirmation is mandatory.');
    }

    if (! $installed) {
        $host = trim((string) ($_POST['db_host'] ?? ''));
        $name = trim((string) ($_POST['db_name'] ?? ''));
        $user = trim((string) ($_POST['db_user'] ?? ''));
        $password = (string) ($_POST['db_pass'] ?? '');
        $url = trim((string) ($_POST['app_url'] ?? ''));

        if (! preg_match('/^[a-zA-Z0-9_.-]{1,140}$/D', $host) ||
            ! preg_match('/^[a-zA-Z0-9_]{1,128}$/D', $name) ||
            ! preg_match('/^[a-zA-Z0-9_]{1,128}$/D', $user) ||
            ! filter_var($url, FILTER_VALIDATE_URL) || ! str_starts_with($url, 'https://')) {
            setupFail('بيانات قاعدة البيانات أو الرابط غير صحيحة.');
        }

        try {
            new PDO('mysql:host='.$host.';dbname='.$name.';charset=utf8mb4', $user, $password, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_TIMEOUT => 5,
            ]);
        } catch (Throwable) {
            setupFail('لا يمكن الاتصال بقاعدة البيانات. راجع البيانات في cPanel.');
        }

        $environment = file_get_contents($appRoot.'/.env.example') ?: '';
        $values = [
            'APP_NAME' => 'AIWMWeb',
            'APP_ENV' => 'production',
            'APP_DEBUG' => 'false',
            'APP_KEY' => 'base64:'.base64_encode(random_bytes(32)),
            'APP_URL' => $url,
            'DB_CONNECTION' => 'mysql',
            'DB_HOST' => $host,
            'DB_PORT' => '3306',
            'DB_DATABASE' => $name,
            'DB_USERNAME' => $user,
            'DB_PASSWORD' => $password,
            'CACHE_STORE' => 'file',
            'QUEUE_CONNECTION' => 'sync',
            'SESSION_DRIVER' => 'file',
        ];

        foreach ($values as $key => $value) {
            $environment = setupPutEnv($environment, $key, $value);
        }

        setupPersist($private.'/fresh-migration-pending.lock', gmdate('c')."\n");
        setupPersist($envPath, $environment);
    }

    // A failed or timed-out fresh migration must remain retryable; never
    // mark a half-initialized database as successfully installed.
    require $appRoot.'/vendor/autoload.php';
    $app = require $appRoot.'/bootstrap/app.php';
    $app->usePublicPath(__DIR__);
    $console = $app->make(\Illuminate\Contracts\Console\Kernel::class);
    $console->bootstrap();
    $console->call('optimize:clear');

    $pendingMigration = $private.'/fresh-migration-pending.lock';

    if (! $installed || is_file($pendingMigration)) {
        $exit = $console->call('migrate', ['--force' => true]);

        if ($exit !== 0) {
            setupFail('Database migrations failed. Check protected Laravel logs; the database was not reset.');
        }

        @unlink($pendingMigration);
    }

    // Do NOT mint and persist the verification public key until migrations
    // have succeeded: otherwise a failed setup would lose its secret key.
    $env = (string) file_get_contents($envPath);
    $existingPublicKey = null;

    if (preg_match('/^AIWM_UPDATES_PUBLIC_KEY="?([^"\r\n]*)"?$/m', $env, $matches) && $matches[1] !== '') {
        $existingPublicKey = $matches[1];
    }

    $signingSecret = null;

    if ($existingPublicKey === null) {
        $pair = sodium_crypto_sign_keypair();
        $publicKey = base64_encode(sodium_crypto_sign_publickey($pair));
        $signingSecret = base64_encode(sodium_crypto_sign_secretkey($pair));
        $env = setupPutEnv($env, 'AIWM_UPDATES_PUBLIC_KEY', $publicKey);
    }

    $env = setupPutEnv($env, 'AIWM_UPDATES_PUBLIC_ROOT', __DIR__);
    $env = setupPutEnv($env, 'AIWM_UPDATES_ENABLED', 'true');
    setupPersist($envPath, $env);
    $console->call('optimize:clear');

    // Lock before revealing signing key; subsequent GET/POST cannot reopen setup.
    setupPersist($installedLock, gmdate('c')."\n");
    @unlink($challengePath);
    @unlink(__FILE__);

    $signingInfo = $signingSecret === null
        ? '<p>تم الاحتفاظ بمفتاح توقيع التحديثات السابق.</p>'
        : '<p class="warn">احفظ المفتاح الخاص التالي مرة واحدة في GitHub Actions secret باسم AIWM_UPDATES_SECRET_KEY. لن يحتفظ به السيرفر أو يظهر مجددًا. لا ترسله في محادثة.</p><code>'.setupEscape($signingSecret).'</code>';

    setupPage('تم تجهيز AIWMWeb', $signingInfo
        .'<p>لوحة الإدارة: <strong>/admin/system-updates</strong></p>'
        .(!$installed ? '<p>أكمل إنشاء حساب المدير من /setup.</p>' : '<p>حساباتك وبياناتك الحالية محفوظة.</p>')
        .'<p>تم قفل معالج التثبيت وإزالة ملفه من مجلد الموقع إن سمحت صلاحيات الملفات بذلك.</p>');
} catch (Throwable $exception) {
    error_log('[AIWM-SETUP] '.$exception->getMessage());
    setupPage('تعذّر إكمال التثبيت', '<p class="warn">'.setupEscape($exception->getMessage()).'</p><p>لم يُحذف أي جدول أو محتوى مستخدم. راجع الصلاحيات أو سجلات Laravel قبل إعادة المحاولة.</p>');
}
