<?php

namespace App\Services\Concerns;

use App\Exceptions\PlanningRejected;
use App\Models\ActivityLog;
use App\Models\Board;
use App\Models\BoardStatus;
use App\Models\Cabinet;
use App\Models\CabinetChecklist;
use App\Models\Department;
use App\Models\Project;
use App\Models\ProjectDepartmentStatus;
use App\Models\User;
use App\Services\CabinetCopyService;
use App\Services\HandoffService;
use App\Services\ProjectLock;
use App\Services\WorkScope;
use Illuminate\Support\Facades\DB;

/**
 * The Planning operations that let the page do everything the original index page did without leaving it: create / copy /
 * restore projects, edit checklist items, edit and acknowledge comments, a status per cabinet, people in charge, importing a
 * request. Used by PlanningSync (which owns the helpers: id(), projectFor(), requirePmo(), date()).
 */
trait PlanningSyncExtras
{
    // ---- projects ----

    private function projectCreate(User $user, array $op): ?string
    {
        $this->requirePmo($user, 'สร้างโครงการ');
        [$so, $title, $customer] = [trim((string) ($op['so'] ?? '')), trim((string) ($op['title'] ?? '')), trim((string) ($op['customer'] ?? ''))];
        if ($so === '' || $title === '' || $customer === '' || mb_strlen($so) > 50 || mb_strlen($title) > 255 || mb_strlen($customer) > 255) {
            throw new PlanningRejected('กรุณากรอกเลขที่โครงการ ชื่อ และลูกค้า');
        }
        if (Project::where('project_no', $so)->exists()) {
            throw new PlanningRejected("เลขที่โครงการ {$so} มีอยู่แล้ว");
        }
        [$start, $due] = [$this->date($op['startDate'] ?? null), $this->date($op['date'] ?? null)];
        if ($start && $due && $due < $start) {
            throw new PlanningRejected('วันครบกำหนดต้องไม่ก่อนวันเริ่ม');
        }

        // the rest of the project form (all optional): phone, job type, contract value, margin - same rules as everywhere else
        $extra = \Illuminate\Support\Facades\Validator::make([
            'customer_phone' => trim((string) ($op['customerPhone'] ?? '')) ?: null,
            'job_type_id' => ($op['jobTypeId'] ?? null) ?: null,
            'contract_value' => Project::cleanAmount($op['contractValue'] ?? null),
            'margin' => Project::cleanAmount($op['margin'] ?? null),
        ], [
            'customer_phone' => ['nullable', 'string', 'max:50'], 'job_type_id' => ['nullable', 'exists:job_types,id'],
            'contract_value' => ['nullable', 'numeric', 'min:0', 'max:9999999999999'], 'margin' => ['nullable', 'numeric', 'max:9999999999999'],
        ], ['contract_value.numeric' => 'มูลค่างานต้องเป็นตัวเลข', 'margin.numeric' => 'Margin ต้องเป็นตัวเลข'])->validate();
        if (! $user->allows('data.finance')) {   // not theirs to set (Settings > สิทธิ์การใช้งาน)
            unset($extra['contract_value'], $extra['margin']);
        }
        if (! $user->allows('data.contact')) {
            unset($extra['customer_phone']);
        }

        $project = Project::create($extra + [
            'project_no' => $so, 'project_name' => $title, 'customer_name' => $customer,
            'start_date' => $start, 'due_date' => $due,
            'priority' => self::PRIORITY[$op['priority'] ?? 'none'] ?? 'normal',
            'status' => Project::STATUS_PLANNING,
            'board_id' => $this->boardIdOf($op['boardId'] ?? null),
            'project_manager_id' => User::where('is_active', true)->whereIn('role', Project::MANAGER_ROLES)->find((int) ($op['managerId'] ?? 0))?->id,
        ]);
        $this->created = $project->id;

        return "สร้างโครงการ {$project->project_no} แล้ว";
    }

