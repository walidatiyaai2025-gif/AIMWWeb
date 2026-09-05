<?php

namespace App\Providers;

use App\Http\Controllers\SeoVisibleControlController;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

final class SeoVisibleControlRouteServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Route::prefix('/tenants/{tenant}')
            ->middleware(['web', 'auth', 'tenant.context'])
            ->controller(SeoVisibleControlController::class)
            ->group(function (): void {
                Route::get('/sites/{site}/seo', 'manager')
                    ->defaults('canonical_operation_id', 'AIMW-SEO-5F71B89C92')
                    ->whereNumber('site')
                    ->name('canonical.site.seo');

                Route::get('/sites/{site}/seo/presentation', 'presentation')
                    ->whereNumber('site')
                    ->name('canonical.site.seo.presentation');

                Route::get('/seo-workspace', 'workspace')
                    ->defaults('canonical_operation_id', 'AIMW-SEO-4CBBC7AAD9')
                    ->name('canonical.workspace.seo-hub');
            });
    }
}
