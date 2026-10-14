<?php

namespace App\Services;

use App\Exceptions\AssignmentException;
use App\Exceptions\PlanningRejected;
use App\Exceptions\ProjectLockedException;
use App\Models\ActivityLog;
use App\Models\AppSetting;
use App\Models\Board;
use App\Models\BoardLabel;
use App\Models\BoardStatus;
use App\Models\Cabinet;
use App\Models\CabinetTemplate;
use App\Models\CabinetTask;
use App\Models\DepartmentField;
use App\Models\ProjectFieldValue;
use App\Models\CabinetChecklist;
use App\Models\CabinetSubtask;
use App\Models\DateLimitRule;
use App\Models\Department;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Applies the edits made on the /planning page (the Avatar Planning UI running on PMO data) to PMO's real data.
 *
 * The page never writes data itself: it sends a list of operations, and every one goes through the SAME services and
 * rules the rest of PMO uses (checklist toggling + automation, the accept/start/complete workflow, ProjectEditor, date
 * limit rules, closed-project locks, activity log, notifications) and is checked against WorkScope / the user's role.
 * Each operation is its own transaction: a refused one changes nothing and comes back with a Thai reason; the page then
 * repaints from the authoritative board, so a refused edit simply "bounces back".
 *
 * What is NOT possible here (so it is refused, never faked): creating projects, cabinets or stages, renaming cabinets or
 * checklist items, free-form statuses, labels - PMO's structure comes from templates and its status from the workflow.
 * (Adding / removing a checklist item IS possible, under the same rule as the Checklist screens: PMO roles, or the department
 * that has accepted the work.)
 */
class PlanningSync
{
    use Concerns\PlanningSyncExtras;

    /** @var list<string> warnings collected while one operation runs, added to its answer */
    private array $notes = [];

    /** Project id the last create / copy / import / restore produced (the page opens it). */
    private ?int $created = null;

    public function created(): ?int
    {
        return $this->created;
    }

    private const STEP_FROM = [
        'accept' => CabinetSubtask::ASSIGNMENT_ASSIGNED,
        'start' => CabinetSubtask::ASSIGNMENT_ACCEPTED,
        'complete' => CabinetSubtask::ASSIGNMENT_IN_PROGRESS,
    ];

    private const PRIORITY = ['none' => 'normal', 'medium' => 'normal', 'low' => 'low', 'high' => 'high', 'urgent' => 'urgent'];

    public function __construct(
        private SubtaskAssignmentService $assignments,
        private AutomationService $automation,
        private ProgressService $progress,
        private NotificationService $notifications,
        private ProjectEditor $editor,
    ) {}

    /**
     * @param  list<mixed>  $ops
     * @return list<array{ok: bool, type: string, message: ?string}>
     */
    public function apply(User $user, array $ops): array
    {
        return array_map(fn ($op) => $this->applyOne($user, is_array($op) ? $op : []), array_values(array_slice($ops, 0, 200)));
    }

    private function applyOne(User $user, array $op): array
    {
        $type = (string) ($op['type'] ?? '');

        try {
            $this->notes = [];
            // one notice per department / project for the work this operation hands out (sent only if it succeeds)
            $message = $this->notifications->batch(fn () => DB::transaction(fn () => match ($type) {
                'checklist' => $this->checklist($user, $op),
                'checklist_add' => $this->checklistAdd($user, $op),
                'checklist_delete' => $this->checklistDelete($user, $op),
                'comment' => $this->comment($user, $op),
                'project' => $this->project($user, $op),
                'stage_due' => $this->stageDue($user, $op),
                'stage_dates' => $this->stageDates($user, $op),
                'cabinet_due' => $this->cabinetDue($user, $op, false),
                'cabinet_dept_due' => $this->cabinetDue($user, $op, true),
                'stage_field' => $this->stageField($user, $op),
                'labels_set' => $this->labelsSet($user, $op),
                'label_create' => $this->labelCreate($user, $op),
                'label_delete' => $this->labelDelete($user, $op),
                'cabinet_add' => $this->cabinetAdd($user, $op),
                'cabinet_rename' => $this->cabinetRename($user, $op),
                'cabinet_delete' => $this->cabinetDelete($user, $op),
                'stage_add' => $this->stageAdd($user, $op),
                'subtask_assign' => $this->subtaskAssign($user, $op),
                'cabinet_info' => $this->cabinetInfo($user, $op),
                'subtask_info' => $this->subtaskInfo($user, $op),
                'project_shift' => $this->projectShift($user, $op),
                'stage_remove' => $this->stageRemove($user, $op),
                'board_set' => $this->boardSet($user, $op),
                'project_create' => $this->projectCreate($user, $op),
                'project_duplicate' => $this->projectDuplicate($user, $op),
                'project_restore' => $this->projectRestore($user, $op),
                'assignees_set' => $this->assigneesSet($user, $op),
                'cabinet_status' => $this->cabinetStatus($user, $op),
                'stage_status' => $this->stageStatus($user, $op),
                'checklist_rename' => $this->checklistRename($user, $op),
                'checklist_remark' => $this->checklistRemark($user, $op),
                'checklist_date' => $this->checklistDate($user, $op),
                'checklist_date_all' => $this->checklistDateAll($user, $op),
                'checklist_move' => $this->checklistMove($user, $op),
                'comment_edit' => $this->commentEdit($user, $op),
                'comment_delete' => $this->commentDelete($user, $op),
                'alert_ack' => $this->alertAck($user, $op),
                'stage_done' => $this->stageDone($user, $op),
                'stage_step' => $this->stageStep($user, $op),
                'delete' => $this->delete($user, $op),
                'unsupported' => throw new PlanningRejected((string) ($op['what'] ?? 'การแก้ไขนี้ยังไม่รองรับในหน้านี้')),
                default => throw new PlanningRejected('ไม่รู้จักการแก้ไขนี้'),
            }));

            // things the person should know although it worked (e.g. a department with nobody in it)
            $message = $this->notes ? trim(($message ? $message.' · ' : '').implode(' · ', array_unique($this->notes))) : $message;

            return ['ok' => true, 'type' => $type, 'message' => $message];
        } catch (PlanningRejected|AssignmentException|ProjectLockedException $e) {
            return ['ok' => false, 'type' => $type, 'message' => $e->getMessage()];
        } catch (ValidationException $e) {
            return ['ok' => false, 'type' => $type, 'message' => collect($e->errors())->flatten()->first() ?? 'ข้อมูลไม่ถูกต้อง'];
        }
    }

