<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** 'perm:page.planning' - one permission of Settings > สิทธิ์การใช้งาน (App\Support\Access). The dashboard sends you on to a page you may open. */
class EnsurePermission
{
    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $user = $request->user();
        if ($user && ! $user->allows($permission)) {
            if ($permission === 'page.dashboard' && $request->isMethod('GET')) {
                return redirect()->to(self::home($user));
            }
            abort(403, 'คุณไม่มีสิทธิ์ใช้หน้านี้ (ตั้งค่า → สิทธิ์การใช้งาน)');
        }

        return $next($request);
    }

    /** Where someone lands when the dashboard is not for them: the first page they may open. */
    public static function home(\App\Models\User $user): string
    {
        foreach (['page.planning' => 'planning.board', 'page.projects' => 'projects.index', 'page.cabinets' => 'cabinets.index'] as $perm => $route) {
            if ($user->allows($perm)) {
                return route($route);
            }
        }

        return route('notifications.index');
    }
}