    private function projectDuplicate(User $user, array $op): ?string
    {
        $this->requirePmo($user, 'คัดลอกโครงการ');
        $source = $this->projectFor($user, $op['taskId'] ?? null);
        $so = trim((string) ($op['so'] ?? ''));
        if ($so === '' || mb_strlen($so) > 50) {
            throw new PlanningRejected('กรุณากรอกเลขที่โครงการใหม่');
        }
        if (Project::where('project_no', $so)->exists()) {
            throw new PlanningRejected("เลขที่โครงการ {$so} มีอยู่แล้ว");
        }

        $copy = DB::transaction(function () use ($source, $so, $op) {
            $copy = $source->replicate(['project_no', 'board_status_id', 'created_by', 'updated_by']);
            $copy->project_no = $so;
            $copy->project_name = trim((string) ($op['title'] ?? '')) ?: $source->project_name.' (สำเนา)';
            $copy->status = Project::STATUS_PLANNING;
            $copy->save();
            $copy->boardLabels()->sync($source->boardLabels()->pluck('board_labels.id'));

            // every cabinet with its tasks / sub tasks / checklists (the workflow starts over - CabinetCopyService)
            foreach ($source->cabinets()->orderBy('sequence')->get() as $i => $cabinet) {
                $mo = $cabinet->mo_no.'-'.$so;
                for ($n = 2; Cabinet::withTrashed()->where('mo_no', $mo)->exists(); $n++) {
                    $mo = $cabinet->mo_no.'-'.$so.'-'.$n;
                }
                $target = $copy->cabinets()->create(['mo_no' => mb_substr($mo, 0, 100), 'cabinet_name' => $cabinet->cabinet_name, 'quantity' => $cabinet->quantity,
                    'cabinet_type' => $cabinet->cabinet_type, 'size' => $cabinet->size, 'cabinet_template_id' => $cabinet->cabinet_template_id, 'sequence' => $i + 1]);
                app(CabinetCopyService::class)->copyFrom($cabinet->load('tasks.subtasks.checklists'), $target);
            }

            return $copy;
        });
        $this->created = $copy->id;

        return "คัดลอกเป็นโครงการ {$copy->project_no} แล้ว";
    }

    /** Back from the trash, on this page (same rule as the trash screen: Admin / PM, and the number must still be free). */
    private function projectRestore(User $user, array $op): ?string
    {
        if (! $user->canManageTrash()) {
            throw new PlanningRejected('กู้คืนได้เฉพาะ Admin / Project Manager');
        }
        $project = Project::onlyTrashed()->find($this->id($op['taskId'] ?? null, 'p')) ?? throw new PlanningRejected('ไม่พบโครงการนี้ในถังขยะ');
        if (Project::where('project_no', $project->project_no)->exists()) {
            throw new PlanningRejected("กู้คืนไม่ได้: มีโครงการเลขที่ {$project->project_no} อยู่แล้ว");
        }
        $project->restore();

        return "กู้คืนโครงการ {$project->project_no} แล้ว";
    }

    private function boardIdOf(mixed $raw): ?int
    {
        return is_string($raw) && preg_match('/^b(\d+)$/', $raw, $m) ? Board::find((int) $m[1])?->id : null;
    }

