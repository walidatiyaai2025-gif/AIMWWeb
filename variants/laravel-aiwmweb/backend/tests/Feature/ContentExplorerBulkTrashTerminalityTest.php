<?php

namespace Tests\Feature;

use App\Http\Controllers\ContentApiController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class ContentExplorerBulkTrashTerminalityTest extends TestCase
{
    public function test_source_contract_and_canonical_route_are_bound(): void
    {
        $source = (string) file_get_contents(base_path('../../../src/AIWordPressManager.Web/Components/Pages/ContentExplorer.razor'));

        $this->assertStringContainsString('BulkTrashAsync', $source);
        $this->assertStringContainsString('ApplicationPermissionCatalog.ContentEdit', $source);
        $this->assertStringContainsString('TrashService.RunAsync', $source);

        $route = Route::getRoutes()->match(
            Request::create('/api/v1/tenants/alpha/sites/7/content/bulk/trash', 'POST'),
        );

        $this->assertSame(ContentApiController::class.'@bulkTrash', ltrim($route->getActionName(), '\\'));
        $this->assertSame('api.v1.content.explorer.bulk-trash', $route->getName());
        $this->assertSame('AIMW-BILL-452B93663B', $route->defaults['canonical_operation_id'] ?? null);
        $this->assertContains('auth', $route->gatherMiddleware());
        $this->assertContains('tenant.context', $route->gatherMiddleware());
    }

    public function test_bulk_trash_handler_keeps_the_server_owned_security_boundary(): void
    {
        $controller = (string) file_get_contents(app_path('Http/Controllers/ContentApiController.php'));

        $this->assertStringContainsString("\$auth->authorize('content.edit')", $controller);
        $this->assertStringContainsString('Bulk trash does not accept caller-owned identity fields.', $controller);
        $this->assertStringContainsString("'content_type' => ['required', Rule::in(['post', 'page'])]", $controller);
        $this->assertStringContainsString("'wordpress_id' => 'required|integer|min:1'", $controller);
        $this->assertStringContainsString("->where('site_id', \$site)", $controller);
        $this->assertStringContainsString("'status' => 'conflict'", $controller);
        $this->assertStringContainsString("'status' => 'trashed'", $controller);
    }
}
