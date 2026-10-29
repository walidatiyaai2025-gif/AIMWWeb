<?php

use App\AI\Durable\BatchService;
use App\AI\Durable\ExecutionPermit;
use App\AI\Platform\Contracts\AiGenerator;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
config(['cpanel.enabled' => true, 'cpanel.ai_enabled' => true, 'cpanel.cron_enabled' => true, 'cpanel.preflight_passed' => true, 'cpanel.min_interval' => 0]);
if (($argv[1] ?? '') === 'setup') {
    Artisan::call('migrate', ['--force' => true]);
    $tenant = Tenant::query()->create(['name' => 'Concurrency', 'slug' => 'concurrency']);
    $user = User::factory()->create();
    $context = app(TenantContext::class);
    $context->activate($tenant);
    $member = TenantMembership::query()->create(['user_id' => $user->id, 'status' => 'active']);
    $role = Role::query()->create(['name' => 'Owner']);
    $permission = Permission::query()->create(['name' => 'ai.use']);
    $role->permissions()->attach($permission, ['tenant_id' => $tenant->id]);
    $member->roles()->attach($role, ['tenant_id' => $tenant->id]);
    $context->activate($tenant, $member);
    $site = Site::query()->create(['name' => 'Fixture', 'url' => 'https://fixture.example', 'status' => 'active']);
    app(BatchService::class)->submit([
        ['key' => 'one', 'request' => ['workflow' => 'test', 'user_prompt' => 'one']],
        ['key' => 'two', 'request' => ['workflow' => 'test', 'user_prompt' => 'two']],
    ], $site->id);
    exit(0);
}
$app->instance(AiGenerator::class, new class implements AiGenerator
{
    public function generate(array $request): array
    {
        app(ExecutionPermit::class)->beforeProviderCall();
        $path = getenv('AI_TEST_COUNTER');
        $file = fopen($path, 'c+');
        flock($file, LOCK_EX);
        $state = json_decode(stream_get_contents($file) ?: '{"active":0,"max":0,"calls":0}', true);
        $state['active']++;
        $state['calls']++;
        $state['max'] = max($state['max'], $state['active']);
        ftruncate($file, 0);
        rewind($file);
        fwrite($file, json_encode($state));
        fflush($file);
        flock($file, LOCK_UN);
        // Deterministic mocked latency gives the second process time to compete.
        usleep(800000);
        flock($file, LOCK_EX);
        rewind($file);
        $state = json_decode(stream_get_contents($file), true);
        $state['active']--;
        ftruncate($file, 0);
        rewind($file);
        fwrite($file, json_encode($state));
        fclose($file);

        return ['provider' => 'mock', 'model' => 'mock', 'correlation_id' => (string) Str::uuid(), 'structured' => ['text' => 'saved'], 'content' => 'saved'];
    }
});
echo app(BatchService::class)->runOne();
