<?php

namespace App\Services;

use App\Models\CabinetSubtask;
use App\Models\Department;
use App\Models\Project;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Read side of "My Department": who may look at which department, the one schedule query behind the calendar
 * JSON and the CSV export, and the array shape of a single Sub Task item. Nothing is stored for it.
 */
class DepartmentSchedule
{
    public function __construct(private SubtaskAssignmentService $assignments) {}

    /**
     * Which department is being viewed - null means "ทุกแผนก" (PMO only).
     * Regular users are pinned to their own; admin/PM may pick another
     * department, or all of them, via department_id. This is the ONLY place
     * that decision is made, so a Department User can never reach another
     * department's data by hand-crafting the request (not just hidden in the UI).
     */
    public function resolveDepartment(User $user, mixed $requested): ?Department
    {
        // read-only lists / CSV: a department marked "sees everything" (Settings > แผนก) may ask for every department too
        if ($user->canSeeAllWork()) {
            if ($requested === 'all') {
                return null;
            }

            if ($requested) {
                return Department::findOrFail((int) $requested);
            }

            return $user->department
                ?? Department::where('is_active', true)->orderBy('name')->first()
                ?? abort(404, 'ยังไม่มีแผนกในระบบ');
        }

        abort_unless($user->department_id, 403, 'บัญชีของคุณยังไม่ได้ผูกกับแผนก กรุณาติดต่อผู้ดูแลระบบ');
        abort_if($requested && ((int) $requested !== $user->department_id), 403, 'คุณไม่มีสิทธิ์ดูงานของแผนกอื่น');

        return $user->department;
    }

    /** You can only open (or star) a Sub Task that is dispatched to a department you may look at. */
    public function canViewSubtask(User $user, CabinetSubtask $subtask): bool
    {
        return $subtask->department_id !== null
            && ($user->canViewOtherDepartments() || $user->department_id === $subtask->department_id);
    }

    /**
     * The one query behind both the calendar JSON and the CSV, so what is exported
     * is exactly what the screen shows. $data is the validated window/filters.
     */
    public function subtasks(?Department $department, array $data, Carbon $today): Collection
    {
        return CabinetSubtask::query()
            ->when($department, fn ($q) => $q->where('department_id', $department->id))
            ->whereIn('assignment_status', WorkScope::ACTIVE_STATUSES)
            ->when($data['project_id'] ?? null, function ($q) use ($data) {
                $q->whereHas('cabinetTask.cabinet', fn ($q) => $q->where('project_id', $data['project_id']));
            })
            ->when($data['status'] ?? null, function ($q) use ($data, $today) {
                // OVERDUE is a derived state (due date passed, not completed),
                // never a real assignment_status value - same rule the frontend
                // already uses for is_overdue, just applied server-side here.
                if ($data['status'] === 'OVERDUE') {
                    $q->whereDate('due_date', '<', $today)->where('assignment_status', '!=', CabinetSubtask::ASSIGNMENT_COMPLETED);
                } else {
                    $q->where('assignment_status', $data['status']);
                }
            })
            ->where(function ($q) use ($data, $today) {
                // Active in the window: [start ?? due, due ?? start] overlaps [from, to].
                $q->where(function ($q) use ($data) {
                    $q->whereRaw('COALESCE(start_date, due_date) <= ?', [$data['to']])
                        ->whereRaw('COALESCE(due_date, start_date) >= ?', [$data['from']]);
                })
                    ->orWhere(function ($q) use ($today) {
                        $q->whereDate('due_date', '<', $today)
                            ->where('assignment_status', '!=', CabinetSubtask::ASSIGNMENT_COMPLETED);
                    })
                    ->orWhere(function ($q) {
                        $q->whereNull('start_date')->whereNull('due_date');
                    });
            })
            ->with('cabinetTask.cabinet.project', 'owner', 'department')
            ->withCount([
                'checklists',
                'checklists as completed_checklists_count' => fn ($q) => $q->where('is_completed', true),
            ])
            ->orderBy('due_date')
            ->orderBy('id')
            ->get();
    }

    /**
     * The signed-in user's starred Sub Task ids - one query per request, kept on the
     * request itself (not on this service) so it can never outlive that request.
     */
    public function starredIds(User $user): Collection
    {
        $bag = request()->attributes;
        $key = 'starred_subtask_ids.'.$user->id;

        if (! $bag->has($key)) {
            $bag->set($key, $user->starredSubtasks()->pluck('cabinet_subtasks.id'));
        }

        return $bag->get($key);
    }

    public function item(CabinetSubtask $subtask, User $user, Carbon $today): array
    {
        $cabinet = $subtask->cabinetTask->cabinet;
        $project = $cabinet->project;
        $completed = $subtask->isDone();
        $daysRemaining = $subtask->due_date ? (int) $today->diffInDays($subtask->due_date, false) : null;

        return [
            'id' => $subtask->id,
            'project_id' => $project->id,
            'project_no' => $project->project_no,
            'project_name' => $project->project_name,
            'customer_name' => $project->customer_name,
            // User-assigned Calendar color override (nullable) - see taskColor()
            // in _script.blade.php, which falls back to the auto-assigned palette
            // when this is null. project_color_url follows the same per-item URL
            // convention as the `urls` block below instead of being built client-side.
            'color' => $project->color,
            'project_color_url' => route('projects.color', $project),
            'cabinet_id' => $cabinet->id,
            'cabinet_mo' => $cabinet->mo_no,
            'cabinet_name' => $cabinet->cabinet_name,
            'cabinet_task_id' => $subtask->cabinet_task_id,
            'task_name' => $subtask->cabinetTask->name,
            'name' => $subtask->name,
            // Per-item Department - needed once "ทุกแผนก" can return items from more than
            // one Department at a time; a single-Department view already knows this from
            // the top-level `department` key, but both views read the same shape.
            'department_id' => $subtask->department_id,
            'department_name' => $subtask->department?->name,
            'owner_id' => $subtask->owner_id,
            'owner_name' => $subtask->owner?->name,
            'start_date' => $subtask->start_date?->format('Y-m-d'),
            'due_date' => $subtask->due_date?->format('Y-m-d'),
            // Sub tasks carry no priority of their own - it is the project's.
            'priority' => $project->priority,
            'priority_label' => Project::$priorities[$project->priority] ?? $project->priority,
            'assignment_status' => $subtask->assignment_status,
            'assignment_status_label' => $subtask->assignment_status_label,
            'is_done' => $completed,
            'is_overdue' => $daysRemaining !== null && $daysRemaining < 0 && ! $completed,
            'is_near_due' => $daysRemaining !== null && $daysRemaining >= 0 && $daysRemaining <= 3 && ! $completed,
            'days_remaining' => $daysRemaining,
            'progress' => $subtask->progress,
            'checklists_total' => $subtask->checklists_count ?? 0,
            'checklists_completed' => $subtask->completed_checklists_count ?? 0,
            'is_department_locked' => $subtask->is_department_locked,
            'is_starred' => $this->starredIds($user)->contains($subtask->id),
            'urls' => [
                'accept' => route('cabinet-subtasks.accept', $subtask),
                'start' => route('cabinet-subtasks.start', $subtask),
                'complete' => route('cabinet-subtasks.complete', $subtask),
                'detail' => route('my-department.show', $subtask),
                'star' => route('my-department.star', $subtask),
            ],
        ] + $this->assignments->actionsFor($subtask, $user);
    }
}