    /**
     * Hands one sub task to a department, with its dates if given: 'd_5', or ['dept' => 'd_5', 'start' => 'YYYY-MM-DD', 'due' => ...].
     * The normal dispatch (SubtaskAssignmentService: logged, the department is told); the due date passes the date-limit rules.
     *
     * @return int 1 when it was handed out
     */
    private function dispatchTo(User $user, \App\Models\CabinetSubtask $subtask, mixed $value): int
    {
        [$dept, $start, $due] = is_array($value) ? [$value['dept'] ?? null, $value['start'] ?? null, $value['due'] ?? null] : [$value, null, null];
        if (! is_string($dept) || $dept === '') {
            return 0;
        }
        $department = \App\Models\Department::where('is_active', true)->find($this->id($dept, 'd_')) ?? throw new PlanningRejected('ไม่พบแผนกนี้');
        [$start, $due] = [$this->date($start), $this->date($due)];
        // no dates given: the department's range in this project (all its work there shares one start - end)
        if (! $start && ! $due) {
            $range = \App\Models\CabinetSubtask::where('department_id', $department->id)->where('id', '!=', $subtask->id)
                ->whereHas('cabinetTask.cabinet', fn ($q) => $q->where('project_id', $subtask->cabinetTask->cabinet->project_id))
                ->selectRaw('min(start_date) s, max(due_date) d')->first();
            [$start, $due] = [$range?->s ? substr($range->s, 0, 10) : null, $range?->d ? substr($range->d, 0, 10) : null];
        }
        if ($start && $due && $due < $start) {
            throw new PlanningRejected('วันส่งต้องไม่ก่อนวันเริ่ม ('.$subtask->name.')');
        }
        if ($due && ($message = \App\Models\DateLimitRule::violationFor($subtask->cabinetTask->cabinet_id, $department->id, $due))) {
            throw new PlanningRejected($message);
        }

        $sent = (int) $this->assignments->changeDepartment($subtask, $department->id, $user);
        if ($sent && ! $this->notifications->headcount($department->id)) {
            $this->notes[] = "⚠️ แผนก {$department->name} ยังไม่มีผู้ใช้ในระบบ — ไม่มีใครเห็นงานหรือได้รับแจ้งเตือน (เพิ่มผู้ใช้ที่ ตั้งค่า › ผู้ใช้งาน)";
        }
        if ($start || $due) {
            $subtask->refresh()->update(array_filter(['start_date' => $start, 'due_date' => $due]));
        }

        return $sent;
    }

    // ---- the project's record, a cabinet's record, one sub task's dates / owner / remark ----

    private function cabinetInfo(User $user, array $op): ?string
    {
        $this->requirePmo($user, 'แก้ข้อมูลตู้');
        $project = $this->projectFor($user, $op['taskId'] ?? null);
        ProjectLock::assertOpen($project->id);
        $cabinet = Cabinet::where('project_id', $project->id)->find($this->id($op['cabinetId'] ?? null, 'c')) ?? throw new PlanningRejected('ไม่พบตู้นี้ในโครงการ');
        $f = is_array($op['fields'] ?? null) ? $op['fields'] : [];
        $map = [];
        foreach (['startDate' => 'start_date', 'dueDate' => 'due_date', 'expectedDate' => 'expected_completion_date'] as $k => $col) {
            if (array_key_exists($k, $f)) {
                $map[$col] = $this->date($f[$k]);
            }
        }
        foreach (['type' => ['cabinet_type', 100], 'size' => ['size', 100], 'description' => ['description', 5000], 'remark' => ['remark', 5000]] as $k => [$col, $max]) {
            if (array_key_exists($k, $f)) {
                $map[$col] = mb_substr(trim((string) $f[$k]), 0, $max) ?: null;
            }
        }
        if (array_key_exists('quantity', $f)) {
            $q = filter_var($f['quantity'], FILTER_VALIDATE_INT);
            $map['quantity'] = $q !== false && $q > 0 && $q < 100000 ? $q : throw new PlanningRejected('จำนวนต้องเป็นตัวเลขมากกว่า 0');
        }
        $start = array_key_exists('start_date', $map) ? $map['start_date'] : $cabinet->start_date?->format('Y-m-d');
        $due = array_key_exists('due_date', $map) ? $map['due_date'] : $cabinet->due_date?->format('Y-m-d');
        $expected = array_key_exists('expected_completion_date', $map) ? $map['expected_completion_date'] : $cabinet->expected_completion_date?->format('Y-m-d');
        if ($start && $due && $due < $start) {
            throw new PlanningRejected('วันครบกำหนดของตู้ต้องไม่ก่อนวันเริ่ม');
        }
        if ($expected && $due && $expected > $due) {
            throw new PlanningRejected('วันคาดว่าจะเสร็จต้องไม่เกินวันครบกำหนด (Due Date)');
        }
        if ($map) {
            $cabinet->update($map);
        }

        return null;
    }