    // ---- id helpers (the board uses p12 / c34 / k56 / d_7 ids) ----

    private function id(mixed $value, string $prefix): int
    {
        if (! is_string($value) || ! preg_match('/^'.preg_quote($prefix, '/').'(\d+)$/', $value, $m)) {
            throw new PlanningRejected('ข้อมูลที่ส่งมาไม่ถูกต้อง');
        }

        return (int) $m[1];
    }

    private function projectFor(User $user, mixed $taskId): Project
    {
        $project = Project::find($this->id($taskId, 'p')) ?? throw new PlanningRejected('ไม่พบโครงการนี้ (อาจถูกลบไปแล้ว)');

        if (! WorkScope::canSeeProject($project, $user)) {
            throw new PlanningRejected('คุณไม่มีสิทธิ์ในโครงการนี้');
        }

        return $project;
    }

    private function requirePmo(User $user, string $what): void
    {
        if (! $user->canDispatchWork()) {
            throw new PlanningRejected("เฉพาะผู้ดูแลระบบ (Admin) หรือ Project Manager เท่านั้นที่{$what}ได้");
        }
    }

    private function date(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        // strict YYYY-MM-DD only: anything else (31/10/2026, 2026-02-30, a number, an array...) is refused, never an exception
        if (! is_string($value) || ! preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m) || ! checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            throw new PlanningRejected('วันที่ไม่ถูกต้อง');
        }

