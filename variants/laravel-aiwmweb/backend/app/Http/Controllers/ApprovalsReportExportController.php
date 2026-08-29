<?php

namespace App\Http\Controllers;

use App\Authorization\TenantAuthorizer;
use App\Tenancy\TenantContext;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class ApprovalsReportExportController extends Controller
{
    public function show(
        string $tenant,
        TenantAuthorizer $authorizer,
        TenantContext $context,
    ): View {
        $authorizer->authorize('reports.view');
        $this->assertTenant($tenant, $context);

        $rows = $this->rows($context);

        return view('reports.approvals-export', [
            'rows' => $rows,
            'canExport' => $context->membership()->hasPermission('reports.manage'),
            'downloadUrl' => '/tenants/'.rawurlencode($tenant).'/reports/approvals.csv',
        ]);
    }

    public function download(
        string $tenant,
        TenantAuthorizer $authorizer,
        TenantContext $context,
    ): StreamedResponse {
        $authorizer->authorize('reports.manage');
        $this->assertTenant($tenant, $context);

        $rows = $this->rows($context);

        return response()->streamDownload(function () use ($rows): void {
            $stream = fopen('php://output', 'wb');
            fwrite($stream, "\xEF\xBB\xBF");
            fputcsv($stream, ['Id', 'Title', 'Site', 'Status']);

            foreach ($rows as $row) {
                fputcsv($stream, [$row['id'], $row['title'], $row['site'], $row['status']]);
            }

            fclose($stream);
        }, 'approvals-report.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function rows(TenantContext $context): Collection
    {
        return DB::table('approvals')
            ->join('suggestions', function ($join): void {
                $join->on('suggestions.id', '=', 'approvals.suggestion_id')
                    ->on('suggestions.tenant_id', '=', 'approvals.tenant_id');
            })
            ->join('sites', function ($join): void {
                $join->on('sites.id', '=', 'suggestions.site_id')
                    ->on('sites.tenant_id', '=', 'approvals.tenant_id');
            })
            ->leftJoin('synced_contents', function ($join): void {
                $join->on('synced_contents.id', '=', 'suggestions.synced_content_id')
                    ->on('synced_contents.tenant_id', '=', 'approvals.tenant_id');
            })
            ->where('approvals.tenant_id', $context->id())
            ->orderBy('approvals.id')
            ->get([
                'approvals.id',
                'approvals.status',
                'sites.name as site_name',
                'synced_contents.title as content_title',
            ])
            ->map(fn ($row): array => [
                'id' => (string) $row->id,
                'title' => trim((string) ($row->content_title ?? '')) !== ''
                    ? (string) $row->content_title
                    : 'Approval #'.$row->id,
                'site' => (string) $row->site_name,
                'status' => (string) $row->status,
            ]);
    }

    private function assertTenant(string $tenant, TenantContext $context): void
    {
        abort_unless($context->tenant()->slug === $tenant, 404);
    }
}
