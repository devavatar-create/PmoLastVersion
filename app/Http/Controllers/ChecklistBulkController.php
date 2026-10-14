<?php

namespace App\Http\Controllers;

use App\Exceptions\PlanningRejected;
use App\Exceptions\ProjectLockedException;
use App\Models\Project;
use App\Services\ChecklistBulk;
use Illuminate\Http\Request;

/** "แก้ Checklist ทั้งโครงการ" on the project page (Admin / PM - route middleware 'pmo'). */
class ChecklistBulkController extends Controller
{
    public function options(Project $project, ChecklistBulk $bulk)
    {
        return response()->json(['subtasks' => $bulk->options($project)]);
    }

    public function run(Request $request, Project $project, ChecklistBulk $bulk)
    {
        $request->validate(['action' => ['required', 'in:add,rename,delete'], 'subtask' => ['required', 'string', 'max:255']]);
        try {
            return response()->json(['ok' => true] + $bulk->run($project, $request->user(), $request->all(), $request->boolean('preview')));
        } catch (PlanningRejected $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 422);
        } catch (ProjectLockedException $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 423);
        }
    }
}
