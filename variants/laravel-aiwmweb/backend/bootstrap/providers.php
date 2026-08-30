<?php

use App\Providers\AboutBuildRouteServiceProvider;
use App\Providers\AiPromptTemplatesRouteServiceProvider;
use App\Providers\ApprovalQueueRouteServiceProvider;
use App\Providers\ApprovalsReportExportRouteServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\LoginReadRouteServiceProvider;
use App\Providers\SetupRouteServiceProvider;
use App\Providers\SitesBulkDeleteRouteServiceProvider;

return [
    AppServiceProvider::class,
    ApprovalsReportExportRouteServiceProvider::class,
    LoginReadRouteServiceProvider::class,
    AboutBuildRouteServiceProvider::class,
    AiPromptTemplatesRouteServiceProvider::class,
    ApprovalQueueRouteServiceProvider::class,
    SetupRouteServiceProvider::class,
    SitesBulkDeleteRouteServiceProvider::class,
];
