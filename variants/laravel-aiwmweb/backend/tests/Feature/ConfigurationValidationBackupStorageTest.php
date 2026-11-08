<?php

namespace Tests\Feature;

use App\Http\Controllers\ConfigurationValidationController;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class ConfigurationValidationBackupStorageTest extends TestCase
{
    /**
     * Probe the same local-disk path used by TenantBackupCreateService without
     * running a backup or creating/modifying any directory during validation.
     *
     * @return array{key:string,title:string,status:string,value:string,message:string}
     */
    private function backupStatus(): array
    {
        $controller = app(ConfigurationValidationController::class);
        $method = new \ReflectionMethod($controller, 'backupStorageItem');

        return $method->invoke($controller);
    }

    public function test_fresh_install_reports_unprovisioned_backup_directory_as_warning_without_creating_it(): void
    {
        Storage::fake('local');
        $disk = Storage::disk('local');
        $root = $disk->path('');
        $target = $disk->path('backups');

        $this->assertDirectoryExists($root);
        $this->assertDirectoryDoesNotExist($target);

        $item = $this->backupStatus();

        $this->assertSame('backups', $item['key']);
        $this->assertSame('warning', $item['status']);
        $this->assertStringContainsString('not been provisioned', $item['message']);
        $this->assertDirectoryDoesNotExist($target);
        $this->assertStringNotContainsString($root, json_encode($item, JSON_THROW_ON_ERROR));
    }

    public function test_provisioned_canonical_local_backup_directory_reports_valid_without_writes(): void
    {
        Storage::fake('local');
        $disk = Storage::disk('local');
        $disk->makeDirectory('backups');

        $target = $disk->path('backups');
        $before = scandir($target);
        $item = $this->backupStatus();

        $this->assertSame('valid', $item['status']);
        $this->assertSame($before, scandir($target));
        $this->assertStringNotContainsString($target, json_encode($item, JSON_THROW_ON_ERROR));
    }

    public function test_non_directory_collision_reports_error_and_does_not_mutate_file(): void
    {
        Storage::fake('local');
        $disk = Storage::disk('local');
        $disk->put('backups', 'must remain untouched');

        $item = $this->backupStatus();

        $this->assertSame('error', $item['status']);
        $this->assertSame('must remain untouched', $disk->get('backups'));
        $this->assertStringNotContainsString($disk->path('backups'), json_encode($item, JSON_THROW_ON_ERROR));
    }

    public function test_symlinked_backup_target_reports_error_without_traversing_it(): void
    {
        Storage::fake('local');
        if (! function_exists('symlink')) {
            $this->markTestSkipped('Symbolic links are unsupported.');
        }

        $disk = Storage::disk('local');
        $outside = $disk->path('outside-fixture');
        $disk->makeDirectory('outside-fixture');
        $link = $disk->path('backups');

        if (! @symlink($outside, $link)) {
            $this->markTestSkipped('The test filesystem cannot create symbolic links.');
        }

        try {
            $item = $this->backupStatus();
            $this->assertSame('error', $item['status']);
            $this->assertSame(['.', '..'], scandir($outside));
            $this->assertStringNotContainsString($outside, json_encode($item, JSON_THROW_ON_ERROR));
        } finally {
            @unlink($link);
        }
    }
}
