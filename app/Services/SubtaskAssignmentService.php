<?php

namespace App\Services;

use App\Exceptions\AssignmentException;
use App\Models\ActivityLog;
use App\Models\AppSetting;
use App\Models\CabinetSubtask;
use App\Models\Department;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Department-level assignment workflow for a cabinet Sub Task:
 * UNASSIGNED -> ASSIGNED -> ACCEPTED -> IN_PROGRESS -> COMPLETED.
 *
 * Core rule: the assigned department may only be changed while the status is
 * UNASSIGNED or ASSIGNED. The moment the department accepts, it is locked.
 * Every transition re-reads the row under a lock so two concurrent requests
 * (e.g. "change department" racing "accept") cannot both succeed.
 */
class SubtaskAssignmentService
{
    public function __construct(private NotificationService $notifications) {}

    /**
     * Returns true when the department actually changed.
     */
    public function changeDepartment(CabinetSubtask $subtask, ?int $departmentId, User $actor): bool
    {
        // Only Admin/PM may assign or reassign a department - checked here (not
        // just hidden in the UI) so the dedicated endpoint can't be driven directly.
        if (! $actor->canDispatchWork()) {
            throw new AssignmentException('เฉพาะผู้ดูแลระบบ (Admin) หรือ Project Manager เท่านั้นที่มีสิทธิ์เปลี่ยนแผนก', 403);
        }

        return DB::transaction(function () use ($subtask, $departmentId, $actor) {
            $locked = CabinetSubtask::whereKey($subtask->id)->lockForUpdate()->firstOrFail();
            ProjectLock::assertOpen($locked->cabinetTask->cabinet->project_id);

            // Re-submitting the current department is a no-op, not an error.
            if ($locked->department_id === $departmentId) {
                $this->syncInstance($subtask, $locked);

                return false;
            }

            if ($locked->is_department_locked) {
                throw new AssignmentException('ไม่สามารถเปลี่ยนแผนกได้ เนื่องจากงานนี้ถูกรับแล้ว');
            }

            $oldDepartmentId = $locked->department_id;

            $locked->forceFill([
                'department_id' => $departmentId,
                'assignment_status' => $departmentId
                    ? CabinetSubtask::ASSIGNMENT_ASSIGNED
                    : CabinetSubtask::ASSIGNMENT_UNASSIGNED,
                'assigned_at' => $departmentId ? now() : null,   // the "waiting since" of the รอรับเกิน n วัน reminder
            ])->saveQuietly();

            $oldName = $this->departmentName($oldDepartmentId);
            $newName = $this->departmentName($departmentId);

            $description = match (true) {
                $oldDepartmentId === null => "จ่ายงาน {$this->label($locked)} ให้แผนก {$newName}",
                $departmentId === null => "ยกเลิกการจ่ายงาน {$this->label($locked)} (เดิมแผนก {$oldName})",
                default => "เปลี่ยนแผนกของ {$this->label($locked)} {$oldName} → {$newName}",
            };

            $this->log($locked, $actor, $oldDepartmentId === null ? 'assigned' : 'department_changed', $description, [
                'department_id' => ['label' => 'แผนก', 'old' => $oldName, 'new' => $newName],
            ]);

            $this->syncInstance($subtask, $locked);

            if ($departmentId !== null) {
                $this->notifications->assigned($locked, $actor, moved: $oldDepartmentId !== null);
            }

            return true;
        });
    }

