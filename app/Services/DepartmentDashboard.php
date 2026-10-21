<?php

namespace App\Services;

use App\Models\CabinetSubtask;
use App\Models\Department;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Department-level numbers for the dashboard (Sub Task granularity, the same
 * rows My Department shows): headline tiles, an "unfinished work by due date"
 * workload histogram, and one progress row per department.
 *
 * Read-only. Which departments the viewer may see is decided by the caller
 * (DashboardController) and passed in as $departmentIds - this class never
 * widens that scope.
 */
class DepartmentDashboard
{
    /** Same rows the calendar shows: a Sub Task that has actually been dispatched to a department. */
    private const ACTIVE_STATUSES = [
        CabinetSubtask::ASSIGNMENT_ASSIGNED,
        CabinetSubtask::ASSIGNMENT_ACCEPTED,
        CabinetSubtask::ASSIGNMENT_IN_PROGRESS,
        CabinetSubtask::ASSIGNMENT_COMPLETED,
    ];

    /**
     * Which departments this user may see numbers for - THE one place that decides it
     * (dashboard + status report both call it). Admin/PM: a requested department, or all
     * (null). Everyone else: their own department only, whatever they ask for; no
     * department at all means nothing ([]).
     *
     * @return list<int>|null
     */
    public static function scopeFor(User $user, ?int $requestedDepartmentId = null): ?array
    {
        if ($user->canSeeAllWork()) {
            return $requestedDepartmentId ? [$requestedDepartmentId] : null;
        }

        return $user->department_id ? [$user->department_id] : [];
    }

    /**
     * @param  list<int>|null  $departmentIds  null = every department, [] = none (nothing to show)
     * @return array{totals: array, buckets: list<array>, departments: list<array>}
     */
    public function build(?array $departmentIds, Carbon $today): array
    {
        $subtasks = $this->subtasks($departmentIds);

        return [
            'totals' => $this->totals($subtasks, $today),
            'buckets' => $this->workloadBuckets($subtasks, $today),
            'departments' => $this->perDepartment($subtasks, $departmentIds, $today),
        ];
    }

    /**
     * One row per Project for the dashboard table (Avatar Planning's "โปรเจคทั้งหมด"): worst-first status,
     * latest due date, cabinets, checklist progress and the departments involved. Same department scope rules.
     *
     * @param  list<int>|null  $departmentIds
     * @return list<array<string, mixed>>
     */
    public function projects(?array $departmentIds, Carbon $today, int $limit = 200): array
    {
        if ($departmentIds === []) {
            return [];
        }

        $rows = CabinetSubtask::query()
            ->whereNotNull('department_id')
            ->whereIn('assignment_status', self::ACTIVE_STATUSES)
            ->when($departmentIds !== null, fn ($q) => $q->whereIn('department_id', $departmentIds))
            ->with('cabinetTask.cabinet.project', 'department:id,name')
            ->withCount([
                'checklists',
                'checklists as completed_checklists_count' => fn ($q) => $q->where('is_completed', true),
            ])
            ->get();

        return $rows->groupBy(fn ($s) => $s->cabinetTask->cabinet->project_id)->map(function (Collection $group) use ($today) {
            $project = $group->first()->cabinetTask->cabinet->project;
            $due = $group->pluck('due_date')->filter()->max();
            $status = match (true) {
                $group->contains(fn ($s) => $this->isOverdue($s, $today)) => 'overdue',
                $group->every(fn ($s) => $this->isDone($s)) => 'completed',
                $group->contains(fn ($s) => $s->assignment_status === CabinetSubtask::ASSIGNMENT_IN_PROGRESS) => 'in_progress',
                $group->contains(fn ($s) => $s->assignment_status === CabinetSubtask::ASSIGNMENT_ACCEPTED) => 'accepted',
                default => 'assigned',
            };
            $checkDone = (int) $group->sum('completed_checklists_count');
            $checkTotal = (int) $group->sum('checklists_count');

            return [
                'project_id' => $project->id,
                'project_no' => $project->project_no,
                'project_name' => $project->project_name,
                'priority' => $project->priority,
                'due_date' => $due?->format('Y-m-d'),
                'days_remaining' => $due ? (int) $today->diffInDays($due, false) : null,
                'status' => $status,
                'cabinets' => $group->pluck('cabinetTask.cabinet_id')->unique()->count(),
                'departments' => $group->pluck('department.name')->filter()->unique()->sort()->values()->all(),
                'checklist_done' => $checkDone,
                'checklist_total' => $checkTotal,
                'checklist_pct' => $checkTotal > 0 ? (int) round($checkDone / $checkTotal * 100) : 0,
            ];
        })->sortBy([
            fn ($a, $b) => ($a['status'] === 'overdue' ? 0 : 1) <=> ($b['status'] === 'overdue' ? 0 : 1),
            fn ($a, $b) => ($a['due_date'] ?? '9999-12-31') <=> ($b['due_date'] ?? '9999-12-31'),
        ])->take($limit)->values()->all();
    }

