<?php

namespace App\Jobs;

use App\Models\SeoAudit;
use App\Models\SeoFinding;
use App\Models\SyncedContent;
use Throwable;

final class RunSeoAuditJob extends TenantAwareJob
{
    public function __construct(int $tenantId, public readonly int $auditId)
    {
        parent::__construct($tenantId);
    }

    public function uniqueId(): string
    {
        return "tenant:{$this->tenantId}:audit:{$this->auditId}";
    }

    public function handle(): void
    {
        $audit = SeoAudit::query()->findOrFail($this->auditId);
        $audit->update(['status' => 'running', 'failure' => null]);
        try {
            foreach (SyncedContent::query()->where('site_id', $audit->site_id)->cursor() as $content) {
                $checks = [];
                if (blank($content->title)) {
                    $checks[] = ['missing_title', 'high', 'Add a descriptive page title.'];
                }
                if (blank($content->seo_description)) {
                    $checks[] = ['missing_meta_description', 'medium', 'Add a concise meta description grounded in the page content.'];
                }
                if (blank($content->slug)) {
                    $checks[] = ['missing_slug', 'high', 'Add a stable descriptive slug.'];
                }
                if (mb_strlen(strip_tags((string) $content->content)) < 300) {
                    $checks[] = ['thin_content', 'low', 'Review whether the content answers the user intent with sufficient detail.'];
                }
                if (empty($content->headings)) {
                    $checks[] = ['missing_headings', 'medium', 'Add a meaningful heading structure.'];
                }
                foreach ($checks as [$code,$severity,$recommendation]) {
                    SeoFinding::query()->updateOrCreate(['seo_audit_id' => $audit->id, 'synced_content_id' => $content->id, 'code' => $code], ['severity' => $severity, 'recommendation' => $recommendation, 'status' => 'open']);
                }
            }
            $audit->update(['status' => 'succeeded', 'completed_at' => now()]);
        } catch (Throwable $e) {
            $audit->update(['status' => 'failed', 'failure' => $e->getMessage(), 'completed_at' => now()]);
            throw $e;
        }
    }
}
