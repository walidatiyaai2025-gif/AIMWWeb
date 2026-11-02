<?php

/**
 * AIWMWeb fresh-install wizard for a cPanel ZIP package.
 *
 * Fresh database credentials are proof of ownership of the fresh database.
 * No publicly embedded installation token or signing secret is shipped.
 * Existing installations are never taken over or reset.
 */
declare(strict_types=1);

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store, private, max-age=0');
header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; form-action 'self'; base-uri 'none'");
header('X-Robots-Tag: noindex, nofollow, noarchive');

$appRoot = dirname(__DIR__).'/app';
$stateDir = $appRoot.'/storage/app/aiwm-install';
$envPath = $appRoot.'/.env';
$lockPath = $stateDir.'/installed.lock';
$pendingPath = $stateDir.'/fresh-migration-pending.lock';

function aiwmEscape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function aiwmFailure(string $message): never
{
    throw new RuntimeException($message);
}

function aiwmReadiness(string $root): array
{
    $problems = [];

    if (PHP_VERSION_ID < 80300) {
        $problems[] = 'PHP 8.3 أو أحدث مطلوب';
    }

    foreach (['pdo_mysql', 'mbstring', 'openssl', 'zip', 'sodium', 'fileinfo', 'tokenizer', 'json'] as $ext) {
        if (! extension_loaded($ext)) {
            $problems[] = 'امتداد PHP غير متاح: '.$ext;
        }
    }

    foreach (['vendor/autoload.php', 'bootstrap/app.php', '.env.example', 'artisan', 'public/build/manifest.json'] as $file) {
        if (! is_file($root.'/'.$file)) {
            $problems[] = 'ملف مطلوب غير موجود: '.$file;
        }
    }

    foreach (['storage', 'bootstrap/cache', 'storage/framework/views', 'storage/framework/sessions'] as $dir) {
        if (! is_dir($root.'/'.$dir) || ! is_writable($root.'/'.$dir)) {
            $problems[] = 'مجلد غير قابل للكتابة: '.$dir;
        }
    }

    return $problems;
}

function aiwmEnvironment(string $text, string $key, string $value): string
{
    if (! preg_match('/^[A-Z][A-Z0-9_]*$/D', $key) || preg_match('/[\r\n\x00]/', $value)) {
        aiwmFailure('Invalid server configuration.');
    }

    $line = $key.'="'.str_replace(['\\', '"'], ['\\\\', '\\"'], $value).'"';
    $expression = '/^'.preg_quote($key, '/').'=.*$/m';

    if (preg_match($expression, $text)) {
        return (string) preg_replace_callback($expression, static fn (): string => $line, $text, 1);
    }

    return rtrim($text, "\r\n")."\n".$line."\n";
}

function aiwmSave(string $path, string $data): void
{
    $tmp = $path.'.'.bin2hex(random_bytes(10));

    if (file_put_contents($tmp, $data, LOCK_EX) === false) {
        aiwmFailure('Cannot write private installation state.');
    }

    chmod($tmp, 0600);

    if (! rename($tmp, $path)) {
        @unlink($tmp);
        aiwmFailure('Cannot activate private installation state.');
    }
}

function aiwmDatabase(array $db): PDO
{
    try {
        $pdo = new PDO(
            'mysql:host='.$db['host'].';port=3306;dbname='.$db['name'].';charset=utf8mb4',
            $db['user'],
            $db['password'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5],
        );
        $pdo->query('SELECT 1');

        return $pdo;
    } catch (Throwable) {
        aiwmFailure('فشل الاتصال بقاعدة البيانات. تأكد من الاسم والمستخدم وكلمة المرور في cPanel.');
    }
}

