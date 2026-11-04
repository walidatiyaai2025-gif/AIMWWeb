<?php

/**
 * CI-only exact ZIP acceptance: run actual cPanel PHP installer over an
 * empty MySQL database and assert Laravel owner/platform control readiness.
 *
 * Args: EXTRACTED_ZIP_ROOT DB_HOST DB_NAME DB_USER DB_PASSWORD
 */
declare(strict_types=1);

if ($argc !== 6) {
    fwrite(STDERR, "Usage: php smoke-cpanel-wizard.php ROOT HOST DB USER PASSWORD\n");
    exit(2);
}

[, $root, $host, $database, $username, $password] = $argv;
$installer = $root.'/public/install.php';
$private = $root.'/app/storage/app/aiwm-install';

if (! is_file($installer) || (! is_dir($private) && ! mkdir($private, 0700, true))) {
    throw new RuntimeException('The extracted full Setup ZIP is missing its installer.');
}

$sessionId = 'aiwmsetupci'.bin2hex(random_bytes(12));
$csrf = bin2hex(random_bytes(32));
session_save_path($private);
session_name('AIWM_FIRST_SETUP');
session_id($sessionId);
session_start();
$_SESSION['aiwm_csrf'] = $csrf;
session_write_close();

function executeStep(string $installer, string $id, array $post = []): string
{
    $code = <<<'CODE'
$_SERVER['HTTPS'] = 'on';
$_SERVER['HTTP_HOST'] = 'aiwm.example.test';
$_SERVER['REQUEST_METHOD'] = $argv[3] === 'POST' ? 'POST' : 'GET';
$_COOKIE['AIWM_FIRST_SETUP'] = $argv[2];
$_POST = json_decode(base64_decode($argv[4]), true, flags: JSON_THROW_ON_ERROR);
require $argv[1];
CODE;
    $command = escapeshellarg(PHP_BINARY)
        .' -d display_errors=stderr -r '.escapeshellarg($code)
        .' '.escapeshellarg($installer)
        .' '.escapeshellarg($id)
        .' '.escapeshellarg($post === [] ? 'GET' : 'POST')
        .' '.escapeshellarg(base64_encode(json_encode($post, JSON_THROW_ON_ERROR)))
        .' 2>&1';
    exec($command, $lines, $status);
    $output = implode("\n", $lines);

    if ($status !== 0 || str_contains($output, 'Fatal error') || str_contains($output, 'Uncaught')) {
        throw new RuntimeException('CI setup stage terminated: '.substr($output, -1700));
    }

    return $output;
}

function requireOutput(string $actual, string $needle): void
{
    if (! str_contains($actual, $needle)) {
        throw new RuntimeException('Setup smoke expectation not met: '.$needle.'; response: '.substr($actual, -1300));
    }
}

$first = executeStep($installer, $sessionId);
requireOutput($first, 'مرحبًا بك في معالج AIWMWeb');
requireOutput($first, 'تحقق من الملكية ثم التالي');
$ownerSecretPath = $private.'/owner-activation.secret';
if (! is_file($ownerSecretPath)) {
    throw new RuntimeException('Owner activation challenge was not created in private storage.');
}
$ownerSecret = trim((string) file_get_contents($ownerSecretPath));
if (! preg_match('/^[a-f0-9]{64}$/D', $ownerSecret)) {
    throw new RuntimeException('Invalid randomly generated hosting owner challenge.');
}
if (str_contains($first, $ownerSecret)) {
    throw new RuntimeException('Owner activation challenge leaked in the public installer page.');
}
// A network-only visitor cannot advance with MySQL credentials or a guessed token.
$attacker = executeStep($installer, $sessionId, ['csrf' => $csrf, 'action' => 'requirements',
    'owner_activation_secret' => str_repeat('0', 64),
]);
requireOutput($attacker, 'إثبات ملكية الاستضافة غير صحيح');
requireOutput($first, 'فحص السيرفر');

$databasePage = executeStep($installer, $sessionId, ['csrf' => $csrf, 'action' => 'requirements', 'owner_activation_secret' => $ownerSecret]);
requireOutput($databasePage, 'إعداد قاعدة بيانات MySQL');
$ownerPage = executeStep($installer, $sessionId, [
    'csrf' => $csrf, 'action' => 'database',
    'db_host' => $host, 'db_name' => $database,
    'db_user' => $username, 'db_password' => $password,
]);
requireOutput($ownerPage, 'إعداد المنصة وحساب المدير');
requireOutput($ownerPage, 'قاعدة البيانات متصلة وفارغة');

$finished = executeStep($installer, $sessionId, [
    'csrf' => $csrf, 'action' => 'install',
    'platform_name' => 'AIWMWeb',
    'tenant_name' => 'CI Smoke Workspace',
    'admin_name' => 'CI Owner',
    'admin_email' => 'ci-owner@example.test',
    'admin_password' => 'Only-For-CI-Smoke-1234!',
    'admin_password_confirmation' => 'Only-For-CI-Smoke-1234!',
    'app_url' => 'https://aiwm.example.test',
]);
requireOutput($finished, 'تم التثبيت بنجاح');
requireOutput($finished, 'فتح المنصة وتسجيل الدخول');

if (is_file($ownerSecretPath)) {
    throw new RuntimeException('Owner activation proof was not consumed after installation.');
}
if (! is_file($root.'/app/.env') || ! is_file($private.'/installed.lock')) {
    throw new RuntimeException('The installer failed to persist the completed runtime and lock.');
}
if (is_file($installer)) {
    throw new RuntimeException('The one-time installer was not removed.');
}

$env = file_get_contents($root.'/app/.env');
if (! str_contains($env, 'AIWM_UPDATES_ENABLED="false"') ||
    ! str_contains($env, 'APP_ENV="production"') ||
    str_contains($env, 'AIWM_UPDATES_SECRET_KEY')) {
    throw new RuntimeException('Unexpected unsafe production environment or signing secret.');
}

$pdo = new PDO(
    'mysql:host='.$host.';dbname='.$database.';charset=utf8mb4',
    $username,
    $password,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
);
$user = $pdo->query("SELECT email, platform_admin FROM users WHERE email = 'ci-owner@example.test'")->fetch(PDO::FETCH_ASSOC);
if (!$user || (int) $user['platform_admin'] !== 1) {
    throw new RuntimeException('The wizard did not create a real platform administrator.');
}

$count = (int) $pdo->query('SELECT COUNT(*) FROM tenant_memberships')->fetchColumn();
if ($count < 1) {
    throw new RuntimeException('No tenant membership was created.');
}

fwrite(STDOUT, "OWNER_ACTIVATION=PASS ANONYMOUS_TAKEOVER=REJECTED DATABASE=PASS PLATFORM_OWNER=PASS FIRST_RUN_LOCK=PASS SIGNING_FAIL_CLOSED=PASS\n");