    /** One sub task: dates, the person doing it, a remark. PMO roles: any; a department member: their own department's work. */
    private function subtaskInfo(User $user, array $op): ?string
    {
        $this->requirePmo($user, 'แก้วันที่ / ผู้ทำ / หมายเหตุของงาน');
        $project = $this->projectFor($user, $op['taskId'] ?? null);
        ProjectLock::assertOpen($project->id);
        $subtask = \App\Models\CabinetSubtask::with('cabinetTask')->whereHas('cabinetTask.cabinet', fn ($q) => $q->where('project_id', $project->id))
            ->find($this->id($op['subtaskId'] ?? null, 's')) ?? throw new PlanningRejected('ไม่พบงานย่อยนี้');
        if (! $user->canDispatchWork() && ($subtask->department_id === null || $subtask->department_id !== $user->department_id)) {
            throw new PlanningRejected('แก้ได้เฉพาะงานของแผนกตัวเอง');
        }
        if ($subtask->assignment_status === \App\Models\CabinetSubtask::ASSIGNMENT_COMPLETED && (array_key_exists('startDate', $op['fields'] ?? []) || array_key_exists('dueDate', $op['fields'] ?? []))) {
            throw new PlanningRejected('งานที่เสร็จแล้วแก้วันไม่ได้');
        }
        $f = is_array($op['fields'] ?? null) ? $op['fields'] : [];
        $map = [];
        if (array_key_exists('startDate', $f)) {
            $map['start_date'] = $this->date($f['startDate']);
        }
        if (array_key_exists('dueDate', $f)) {
            $map['due_date'] = $this->date($f['dueDate']);
        }
        if (array_key_exists('remark', $f)) {
            $map['remark'] = mb_substr(trim((string) $f['remark']), 0, 2000) ?: null;
        }
        if (array_key_exists('ownerId', $f)) {
            $owner = $f['ownerId'] === '' || $f['ownerId'] === null ? null
                : User::where('is_active', true)->find((int) $f['ownerId']) ?? throw new PlanningRejected('ไม่พบผู้ใช้นี้');
            if ($owner && $subtask->department_id && $owner->department_id !== $subtask->department_id && ! $owner->canDispatchWork()) {
                throw new PlanningRejected('ผู้รับผิดชอบต้องอยู่ในแผนกของงานนี้');
            }
            $map['owner_id'] = $owner?->id;
        }
        $start = array_key_exists('start_date', $map) ? $map['start_date'] : $subtask->start_date?->format('Y-m-d');
        $due = array_key_exists('due_date', $map) ? $map['due_date'] : $subtask->due_date?->format('Y-m-d');
        if ($start && $due && $due < $start) {
            throw new PlanningRejected('วันส่งต้องไม่ก่อนวันเริ่ม');
        }
        if (! empty($map['due_date']) && ($message = \App\Models\DateLimitRule::violationFor($subtask->cabinetTask->cabinet_id, $subtask->department_id, $map['due_date']))) {
            throw new PlanningRejected($message);
        }
        $dueMoved = array_key_exists('due_date', $map) && $map['due_date'] !== $subtask->due_date?->format('Y-m-d');
        if ($map) {
            $subtask->update($map);
        }
        if ($dueMoved) {
            $this->notifications->dueChanged(collect([$subtask->fresh('cabinetTask.cabinet.project')]), $user);
        }

        return null;
    }

