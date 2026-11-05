<?php

/**
 * AIWMWeb fresh-install wizard for a cPanel ZIP package.
 *
 * Fresh database credentials are NOT proof of hosting ownership.
 * A private random owner activation secret must be read via cPanel File Manager.
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
$ownerPath = $stateDir.'/owner-activation.secret';

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

function aiwmRecordRejectedActivation(string $dir): void
{
    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    $path = $dir.'/attempt-'.hash('sha256', $ip).'.json';
    $stream = @fopen($path, 'c+');
    if ($stream === false || ! flock($stream, LOCK_EX)) {
        aiwmFailure('تعذر التحقق من حد محاولات التفعيل.');
    }

    try {
        $row = json_decode((string) stream_get_contents($stream), true);
        $now = time();
        if (! is_array($row) || ($row['since'] ?? 0) < $now - 900) {
            $row = ['since' => $now, 'count' => 0];
        }
        ++$row['count'];
        ftruncate($stream, 0);
        rewind($stream);
        fwrite($stream, json_encode($row, JSON_THROW_ON_ERROR));
        fflush($stream);
        if ($row['count'] > 5) {
            aiwmFailure('تم تجاوز حد محاولات التفعيل. حاول بعد 15 دقيقة.');
        }
    } finally {
        flock($stream, LOCK_UN);
        fclose($stream);
    }
}

function aiwmDatabase(array $db): PDO
{
    try {
        $pdo = new PDO(
            'mysql:host='.$db['host'].';port='.$db['port'].';dbname='.$db['name'].';charset=utf8mb4',
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

/** Translate only hard-coded wizard copy. Never alter persisted tenant values. */
function aiwmUiText(string $text): string
{
    if (($_SESSION['aiwm_ui_lang'] ?? 'ar') !== 'en') {
        return $text;
    }

    static $translations = null;
    $translations ??= json_decode('{"مرحبًا بك في معالج AIWMWeb":"Welcome to the AIWMWeb Setup Wizard","تحقق من الملكية ثم التالي":"Verify ownership and continue","الملكية":"Ownership","الصلاحيات":"Permissions","الدومين":"Domain","المنصة":"Platform","المستأجر":"Tenant","المدير":"Administrator","مراحل التثبيت":"Installation steps","التالي":"Next","تثبيت آمن لمرة واحدة — قاعدة بيانات جديدة فقط — لا تُعرض كلمات المرور أو مفاتيح التوقيع في السجلات.":"Secure one-time installation — fresh database only — passwords and signing keys are never logged.","حزمة Laravel مكتملة؛ ثبّتها في مجلد جديد مع قاعدة MySQL فارغة. لا حاجة إلى Terminal أو Composer على الاستضافة.":"This complete Laravel package must be installed in a new directory with an empty MySQL database. No server terminal or Composer is required.","اقرأ رمز إثبات ملكية الاستضافة من ":"Read the hosting ownership proof from "," عبر cPanel File Manager. لا تشاركه.":" using cPanel File Manager. Do not share it.","رمز تفعيل مالك الاستضافة":"Hosting owner activation secret","إصدار PHP والامتدادات المطلوبة وComposer vendor وFrontend build اجتازت فحص السيرفر الحقيقي.":"The PHP version, required extensions, Composer production dependencies, and frontend build passed checks on this server.","فُحصت مجلدات Storage وCache وصلاحيات الكتابة.":"Storage, cache, and write permissions have been checked.","يجب أن يشير Document Root في cPanel إلى ":"The cPanel Document Root must point to ","؛ لا يمكن تجاوز ذلك من PHP بدون صلاحية الاستضافة.":"; PHP cannot change this without hosting permissions.","لا يُقبل تثبيت على نطاق مختلف عن نطاق الطلب الحالي عبر HTTPS.":"The installation URL must match this HTTPS request domain.","تحقق من الدومين ثم التالي":"Verify domain and continue","إعداد قاعدة بيانات MySQL":"Configure MySQL Database","أنشئ قاعدة MySQL ومستخدمًا بصلاحياتها من cPanel أولًا. يتم رفض أي قاعدة تحتوي على جداول سابقة.":"First create a MySQL database and authorized user in cPanel. Databases with existing tables are rejected.","اختبار الاتصال والتالي":"Test connection and continue","تم التأكد من أن قاعدة البيانات متصلة وفارغة.":"The database connection succeeded and the database is empty.","اسم المنصة":"Platform name","اسم مساحة العمل":"Workspace name","اسم المدير":"Administrator name","البريد الإلكتروني":"Email address","كلمة المرور (12 حرفًا على الأقل)":"Password (at least 12 characters)","تأكيد كلمة المرور":"Confirm password","سيُنشأ ":"The installer will create "," بصلاحيات خاصة مع APP_KEY عشوائي عند تنفيذ التثبيت، وليس داخل ZIP. يتم حماية الملف من الكتابة فوق موقع قائم.":" with private permissions and a random APP_KEY during installation, not inside the ZIP. Existing installations are protected against overwrite.","سيتم اختبار الكتابة الفعلية إلى Storage. رابط التخزين العام يعتمد على دعم الاستضافة؛ تحقّق من صلاحيات public/ وSymlinks من cPanel.":"Storage write access will be tested. Public storage linking depends on the host; verify public/ permissions and symlink support in cPanel.","اختبر Storage ثم التالي":"Test storage and continue","File Cache وSessions جاهزان دون Redis. Database Queue تحتاج تشغيل Worker من الاستضافة؛ اختر sync عند غياب Worker.":"File cache and sessions work without Redis. Database queues require a hosting worker; choose sync when no worker is available.","سيُستخدم Mail log افتراضيًا. لم يتم ضبط SMTP أو إرسال بريد حقيقي؛ أعد ضبطه واختبره من إعدادات الإدارة بعد التثبيت.":"Mail logging will be used by default. SMTP is not configured and no real email has been sent; configure and test mail in the admin settings after installation.","تأكيد وضع Mail ثم التالي":"Confirm mail mode and continue","إنشاء Cron يحتاج صلاحيات cPanel. لا يتم الادعاء بتشغيل Scheduler تلقائيًا. أضف مهمة Laravel schedule:run كل دقيقة من cPanel Cron Jobs عندما تدعم الاستضافة ذلك.":"Creating Cron jobs requires cPanel permissions. The scheduler is not automatically claimed as active. Configure Laravel schedule:run every minute in cPanel Cron Jobs if supported.","سيتم تشغيل الترحيلات، وإنشاء مدير المنصة وTenant مع RBAC، وفحص سلامة الهوية. لا تُنشأ حسابات تجريبية أو بيانات سرية افتراضية.":"Migrations, the platform administrator, tenant, RBAC, and identity health checks will run. No demo accounts or default secrets are created.","تثبيت النظام → Finish":"Install system → Finish","التثبيت مكتمل بالفعل":"Installation already completed","هذا المعالج مقفل. ":"This installer is locked. ","تسجيل الدخول":"Sign in","الموقع مثبت بالفعل":"An installation already exists","يوجد ملف إعدادات سابق. حرصًا على حماية بياناتك لا يمكن تثبيت نظام جديد فوق قاعدة بيانات قائمة. استخدم مجلدًا وقاعدة بيانات جديدين أو استرجع النسخة الاحتياطية.":"An environment file already exists. To protect your data, this wizard cannot overwrite an existing installation. Use a new directory and empty database, or restore a verified backup.","فحص متطلبات الاستضافة":"Hosting requirement checks","فعّل المتطلبات الموضحة قبل استكمال التثبيت.":"Enable the listed requirements before continuing.","يجب فتح معالج التثبيت عبر HTTPS.":"Open the setup wizard using HTTPS.","يجب إعادة إثبات ملكية الاستضافة قبل استئناف التثبيت.":"Hosting ownership must be reverified before resuming installation.","انتهت صلاحية إثبات ملكية الاستضافة؛ ابدأ التفعيل مرة أخرى.":"Hosting ownership proof has expired; restart activation.","انتهت صلاحية نموذج التثبيت؛ أعد تحميل الصفحة.":"The setup form has expired; reload the page.","إثبات ملكية الاستضافة غير صحيح. اقرأ ملف التفعيل الخاص من cPanel File Manager.":"Invalid hosting ownership proof. Read the private activation file in cPanel File Manager.","فشل الاتصال بقاعدة البيانات. تأكد من الاسم والمستخدم وكلمة المرور في cPanel.":"Database connection failed. Check database name, user, and password in cPanel.","تعذّر إكمال الخطوة":"Could not complete this step","لم يتم حذف أي بيانات أو جداول. راجع البيانات وأعد فتح المعالج لاستكمال التثبيت.":"No data or tables were deleted. Review the configuration and reopen the wizard to resume.","العودة إلى المعالج":"Return to wizard","حدث خطأ في التثبيت. راجع ملف Laravel log دون إعادة تهيئة قاعدة البيانات.":"Installation error. Check the Laravel log without reinitializing the database.","تم التثبيت بنجاح":"Installation completed successfully","نجح اتصال MySQL، الترحيل، إنشاء مساحة العمل، حساب المدير بصلاحية المنصة، وفحص ملفات الواجهة.":"MySQL connection, migrations, workspace setup, platform administrator creation and frontend checks succeeded.","تم إغلاق معالج التثبيت. ادخل بحساب المدير الذي أنشأته.":"The installer is locked. Sign in with the administrator account you created.","فتح المنصة وتسجيل الدخول":"Open platform and sign in","نظام رفع التحديثات الموقعة موجود في لوحة الإدارة، لكن تثبيتها يظل معطّلًا حتى إعداد مفتاح التوقيع الخاص في نظام الإصدارات. مفاتيح التوقيع لا تُعرض في صفحات عامة.":"Signed ZIP updates are available in the administration dashboard but remain disabled until the trusted release signing key is configured. Signing secrets are never displayed on public pages.","مرحلة التثبيت غير معروفة.":"Unknown installation step.","أكمل فحص قاعدة البيانات أولًا.":"Complete the database checks first.","يجب إثبات ملكية الاستضافة أولًا.":"Verify hosting ownership first.","تثبيت آخر قيد التنفيذ":"Another installation is in progress"}', true, 512, JSON_THROW_ON_ERROR);

    return strtr($text, $translations);
}