function aiwmPage(string $title, int $step, string $body, ?string $error = null): never
{
    $steps = ['فحص السيرفر', 'قاعدة البيانات', 'المنصة والمدير', 'التثبيت'];
    $progress = '<nav aria-label="مراحل التثبيت" class="steps">';

    foreach ($steps as $index => $caption) {
        $current = $index === $step ? ' active' : '';
        $done = $index < $step ? ' done' : '';
        $progress .= '<span class="step'.$current.$done.'">'.($index + 1).'. '.aiwmEscape($caption).'</span>';
    }

    $progress .= '</nav>';
    $errorHtml = $error === null ? '' : '<p class="error" role="alert">'.aiwmEscape($error).'</p>';
    $token = isset($_SESSION['aiwm_csrf']) ? (string) $_SESSION['aiwm_csrf'] : '';
    $body = str_replace('@@CSRF@@', aiwmEscape($token), $body);

    echo '<!doctype html><html lang="ar" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<title>'.aiwmEscape($title).' — AIWMWeb</title>';
    echo '<style>:root{font-family:system-ui,Tahoma,sans-serif;color-scheme:dark}*{box-sizing:border-box}body{margin:0;padding:3rem 1rem;min-height:100vh;background:radial-gradient(circle at 20% 0,#1a3550,#0b1220 65%);color:#e8f4fa}main{max-width:820px;margin:auto;background:#131f30;padding:clamp(18px,4vw,36px);border:1px solid #345169;border-radius:22px;box-shadow:0 18px 70px #0005}h1{font-size:clamp(1.5rem,4vw,2rem);margin:.5rem 0 1rem}.logo{color:#6ee7c7;font-weight:800;letter-spacing:.07em}.steps{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:7px;margin:24px 0}.step{border:1px solid #345169;border-radius:8px;padding:10px 6px;font-size:12px;text-align:center;color:#a1b9cc}.active{background:#175545;color:white}.done{border-color:#1eae86;color:#73e8bf}p{line-height:1.75;color:#b9cede}label{display:block;margin:15px 0 6px;font-weight:600}input,select{width:100%;padding:13px;background:#0a1624;border:1px solid #456075;border-radius:9px;color:white;font-size:1rem}input:focus,button:focus{outline:2px solid #6ee7c7}button,.button{display:inline-block;cursor:pointer;margin-top:20px;padding:13px 22px;border:0;border-radius:10px;font-size:1rem;font-weight:800;color:#071d17;background:#6ee7c7;text-decoration:none}.error{border:1px solid #cf716b;background:#421e25;padding:12px;color:#ffd0ca;border-radius:9px}.pass{padding:10px;color:#9df2d6;border-right:3px solid #6ee7c7}.warn{padding:10px;color:#ffdeab;border-right:3px solid #e3a94f}.footer{font-size:12px;margin-top:24px;color:#8faabd}code{direction:ltr;unicode-bidi:plaintext;display:inline-block}form{margin-top:15px}.hint{color:#a9bfd1;font-size:13px}</style>';
    echo '</head><body><main><div class="logo">AIWMWEB | SETUP</div>'.$progress.'<h1>'.aiwmEscape($title).'</h1>'.$errorHtml.$body;
    echo '<p class="footer">تثبيت آمن لمرة واحدة — قاعدة بيانات جديدة فقط — لا تُعرض كلمات المرور أو مفاتيح التوقيع في السجلات.</p></main></body></html>';
    exit;
}

function aiwmForm(string $action, string $content, string $label = 'التالي'): string
{
    return '<form method="post" autocomplete="off"><input type="hidden" name="csrf" value="@@CSRF@@"><input type="hidden" name="action" value="'.aiwmEscape($action).'">'.$content.'<button type="submit">'.aiwmEscape($label).'</button></form>';
}