    /**
     * The customer moved the job: the project, its cabinets and every sub task that is not finished yet move by the same number
     * of days (Admin / PM). All or nothing - if one moved date breaks a date-limit rule, nothing moves.
     */
    private function projectShift(User $user, array $op): ?string
    {
        $this->requirePmo($user, 'เลื่อนทั้งโครงการ');
        $project = $this->projectFor($user, $op['taskId'] ?? null);
        ProjectLock::assertOpen($project->id);
        $days = filter_var($op['days'] ?? null, FILTER_VALIDATE_INT);
        if ($days === false || $days === 0 || abs($days) > 730) {
            throw new PlanningRejected('จำนวนวันต้องเป็นตัวเลข (บวก = เลื่อนออก, ลบ = เลื่อนเข้า) ไม่เกิน 730');
        }
        $move = fn ($d) => $d?->copy()->addDays($days)->format('Y-m-d');

        $moved = collect();
        DB::transaction(function () use ($project, $move, &$moved) {
            $project->update(array_filter(['start_date' => $move($project->start_date), 'due_date' => $move($project->due_date)]));
            foreach ($project->cabinets()->get() as $cabinet) {
                $cabinet->update(array_filter(['start_date' => $move($cabinet->start_date), 'due_date' => $move($cabinet->due_date), 'expected_completion_date' => $move($cabinet->expected_completion_date)]));
                foreach (\App\Models\CabinetSubtask::whereHas('cabinetTask', fn ($q) => $q->where('cabinet_id', $cabinet->id))
                    ->where('assignment_status', '!=', \App\Models\CabinetSubtask::ASSIGNMENT_COMPLETED)->get() as $s) {
                    $due = $move($s->due_date);
                    if ($due && ($message = \App\Models\DateLimitRule::violationFor($cabinet->id, $s->department_id, $due))) {
                        throw new PlanningRejected($message);
                    }
                    $s->update(array_filter(['start_date' => $move($s->start_date), 'due_date' => $due]));
                    if ($due) {
                        $moved->push($s);
                    }
                }
            }
        });
        ActivityLog::record($project->id, $user->id, $project, 'updated', 'เลื่อนทั้งโครงการ '.($days > 0 ? '+' : '').$days.' วัน');
        $this->notifications->dueChanged($moved->each->loadMissing('cabinetTask.cabinet.project'), $user);

        return 'เลื่อนทั้งโครงการ '.($days > 0 ? '+' : '').$days.' วันแล้ว';
    }

    // ---- people in charge ----

    private function assigneesSet(User $user, array $op): ?string
    {
        $this->requirePmo($user, 'กำหนดผู้รับผิดชอบ');
        $project = $this->projectFor($user, $op['taskId'] ?? null);
        $ids = collect(is_array($op['userIds'] ?? null) ? $op['userIds'] : [])->filter(fn ($v) => is_int($v) || (is_string($v) && ctype_digit($v)))->map(fn ($v) => (int) $v)->unique()->take(30);
        $people = User::whereIn('id', $ids)->where('is_active', true)->get();

        $before = $project->assignees()->pluck('users.id');
        $project->assignees()->sync($people->pluck('id'));
        foreach ($people->whereNotIn('id', $before) as $added) {
            $this->notifications->assignedPerson($project, $user, $added);
        }
        ActivityLog::record($project->id, $user->id, $project, 'updated', 'ผู้รับผิดชอบ: '.($people->isEmpty() ? '(ไม่มี)' : $people->pluck('name')->implode(', ')));

        return null;
    }

    // ---- cabinets ----

    private function cabinetStatus(User $user, array $op): ?string
    {
        $this->requirePmo($user, 'ตั้งสถานะตู้');
        $project = $this->projectFor($user, $op['taskId'] ?? null);
        ProjectLock::assertOpen($project->id);
        $cabinet = Cabinet::where('project_id', $project->id)->find($this->id($op['cabinetId'] ?? null, 'c')) ?? throw new PlanningRejected('ไม่พบตู้นี้ในโครงการ');
        if (! $user->canDispatchWork() && ! WorkScope::projectSubtasks($project, $user)->whereHas('cabinetTask', fn ($q) => $q->where('cabinet_id', $cabinet->id))->exists()) {
            throw new PlanningRejected('ตั้งสถานะได้เฉพาะตู้ที่แผนกของคุณมีงาน');
        }
        $raw = $op['statusId'] ?? '';
        $status = $raw === '' || $raw === null ? null : (is_string($raw) ? BoardStatus::fromPageId($raw) : null) ?? throw new PlanningRejected('สถานะไม่ถูกต้อง');

        $cabinet->forceFill(['board_status_id' => $status?->id])->save();
        ActivityLog::record($project->id, $user->id, $cabinet, 'updated', "ตั้งสถานะตู้ {$cabinet->mo_no} เป็น ".($status?->name ?? 'อัตโนมัติ'));

        return null;
    }