        return $value;
    }

    private function deptSubtasks(Project $project, User $user, mixed $deptKey): Collection
    {
        $deptId = $this->id($deptKey, 'd_');

        return WorkScope::projectSubtasks($project, $user)->where('department_id', $deptId)->with('cabinetTask.cabinet')->orderBy('id')->get();
    }

    private function open(Collection $subtasks): Collection
    {
        return $subtasks->reject(fn ($s) => $s->assignment_status === CabinetSubtask::ASSIGNMENT_COMPLETED)->values();
    }

    // ---- operations ----

    private function checklist(User $user, array $op): ?string
    {
        $item = CabinetChecklist::with('subtask.cabinetTask.cabinet.project')->find($this->id($op['itemId'] ?? null, 'k'))
            ?? throw new PlanningRejected('ไม่พบรายการ checklist นี้');
        $subtask = $item->subtask;
        $project = $subtask->cabinetTask->cabinet->project;
        $done = (bool) ($op['done'] ?? false);

        if (! WorkScope::projectSubtasks($project, $user)->whereKey($subtask->id)->exists()) {
            throw new PlanningRejected('คุณไม่มีสิทธิ์ในรายการนี้');
        }
        if ((bool) $item->is_completed === $done) {
            return null;
        }
        if (! $subtask->isChecklistEditableBy($user)) {
            throw new PlanningRejected('ติ๊ก Checklist ได้เฉพาะแผนกที่รับงานแล้ว — ต้อง "รับงาน" ก่อน');
        }

        $this->progress->toggleChecklist($item, $done, $user);
        if ($done) {
            $this->automation->afterChecklistTicked($subtask->fresh(), $user);
        }

        return null;
    }

    /** The department's Sub Task in this cabinet that a new checklist item would belong to (visible to the user). */
    private function cabinetDeptSubtask(User $user, Project $project, mixed $cabinetKey, mixed $deptKey): CabinetSubtask
    {
        return WorkScope::projectSubtasks($project, $user)
            ->whereHas('cabinetTask', fn ($q) => $q->where('cabinet_id', $this->id($cabinetKey, 'c')))
            ->where('department_id', $this->id($deptKey, 'd_'))
            ->orderBy('id')->first()
            ?? throw new PlanningRejected('ไม่พบงานของแผนกนี้ในตู้นี้');
    }

    private function checklistAdd(User $user, array $op): ?string
    {
        $project = $this->projectFor($user, $op['taskId'] ?? null);
        // into the Sub Task picked in the checklist window; older pages send only the cabinet + department (its first Sub Task)
        $subtask = ! empty($op['subtaskId'])
            ? WorkScope::projectSubtasks($project, $user)->whereHas('cabinetTask', fn ($q) => $q->where('cabinet_id', $this->id($op['cabinetId'] ?? null, 'c')))
                ->find($this->id($op['subtaskId'], 's')) ?? throw new PlanningRejected('ไม่พบงานย่อยนี้ในตู้นี้')
            : $this->cabinetDeptSubtask($user, $project, $op['cabinetId'] ?? null, $op['deptId'] ?? null);
        $text = trim((string) ($op['text'] ?? ''));

        if ($text === '') {
            throw new PlanningRejected('กรุณาพิมพ์ชื่อรายการ checklist');
        }
        if (! $subtask->isChecklistEditableBy($user)) {
            throw new PlanningRejected('เพิ่ม Checklist ได้เฉพาะแผนกที่รับงานแล้ว — ต้อง "รับงาน" ก่อน');
        }
        ProjectLock::assertOpen($project->id);

        $subtask->checklists()->create([
            'checklist_template_id' => null,
            'name' => mb_substr($text, 0, 255),
            'is_completed' => false,
            'sequence' => (int) $subtask->checklists()->max('sequence') + 1,
        ]);
        $this->progress->recalculateSubtask($subtask);

        return null;
    }

    private function checklistDelete(User $user, array $op): ?string
    {
        $item = CabinetChecklist::with('subtask.cabinetTask.cabinet.project')->find($this->id($op['itemId'] ?? null, 'k'))
            ?? throw new PlanningRejected('ไม่พบรายการ checklist นี้ (อาจถูกลบไปแล้ว)');
        $subtask = $item->subtask;
        $project = $subtask->cabinetTask->cabinet->project;

        if (! WorkScope::projectSubtasks($project, $user)->whereKey($subtask->id)->exists()) {
            throw new PlanningRejected('คุณไม่มีสิทธิ์ในรายการนี้');
        }
        if (! $subtask->isChecklistEditableBy($user)) {
            throw new PlanningRejected('ลบ Checklist ได้เฉพาะแผนกที่รับงานแล้ว — ต้อง "รับงาน" ก่อน');
        }
        ProjectLock::assertOpen($project->id);

        $item->delete();
        $this->progress->recalculateSubtask($subtask);

        return null;
    }

    /**
     * Who the sender may tag in a comment on this project: the person must be active, must be able to SEE the project
     * (the notice opens it), and - unless the sender is PMO - must be in the sender's own department or a PMO role
     * (the same "no cross-department tagging" rule as the original page).
     *
     * @param  list<mixed>  $ids
     * @return array{0: \Illuminate\Support\Collection<int, User>, 1: list<string>} [allowed targets, names that were skipped]
     */
    public function mentionTargets(User $sender, Project $project, array $ids): array
    {
        $ids = collect($ids)->filter(fn ($v) => is_int($v) || (is_string($v) && ctype_digit($v)))->map(fn ($v) => (int) $v)->unique()->take(20);
        $allowed = collect();
        $skipped = [];

        foreach (User::whereIn('id', $ids)->where('is_active', true)->get() as $target) {
            $sameCircle = AppSetting::get('cross_mention') || $sender->canViewOtherDepartments() || $target->canViewOtherDepartments()
                || ($sender->department_id !== null && $sender->department_id === $target->department_id);

            if ($target->id !== $sender->id && $sameCircle && WorkScope::canSeeProject($project, $target)) {
                $allowed->push($target);
            } elseif ($target->id !== $sender->id) {
                $skipped[] = $target->name;
            }
        }

        return [$allowed, $skipped];
    }

    /** The note the last comment operation wrote (so the caller can attach the files that came with it). */
    private ?ActivityLog $lastNote = null;

    public function lastNote(): ?ActivityLog
    {
        return $this->lastNote;
    }

    private function comment(User $user, array $op): ?string
    {
        $project = $this->projectFor($user, $op['taskId'] ?? null);
        $text = trim((string) ($op['text'] ?? ''));
        if ($text === '') {
            throw new PlanningRejected('แนบรูป/ไฟล์ในหน้านี้ยังไม่รองรับ — เขียนข้อความ หรือแนบไฟล์ที่หน้าโครงการ');
        }
        $text = mb_substr($text, 0, 2000);

        // an alert to a department keeps who acknowledged it; a reply points at the comment it answers
        $changes = [];
        if (! empty($op['alertDept']) && $user->canDispatchWork() && ($alertDept = Department::find($this->id($op['alertDept'], 'd_')))) {
            $changes += ['alert_department_id' => $alertDept->id, 'acks' => []];
        }
        if (! empty($op['replyTo']) && is_string($op['replyTo']) && preg_match('/^a(\d+)$/', $op['replyTo'], $m)
            && ActivityLog::where('project_id', $project->id)->where('action', 'note')->whereKey((int) $m[1])->exists()) {
            $changes['reply_to'] = (int) $m[1];
        }
        $this->lastNote = ActivityLog::record($project->id, $user->id, $project, 'note', $text, $changes);

        $notes = [];

        if (! empty($op['alertDept'])) {
            if (! $user->canDispatchWork()) {
                $notes[] = 'ส่งแจ้งเตือนแผนกได้เฉพาะ Admin / Project Manager';
            } else {
                $dept = Department::find($this->id($op['alertDept'], 'd_')) ?? throw new PlanningRejected('ไม่พบแผนกที่จะแจ้งเตือน');
                $n = $this->notifications->projectAlert($project, $dept, $user, $text);
                $notes[] = $n ? "ส่งแจ้งเตือนถึงแผนก {$dept->name} {$n} คนแล้ว" : "⚠️ แผนก {$dept->name} ยังไม่มีผู้ใช้ในระบบ — ไม่มีใครได้รับแจ้งเตือนนี้";
            }
        }

        if (! empty($op['mentions']) && is_array($op['mentions'])) {
            [$targets, $skipped] = $this->mentionTargets($user, $project, $op['mentions']);
            $sent = $targets->sum(fn (User $t) => $this->notifications->mentioned($project, $user, $t, $text));
            $told = $targets->pluck('id')->all();
            if ($sent) {
                $notes[] = "แจ้งเตือนผู้ถูกแท็ก {$sent} คน";
            }
            if ($skipped) {
                $notes[] = 'ไม่ได้แจ้ง '.implode(', ', array_slice($skipped, 0, 3)).' (ไม่มีสิทธิ์ในโครงการนี้ หรือคนละแผนก)';
            }
        }

        if (! empty($op['hadAttachment'])) {
            $notes[] = 'รูป/ไฟล์แนบยังไม่รองรับในหน้านี้';
        }

        // everyone else following the project hears about the new comment / reply (not those already tagged / alerted)
        $skip = array_merge($told ?? [], isset($dept) ? User::where('department_id', $dept->id)->pluck('id')->all() : []);
        $this->notifications->commented($project, $user, $this->lastNote, $skip);

        return $notes ? 'บันทึกคอมเมนต์แล้ว · '.implode(' · ', $notes) : null;
    }

    private function project(User $user, array $op): ?string
    {
        $this->requirePmo($user, 'แก้ข้อมูลและวันที่ของโครงการ');
        $project = $this->projectFor($user, $op['taskId'] ?? null);
        $f = is_array($op['fields'] ?? null) ? $op['fields'] : [];

        $map = [];
        if (array_key_exists('title', $f)) {
            $map['project_name'] = is_string($f['title']) ? trim($f['title']) : '';
        }
        if (array_key_exists('description', $f)) {
            $map['description'] = $f['description'] === '' ? null : $f['description'];
        }
        if (array_key_exists('priority', $f)) {
            $map['priority'] = self::PRIORITY[$f['priority']] ?? throw new PlanningRejected('ระดับความสำคัญไม่ถูกต้อง');
        }
        if (array_key_exists('statusId', $f)) {
            // the project's status, picked by Admin / PM ('' = none: the page then shows PMO's own project status)
            $status = $f['statusId'] === '' || $f['statusId'] === null ? null
                : (is_string($f['statusId']) ? BoardStatus::fromPageId($f['statusId']) : null) ?? throw new PlanningRejected('สถานะไม่ถูกต้อง');
            $project->forceFill(['board_status_id' => $status?->id])->save();
            ActivityLog::record($project->id, $user->id, $project, 'updated', 'ตั้งสถานะโครงการเป็น '.($status?->name ?? 'ตามสถานะ PMO'));
        }
        if (array_key_exists('startDate', $f)) {
            $map['start_date'] = $this->date($f['startDate']);
        }
        if (array_key_exists('date', $f)) {
            $map['due_date'] = $this->date($f['date']);
        }
        // the project's own record (customer, PO, PM, ...): Admin / PM only, like the dispatch side of PMO
        $info = ['customer' => 'customer_name', 'customerPhone' => 'customer_phone', 'poNo' => 'po_no', 'soNo' => 'sales_order_no', 'owner' => 'project_owner', 'salesPerson' => 'sales_person',
            'managerId' => 'project_manager_id', 'paymentTerms' => 'payment_terms', 'jobTypeId' => 'job_type_id', 'pmoStatus' => 'status',
            'contractValue' => 'contract_value', 'margin' => 'margin'];
        if (array_intersect_key($f, $info)) {
            $this->requirePmo($user, 'แก้ข้อมูลโครงการ (ลูกค้า / PO / PM / สถานะ PMO)');
            // what this person may not see, they do not change (Settings > สิทธิ์การใช้งาน)
            if (! $user->allows('data.finance') && array_intersect_key($f, ['contractValue' => 1, 'margin' => 1])) {
                throw new PlanningRejected('คุณไม่มีสิทธิ์ดูหรือแก้ข้อมูลการเงินของโครงการ');
            }
            if (! $user->allows('data.contact') && array_key_exists('customerPhone', $f)) {
                throw new PlanningRejected('คุณไม่มีสิทธิ์ดูหรือแก้ข้อมูลติดต่อลูกค้า');
            }
            foreach ($info as $key => $column) {
                if (array_key_exists($key, $f)) {
                    $v = is_string($f[$key]) ? trim($f[$key]) : $f[$key];
                    $map[$column] = $v === '' ? null : $v;
                }
            }
        }
        if ($map) {
            $this->editor->update($project, $map);   // same validation as the project form; the model's own guards (closing a project) apply
        }

        return null;
    }

    /** Changes the due date of every still-open Sub Task in $subtasks, after the date-limit rules say it is allowed. */
    private function moveDue(Collection $subtasks, ?string $due, User $user): void
    {
        $open = $this->open($subtasks);
        if ($open->isEmpty()) {
            throw new PlanningRejected('ไม่มีงานที่แก้วันได้ (เสร็จแล้วหรือยังไม่ได้จ่ายงาน)');
        }
        if ($due) {
            foreach ($open as $s) {
                if ($message = DateLimitRule::violationFor($s->cabinetTask->cabinet_id, $s->department_id, $due)) {
                    throw new PlanningRejected($message);
                }
            }
        }
        foreach ($open as $s) {
            $change = ['due_date' => $due];
            if ($due && $s->start_date && $s->start_date->format('Y-m-d') > $due) {
                $change['start_date'] = $due;   // keep start <= due
            }
            $s->update($change);
        }
        $this->notifications->dueChanged($open, $user);   // the departments whose dates moved hear it
    }

    /**
     * One start - end range for ALL of a department's open work in the project (the department decides the order inside it;
     * PMO only watches the end date). Date-limit rules hold; finished work keeps its dates.
     */
    private function stageDates(User $user, array $op): ?string
    {
        $this->requirePmo($user, 'กำหนดช่วงวันของแผนก');
        $project = $this->projectFor($user, $op['taskId'] ?? null);
        ProjectLock::assertOpen($project->id);
        [$start, $due] = [$this->date($op['start'] ?? null), $this->date($op['due'] ?? null)];
        if ($start && $due && $due < $start) {
            throw new PlanningRejected('วันส่งต้องไม่ก่อนวันเริ่ม');
        }
        $open = $this->open($this->deptSubtasks($project, $user, $op['deptId'] ?? null));
        if ($open->isEmpty()) {
            throw new PlanningRejected('ไม่มีงานที่แก้วันได้ (เสร็จแล้วหรือยังไม่ได้จ่ายงาน)');
        }
        foreach ($open as $s) {
            if ($due && ($message = DateLimitRule::violationFor($s->cabinetTask->cabinet_id, $s->department_id, $due))) {
                throw new PlanningRejected($message);
            }
        }
        $moved = $open->filter(fn ($s) => $s->due_date?->format('Y-m-d') !== $due);
        foreach ($open as $s) {
            $s->update(['start_date' => $start, 'due_date' => $due]);
        }
        if ($moved->isNotEmpty()) {
            $this->notifications->dueChanged($moved->each->refresh(), $user);
        }

        return null;
    }

    private function stageDue(User $user, array $op): ?string
    {
        $this->requirePmo($user, 'แก้วันกำหนดส่งของแผนก');
        $project = $this->projectFor($user, $op['taskId'] ?? null);
        $this->moveDue($this->deptSubtasks($project, $user, $op['deptId'] ?? null)->when(! $user->canDispatchWork(), fn ($c) => $c->where('department_id', $user->department_id)), $this->date($op['due'] ?? null), $user);

        return null;
    }

    private function cabinetDue(User $user, array $op, bool $perDept): ?string
    {
        $this->requirePmo($user, 'แก้วันกำหนดส่งของตู้');
        $project = $this->projectFor($user, $op['taskId'] ?? null);
        $cabinetId = $this->id($op['cabinetId'] ?? null, 'c');

        $subtasks = WorkScope::projectSubtasks($project, $user)
            ->whereHas('cabinetTask', fn ($q) => $q->where('cabinet_id', $cabinetId))
            ->when(! $user->canDispatchWork(), fn ($q) => $q->where('department_id', $user->department_id))   // dates stay inside your own department's work, even if you may SEE more
            ->when($perDept, fn ($q) => $q->where('department_id', $this->id($op['deptId'] ?? null, 'd_')))
            ->with('cabinetTask.cabinet')->get();

        $this->moveDue($subtasks, $this->date($op['due'] ?? null), $user);

        return null;
    }

    /** Ticking "เสร็จแล้ว" on a department's stage = that department finishes its Sub Tasks (accept -> start -> complete as needed). */
    private function stageDone(User $user, array $op): ?string
    {
        $project = $this->projectFor($user, $op['taskId'] ?? null);
        $open = $this->open($this->deptSubtasks($project, $user, $op['deptId'] ?? null));

        foreach ($open as $s) {
            foreach (['accept', 'start', 'complete'] as $step) {
                if ($s->fresh()->assignment_status === self::STEP_FROM[$step]) {
                    $this->assignments->{$step}($s->fresh(), $user);
                }
            }
        }

        return $open->isEmpty() ? null : 'ทำเครื่องหมายงานของแผนกเป็นเสร็จแล้ว '.$open->count().' รายการ';
    }

    private function stageStep(User $user, array $op): ?string
    {
        $step = (string) ($op['step'] ?? '');
        if (! isset(self::STEP_FROM[$step])) {
            throw new PlanningRejected('ขั้นตอนไม่ถูกต้อง');
        }

        $project = $this->projectFor($user, $op['taskId'] ?? null);
        $eligible = $this->deptSubtasks($project, $user, $op['deptId'] ?? null)->where('assignment_status', self::STEP_FROM[$step])->values();
        if ($eligible->isEmpty()) {
            throw new PlanningRejected('ไม่มีงานที่ทำรายการนี้ได้ในขณะนี้');
        }

        foreach ($eligible as $s) {
            $this->assignments->{$step}($s, $user);   // refuses (403-style) unless the user belongs to that department
        }

        return ['accept' => 'รับงาน', 'start' => 'เริ่มงาน', 'complete' => 'ปิดงาน'][$step].' '.$eligible->count().' รายการแล้ว';
    }

    /** One extra field (Settings > ฟิลด์เพิ่มเติม) of a department's part of a project: PMO roles any department's, members their own. */
    private function stageField(User $user, array $op): ?string
    {
        $project = $this->projectFor($user, $op['taskId'] ?? null);
        ProjectLock::assertOpen($project->id);
        $deptId = $this->id($op['deptId'] ?? null, 'd_');

        if (! $user->canDispatchWork() && $user->department_id !== $deptId) {
            throw new PlanningRejected('แก้ข้อมูลเพิ่มเติมได้เฉพาะของแผนกตัวเอง');
        }
        if (! WorkScope::projectSubtasks($project, $user)->where('department_id', $deptId)->exists()) {
            throw new PlanningRejected('แผนกนี้ไม่มีงานในโครงการนี้');
        }

        $field = DepartmentField::where('department_id', $deptId)->find($this->id($op['fieldId'] ?? null, 'f'))
            ?? throw new PlanningRejected('ไม่พบฟิลด์นี้ (อาจถูกลบไปแล้ว)');
        $raw = is_string($op['value'] ?? null) ? trim($op['value']) : '';

        if ($raw === '') {
            ProjectFieldValue::where(['project_id' => $project->id, 'department_field_id' => $field->id])->delete();
            $shown = '(ว่าง)';
        } else {
            if ($field->type === 'select') {
                $option = $field->options()->find($this->id($raw, 'o')) ?? throw new PlanningRejected('ตัวเลือกไม่ถูกต้อง');
                [$value, $shown] = [(string) $option->id, $option->label];
            } else {
                if ($field->type === 'link' && ! preg_match('#^https?://[^\s]+$#i', $raw)) {
                    throw new PlanningRejected('ลิงก์ต้องขึ้นต้นด้วย http:// หรือ https://');
                }
                [$value, $shown] = [mb_substr($raw, 0, 500), mb_substr($raw, 0, 500)];
            }
            ProjectFieldValue::updateOrCreate(['project_id' => $project->id, 'department_field_id' => $field->id], ['value' => $value]);
        }

        ActivityLog::record($project->id, $user->id, $project, 'updated', "ตั้งค่า {$field->name} ของแผนก ".Department::find($deptId)?->name." = {$shown}");

        return null;
    }

    // ---- labels (tags) ----

    private function labelsSet(User $user, array $op): ?string
    {
        $this->requirePmo($user, 'ติด / ถอดแท็ก');
        $project = $this->projectFor($user, $op['taskId'] ?? null);
        $ids = collect(is_array($op['labels'] ?? null) ? $op['labels'] : [])->map(fn ($l) => is_string($l) && preg_match('/^l(\d+)$/', $l, $m) ? (int) $m[1] : null)->filter()->unique();
        $valid = BoardLabel::whereIn('id', $ids)->pluck('id');

        $project->boardLabels()->sync($valid);
        ActivityLog::record($project->id, $user->id, $project, 'updated', 'แท็ก: '.($valid->isEmpty() ? '(ไม่มี)' : BoardLabel::whereIn('id', $valid)->pluck('name')->implode(', ')));

        return null;
    }

    private function labelCreate(User $user, array $op): ?string
    {
        $this->requirePmo($user, 'สร้างแท็ก');
        $name = trim((string) ($op['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 40) {
            throw new PlanningRejected('ชื่อแท็กต้องมี 1–40 ตัวอักษร');
        }
        $color = is_string($op['color'] ?? null) && preg_match('/^#[0-9A-Fa-f]{6}$/', $op['color']) ? $op['color'] : '#7b68ee';
        BoardLabel::create(['name' => $name, 'color' => $color, 'sort' => (int) BoardLabel::max('sort') + 1]);

        return null;
    }

    private function labelDelete(User $user, array $op): ?string
    {
        $this->requirePmo($user, 'ลบแท็ก');
        BoardLabel::find($this->id($op['labelId'] ?? null, 'l'))?->delete();   // detached from every project by the foreign key

        return null;
    }

    // ---- cabinets and department stages: building a project up (PMO roles) ----

    private function cabinetOf(Project $project, mixed $id): Cabinet
    {
        return Cabinet::where('project_id', $project->id)->find($this->id($id, 'c')) ?? throw new PlanningRejected('ไม่พบตู้นี้ในโครงการ');
    }

    private function cabinetAdd(User $user, array $op): ?string
    {
        $this->requirePmo($user, 'เพิ่มตู้');
        $project = $this->projectFor($user, $op['taskId'] ?? null);
        ProjectLock::assertOpen($project->id);
        [$mo, $name] = [trim((string) ($op['mo'] ?? '')), trim((string) ($op['text'] ?? ''))];

        if ($mo === '' || $name === '' || mb_strlen($mo) > 100 || mb_strlen($name) > 255) {
            throw new PlanningRejected('กรุณากรอกเลข MO และชื่อตู้');
        }
        if (Cabinet::where('mo_no', $mo)->exists()) {
            throw new PlanningRejected("เลข MO {$mo} มีอยู่แล้ว");
        }

        [$cabStart, $cabDue] = [$this->date($op['startDate'] ?? null), $this->date($op['dueDate'] ?? null)];
        if ($cabStart && $cabDue && $cabDue < $cabStart) {
            throw new PlanningRejected('วันครบกำหนดของตู้ต้องไม่ก่อนวันเริ่ม');
        }
        $cabinet = $project->cabinets()->create(['mo_no' => $mo, 'cabinet_name' => $name, 'start_date' => $cabStart, 'due_date' => $cabDue, 'sequence' => (int) $project->cabinets()->max('sequence') + 1]);
        // its tasks / sub tasks / checklists come from the chosen template ('none' = an empty cabinet; not given = the default one)
        $raw = $op['templateId'] ?? null;
        $template = $raw === 'none' ? null : CabinetTemplate::with('taskTemplates.subtaskTemplates.checklistTemplates')
            ->when($raw !== null && $raw !== '', fn ($q) => $q->whereKey((int) $raw), fn ($q) => $q->where('is_default', true))->first();
        if ($raw !== null && $raw !== '' && $raw !== 'none' && ! $template) {
            throw new PlanningRejected('ไม่พบเทมเพลตนี้');
        }
        if ($template) {
            app(CabinetTemplateService::class)->applyToCabinet($cabinet, $template);
        }

        // hand the template's sub tasks to the departments picked in the same window (the normal dispatch: logged, the department is told)
        $assign = is_array($op['assign'] ?? null) ? $op['assign'] : [];
        $sent = 0;
        foreach (CabinetSubtask::with('cabinetTask')->whereHas('cabinetTask', fn ($q) => $q->where('cabinet_id', $cabinet->id))->get() as $subtask) {
            $sent += $this->dispatchTo($user, $subtask, $assign[(string) $subtask->cabinet_subtask_template_id] ?? null);
        }

        return "เพิ่มตู้ {$cabinet->mo_no} แล้ว".($sent ? " · จ่ายงาน {$sent} งาน" : '');
    }

    private function cabinetRename(User $user, array $op): ?string
    {
        $this->requirePmo($user, 'แก้ไขตู้');
        $project = $this->projectFor($user, $op['taskId'] ?? null);
        ProjectLock::assertOpen($project->id);
        $cabinet = $this->cabinetOf($project, $op['cabinetId'] ?? null);
        [$mo, $name] = [trim((string) ($op['mo'] ?? $cabinet->mo_no)), trim((string) ($op['text'] ?? $cabinet->cabinet_name))];

        if ($mo === '' || $name === '' || mb_strlen($mo) > 100 || mb_strlen($name) > 255) {
            throw new PlanningRejected('กรุณากรอกเลข MO และชื่อตู้');
        }
        if (Cabinet::where('mo_no', $mo)->where('id', '!=', $cabinet->id)->exists()) {
            throw new PlanningRejected("เลข MO {$mo} มีอยู่แล้ว");
        }
        $cabinet->update(['mo_no' => $mo, 'cabinet_name' => $name]);

        return null;
    }

    private function cabinetDelete(User $user, array $op): ?string
    {
        $this->requirePmo($user, 'ลบตู้');
        $project = $this->projectFor($user, $op['taskId'] ?? null);
        ProjectLock::assertOpen($project->id);
        $cabinet = $this->cabinetOf($project, $op['cabinetId'] ?? null);
        $cabinet->delete();   // soft delete + cascade: it goes to the trash and can be restored there

        return 'ลบตู้ '.$cabinet->mo_no.' แล้ว (กู้คืนได้ที่ถังขยะ)';
    }

    /** Hands sub tasks of this project that have no department yet (e.g. from a template) to departments: { s12: 'd_5', ... }. */
    private function subtaskAssign(User $user, array $op): ?string
    {
        $this->requirePmo($user, 'จ่ายงาน');
        $project = $this->projectFor($user, $op['taskId'] ?? null);
        ProjectLock::assertOpen($project->id);
        $assign = is_array($op['assign'] ?? null) ? $op['assign'] : [];
        $sent = 0;

        foreach ($assign as $key => $value) {
            $subtask = CabinetSubtask::with('cabinetTask')->whereHas('cabinetTask.cabinet', fn ($q) => $q->where('project_id', $project->id))->find($this->id((string) $key, 's'))
                ?? throw new PlanningRejected('ไม่พบงานย่อยนี้ในโครงการ');
            $sent += $this->dispatchTo($user, $subtask, $value);
        }

        return $sent ? "จ่ายงานแล้ว {$sent} งาน" : null;
    }

    /** Adds a department to the project: every cabinet gets one Sub Task "งาน <แผนก>" handed to it (the normal dispatch: logged, the department is told). */
    private function stageAdd(User $user, array $op): ?string
    {
        $this->requirePmo($user, 'เพิ่มแผนกเข้างาน');
        $project = $this->projectFor($user, $op['taskId'] ?? null);
        ProjectLock::assertOpen($project->id);
        $department = Department::where('is_active', true)->find($this->id($op['deptId'] ?? null, 'd_')) ?? throw new PlanningRejected('ไม่พบแผนกนี้');

        if (WorkScope::projectSubtasks($project, $user)->where('department_id', $department->id)->exists()) {
            throw new PlanningRejected("แผนก {$department->name} อยู่ในโครงการนี้แล้ว");
        }
        $cabinets = $project->cabinets()->get();
        if ($cabinets->isEmpty()) {
            throw new PlanningRejected('โครงการนี้ยังไม่มีตู้ — เพิ่มตู้ก่อน');
        }

        foreach ($cabinets as $cabinet) {
            $task = $cabinet->tasks()->orderBy('sequence')->first()
                ?? $cabinet->tasks()->create(['name' => 'งานแผนก', 'status' => 'not_started', 'progress' => 0, 'sequence' => 1]);
            $subtask = $task->subtasks()->create([
                'name' => "งาน {$department->name}", 'status' => 'not_started', 'progress' => 0,
                'sequence' => (int) $task->subtasks()->max('sequence') + 1,
            ] + CabinetSubtask::initialAssignment(null));
            $this->dispatchTo($user, $subtask->load('cabinetTask'), ['dept' => 'd_'.$department->id, 'start' => $op['start'] ?? null, 'due' => $op['due'] ?? null]);
        }

        return "เพิ่มแผนก {$department->name} เข้าโครงการแล้ว";
    }

    /** Takes a department off the project - only while none of its work was accepted yet (the same lock as changing a department). */
    private function stageRemove(User $user, array $op): ?string
    {
        $this->requirePmo($user, 'เอาแผนกออกจากงาน');
        $project = $this->projectFor($user, $op['taskId'] ?? null);
        $rows = $this->deptSubtasks($project, $user, $op['deptId'] ?? null);

        if ($rows->isEmpty()) {
            throw new PlanningRejected('แผนกนี้ไม่มีงานในโครงการนี้');
        }
        if ($rows->contains(fn ($s) => $s->assignment_status !== CabinetSubtask::ASSIGNMENT_ASSIGNED)) {
            throw new PlanningRejected('เอาแผนกออกไม่ได้ — แผนกนี้รับงานไปแล้ว');
        }
        foreach ($rows as $subtask) {
            $this->assignments->changeDepartment($subtask, null, $user);
        }

        return 'เอาแผนกออกจากโครงการแล้ว';
    }

    private function boardSet(User $user, array $op): ?string
    {
        $this->requirePmo($user, 'ย้ายโครงการไปบอร์ดอื่น');
        $project = $this->projectFor($user, $op['taskId'] ?? null);
        $raw = $op['boardId'] ?? '';
        $board = ($raw === '' || $raw === 'main' || $raw === null) ? null
            : (is_string($raw) && preg_match('/^b(\d+)$/', $raw, $m) ? Board::find((int) $m[1]) : null) ?? throw new PlanningRejected('ไม่พบบอร์ดนี้');

        $project->forceFill(['board_id' => $board?->id])->save();
        ActivityLog::record($project->id, $user->id, $project, 'updated', 'ย้ายไปบอร์ด '.($board?->name ?? 'หลัก'));

        return 'ย้ายโครงการไปบอร์ด '.($board?->name ?? 'หลัก').' แล้ว';
    }

    private function delete(User $user, array $op): ?string
    {
        $this->requirePmo($user, 'ลบโครงการ');
        $project = $this->projectFor($user, $op['taskId'] ?? null);
        $project->delete();   // soft delete + cascade: it goes to the trash and can be restored there

        return 'ย้ายโครงการ '.$project->project_no.' ไปถังขยะแล้ว';
    }
}
