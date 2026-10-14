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
    ->withMiddleware(function (Middleware $middleware): void {
        // Every page here is a logged-in view of live data - never cacheable
        // by a browser, CDN, or hosting-level page cache. See the class docblock.
        $middleware->alias([
            'admin' => \App\Http\Middleware\EnsureAdmin::class,
            'gzip' => \App\Http\Middleware\GzipResponse::class,
            'pmo' => \App\Http\Middleware\EnsureCanEditPlan::class,   // project data / dates: edit.manage (Admin + Project Manager by default)
            'perm' => \App\Http\Middleware\EnsurePermission::class,      // perm:page.planning - Settings > สิทธิ์การใช้งาน
            'project.visible' => \App\Http\Middleware\EnsureProjectVisible::class,   // a project (or anything in one) only for people who see it
        ]);

        $middleware->web(append: [
            \App\Http\Middleware\DisableResponseCaching::class,
            \App\Http\Middleware\RunDailyReminders::class,   // reminders without a cron job (once a day)
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