    private function subtasks(?array $departmentIds): Collection
    {
        if ($departmentIds === []) {
            return collect();
        }

        return CabinetSubtask::query()
            ->whereNotNull('department_id')
            ->whereIn('assignment_status', self::ACTIVE_STATUSES)
            ->when($departmentIds !== null, fn ($q) => $q->whereIn('department_id', $departmentIds))
            // a closed project is finished and read-only: its work is not the department's load (as on the overview)
            ->whereHas('cabinetTask.cabinet.project', fn ($q) => $q->whereNotIn('status', [\App\Models\Project::STATUS_COMPLETED, \App\Models\Project::STATUS_CANCELLED]))
            ->withCount([
                'checklists',
                'checklists as completed_checklists_count' => fn ($q) => $q->where('is_completed', true),
            ])
            ->get(['id', 'department_id', 'status', 'assignment_status', 'due_date']);
    }

    private function isDone(CabinetSubtask $s): bool
    {
        return $s->isDone();
    }

    private function isOverdue(CabinetSubtask $s, Carbon $today): bool
    {
        return ! $this->isDone($s) && $s->due_date !== null && $s->due_date->lt($today);
    }

    private function totals(Collection $subtasks, Carbon $today): array
    {
        $checkDone = (int) $subtasks->sum('completed_checklists_count');
        $checkTotal = (int) $subtasks->sum('checklists_count');

        return [
            'total' => $subtasks->count(),
            'completed' => $subtasks->filter(fn ($s) => $this->isDone($s))->count(),
            'in_progress' => $subtasks->filter(fn ($s) => in_array($s->assignment_status, [
                CabinetSubtask::ASSIGNMENT_ACCEPTED,
                CabinetSubtask::ASSIGNMENT_IN_PROGRESS,
            ], true))->count(),
            'overdue' => $subtasks->filter(fn ($s) => $this->isOverdue($s, $today))->count(),
            'checklist_done' => $checkDone,
            'checklist_total' => $checkTotal,
            'checklist_pct' => $checkTotal > 0 ? (int) round($checkDone / $checkTotal * 100) : 0,
        ];
    }

    /**
     * Unfinished, dated Sub Tasks by how soon they are due. Undated work is not
     * in any bucket (it has no due date to place) - it still counts in the totals.
     *
     * @return list<array{key: string, label: string, count: int, overdue: bool}>
     */
    private function workloadBuckets(Collection $subtasks, Carbon $today): array
    {
        $buckets = [
            ['key' => 'overdue', 'label' => 'เลยกำหนด', 'count' => 0, 'overdue' => true],
            ['key' => 'd7', 'label' => 'ภายใน 7 วัน', 'count' => 0, 'overdue' => false],
            ['key' => 'd14', 'label' => '8–14 วัน', 'count' => 0, 'overdue' => false],
            ['key' => 'd21', 'label' => '15–21 วัน', 'count' => 0, 'overdue' => false],
            ['key' => 'd22', 'label' => '22+ วัน', 'count' => 0, 'overdue' => false],
        ];

        foreach ($subtasks as $s) {
            if ($this->isDone($s) || $s->due_date === null) {
                continue;
            }

            $days = (int) $today->diffInDays($s->due_date, false);
            $i = match (true) {
                $days < 0 => 0,
                $days <= 7 => 1,
                $days <= 14 => 2,
                $days <= 21 => 3,
                default => 4,
            };
            $buckets[$i]['count']++;
        }

        return $buckets;
    }

    /**
     * One row per department that is in scope (even with no work yet, so the
     * list does not change shape when a department has nothing on its plate).
     */
    private function perDepartment(Collection $subtasks, ?array $departmentIds, Carbon $today): array
    {
        $departments = Department::query()
            ->where('is_active', true)
            ->when($departmentIds !== null, fn ($q) => $q->whereIn('id', $departmentIds))
            ->orderBy('name')
            ->get(['id', 'name']);

        $byDept = $subtasks->groupBy('department_id');

        return $departments->map(function (Department $d) use ($byDept, $today) {
            $rows = $byDept->get($d->id, collect());
            $done = $rows->filter(fn ($s) => $this->isDone($s))->count();
            $checkDone = (int) $rows->sum('completed_checklists_count');
            $checkTotal = (int) $rows->sum('checklists_count');

            return [
                'id' => $d->id,
                'name' => $d->name,
                'total' => $rows->count(),
                'completed' => $done,
                'overdue' => $rows->filter(fn ($s) => $this->isOverdue($s, $today))->count(),
                'checklist_done' => $checkDone,
                'checklist_total' => $checkTotal,
                'checklist_pct' => $checkTotal > 0 ? (int) round($checkDone / $checkTotal * 100) : 0,
            ];
        })->values()->all();
    }
}
