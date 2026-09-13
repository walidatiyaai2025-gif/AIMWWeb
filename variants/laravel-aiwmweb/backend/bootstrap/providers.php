<?php

use App\Providers\AboutBuildRouteServiceProvider;
use App\Providers\AiCenterApprovalStatusRouteServiceProvider;
use App\Providers\AiCenterRouteServiceProvider;
use App\Providers\AiPromptTemplatesRouteServiceProvider;
use App\Providers\AiProviderApiKeyRemovalRouteServiceProvider;
use App\Providers\AiWorkspaceRouteServiceProvider;
use App\Providers\ApprovalQueueRouteServiceProvider;
use App\Providers\ApprovalsReportExportRouteServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\ErrorRouteServiceProvider;
use App\Providers\LoginReadRouteServiceProvider;
use App\Providers\OperationsMaintenanceRouteServiceProvider;
use App\Providers\PublicWelcomeRouteServiceProvider;
use App\Providers\SeoVisibleControlRouteServiceProvider;
use App\Providers\SetupRouteServiceProvider;
use App\Providers\SiteEmailSettingsRouteServiceProvider;
use App\Providers\SiteOperationDetailsRouteServiceProvider;
use App\Providers\SiteOperationsMaintenanceRouteServiceProvider;
use App\Providers\SitesBulkDeleteRouteServiceProvider;
use App\Providers\SitesConnectRouteServiceProvider;
use App\Providers\SiteSettingsRouteServiceProvider;

return [
    AppServiceProvider::class,
    ApprovalsReportExportRouteServiceProvider::class,
    LoginReadRouteServiceProvider::class,
    PublicWelcomeRouteServiceProvider::class,
    AboutBuildRouteServiceProvider::class,
    AiCenterRouteServiceProvider::class,
    AiCenterApprovalStatusRouteServiceProvider::class,
    AiPromptTemplatesRouteServiceProvider::class,
    AiProviderApiKeyRemovalRouteServiceProvider::class,
    AiWorkspaceRouteServiceProvider::class,
    ApprovalQueueRouteServiceProvider::class,
    SetupRouteServiceProvider::class,
    ErrorRouteServiceProvider::class,
    SitesBulkDeleteRouteServiceProvider::class,
    SitesConnectRouteServiceProvider::class,
    OperationsMaintenanceRouteServiceProvider::class,
    SiteOperationsMaintenanceRouteServiceProvider::class,
    SiteOperationDetailsRouteServiceProvider::class,
    SiteEmailSettingsRouteServiceProvider::class,
    SiteSettingsRouteServiceProvider::class,
    SeoVisibleControlRouteServiceProvider::class,
];