    /**
     * Dispatch many sub tasks at once (a whole step across the project's cabinets, or rows ticked on a cabinet page).
     * Each one goes through changeDepartment() - same lock, same rules, same log line - and the departments get one
     * notice per project instead of one per sub task. Work a department already accepted is skipped and counted,
     * never changed. With $dueDate, every sub task sent (or already with that department) gets it as its due date;
     * the caller checks Settings > Automation's date limits first.
     *
     * @param  list<int>  $subtaskIds
     * @return array{changed: list<CabinetSubtask>, unchanged: int, locked: int, dated: int}
     */
    public function changeDepartments(array $subtaskIds, ?int $departmentId, User $actor, ?string $dueDate = null): array
    {
        if (! $actor->canDispatchWork()) {
            throw new AssignmentException('เฉพาะผู้ดูแลระบบ (Admin) หรือ Project Manager เท่านั้นที่มีสิทธิ์จ่ายงาน', 403);
        }

        $redated = collect();   // already this department's: their date moved, which the "new work" notice does not cover
        $out = $this->notifications->batch(function () use ($subtaskIds, $departmentId, $actor, $dueDate, $redated) {
            $out = ['changed' => [], 'unchanged' => 0, 'locked' => 0, 'dated' => 0];
            foreach (CabinetSubtask::whereKey(array_unique($subtaskIds))->orderBy('id')->get() as $subtask) {
                if ($subtask->is_department_locked && $subtask->department_id !== $departmentId) {
                    $out['locked']++;

                    continue;
                }
                try {
                    $changed = $this->changeDepartment($subtask, $departmentId, $actor);
                } catch (AssignmentException) {
                    $out['locked']++;   // accepted by the department between loading and locking the row

                    continue;
                }
                $changed ? $out['changed'][] = $subtask : $out['unchanged']++;

                if ($dueDate && $departmentId && $subtask->fresh()->due_date?->format('Y-m-d') !== $dueDate) {
                    $subtask->refresh()->update(['due_date' => $dueDate]);   // a normal save: the change log and the closed-project guard apply
                    $out['dated']++;
                    $changed ?: $redated->push($subtask->fresh('cabinetTask.cabinet.project'));
                }
            }

            return $out;
        });
        if ($redated->isNotEmpty()) {
            $this->notifications->dueChanged($redated, $actor);
        }

        return $out;
    }

    public function accept(CabinetSubtask $subtask, User $actor): CabinetSubtask
    {
        return $this->transition($subtask, $actor, CabinetSubtask::ASSIGNMENT_ASSIGNED, CabinetSubtask::ASSIGNMENT_ACCEPTED, 'accepted', 'accepted_by', 'accepted_at',
            fn ($s) => "แผนก {$s->department->name} รับงาน {$this->label($s)} แล้ว");
    }

    public function start(CabinetSubtask $subtask, User $actor): CabinetSubtask
    {
        return $this->transition($subtask, $actor, CabinetSubtask::ASSIGNMENT_ACCEPTED, CabinetSubtask::ASSIGNMENT_IN_PROGRESS, 'started', 'started_by', 'started_at',
            fn ($s) => "แผนก {$s->department->name} เริ่มงาน {$this->label($s)}");
    }

    public function complete(CabinetSubtask $subtask, User $actor): CabinetSubtask
    {
        // Settings > กฎการทำงาน: "งานกดเสร็จไม่ได้ ถ้าเช็คลิสต์ยังไม่ครบ" (only once the work really has a checklist)
        if (AppSetting::get('block_done_needs_checklist') && $subtask->checklists()->where('is_completed', false)->exists()) {
            throw new AssignmentException('ปิดงานไม่ได้ — เช็คลิสต์ยังติ๊กไม่ครบ (ตั้งค่าไว้ที่ ตั้งค่า › กฎการทำงาน)');
        }

        return $this->transition($subtask, $actor, CabinetSubtask::ASSIGNMENT_IN_PROGRESS, CabinetSubtask::ASSIGNMENT_COMPLETED, 'completed', 'completed_by', 'completed_at',
            fn ($s) => "แผนก {$s->department->name} ทำงาน {$this->label($s)} เสร็จแล้ว");
    }

    /**
     * Which workflow buttons this user may currently use on the sub task.
     */
    public function actionsFor(CabinetSubtask $subtask, ?User $user): array
    {
        $inDepartment = $this->canActFor($subtask, $user);

        return [
            'can_accept' => $inDepartment && $subtask->assignment_status === CabinetSubtask::ASSIGNMENT_ASSIGNED,
            'can_start' => $inDepartment && $subtask->assignment_status === CabinetSubtask::ASSIGNMENT_ACCEPTED,
            'can_complete' => $inDepartment && $subtask->assignment_status === CabinetSubtask::ASSIGNMENT_IN_PROGRESS,
        ];
    }

