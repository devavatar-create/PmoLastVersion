<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Department;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Builds the JSON behind the wide "โครงการ" dialog opened from a calendar bar in My Department:
 * the project header block, one chip per department, the cabinets with their Sub Tasks, and the activity feed.
 */
class ProjectModal
{
    public function __construct(private DepartmentSchedule $schedule) {}

    /**
     * A user may open a Project here if they are PMO-level, or their own department has dispatched work in it -
     * the same visibility as the calendar itself (not "any project by id").
     */
    public function canOpen(User $user, Project $project): bool
    {
        return $user->canViewOtherDepartments() || WorkScope::projectSubtasks($project, $user)->exists();
    }

    public function payload(Project $project, User $user): array
    {
        $today = today();

        $subtasks = WorkScope::projectSubtasks($project, $user)
            ->with('cabinetTask.cabinet.project', 'owner', 'department')
            ->withCount([
                'checklists',
                'checklists as completed_checklists_count' => fn ($q) => $q->where('is_completed', true),
            ])
            ->orderBy('due_date')->orderBy('id')->get();

        $items = $subtasks->map(fn ($s) => $this->schedule->item($s, $user, $today));

        return [
            'project' => $this->block($project, $user),
            'departments' => $this->departmentChips($items),
            'cabinets' => $this->cabinets($items),
            'activity' => $user->allows('data.comments') ? $this->feed($project, $user)->map(fn ($l) => $this->activityItem($l))->values() : collect(),   // Settings > สิทธิ์การใช้งาน
            'alert_departments' => $user->canDispatchWork() ? Department::where('is_active', true)->orderBy('name')->get(['id', 'name']) : [],
        ];
    }

    public function block(Project $project, User $user): array
    {
        $project->loadMissing('projectManager');

        return [
            'id' => $project->id,
            'project_no' => $project->project_no,
            'project_name' => $project->project_name,
            'customer_name' => $project->customer_name,
            'status' => $project->status,
            'priority' => $project->priority,
            'start_date' => $project->start_date?->format('Y-m-d'),
            'due_date' => $project->due_date?->format('Y-m-d'),
            'description' => $project->description,
            'manager_name' => $project->projectManager?->name,
            'locked' => $project->isLocked(),
            'can_edit' => $user->canDispatchWork(),
            'statuses' => Project::$statuses,
            'priorities' => Project::$priorities,
            'urls' => [
                'show' => route('projects.show', $project),
                'update' => route('my-department.project.update', $project),
                'note' => route('my-department.project.note', $project),
                'destroy' => route('projects.destroy', $project),
            ],
        ];
    }

    public function activityItem(ActivityLog $log): array
    {
        return [
            'id' => $log->id,
            'user_name' => $log->user?->name ?? 'ระบบ',
            'action' => $log->action,
            'is_note' => $log->action === 'note',
            'description' => $log->descriptionFor(auth()->user()),   // money / phone values masked for whom Settings > สิทธิ์การใช้งาน hides them
            'created_at' => $log->created_at?->thaiDateTime(),
            'attachments' => $log->attachments->map(fn ($a) => ['name' => $a->original_name, 'url' => $a->url, 'size' => $a->size_for_humans])->values(),
        ];
    }

    /** "ส่งต่อแผนก": one chip per department with work in this project. */
    private function departmentChips(Collection $items): Collection
    {
        return $items->groupBy('department_id')->map(function ($rows) {
            $done = $rows->where('assignment_status', 'COMPLETED')->count();
            $checkTotal = $rows->sum('checklists_total');

            return [
                'id' => $rows->first()['department_id'],
                'name' => $rows->first()['department_name'],
                'total' => $rows->count(),
                'done' => $done,
                'overdue' => $rows->where('is_overdue', true)->count(),
                'due_date' => $rows->pluck('due_date')->filter()->max(),
                'pct' => $checkTotal > 0 ? (int) round($rows->sum('checklists_completed') / $checkTotal * 100) : ($rows->count() ? (int) round($done / $rows->count() * 100) : 0),
            ];
        })->sortBy(fn ($d) => $d['due_date'] ?? '9999-12-31')->values();
    }

    private function cabinets(Collection $items): Collection
    {
        return $items->groupBy('cabinet_id')->map(function ($rows) {
            $first = $rows->first();
            $checkTotal = $rows->sum('checklists_total');
            $checkDone = $rows->sum('checklists_completed');

            return [
                'id' => $first['cabinet_id'],
                'mo' => $first['cabinet_mo'],
                'name' => $first['cabinet_name'],
                'checklists_total' => $checkTotal,
                'checklists_completed' => $checkDone,
                'pct' => $checkTotal > 0 ? (int) round($checkDone / $checkTotal * 100) : 0,
                'due_date' => $rows->pluck('due_date')->filter()->max(),
                'items' => $rows->values(),
            ];
        })->sortBy('mo')->values();
    }

    private function feed(Project $project, User $user): Collection
    {
        return ActivityLog::query()->where('project_id', $project->id)
            // everyone sees the discussion; the system's own change log is for PMO roles only
            ->when(! $user->canViewOtherDepartments(), fn ($q) => $q->where('action', 'note'))
            // "created" rows of cabinets/tasks/checklists are bulk noise (a template creates hundreds) - keep only the Project's own
            ->where(fn ($q) => $q->where('action', '!=', 'created')->orWhere('loggable_type', Project::class))
            ->with('user', 'attachments')->latest('id')->limit(40)->get();
    }
}