    /** A department's status in one project, set by Admin / PM over the one worked out from its work ('' = back to automatic). */
    private function stageStatus(User $user, array $op): ?string
    {
        $this->requirePmo($user, 'ตั้งสถานะงานของแผนก');
        $project = $this->projectFor($user, $op['taskId'] ?? null);
        ProjectLock::assertOpen($project->id);
        $department = Department::find($this->id($op['deptId'] ?? null, 'd_')) ?? throw new PlanningRejected('ไม่พบแผนกนี้');
        $raw = $op['statusId'] ?? '';
        $status = $raw === '' || $raw === null ? null : (is_string($raw) ? BoardStatus::fromPageId($raw) : null) ?? throw new PlanningRejected('สถานะไม่ถูกต้อง');

        $where = ['project_id' => $project->id, 'department_id' => $department->id];
        $status ? ProjectDepartmentStatus::updateOrCreate($where, ['board_status_id' => $status->id]) : ProjectDepartmentStatus::where($where)->delete();
        ActivityLog::record($project->id, $user->id, $project, 'updated', "ตั้งสถานะงานของแผนก {$department->name} เป็น ".($status?->name ?? 'อัตโนมัติ'));

        return null;
    }

    // ---- checklist items: rename, remark, order ----

    private function editableItem(User $user, mixed $id): CabinetChecklist
    {
        $item = CabinetChecklist::with('subtask.cabinetTask.cabinet.project')->find($this->id($id, 'k')) ?? throw new PlanningRejected('ไม่พบรายการ checklist นี้');
        $project = $item->subtask->cabinetTask->cabinet->project;
        if (! WorkScope::projectSubtasks($project, $user)->whereKey($item->subtask->id)->exists() || ! $item->subtask->isChecklistEditableBy($user)) {
            throw new PlanningRejected('แก้ Checklist ได้เฉพาะแผนกที่รับงานแล้ว (หรือ Admin / PM)');
        }
        ProjectLock::assertOpen($project->id);

        return $item;
    }

    private function checklistRename(User $user, array $op): ?string
    {
        $item = $this->editableItem($user, $op['itemId'] ?? null);
        $name = trim((string) ($op['text'] ?? ''));
        if ($name === '' || mb_strlen($name) > 255) {
            throw new PlanningRejected('กรุณาพิมพ์ชื่อรายการ checklist');
        }
        $item->update(['name' => $name]);

        return null;
    }

    private function checklistRemark(User $user, array $op): ?string
    {
        $item = $this->editableItem($user, $op['itemId'] ?? null);
        $item->update(['remark' => mb_substr(trim((string) ($op['remark'] ?? '')), 0, 1000) ?: null]);

        return null;
    }

    /** Settings > การส่งต่องาน: the date a sending department gives an item ("วันของเข้า"); the receiving departments hear it. */
    private function checklistDate(User $user, array $op): ?string
    {
        $item = $this->editableItem($user, $op['itemId'] ?? null);
        $this->requireDateLabel($item->subtask->department_id);
        $item->update(['expected_date' => $this->date($op['date'] ?? null)]);
        if ($item->wasChanged('expected_date')) {
            app(HandoffService::class)->datesChanged(collect([$item]), $user);
        }

        return null;
    }

    /** The same date for every item of one department in one cabinet ("ใส่วันเดียวกันทั้งตู้"). */
    private function checklistDateAll(User $user, array $op): ?string
    {
        $project = $this->projectFor($user, $op['taskId'] ?? null);
        ProjectLock::assertOpen($project->id);
        $deptId = $this->id($op['deptId'] ?? null, 'd_');
        $this->requireDateLabel($deptId);
        $subtasks = WorkScope::projectSubtasks($project, $user)->where('department_id', $deptId)
            ->whereHas('cabinetTask', fn ($q) => $q->where('cabinet_id', $this->id($op['cabinetId'] ?? null, 'c')))->with('checklists')->get();
        if ($subtasks->isEmpty() || $subtasks->contains(fn ($s) => ! $s->isChecklistEditableBy($user))) {
            throw new PlanningRejected('ใส่วันที่ได้เฉพาะแผนกที่รับงานแล้ว (หรือ Admin / PM)');
        }
        $date = $this->date($op['date'] ?? null);
        $changed = $subtasks->flatMap->checklists->filter(fn ($c) => $c->expected_date?->format('Y-m-d') !== $date)
            ->each(fn ($c) => $c->update(['expected_date' => $date]));
        if ($changed->isNotEmpty()) {
            app(HandoffService::class)->datesChanged($changed->values(), $user);
        }

        return $changed->isEmpty() ? null : 'ใส่วันที่ให้ '.$changed->count().' รายการ';
    }