try {
    $https = isset($_SERVER['HTTPS']) && (string) $_SERVER['HTTPS'] !== '' &&
        strtolower((string) $_SERVER['HTTPS']) !== 'off';
    if (! $https) {
        aiwmFailure('يجب فتح معالج التثبيت عبر HTTPS.');
    }
    if (! is_dir($stateDir) && ! @mkdir($stateDir, 0700, true) && ! is_dir($stateDir)) {
        aiwmFailure('Cannot create private installer directory.');
    }

    // No upgrade/takeover through an anonymous first-run web installer.
    if (is_file($lockPath)) {
        http_response_code(410);
        aiwmPage('التثبيت مكتمل بالفعل', 3, '<p>هذا المعالج مقفل. <a class="button" href="/login">تسجيل الدخول</a></p>');
    }

    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.cookie_httponly', '1');
    session_save_path($stateDir);
    session_name('AIWM_FIRST_SETUP');
    session_set_cookie_params([
        'lifetime' => 0, 'path' => '/install.php',
        'secure' => true, 'httponly' => true, 'samesite' => 'Strict',
    ]);
    session_start();

    if (! isset($_SESSION['aiwm_csrf'])) {
        $_SESSION['aiwm_csrf'] = bin2hex(random_bytes(32));
    }

    $pendingAuthorized = is_file($pendingPath) && ! empty($_SESSION['aiwm_authorized']);
    if (is_file($envPath) && ! $pendingAuthorized) {
        http_response_code(403);
        aiwmPage('الموقع مثبت بالفعل', 0, '<p class="warn">يوجد ملف إعدادات سابق. حرصًا على حماية بياناتك لا يمكن تثبيت نظام جديد فوق قاعدة بيانات قائمة. استخدم مجلدًا وقاعدة بيانات جديدين أو استرجع النسخة الاحتياطية.</p>');
    }

    $failures = aiwmReadiness($appRoot);

    if ($failures !== []) {
        http_response_code(503);
        aiwmPage('فحص متطلبات الاستضافة', 0, '<p class="error">'.implode('</p><p class="error">', array_map('aiwmEscape', $failures)).'</p><p>فعّل المتطلبات الموضحة قبل استكمال التثبيت.</p>');
    }

    $action = (string) ($_POST['action'] ?? '');
    $post = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';

    if ($post) {
        if (! hash_equals((string) $_SESSION['aiwm_csrf'], (string) ($_POST['csrf'] ?? ''))) {
            aiwmFailure('انتهت صلاحية نموذج التثبيت؛ أعد تحميل الصفحة.');
        }

        if ($action === 'requirements') {
            $_SESSION['aiwm_step'] = 'database';
        } elseif ($action === 'database') {
            $db = [
                'host' => trim((string) ($_POST['db_host'] ?? '')),
                'name' => trim((string) ($_POST['db_name'] ?? '')),
                'user' => trim((string) ($_POST['db_user'] ?? '')),
                'password' => (string) ($_POST['db_password'] ?? ''),
            ];

            if (! preg_match('/^[a-zA-Z0-9_.-]{1,140}$/D', $db['host']) ||
                ! preg_match('/^[a-zA-Z0-9_]{1,128}$/D', $db['name']) ||
                ! preg_match('/^[a-zA-Z0-9_]{1,128}$/D', $db['user']) ||
                $db['password'] === '') {
                aiwmFailure('أدخل بيانات MySQL الصحيحة لجميع الحقول.');
            }

            $pdo = aiwmDatabase($db);

            if (! $pendingAuthorized) {
                $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_NUM);
                if (count($tables) !== 0) {
                    aiwmFailure('قاعدة البيانات تحتوي على جداول بالفعل. أنشئ قاعدة بيانات جديدة وفارغة ولا تمسح البيانات الحالية.');
                }
            }

            $_SESSION['aiwm_db'] = $db;
            $_SESSION['aiwm_step'] = 'owner';
        } elseif ($action === 'install') {
            if (($_SESSION['aiwm_step'] ?? '') !== 'owner' || ! isset($_SESSION['aiwm_db'])) {
                aiwmFailure('أكمل فحص قاعدة البيانات أولًا.');
            }

            $name = trim((string) ($_POST['platform_name'] ?? ''));
            $workspace = trim((string) ($_POST['tenant_name'] ?? ''));
            $owner = trim((string) ($_POST['admin_name'] ?? ''));
            $email = trim((string) ($_POST['admin_email'] ?? ''));
            $password = (string) ($_POST['admin_password'] ?? '');
            $confirmation = (string) ($_POST['admin_password_confirmation'] ?? '');
            $url = trim((string) ($_POST['app_url'] ?? ''));

            if ($name === '' || mb_strlen($name) > 100 ||
                $workspace === '' || mb_strlen($workspace) > 120 ||
                $owner === '' || mb_strlen($owner) > 120 ||
                ! filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 255 ||
                strlen($password) < 12 || $password !== $confirmation ||
                ! filter_var($url, FILTER_VALIDATE_URL) ||
                parse_url($url, PHP_URL_SCHEME) !== 'https' ||
                parse_url($url, PHP_URL_HOST) !== ($_SERVER['HTTP_HOST'] ?? '')) {
                aiwmFailure('راجع اسم المنصة، بيانات المدير، تطابق كلمة المرور (12 حرفًا على الأقل) ورابط HTTPS الحالي.');
            }

            $db = $_SESSION['aiwm_db'];
            $pdo = aiwmDatabase($db);

            if (! $pendingAuthorized && count($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_NUM)) > 0) {
                aiwmFailure('قاعدة البيانات لم تعد فارغة. لا يمكن بدء التثبيت فوق جداول موجودة.');
            }

            $environment = is_file($envPath)
                ? (string) file_get_contents($envPath)
                : (string) file_get_contents($appRoot.'/.env.example');

            $updates = [
                'APP_NAME' => $name,
                'APP_ENV' => 'production',
                'APP_DEBUG' => 'false',
                'APP_URL' => $url,
                'APP_LOCALE' => 'ar',
                'DB_CONNECTION' => 'mysql',
                'DB_HOST' => $db['host'],
                'DB_PORT' => '3306',
                'DB_DATABASE' => $db['name'],
                'DB_USERNAME' => $db['user'],
                'DB_PASSWORD' => $db['password'],
                'CACHE_STORE' => 'file',
                'SESSION_DRIVER' => 'file',
                'QUEUE_CONNECTION' => 'sync',
                // Signing is intentionally disabled until a trusted CI key
                // is provisioned. Never fabricate a private signing key in HTML.
                'AIWM_UPDATES_ENABLED' => 'false',
                'AIWM_UPDATES_PUBLIC_ROOT' => __DIR__,
            ];
            if (! is_file($envPath)) {
                $updates['APP_KEY'] = 'base64:'.base64_encode(random_bytes(32));
            }

            foreach ($updates as $key => $value) {
                $environment = aiwmEnvironment($environment, $key, $value);
            }

            $_SESSION['aiwm_authorized'] = true;
            aiwmSave($pendingPath, gmdate('c')."\n");
            aiwmSave($envPath, $environment);

            // This is the SAME Laravel identity-creation service used by
            // /setup, not a second ad-hoc SQL installer.
            require_once $appRoot.'/vendor/autoload.php';
            $laravel = require $appRoot.'/bootstrap/app.php';
            $laravel->usePublicPath(__DIR__);
            $console = $laravel->make(\Illuminate\Contracts\Console\Kernel::class);
            $console->bootstrap();
            $console->call('optimize:clear');
            if ($console->call('migrate', ['--force' => true]) !== 0) {
                aiwmFailure('لم تكتمل ترحيلات قاعدة البيانات. راجع سجلات Laravel ثم أعد المحاولة.');
            }

            $service = $laravel->make(\App\Services\DatabaseSetupMutationService::class);
            $service->apply([
                'tenant_name' => $workspace,
                'admin_name' => $owner,
                'admin_email' => $email,
                'admin_password' => $password,
            ]);

            $status = $laravel->make(\App\Services\DatabaseSetupReadService::class)->status();
            $user = \App\Models\User::query()->where('email', mb_strtolower($email))->first();

            if (! $status['complete'] || ! $user || ! $user->platform_admin ||
                ! is_file(__DIR__.'/build/manifest.json')) {
                aiwmFailure('لم يجتز تثبيت المنصة فحوص الهوية والصلاحيات وملفات الواجهة.');
            }

            $console->call('view:cache');
            $console->call('optimize:clear');
            aiwmSave($lockPath, gmdate('c')."\n");
            @unlink($pendingPath);

            unset($_SESSION['aiwm_db'], $_SESSION['aiwm_authorized']);
            $_SESSION['aiwm_step'] = 'complete';
            // The installer file may be owned by a deployment process and
            // therefore not removable. installed.lock keeps it closed.
            @unlink(__FILE__);
            aiwmPage('تم التثبيت بنجاح', 3,
                '<p class="pass">نجح اتصال MySQL، الترحيل، إنشاء مساحة العمل، حساب المدير بصلاحية المنصة، وفحص ملفات الواجهة.</p>'
                .'<p>تم إغلاق معالج التثبيت. ادخل بحساب المدير الذي أنشأته.</p>'
                .'<a class="button" href="/login">فتح المنصة وتسجيل الدخول</a>'
                .'<p class="warn">نظام رفع التحديثات الموقعة موجود في لوحة الإدارة، لكن تثبيتها يظل معطّلًا حتى إعداد مفتاح التوقيع الخاص في نظام الإصدارات. مفاتيح التوقيع لا تُعرض في صفحات عامة.</p>');
        } else {
            aiwmFailure('مرحلة غير صالحة في معالج التثبيت.');
        }
    }

    $stage = (string) ($_SESSION['aiwm_step'] ?? 'requirements');
    $formNotice = '<p>ملف ZIP يحتوي على Laravel وComposer وواجهة AIWMWeb. التثبيت ينشئ قاعدة بيانات من الصفر ولا يعدّل أي موقع موجود.</p>';

    if ($stage === 'requirements') {
        $body = $formNotice.'<p class="pass">PHP والمتطلبات وملفات الواجهة وصلاحيات الكتابة: جاهزة.</p>'
            .'<p>تأكد من أن Document Root للدومين يشير إلى مجلد <code>public</code> وأنك أنشأت قاعدة MySQL فارغة في cPanel.</p>'
            .aiwmForm('requirements', '', 'التالي: قاعدة البيانات');
        aiwmPage('مرحبًا بك في معالج AIWMWeb', 0, $body);
    }

    if ($stage === 'database') {
        $fields = '<label>DB Host</label><input name="db_host" value="localhost" required maxlength="140" spellcheck="false">'
            .'<label>Database Name</label><input name="db_name" required maxlength="128" spellcheck="false">'
            .'<label>Database Username</label><input name="db_user" required maxlength="128" spellcheck="false">'
            .'<label>Database Password</label><input type="password" name="db_password" required autocomplete="new-password">';
        aiwmPage('إعداد قاعدة بيانات MySQL', 1,
            '<p>أدخل بيانات قاعدة MySQL <strong>الفارغة</strong> التي أنشأتها من cPanel. سيتحقق المعالج من الاتصال وأنها خالية من الجداول قبل الانتقال.</p>'
            .aiwmForm('database', $fields, 'اختبار الاتصال والتالي'));
    }

    if ($stage === 'owner') {
        $host = aiwmEscape((string) ($_SERVER['HTTP_HOST'] ?? ''));
        $fields = '<label>اسم المنصة</label><input name="platform_name" value="AIWMWeb" maxlength="100" required>'
            .'<label>رابط المنصة</label><input type="url" name="app_url" value="https://'.$host.'" required>'
            .'<label>اسم مساحة العمل</label><input name="tenant_name" value="Primary Workspace" maxlength="120" required>'
            .'<label>اسم مدير المنصة</label><input name="admin_name" maxlength="120" required autocomplete="name">'
            .'<label>البريد الإلكتروني للمدير</label><input type="email" name="admin_email" maxlength="255" required autocomplete="email">'
            .'<label>كلمة مرور قوية (12 حرفًا على الأقل)</label><input type="password" name="admin_password" minlength="12" maxlength="255" required autocomplete="new-password">'
            .'<label>تأكيد كلمة المرور</label><input type="password" name="admin_password_confirmation" minlength="12" maxlength="255" required autocomplete="new-password">';
        aiwmPage('إعداد المنصة وحساب المدير', 2,
            '<p class="pass">تم التأكد من أن قاعدة البيانات متصلة وفارغة. في الخطوة التالية سيتم إنشاء الجداول وحساب المدير واختبار سلامة التثبيت تلقائيًا.</p>'
            .aiwmForm('install', $fields, 'تثبيت المنصة وفتحها'));
    }

    aiwmPage('ابدأ التثبيت', 0, $formNotice);
} catch (Throwable $error) {
    // No credentials, application key, or Ed25519 secret are included in logs.
    error_log('[AIWM-SETUP] '.get_class($error).' during setup');
    $message = $error instanceof RuntimeException
        ? $error->getMessage()
        : 'حدث خطأ في التثبيت. راجع ملف Laravel log دون إعادة تهيئة قاعدة البيانات.';
    $stage = (string) ($_SESSION['aiwm_step'] ?? 'requirements');
    $index = match ($stage) {'database' => 1, 'owner' => 2, default => 0};
    http_response_code(422);
    aiwmPage('تعذّر إكمال الخطوة', $index,
        '<p>لم يتم حذف أي بيانات أو جداول. راجع البيانات وأعد فتح المعالج لاستكمال التثبيت.</p>'
        .'<a class="button" href="/install.php">العودة إلى المعالج</a>', $message);
}
