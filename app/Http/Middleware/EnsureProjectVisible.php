<?php

namespace App\Http\Middleware;

use App\Models\ActivityLog;
use App\Models\Cabinet;
use App\Models\CabinetChecklist;
use App\Models\CabinetSubtask;
use App\Models\CabinetTask;
use App\Models\Project;
use App\Models\ProjectTask;
use App\Services\WorkScope;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Every signed-in route that names a project, or something inside one (cabinet, task, sub task, checklist item,
 * log line), opens only for people who see that project (WorkScope) - a typed URL included.
 */
class EnsureProjectVisible
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user && ! $user->canSeeAllWork()) {
            foreach ($request->route()?->parameters() ?? [] as $param) {
                $project = $this->projectOf($param);
                abort_if($project && ! WorkScope::canSeeProject($project, $user), 403, 'คุณไม่มีสิทธิ์เปิดโครงการนี้');
            }
        }

        return $next($request);
    }

    private function projectOf(mixed $p): ?Project
    {
        return match (true) {
            $p instanceof Project => $p,
            $p instanceof Cabinet => $p->project,
            $p instanceof CabinetTask => $p->cabinet?->project,
            $p instanceof CabinetSubtask => $p->cabinetTask?->cabinet?->project,
            $p instanceof CabinetChecklist => $p->subtask?->cabinetTask?->cabinet?->project,
            $p instanceof ProjectTask, $p instanceof ActivityLog => $p->project,
            default => null,
        };
    }
}
