<?php

namespace App\Http\Middleware;

use App\Services\NotificationService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * The daily "due soon / overdue" reminders without a cron job: after the first page someone opens from 07:00 each day,
 * once the answer has been sent (terminate), the reminders go out. Cache::add makes it once per day even when several
 * people arrive at the same moment, and NotificationService never sends the same reminder twice anyway.
 * With a real cron (`* * * * * php artisan schedule:run`, see routes/console.php) both can run side by side.
 */
class RunDailyReminders
{
    /** Tests switch this on to exercise it (normally a test run never sends reminders as a side effect). */
    public static bool $evenInTests = false;

    public function handle(Request $request, Closure $next): Response
    {
        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        if ((app()->runningUnitTests() && ! static::$evenInTests) || ! $request->user() || now()->hour < 7) {
            return;
        }
        if (! Cache::add('pmo:reminders:'.today()->toDateString(), true, now()->addDays(2))) {
            return;   // already done today
        }

        try {
            app(NotificationService::class)->sendReminders();
            app(\App\Services\ProjectFiles::class)->purgeExpired();   // the files' trash empties itself after 30 days
        } catch (\Throwable $e) {
            Cache::forget('pmo:reminders:'.today()->toDateString());   // try again on the next page
            Log::warning('daily reminders failed: '.$e->getMessage());
        }
    }
}
