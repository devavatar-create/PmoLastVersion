<?php

namespace App\Services;

use App\Exceptions\AssignmentException;
use App\Exceptions\ProjectLockedException;
use App\Models\AppSetting;
use App\Models\AutomationRule;
use App\Models\CabinetSubtask;
use App\Models\User;

/**
 * Runs the admin-defined rules after someone TICKS a checklist item.
 *
 * Rules never bypass the workflow: they call SubtaskAssignmentService::start()/complete(),
 * so the same checks apply (acting user must belong to the department, closed projects stay
 * read-only, every move is logged and notified). If a move is not allowed - e.g. a PM ticked
 * the box instead of the department - it is skipped quietly; the tick itself already succeeded.
 * Rules only ever move work forward; un-ticking never reverts anything.
 */
class AutomationService
{
    public function __construct(private SubtaskAssignmentService $assignments, private StatusRuleService $statusRules) {}

    /** @return bool true when the Sub Task's workflow status changed */
    public function afterChecklistTicked(CabinetSubtask $subtask, User $actor): bool
    {
        $changed = $this->moveWork($subtask, $actor);
        // then Settings > กฎสถานะ: the Planning status of the cabinet / project (never PMO's workflow)
        $this->statusRules->applyFor($subtask->cabinetTask?->cabinet?->project);

        return $changed;
    }

    private function moveWork(CabinetSubtask $subtask, User $actor): bool
    {
        $subtask->refresh();

        // Settings > กฎการทำงาน: projects in these statuses (e.g. on hold) are never moved by automation
        $project = $subtask->cabinetTask?->cabinet?->project;
        if ($project && in_array($project->status, (array) AppSetting::get('automation_locked_statuses'), true)) {
            return false;
        }

        $rules = AutomationRule::query()->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('department_id')->orWhere('department_id', $subtask->department_id))
            ->get();

        if ($rules->isEmpty()) {
            return false;
        }

        $total = $subtask->checklists()->count();
        $done = $subtask->checklists()->where('is_completed', true)->count();
        $before = $subtask->assignment_status;

        $wants = fn (string $trigger, string $action) => $rules->contains(fn ($r) => $r->trigger === $trigger && $r->action === $action);

        try {
            // "first item ticked" -> start (ACCEPTED -> IN_PROGRESS)
            if ($done >= 1 && $wants(AutomationRule::TRIGGER_FIRST_DONE, AutomationRule::ACTION_START)
                && $subtask->assignment_status === CabinetSubtask::ASSIGNMENT_ACCEPTED) {
                $this->assignments->start($subtask, $actor);
                $subtask->refresh();
            }

            // "everything ticked" -> complete. Ticking the last box proves the work was done, so a Sub Task
            // that was only ACCEPTED is started first (the workflow has no ACCEPTED -> COMPLETED shortcut).
            if ($total > 0 && $done === $total && $wants(AutomationRule::TRIGGER_ALL_DONE, AutomationRule::ACTION_COMPLETE)) {
                if ($subtask->assignment_status === CabinetSubtask::ASSIGNMENT_ACCEPTED) {
                    $this->assignments->start($subtask, $actor);
                    $subtask->refresh();
                }
                if ($subtask->assignment_status === CabinetSubtask::ASSIGNMENT_IN_PROGRESS) {
                    $this->assignments->complete($subtask, $actor);
                    $subtask->refresh();
                }
            }
        } catch (AssignmentException|ProjectLockedException) {
            // not allowed for this actor / project closed - leave the workflow where it is
        }

        return $subtask->assignment_status !== $before;
    }
}
