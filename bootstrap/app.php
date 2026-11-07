<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->appendToGroup('web', \App\Http\Middleware\SecurityHeaders::class);
        $middleware->alias([
            'permission' => \App\Http\Middleware\CheckPermission::class,
            'superadmin' => \App\Http\Middleware\SuperAdminOnly::class,
            'global_superadmin' => \App\Http\Middleware\GlobalSuperAdminOnly::class,
            'owner' => \App\Http\Middleware\OwnerOnly::class,
            'companymanager' => \App\Http\Middleware\CompanyManagerOnly::class,
            'subscription' => \App\Http\Middleware\EnsureActiveCompanySubscription::class,
            'no_auth_cache' => \App\Http\Middleware\PreventAuthenticatedPageCache::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })
    ->create();