function aiwmPage(string $title, int $step, string $body, ?string $error = null): never
{
    $steps = [
        'الملكية', 'PHP', 'الصلاحيات', 'الدومين', 'MySQL',
        'المنصة', 'المستأجر', 'المدير', '.env', 'Storage',
        'Queue', 'Mail', 'Cron', 'Migrations', 'Finish',
    ];
    $language = ($_SESSION['aiwm_ui_lang'] ?? 'ar') === 'en' ? 'en' : 'ar';
    $direction = $language === 'en' ? 'ltr' : 'rtl';
    $progress = '<nav aria-label="'.aiwmEscape(aiwmUiText('مراحل التثبيت')).'" class="steps">';

    foreach ($steps as $index => $caption) {
        $current = $index === $step ? ' active' : '';
        $done = $index < $step ? ' done' : '';
        $progress .= '<span class="step'.$current.$done.'">'.($index + 1).'. '.aiwmEscape(aiwmUiText($caption)).'</span>';
    }

    $progress .= '</nav>';
    $errorHtml = $error === null ? '' : '<p class="error" role="alert">'.aiwmEscape(aiwmUiText($error)).'</p>';
    $token = isset($_SESSION['aiwm_csrf']) ? (string) $_SESSION['aiwm_csrf'] : '';
    $body = aiwmUiText(str_replace('@@CSRF@@', aiwmEscape($token), $body));

    echo '<!doctype html><html lang="'.aiwmEscape($language).'" dir="'.aiwmEscape($direction).'"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<title>'.aiwmEscape(aiwmUiText($title)).' — AIWMWeb</title>';
    echo '<style>:root{font-family:system-ui,Tahoma,sans-serif;color-scheme:dark}*{box-sizing:border-box}body{margin:0;padding:3rem 1rem;min-height:100vh;background:radial-gradient(circle at 20% 0,#1a3550,#0b1220 65%);color:#e8f4fa}main{max-width:820px;margin:auto;background:#131f30;padding:clamp(18px,4vw,36px);border:1px solid #345169;border-radius:22px;box-shadow:0 18px 70px #0005}h1{font-size:clamp(1.5rem,4vw,2rem);margin:.5rem 0 1rem}.logo{color:#6ee7c7;font-weight:800;letter-spacing:.07em}.steps{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:7px;margin:24px 0}.step{border:1px solid #345169;border-radius:8px;padding:10px 6px;font-size:12px;text-align:center;color:#a1b9cc}.active{background:#175545;color:white}.done{border-color:#1eae86;color:#73e8bf}p{line-height:1.75;color:#b9cede}label{display:block;margin:15px 0 6px;font-weight:600}input,select{width:100%;padding:13px;background:#0a1624;border:1px solid #456075;border-radius:9px;color:white;font-size:1rem}input:focus,button:focus{outline:2px solid #6ee7c7}button,.button{display:inline-block;cursor:pointer;margin-top:20px;padding:13px 22px;border:0;border-radius:10px;font-size:1rem;font-weight:800;color:#071d17;background:#6ee7c7;text-decoration:none}.error{border:1px solid #cf716b;background:#421e25;padding:12px;color:#ffd0ca;border-radius:9px}.pass{padding:10px;color:#9df2d6;border-right:3px solid #6ee7c7}.warn{padding:10px;color:#ffdeab;border-right:3px solid #e3a94f}.footer{font-size:12px;margin-top:24px;color:#8faabd}code{direction:ltr;unicode-bidi:plaintext;display:inline-block}form{margin-top:15px}.hint{color:#a9bfd1;font-size:13px}</style>';
    echo '</head><body><main><div class="logo">AIWMWEB | SETUP</div><div class="hint" aria-label="Language"><a href="/install?lang=ar" hreflang="ar" style="color:#6ee7c7">العربية</a> | <a href="/install?lang=en" hreflang="en" style="color:#6ee7c7">English</a></div>'.$progress.'<h1>'.aiwmEscape(aiwmUiText($title)).'</h1>'.$errorHtml.$body;
    echo '<p class="footer">'.aiwmEscape(aiwmUiText('تثبيت آمن لمرة واحدة — قاعدة بيانات جديدة فقط — لا تُعرض كلمات المرور أو مفاتيح التوقيع في السجلات.')).'</p></main></body></html>';
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

    if (is_link($stateDir) || is_link($ownerPath)) {
        aiwmFailure('Unsafe owner activation path.');
    }
    // Created once, atomically, outside the public document root.
    if (! is_file($ownerPath) && ! is_file($lockPath) && ! is_file($envPath)) {
        $ownerHandle = @fopen($ownerPath, 'x');
        if ($ownerHandle !== false) {
            chmod($ownerPath, 0600);
            fwrite($ownerHandle, bin2hex(random_bytes(32)));
            fclose($ownerHandle);
        }
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
        'lifetime' => 0, 'path' => '/',
        'secure' => true, 'httponly' => true, 'samesite' => 'Strict',
    ]);
    session_start();

    // UI language is session-scoped and never authorizes setup or affects CSRF.
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' &&
        isset($_GET['lang']) && in_array($_GET['lang'], ['ar', 'en'], true)) {
        $_SESSION['aiwm_ui_lang'] = $_GET['lang'];
    }

    if (! isset($_SESSION['aiwm_csrf'])) {
        $_SESSION['aiwm_csrf'] = bin2hex(random_bytes(32));
    }

    if (is_link($pendingPath) || is_link($envPath)) {
        aiwmFailure('Unsafe installation checkpoint path.');
    }
    $pendingState = is_file($pendingPath)
        ? json_decode((string) file_get_contents($pendingPath), true) : null;
    $trustedCheckpoint = is_array($pendingState)
        && ($pendingState['version'] ?? null) === 1
        && is_string($pendingState['url'] ?? null)
        && is_array($pendingState['database'] ?? null)
        && array_keys($pendingState['database']) === ['host', 'port', 'name', 'user'];
    $pendingAuthorized = is_file($pendingPath) && ! empty($_SESSION['aiwm_authorized']);
    // Only the original private owner challenge can reopen a setup that wrote
    // .env but was interrupted before installed.lock. Never reopen a completed
    // installation or an unrelated pre-existing .env.
    $ownerCanReprove = $trustedCheckpoint && is_file($ownerPath)
        && ! is_link($ownerPath) && is_file($envPath) && ! is_file($lockPath);
    if (is_file($envPath) && ! $pendingAuthorized && ! $ownerCanReprove) {
        http_response_code(403);
        aiwmPage('الموقع مثبت بالفعل', 0, '<p class="warn">يوجد ملف إعدادات سابق. حرصًا على حماية بياناتك لا يمكن تثبيت نظام جديد فوق قاعدة بيانات قائمة. استخدم مجلدًا وقاعدة بيانات جديدين أو استرجع النسخة الاحتياطية.</p>');
    }
    if ($ownerCanReprove && ! $pendingAuthorized
        && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST'
        && ($_POST['action'] ?? '') !== 'requirements') {
        aiwmFailure('يجب إعادة إثبات ملكية الاستضافة قبل استئناف التثبيت.');
    }

    $failures = aiwmReadiness($appRoot);

    if ($failures !== []) {
        http_response_code(503);
        aiwmPage('فحص متطلبات الاستضافة', 0, '<p class="error">'.implode('</p><p class="error">', array_map('aiwmEscape', $failures)).'</p><p>فعّل المتطلبات الموضحة قبل استكمال التثبيت.</p>');
    }

    $action = (string) ($_POST['action'] ?? '');
    $post = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';

    if ($post) {
        if ($action !== 'requirements' &&
            (empty($_SESSION['aiwm_owner_verified']) ||
             (int) ($_SESSION['aiwm_owner_verified_at'] ?? 0) < time() - 1800)) {
            aiwmFailure('انتهت صلاحية إثبات ملكية الاستضافة؛ ابدأ التفعيل مرة أخرى.');
        }
        if (! hash_equals((string) $_SESSION['aiwm_csrf'], (string) ($_POST['csrf'] ?? ''))) {
            aiwmFailure('انتهت صلاحية نموذج التثبيت؛ أعد تحميل الصفحة.');
        }

        if ($action === 'requirements') {
            $submittedProof = (string) ($_POST['owner_activation_secret'] ?? '');
            $expectedProof = is_file($ownerPath) && ! is_link($ownerPath)
                ? @file_get_contents($ownerPath) : false;
            if (! is_string($expectedProof) || ! preg_match('/^[a-f0-9]{64}$/D', $submittedProof)
                || ! hash_equals(trim($expectedProof), $submittedProof)) {
                aiwmRecordRejectedActivation($stateDir);
                aiwmFailure('إثبات ملكية الاستضافة غير صحيح. اقرأ ملف التفعيل الخاص من cPanel File Manager.');
            }
            session_regenerate_id(true);
            $_SESSION['aiwm_owner_verified'] = true;
            $_SESSION['aiwm_owner_verified_at'] = time();
            if ($ownerCanReprove) {
                $_SESSION['aiwm_authorized'] = true;
            }
            $_SESSION['aiwm_step'] = 'php';
        } elseif ($action === 'php') {
            if (($_SESSION['aiwm_step'] ?? '') !== 'php') { aiwmFailure('ترتيب مراحل التثبيت غير صحيح.'); }
            if (aiwmReadiness($appRoot) !== []) { aiwmFailure('متطلبات PHP غير مكتملة.'); }
            $_SESSION['aiwm_step'] = 'permissions';
        } elseif ($action === 'permissions') {
            if (($_SESSION['aiwm_step'] ?? '') !== 'permissions') { aiwmFailure('ترتيب مراحل التثبيت غير صحيح.'); }
            if (! is_writable($stateDir) || ! is_writable($appRoot.'/bootstrap/cache')) {
                aiwmFailure('صلاحيات المجلدات لا تسمح بالتثبيت.');
            }
            $_SESSION['aiwm_step'] = 'domain';
        } elseif ($action === 'domain') {
            if (($_SESSION['aiwm_step'] ?? '') !== 'domain') { aiwmFailure('ترتيب مراحل التثبيت غير صحيح.'); }
            $host = (string) ($_SERVER['HTTP_HOST'] ?? '');
            $url = trim((string) ($_POST['app_url'] ?? ''));
            if ($host === '' || ! filter_var($url, FILTER_VALIDATE_URL) ||
                parse_url($url, PHP_URL_SCHEME) !== 'https' ||
                parse_url($url, PHP_URL_HOST) !== $host ||
                parse_url($url, PHP_URL_USER) !== null ||
                parse_url($url, PHP_URL_PASS) !== null) {
                aiwmFailure('APP_URL يجب أن يطابق نطاق HTTPS الحالي.');
            }
            if ($pendingAuthorized && (!$trustedCheckpoint || $pendingState['url'] !== $url)) {
                aiwmFailure('استئناف التثبيت يتطلب استخدام رابط الموقع الأصلي دون تغييره.');
            }
            $_SESSION['aiwm_url'] = $url;
            $_SESSION['aiwm_step'] = 'database';
        } elseif ($action === 'database') {
            if (empty($_SESSION['aiwm_owner_verified'])) { aiwmFailure('يجب إثبات ملكية الاستضافة أولًا.'); }
            $db = [
                'host' => trim((string) ($_POST['db_host'] ?? '')),
                'port' => (string) ($_POST['db_port'] ?? '3306'),
                'name' => trim((string) ($_POST['db_name'] ?? '')),
                'user' => trim((string) ($_POST['db_user'] ?? '')),
                'password' => (string) ($_POST['db_password'] ?? ''),
            ];

            if (! preg_match('/^[a-zA-Z0-9_.-]{1,140}$/D', $db['host']) ||
                ! preg_match('/^[1-9][0-9]{0,4}$/D', $db['port']) ||
                (int) $db['port'] > 65535 ||
                ! preg_match('/^[a-zA-Z0-9_]{1,128}$/D', $db['name']) ||
                ! preg_match('/^[a-zA-Z0-9_]{1,128}$/D', $db['user']) ||
                $db['password'] === '') {
                aiwmFailure('أدخل بيانات MySQL الصحيحة لجميع الحقول.');
            }

            if ($pendingAuthorized && (!$trustedCheckpoint ||
                $pendingState['database'] !== array_intersect_key($db, array_flip(['host', 'port', 'name', 'user'])))) {
                aiwmFailure('استئناف التثبيت يتطلب نفس اتصال قاعدة البيانات الأصلي؛ لن يتم تعديل قاعدة أخرى.');
            }
            $pdo = aiwmDatabase($db);

            if (! $pendingAuthorized) {
                $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_NUM);
                if (count($tables) !== 0) {
                    aiwmFailure('قاعدة البيانات تحتوي على جداول بالفعل. أنشئ قاعدة بيانات جديدة وفارغة ولا تمسح البيانات الحالية.');
                }
            }

            $_SESSION['aiwm_db'] = $db;
            $_SESSION['aiwm_step'] = 'platform';
        } elseif ($action === 'platform') {
            if (($_SESSION['aiwm_step'] ?? '') !== 'platform') { aiwmFailure('ترتيب مراحل التثبيت غير صحيح.'); }
            $name = trim((string) ($_POST['platform_name'] ?? ''));
            $locale = (string) ($_POST['locale'] ?? 'ar');
            $timezone = (string) ($_POST['timezone'] ?? 'UTC');
            if ($name === '' || mb_strlen($name) > 100 || ! in_array($locale, ['ar', 'en'], true) ||
                ! in_array($timezone, timezone_identifiers_list(), true)) {
                aiwmFailure('اسم المنصة أو اللغة أو المنطقة الزمنية غير صالح.');
            }
            $_SESSION['aiwm_platform'] = ['name' => $name, 'locale' => $locale, 'timezone' => $timezone];
            $_SESSION['aiwm_ui_lang'] = $locale;
            $_SESSION['aiwm_step'] = 'tenant';
        } elseif ($action === 'tenant') {
            if (($_SESSION['aiwm_step'] ?? '') !== 'tenant') { aiwmFailure('ترتيب مراحل التثبيت غير صحيح.'); }
            $tenant = trim((string) ($_POST['tenant_name'] ?? ''));
            if ($tenant === '' || mb_strlen($tenant) > 120) { aiwmFailure('اسم مساحة العمل غير صالح.'); }
            $_SESSION['aiwm_tenant'] = $tenant;
            $_SESSION['aiwm_step'] = 'admin';
        } elseif ($action === 'admin') {
            if (($_SESSION['aiwm_step'] ?? '') !== 'admin') { aiwmFailure('ترتيب مراحل التثبيت غير صحيح.'); }
            $name = trim((string) ($_POST['admin_name'] ?? ''));
            $email = trim((string) ($_POST['admin_email'] ?? ''));
            $password = (string) ($_POST['admin_password'] ?? '');
            if ($name === '' || mb_strlen($name) > 120 || ! filter_var($email, FILTER_VALIDATE_EMAIL) ||
                strlen($email) > 255 || strlen($password) < 12 ||
                ! hash_equals($password, (string) ($_POST['admin_password_confirmation'] ?? ''))) {
                aiwmFailure('بيانات مدير المنصة غير صالحة.');
            }
            $_SESSION['aiwm_admin'] = ['name' => $name, 'email' => $email, 'password' => $password];
            $_SESSION['aiwm_step'] = 'environment';
        } elseif ($action === 'environment') {
            if (($_SESSION['aiwm_step'] ?? '') !== 'environment') { aiwmFailure('ترتيب مراحل التثبيت غير صحيح.'); }
            if (is_file($envPath) && ! $pendingAuthorized) { aiwmFailure('ملف .env موجود مسبقًا.'); }
            $_SESSION['aiwm_step'] = 'storage';
        } elseif ($action === 'storage') {
            if (($_SESSION['aiwm_step'] ?? '') !== 'storage') { aiwmFailure('ترتيب مراحل التثبيت غير صحيح.'); }
            $test = $appRoot.'/storage/app/.setup-write-'.bin2hex(random_bytes(5));
            if (file_put_contents($test, 'ok') !== 2) { aiwmFailure('Storage غير قابل للكتابة.'); }
            @unlink($test);
            $_SESSION['aiwm_step'] = 'queue';
        } elseif ($action === 'queue') {
            if (($_SESSION['aiwm_step'] ?? '') !== 'queue') { aiwmFailure('ترتيب مراحل التثبيت غير صحيح.'); }
            $queue = (string) ($_POST['queue_mode'] ?? 'sync');
            if (! in_array($queue, ['sync', 'database'], true)) { aiwmFailure('Queue mode غير مدعوم.'); }
            $_SESSION['aiwm_queue'] = $queue;
            $_SESSION['aiwm_step'] = 'mail';
        } elseif ($action === 'mail') {
            if (($_SESSION['aiwm_step'] ?? '') !== 'mail') { aiwmFailure('ترتيب مراحل التثبيت غير صحيح.'); }
            // No SMTP credentials are guessed; operator can configure and test mail post-install.
            $_SESSION['aiwm_mail'] = 'log';
            $_SESSION['aiwm_step'] = 'scheduler';
        } elseif ($action === 'scheduler') {
            if (($_SESSION['aiwm_step'] ?? '') !== 'scheduler') { aiwmFailure('ترتيب مراحل التثبيت غير صحيح.'); }
            // Cron must be created in cPanel by the authorized hosting account owner.
            $_SESSION['aiwm_scheduler'] = 'manual';
            $_SESSION['aiwm_step'] = 'migrate';
        } elseif ($action === 'install') {
            if (empty($_SESSION['aiwm_owner_verified'])) { aiwmFailure('يجب إثبات ملكية الاستضافة أولًا.'); }
            if (($_SESSION['aiwm_step'] ?? '') !== 'migrate' || ! isset($_SESSION['aiwm_db'], $_SESSION['aiwm_admin'], $_SESSION['aiwm_platform'], $_SESSION['aiwm_tenant'], $_SESSION['aiwm_url'])) {
                aiwmFailure('أكمل فحص قاعدة البيانات أولًا.');
            }

            // Exclusive lease covers the entire migration/identity transaction and lock creation.
            // Multiple independent PHP sessions cannot race to create a platform administrator.
            $lease = @fopen($stateDir.'/migration-install.lock', 'c');
            if ($lease === false || ! flock($lease, LOCK_EX | LOCK_NB)) {
                aiwmFailure('تثبيت آخر قيد التنفيذ. أعد المحاولة بعد انتهاء العملية.');
            }
            if (is_file($lockPath)) { aiwmFailure('تم إغلاق التثبيت بالفعل.'); }

            $name = $_SESSION['aiwm_platform']['name'];
            $workspace = $_SESSION['aiwm_tenant'];
            $owner = $_SESSION['aiwm_admin']['name'];
            $email = $_SESSION['aiwm_admin']['email'];
            $password = $_SESSION['aiwm_admin']['password'];
            $confirmation = $password;
            $url = $_SESSION['aiwm_url'];

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
                'APP_LOCALE' => $_SESSION['aiwm_platform']['locale'],
                'APP_TIMEZONE' => $_SESSION['aiwm_platform']['timezone'],
                'DB_CONNECTION' => 'mysql',
                'DB_HOST' => $db['host'],
                'DB_PORT' => $db['port'],
                'DB_DATABASE' => $db['name'],
                'DB_USERNAME' => $db['user'],
                'DB_PASSWORD' => $db['password'],
                'CACHE_STORE' => 'file',
                'SESSION_DRIVER' => 'file',
                'QUEUE_CONNECTION' => $_SESSION['aiwm_queue'] ?? 'sync',
                'MAIL_MAILER' => 'log',
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
            if (! $pendingAuthorized) {
                aiwmSave($pendingPath, json_encode([
                    'version' => 1, 'url' => $url,
                    'database' => array_intersect_key($db, array_flip(['host', 'port', 'name', 'user'])),
                ], JSON_THROW_ON_ERROR)."\n");
            }
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
            @unlink($ownerPath);
            @unlink($pendingPath);

            foreach (['aiwm_db', 'aiwm_admin', 'aiwm_platform', 'aiwm_tenant', 'aiwm_url', 'aiwm_queue',
                'aiwm_mail', 'aiwm_scheduler', 'aiwm_authorized', 'aiwm_owner_verified', 'aiwm_owner_verified_at'] as $key) {
                unset($_SESSION[$key]);
            }
            session_regenerate_id(true);
            $_SESSION['aiwm_step'] = 'complete';
            // The installer file may be owned by a deployment process and
            // therefore not removable. installed.lock keeps it closed.
            @unlink(__FILE__);
            aiwmPage('تم التثبيت بنجاح', 14,
                '<p class="pass">نجح اتصال MySQL، الترحيل، إنشاء مساحة العمل، حساب المدير بصلاحية المنصة، وفحص ملفات الواجهة.</p>'
                .'<p>تم إغلاق معالج التثبيت. ادخل بحساب المدير الذي أنشأته.</p>'
                .'<a class="button" href="/login">فتح المنصة وتسجيل الدخول</a>'
                .'<p class="warn">نظام رفع التحديثات الموقعة موجود في لوحة الإدارة، لكن تثبيتها يظل معطّلًا حتى إعداد مفتاح التوقيع الخاص في نظام الإصدارات. مفاتيح التوقيع لا تُعرض في صفحات عامة.</p>');
        } else {
            aiwmFailure('مرحلة غير صالحة في معالج التثبيت.');
        }
    }

    $stage = (string) ($_SESSION['aiwm_step'] ?? 'requirements');
    $formNotice = '<p>حزمة Laravel مكتملة؛ ثبّتها في مجلد جديد مع قاعدة MySQL فارغة. لا حاجة إلى Terminal أو Composer على الاستضافة.</p>';

    if ($stage === 'requirements') {
        aiwmPage('مرحبًا بك في معالج AIWMWeb', 0,
            $formNotice.'<p class="warn">اقرأ رمز إثبات ملكية الاستضافة من <code>app/storage/app/aiwm-install/owner-activation.secret</code> عبر cPanel File Manager. لا تشاركه.</p>'
            .aiwmForm('requirements', '<label>رمز تفعيل مالك الاستضافة</label><input type="password" name="owner_activation_secret" minlength="64" maxlength="64" required autocomplete="off">', 'تحقق من الملكية ثم التالي'));
    }
    if ($stage === 'php') {
        aiwmPage('PHP Requirements & Extensions', 1,
            '<p class="pass">إصدار PHP والامتدادات المطلوبة وComposer vendor وFrontend build اجتازت فحص السيرفر الحقيقي.</p>'
            .aiwmForm('php', '', 'التالي'));
    }
    if ($stage === 'permissions') {
        aiwmPage('File Permissions & Hosting', 2,
            '<p class="pass">فُحصت مجلدات Storage وCache وصلاحيات الكتابة.</p>'
            .'<p>يجب أن يشير Document Root في cPanel إلى <code>public/</code>؛ لا يمكن تجاوز ذلك من PHP بدون صلاحية الاستضافة.</p>'
            .aiwmForm('permissions', '', 'التالي'));
    }
    if ($stage === 'domain') {
        $host = aiwmEscape((string) ($_SERVER['HTTP_HOST'] ?? ''));
        aiwmPage('Domain / HTTPS / APP_URL', 3,
            '<p>لا يُقبل تثبيت على نطاق مختلف عن نطاق الطلب الحالي عبر HTTPS.</p>'
            .aiwmForm('domain', '<label>APP_URL</label><input type="url" name="app_url" value="https://'.$host.'" required>', 'تحقق من الدومين ثم التالي'));
    }
    if ($stage === 'database') {
        $fields = '<label>DB Host</label><input name="db_host" value="localhost" required maxlength="140">'
            .'<label>DB Port</label><input type="number" name="db_port" value="3306" min="1" max="65535" required>'
            .'<label>Database Name</label><input name="db_name" required maxlength="128">'
            .'<label>Database Username</label><input name="db_user" required maxlength="128">'
            .'<label>Database Password</label><input type="password" name="db_password" required autocomplete="new-password">';
        aiwmPage('إعداد قاعدة بيانات MySQL', 4,
            '<p>أنشئ قاعدة MySQL ومستخدمًا بصلاحياتها من cPanel أولًا. يتم رفض أي قاعدة تحتوي على جداول سابقة.</p>'
            .aiwmForm('database', $fields, 'اختبار الاتصال والتالي'));
    }
    if ($stage === 'platform') {
        aiwmPage('Platform / Language / Timezone', 5,
            '<p class="pass">تم التأكد من أن قاعدة البيانات متصلة وفارغة.</p>'
            .aiwmForm('platform', '<label>اسم المنصة</label><input name="platform_name" value="AIWMWeb" required maxlength="100">'
                .'<label>Language</label><select name="locale"><option value="ar">العربية</option><option value="en">English</option></select>'
                .'<label>Timezone</label><input name="timezone" value="Asia/Kuwait" required>', 'التالي'));
    }
    if ($stage === 'tenant') {
        aiwmPage('Workspace / Tenant', 6,
            aiwmForm('tenant', '<label>اسم مساحة العمل</label><input name="tenant_name" value="Primary Workspace" maxlength="120" required>', 'التالي'));
    }
    if ($stage === 'admin') {
        aiwmPage('Create Super Admin', 7,
            aiwmForm('admin', '<label>اسم المدير</label><input name="admin_name" maxlength="120" required>'
                .'<label>البريد الإلكتروني</label><input type="email" name="admin_email" maxlength="255" required>'
                .'<label>كلمة المرور (12 حرفًا على الأقل)</label><input type="password" name="admin_password" minlength="12" maxlength="255" required>'
                .'<label>تأكيد كلمة المرور</label><input type="password" name="admin_password_confirmation" required>', 'التالي'));
    }
    if ($stage === 'environment') {
        aiwmPage('Secure Environment / APP_KEY', 8,
            '<p>سيُنشأ <code>.env</code> بصلاحيات خاصة مع APP_KEY عشوائي عند تنفيذ التثبيت، وليس داخل ZIP. يتم حماية الملف من الكتابة فوق موقع قائم.</p>'
            .aiwmForm('environment', '', 'التالي'));
    }
    if ($stage === 'storage') {
        aiwmPage('Storage / Uploads', 9,
            '<p>سيتم اختبار الكتابة الفعلية إلى Storage. رابط التخزين العام يعتمد على دعم الاستضافة؛ تحقّق من صلاحيات public/ وSymlinks من cPanel.</p>'
            .aiwmForm('storage', '', 'اختبر Storage ثم التالي'));
    }
    if ($stage === 'queue') {
        aiwmPage('Cache / Queue', 10,
            '<p>File Cache وSessions جاهزان دون Redis. Database Queue تحتاج تشغيل Worker من الاستضافة؛ اختر sync عند غياب Worker.</p>'
            .aiwmForm('queue', '<label>Queue mode</label><select name="queue_mode"><option value="sync">Sync (shared hosting fallback)</option><option value="database">Database (requires worker)</option></select>', 'التالي'));
    }
    if ($stage === 'mail') {
        aiwmPage('Mail Configuration', 11,
            '<p class="warn">سيُستخدم Mail log افتراضيًا. لم يتم ضبط SMTP أو إرسال بريد حقيقي؛ أعد ضبطه واختبره من إعدادات الإدارة بعد التثبيت.</p>'
            .aiwmForm('mail', '', 'تأكيد وضع Mail ثم التالي'));
    }
    if ($stage === 'scheduler') {
        aiwmPage('Scheduler / Cron', 12,
            '<p class="warn">إنشاء Cron يحتاج صلاحيات cPanel. لا يتم الادعاء بتشغيل Scheduler تلقائيًا. أضف مهمة Laravel schedule:run كل دقيقة من cPanel Cron Jobs عندما تدعم الاستضافة ذلك.</p>'
            .aiwmForm('scheduler', '', 'التالي'));
    }
    if ($stage === 'migrate') {
        aiwmPage('Migrations / Seeders', 13,
            '<p>سيتم تشغيل الترحيلات، وإنشاء مدير المنصة وTenant مع RBAC، وفحص سلامة الهوية. لا تُنشأ حسابات تجريبية أو بيانات سرية افتراضية.</p>'
            .aiwmForm('install', '', 'تثبيت النظام → Finish'));
    }
    aiwmFailure('مرحلة التثبيت غير معروفة.');

} catch (Throwable $error) {
    // No credentials, application key, or Ed25519 secret are included in logs.
    error_log('[AIWM-SETUP] '.get_class($error).' during setup');
    $message = $error instanceof RuntimeException
        ? $error->getMessage()
        : 'حدث خطأ في التثبيت. راجع ملف Laravel log دون إعادة تهيئة قاعدة البيانات.';
    $stage = (string) ($_SESSION['aiwm_step'] ?? 'requirements');
    $index = array_search($stage, [
        'requirements', 'php', 'permissions', 'domain', 'database', 'platform', 'tenant',
        'admin', 'environment', 'storage', 'queue', 'mail', 'scheduler', 'migrate', 'complete',
    ], true);
    $index = is_int($index) ? $index : 0;
    http_response_code(422);
    aiwmPage('تعذّر إكمال الخطوة', $index,
        '<p>لم يتم حذف أي بيانات أو جداول. راجع البيانات وأعد فتح المعالج لاستكمال التثبيت.</p>'
        .'<a class="button" href="/install.php">العودة إلى المعالج</a>', $message);
}
