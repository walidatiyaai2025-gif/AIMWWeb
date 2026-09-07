<?php

namespace App\Http\Controllers;

use App\Authorization\TenantAuthorizer;
use App\Models\SeoAudit;
use App\Models\SeoFinding;
use App\Models\Site;
use App\Models\SyncedContent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

final class SeoVisibleControlController extends Controller
{
    public function __construct(private readonly TenantAuthorizer $authorizer) {}

    public function manager(Request $request, string $tenant, int $site): View
    {
        $this->authorizeRead();
        $model = Site::query()->findOrFail($site);

        return view('seo.manager', [
            'site' => $model,
            'tenant' => $tenant,
            'config' => [
                'tenant' => $tenant,
                'site' => ['id' => (int) $model->getKey(), 'name' => $model->name, 'url' => $model->url],
                'urls' => [
                    'audits' => "/api/tenants/{$tenant}/sites/{$site}/seo/audits",
                    'findings' => "/api/tenants/{$tenant}/sites/{$site}/seo/audits/__AUDIT__/findings",
                    'prepare_bulk' => "/api/tenants/{$tenant}/sites/{$site}/seo/remediations/bulk",
                    'ai_proposal' => "/api/tenants/{$tenant}/sites/{$site}/seo/findings/__FINDING__/ai-proposal",
                    'proposals' => "/api/v1/tenants/{$tenant}/sites/{$site}/seo/remediations/proposals",
                    'presentation' => "/tenants/{$tenant}/sites/{$site}/seo/presentation",
                    'execution' => "/tenants/{$tenant}/module/execution",
                    'sites' => "/tenants/{$tenant}/sites",
                    'explorer' => "/tenants/{$tenant}/module/posts?site={$site}",
                    'approvals' => "/tenants/{$tenant}/approvals",
                ],
            ],
        ]);
    }

    public function workspace(Request $request, string $tenant): View
    {
        $this->authorizeRead();

        return view('seo.workspace', [
            'tenant' => $tenant,
            'links' => [
                'sites' => "/tenants/{$tenant}/sites",
                'audit' => "/tenants/{$tenant}/module/seo-audit",
                'suggestions' => "/tenants/{$tenant}/module/seo-suggestions",
                'approvals' => "/tenants/{$tenant}/approvals",
            ],
        ]);
    }

    public function presentation(Request $request, string $tenant, int $site): JsonResponse
    {
        $this->authorizeRead();
        $model = Site::query()->findOrFail($site);
        $audit = SeoAudit::query()->where('site_id', $site)->latest()->first();

        if (! $audit) {
            return response()->json(['audit_id' => null, 'links' => []]);
        }

        $findings = SeoFinding::query()
            ->where('seo_audit_id', $audit->id)
            ->orderBy('id')
            ->get();
        $content = SyncedContent::query()
            ->where('site_id', $site)
            ->whereIn('id', $findings->pluck('synced_content_id')->filter()->values())
            ->get()
            ->keyBy('id');

        $links = [];
        foreach ($findings as $finding) {
            $item = $content->get($finding->synced_content_id);
            $links[(string) $finding->getKey()] = $item ? $this->contentLink($model, $item) : null;
        }

        return response()->json([
            'audit_id' => (int) $audit->getKey(),
            'links' => $links,
        ]);
    }

    private function authorizeRead(): void
    {
        $this->authorizer->authorize('tenant.view');
        $this->authorizer->authorize('seo.view');
    }

    private function contentLink(Site $site, SyncedContent $content): ?string
    {
        $canonical = trim((string) $content->seo_canonical);
        if ($canonical !== '' && filter_var($canonical, FILTER_VALIDATE_URL)) {
            return $canonical;
        }

        $siteUrl = rtrim(trim((string) $site->url), '/');
        $slug = trim((string) $content->slug, '/');
        if ($siteUrl === '' || $slug === '' || ! filter_var($siteUrl, FILTER_VALIDATE_URL)) {
            return null;
        }

        return $siteUrl.'/'.$slug.'/';
    }
}