    private function requireDateLabel(?int $deptId): void
    {
        if (! app(HandoffService::class)->dateLabel($deptId)) {
            throw new PlanningRejected('แผนกนี้ยังไม่ได้ตั้งชื่อวันที่ส่งต่อ (ตั้งค่า › แผนก)');
        }
    }

    private function checklistMove(User $user, array $op): ?string
    {
        $item = $this->editableItem($user, $op['itemId'] ?? null);
        $list = $item->subtask->checklists()->orderBy('sequence')->orderBy('id')->get()->values();
        $i = $list->search(fn ($c) => $c->id === $item->id);
        $j = ($op['dir'] ?? '') === 'up' ? $i - 1 : $i + 1;
        if (isset($list[$j])) {
            [$list[$i], $list[$j]] = [$list[$j], $list[$i]];
            $list->each(fn ($c, $n) => $c->sequence === $n + 1 ?: $c->forceFill(['sequence' => $n + 1])->saveQuietly());
        }

        return null;
    }

    // ---- comments: edit / delete your own, acknowledge an alert ----

    private function noteOf(Project $project, mixed $id): ActivityLog
    {
        return ActivityLog::where('project_id', $project->id)->where('action', 'note')->find($this->id($id, 'a')) ?? throw new PlanningRejected('ไม่พบคอมเมนต์นี้');
    }

    private function commentEdit(User $user, array $op): ?string
    {
        $note = $this->noteOf($this->projectFor($user, $op['taskId'] ?? null), $op['commentId'] ?? null);
        if ($note->user_id !== $user->id) {
            throw new PlanningRejected('แก้ไขได้เฉพาะคอมเมนต์ของตัวเอง');
        }
        $text = trim((string) ($op['text'] ?? ''));
        if ($text === '') {
            throw new PlanningRejected('คอมเมนต์ต้องมีข้อความ');
        }
        $note->forceFill(['description' => mb_substr($text, 0, 2000), 'changes' => ($note->changes ?? []) + ['edited' => true]])->save();

        return null;
    }

    private function commentDelete(User $user, array $op): ?string
    {
        $project = $this->projectFor($user, $op['taskId'] ?? null);
        $note = $this->noteOf($project, $op['commentId'] ?? null);
        if ($note->user_id !== $user->id && ! $user->isAdmin()) {
            throw new PlanningRejected('ลบได้เฉพาะคอมเมนต์ของตัวเอง');
        }
        foreach ($note->attachments as $file) {
            @unlink(public_path('uploads/comment-attachments/'.$file->path));
            $file->delete();
        }
        $note->delete();
        ActivityLog::record($project->id, $user->id, $project, 'updated', 'ลบคอมเมนต์');

        return null;
    }

    /** "รับทราบ" on an alert sent to my department: recorded on the alert (who, when) and its notice is marked read. */
    private function alertAck(User $user, array $op): ?string
    {
        $project = $this->projectFor($user, $op['taskId'] ?? null);
        $note = $this->noteOf($project, $op['commentId'] ?? null);
        $changes = $note->changes ?? [];
        if (empty($changes['alert_department_id'])) {
            throw new PlanningRejected('คอมเมนต์นี้ไม่ใช่การแจ้งเตือน');
        }
        if ((int) $changes['alert_department_id'] !== (int) $user->department_id) {
            throw new PlanningRejected('รับทราบได้เฉพาะแผนกที่ถูกแจ้งเตือน');
        }
        if (! collect($changes['acks'] ?? [])->contains('user_id', $user->id)) {
            $changes['acks'] = array_values(array_merge($changes['acks'] ?? [], [['user_id' => $user->id, 'name' => $user->name, 'at' => now()->getTimestampMs()]]));
            $note->forceFill(['changes' => $changes])->save();
        }
        $user->unreadNotifications()->get()
            ->filter(fn ($n) => ($n->data['kind'] ?? null) === 'alert' && (int) ($n->data['project_id'] ?? 0) === $project->id)
            ->each->markAsRead();

        return null;
    }
}
