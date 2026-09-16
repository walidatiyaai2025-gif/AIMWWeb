<?php

namespace Tests\Feature;

use App\Http\Controllers\ContentApiController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class CommentsCancelReplyControlTerminalityTest extends TestCase
{
    private const OPERATION_ID = 'AIMW-COMM-843A2F029B';

    public function test_exact_canonical_input_is_the_pending_comments_cancel_reply_control(): void
    {
        $document = json_decode(
            (string) file_get_contents(base_path('../docs/capability-parity-ledger.json')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $operation = collect($document['operations'])->firstWhere('operation_id', self::OPERATION_ID);

        $this->assertNotNull($operation);
        $this->assertSame('PENDING', $operation['migration_state']);
        $this->assertSame('comments', $operation['domain']);
        $this->assertSame('visible_control', $operation['kind']);
        $this->assertSame('/sites/{SiteId:guid}/comments', $operation['route_screen']);
        $this->assertSame('src/AIWordPressManager.Web/Components/Pages/CommentsManager.razor', $operation['current_source']);
        $this->assertStringContainsString('CancelReplyClicked', $operation['visible_control']);
        $this->assertFalse((bool) $operation['mutation']);
        $this->assertTrue((bool) $operation['tenant_owned']);
        $this->assertSame('low', $operation['risk']);
    }

    public function test_real_reply_route_requires_tenant_context_and_content_edit_and_scopes_comment_to_site(): void
    {
        $route = Route::getRoutes()->match(Request::create('/api/v1/tenants/alpha/sites/17/comments/9/reply', 'POST'));

        $this->assertSame(ContentApiController::class.'@replyComment', ltrim($route->getActionName(), '\\'));
        $this->assertContains('web', $route->gatherMiddleware());
        $this->assertContains('tenant.context', $route->gatherMiddleware());

        $middleware = (string) file_get_contents(app_path('Http/Middleware/ResolveTenantContext.php'));
        $controller = (string) file_get_contents(app_path('Http/Controllers/ContentApiController.php'));
        $this->assertStringContainsString('abort_unless($user, 401)', $middleware);
        $this->assertStringContainsString("->where('user_id', $user->getAuthIdentifier())", $middleware);
        $this->assertStringContainsString("->where('status', 'active')", $middleware);
        $this->assertStringContainsString("->where('slug', $slug)", $middleware);
        $this->assertStringContainsString("$auth->authorize('content.edit')", $controller);
        $this->assertStringContainsString("->where('site_id', $site)->findOrFail($comment)", $controller);
        $this->assertStringContainsString("'content' => 'required|string|max:20000'", $controller);
    }

    public function test_frontend_cancel_is_permission_gated_local_only_and_supporting_send_uses_csrf_same_origin_without_retry(): void
    {
        $component = (string) file_get_contents(resource_path('js/comments-comment-link-control.tsx'));
        $core = (string) file_get_contents(resource_path('js/core.ts'));
        $app = (string) file_get_contents(resource_path('js/app.tsx'));

        $this->assertStringContainsString(self::OPERATION_ID, $component);
        $this->assertStringContainsString("context.permissions.includes('content.edit')", $component);
        $this->assertStringContainsString('data-canonical-operation={COMMENTS_CANCEL_REPLY_OPERATION_ID}', $component);
        $this->assertStringContainsString('setReplyingTo(null)', $component);
        $this->assertStringContainsString("setDraft('')", $component);
        $this->assertStringContainsString('if (mutation.isPending) return;', $component);
        $this->assertStringContainsString('retry: false', $component);
        $this->assertStringContainsString("url.origin !== window.location.origin", $component);
        $this->assertStringContainsString("path.endsWith('/comments')", $component);
        $this->assertStringContainsString("route.key === 'comments'", $app);
        $this->assertStringContainsString('CommentsCommentLinksControl', $app);

        $this->assertStringContainsString("meta[name=\"csrf-token\"]", $core);
        $this->assertStringContainsString("headers.set('X-CSRF-TOKEN', csrf)", $core);
        $this->assertStringContainsString("credentials: 'same-origin'", $core);
    }
}
