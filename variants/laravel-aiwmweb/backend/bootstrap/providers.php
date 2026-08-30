<?php

use App\Providers\AboutBuildRouteServiceProvider;
use App\Providers\ApprovalsReportExportRouteServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\LoginReadRouteServiceProvider;

return [
    AppServiceProvider::class,
    ApprovalsReportExportRouteServiceProvider::class,
    LoginReadRouteServiceProvider::class,
    AboutBuildRouteServiceProvider::class,
];
