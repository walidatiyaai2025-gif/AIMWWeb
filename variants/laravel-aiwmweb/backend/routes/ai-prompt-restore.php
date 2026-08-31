<?php

use App\Http\Controllers\AiPromptTemplateRestoreController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'tenant.context'])
    ->post('/tenants/{tenant}/settings/ai-prompts/{template}/revisions/{version}/restore', AiPromptTemplateRestoreController::class)
    ->whereNumber('version')
    ->name('tenant.settings.ai-prompts.restore');
