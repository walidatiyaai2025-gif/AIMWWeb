<?php

use App\Providers\AboutBuildRouteServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\LoginReadRouteServiceProvider;

return [
    AppServiceProvider::class,
    LoginReadRouteServiceProvider::class,
    AboutBuildRouteServiceProvider::class,
];