    /**
     * State returned to async callers so the UI can re-render the row.
     */
    public function payload(CabinetSubtask $subtask, ?User $user): array
    {
        $subtask->load('department', 'acceptedBy', 'startedBy', 'completedBy');

        return [
            'id' => $subtask->id,
            'department_id' => $subtask->department_id,
            'department_name' => $subtask->department?->name,
            'assignment_status' => $subtask->assignment_status,
            'assignment_status_label' => $subtask->assignment_status_label,
            'is_department_locked' => $subtask->is_department_locked,
            'accepted_by_name' => $subtask->acceptedBy?->name,
            'accepted_at' => $subtask->accepted_at?->thaiDateTime(),
            'started_by_name' => $subtask->startedBy?->name,
            'started_at' => $subtask->started_at?->thaiDateTime(),
            'completed_by_name' => $subtask->completedBy?->name,
            'completed_at' => $subtask->completed_at?->thaiDateTime(),
        ] + $this->actionsFor($subtask, $user);
    }

    private function transition(CabinetSubtask $subtask, User $actor, string $from, string $to, string $action, string $byColumn, string $atColumn, \Closure $describe): CabinetSubtask
    {
        return DB::transaction(function () use ($subtask, $actor, $from, $to, $action, $byColumn, $atColumn, $describe) {
            $locked = CabinetSubtask::whereKey($subtask->id)->lockForUpdate()->firstOrFail();
            ProjectLock::assertOpen($locked->cabinetTask->cabinet->project_id);

            if (! $this->canActFor($locked, $actor)) {
                throw new AssignmentException('คุณไม่ใช่สมาชิกแผนกที่ได้รับมอบหมายงานนี้', 403);
            }

            if ($locked->assignment_status !== $from) {
                throw new AssignmentException($this->invalidTransitionMessage($locked, $to));
            }

            $locked->forceFill([
                'assignment_status' => $to,
                $byColumn => $actor->id,
                $atColumn => now(),
            ])->saveQuietly();

            $locked->load('department');
            // Admin / PM doing it for the department (Settings > กฎการทำงาน): said so in the log
            $this->log($locked, $actor, $action, $describe($locked).($this->isInAssignedDepartment($locked, $actor) ? '' : " (ทำแทนโดย {$actor->name})"));
            $this->notifications->progressed($locked, $actor, $action);

            $this->syncInstance($subtask, $locked);

            return $subtask;
        });
    }

    /** The department's own members - or Admin / PM when Settings > กฎการทำงาน lets them act for departments. */
    public function canActFor(CabinetSubtask $subtask, ?User $user): bool
    {
        return $this->isInAssignedDepartment($subtask, $user)
            || ($user !== null && $subtask->department_id !== null && $user->canDispatchWork() && AppSetting::get('pmo_acts_for_departments'));
    }

    private function isInAssignedDepartment(CabinetSubtask $subtask, ?User $user): bool
    {
        return $user !== null
            && $subtask->department_id !== null
            && $user->department_id === $subtask->department_id;
    }

    private function invalidTransitionMessage(CabinetSubtask $subtask, string $to): string
    {
        return match (true) {
            $subtask->assignment_status === CabinetSubtask::ASSIGNMENT_UNASSIGNED => 'งานนี้ยังไม่ได้จ่ายให้แผนก',
            $to === CabinetSubtask::ASSIGNMENT_ACCEPTED => 'งานนี้ถูกรับแล้ว',
            $to === CabinetSubtask::ASSIGNMENT_IN_PROGRESS => 'ไม่สามารถเริ่มงานได้ ต้องรับงานก่อน หรืองานนี้เริ่มไปแล้ว',
            default => 'ไม่สามารถปิดงานได้ ต้องเริ่มงานก่อน หรืองานนี้เสร็จไปแล้ว',
        };
    }

    private function label(CabinetSubtask $subtask): string
    {
        $subtask->loadMissing('cabinetTask.cabinet');

        return "\"{$subtask->name}\" ({$subtask->cabinetTask->cabinet->mo_no} / {$subtask->cabinetTask->name})";
    }

    private function departmentName(?int $id): string
    {
        return $id ? (Department::find($id)?->name ?? "#{$id}") : '-';
    }

    private function log(CabinetSubtask $subtask, User $actor, string $action, string $description, array $changes = []): void
    {
        ActivityLog::record(
            $subtask->cabinetTask->cabinet->project_id,
            $actor->id,
            $subtask,
            $action,
            $description,
            $changes
        );
    }

    /**
     * Copy the freshly written state back onto the model instance the caller holds.
     */
    private function syncInstance(CabinetSubtask $subtask, CabinetSubtask $locked): void
    {
        $subtask->setRawAttributes($locked->getAttributes(), true);
        $subtask->unsetRelations();
    }
}
