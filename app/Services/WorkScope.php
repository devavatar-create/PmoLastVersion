<?php

namespace App\Services;

use App\Models\CabinetSubtask;
use App\Models\Project;
use App\Models\User;

/**
 * Which projects a user sees, and which dispatched Sub Tasks of a Project: PMO roles (admin / Project Manager) all of them, everyone
 * else only their own department's. One definition shared by My Department's project modal and the /planning page,
 * so the two can never disagree about who sees what.
 */
class WorkScope
{
    public const ACTIVE_STATUSES = [
        CabinetSubtask::ASSIGNMENT_ASSIGNED,
        CabinetSubtask::ASSIGNMENT_ACCEPTED,
        CabinetSubtask::ASSIGNMENT_IN_PROGRESS,
        CabinetSubtask::ASSIGNMENT_COMPLETED,
    ];

    public static function projectSubtasks(Project $project, User $user)
    {
        return CabinetSubtask::query()
            ->whereHas('cabinetTask.cabinet', fn ($q) => $q->where('project_id', $project->id))
            ->whereNotNull('department_id')
            ->whereIn('assignment_status', self::ACTIVE_STATUSES)
            ->when(! $user->canSeeAllWork(), fn ($q) => $q->where('department_id', $user->department_id));
    }

    /**
     * Whether a person sees a project at all (every page, its JSON, files, CSV): those who see all work (PM-level, a role
     * given "เห็นทุกโครงการ" in Settings > สิทธิ์การใช้งาน, a department marked "sees everything"), else a project their
     * department has work in, or one they are in charge of / its Project Manager.
     */
    public static function canSeeProject(Project $project, User $user): bool
    {
        return $user->canSeeAllWork() || $project->project_manager_id === $user->id || self::projectSubtasks($project, $user)->exists()
            || $project->assignees()->whereKey($user->id)->exists();   // a person in charge of it
    }

    /** The same rule as a query on projects (lists, counts, CSV) - Project::visibleTo(). */
    public static function visibleProjects($query, User $user)
    {
        if ($user->canSeeAllWork()) {
            return $query;
        }

        return $query->where(function ($q) use ($user) {
            $q->where('project_manager_id', $user->id)->orWhereHas('assignees', fn ($a) => $a->whereKey($user->id));
            if ($user->department_id) {
                $q->orWhereHas('cabinets.tasks.subtasks', fn ($s) => $s->where('department_id', $user->department_id)->whereIn('assignment_status', self::ACTIVE_STATUSES));
            }
        });
    }
}
