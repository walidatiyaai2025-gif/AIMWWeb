<?php

use App\Providers\AboutBuildRouteServiceProvider;
use App\Providers\AiCenterApprovalStatusRouteServiceProvider;
use App\Providers\AiPromptTemplatesRouteServiceProvider;
use App\Providers\ApprovalQueueRouteServiceProvider;
use App\Providers\ApprovalsReportExportRouteServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\ErrorRouteServiceProvider;
use App\Providers\LoginReadRouteServiceProvider;
use App\Providers\OperationsMaintenanceRouteServiceProvider;
use App\Providers\SetupRouteServiceProvider;
use App\Providers\SitesBulkDeleteRouteServiceProvider;
use App\Providers\SitesConnectRouteServiceProvider;
use App\Providers\SiteSettingsRouteServiceProvider;

return [
    AppServiceProvider::class,
    ApprovalsReportExportRouteServiceProvider::class,
    LoginReadRouteServiceProvider::class,
    AboutBuildRouteServiceProvider::class,
    AiCenterApprovalStatusRouteServiceProvider::class,
    AiPromptTemplatesRouteServiceProvider::class,
    ApprovalQueueRouteServiceProvider::class,
    OperationsMaintenanceRouteServiceProvider::class,
    SetupRouteServiceProvider::class,
    ErrorRouteServiceProvider::class,
    SitesBulkDeleteRouteServiceProvider::class,
    SitesConnectRouteServiceProvider::class,
    SiteSettingsRouteServiceProvider::class,
];
