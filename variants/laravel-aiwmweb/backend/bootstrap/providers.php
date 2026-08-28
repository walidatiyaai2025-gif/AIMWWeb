<?php

use App\Providers\AppServiceProvider;
use App\Providers\ApprovalQueueRouteServiceProvider;
use App\Providers\SetupRouteServiceProvider;

return [
    AppServiceProvider::class,
    ApprovalQueueRouteServiceProvider::class,
    SetupRouteServiceProvider::class,
];
