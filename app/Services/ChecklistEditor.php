<?php

namespace App\Services;

use App\Exceptions\PlanningRejected;
use App\Models\ActivityLog;
use App\Models\CabinetChecklist;
use App\Models\CabinetSubtask;
use App\Models\CabinetSubtaskTemplate;
use App\Models\ChecklistTemplate;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The one checklist editor behind every checklist list (a cabinet's Sub Task, a template's Sub Task): add many at once
 * (typed or pasted from Excel), edit in place, delete (with undo), reorder, move to another Sub Task, copy to others.
 *
 * Two kinds of list, one set of operations:
 *  - 'subtask'  : a cabinet's CabinetSubtask -> CabinetChecklist (name, remark, date, ticked). Who: isChecklistEditableBy()
 *                 (the department that accepted the work, or Admin / PM); a closed project refuses. Deletes are soft.
 *  - 'template' : a template's CabinetSubtaskTemplate -> ChecklistTemplate (name, description). Admin / PM only. Deletes are
 *                 real (undo re-creates them). Never touches cabinets already made from the template.
 *
 * Bulk changes are written WITHOUT per-row model events (one Activity Log line per operation, not one per item) - so the
 * closed-project guard and the board version are applied here by hand.
 */
class ChecklistEditor
{
    public const MAX_ITEMS = 300;

    public function __construct(private ProgressService $progress) {}

    // ======================================================================= reading

    /** The list as the editor shows it (the move / copy choices are fetched separately, when the editor needs them). */
    public function state(string $kind, Model $owner, User $user): array
    {
        return [
            'items' => $this->items($kind, $owner),
            'editable' => $this->canEdit($kind, $owner, $user),
            'dateLabel' => $kind === 'subtask' ? app(HandoffService::class)->dateLabel($owner->department_id) : null,
        ];
    }

    /** Where this list's items may go / come from: move (same cabinet / template), copy to, copy in from. */
    public function targets(string $kind, Model $owner): array
    {
        $move = $this->moveTargets($kind, $owner);
        $copyTo = $this->copyTargets($kind, $owner);

        // copy IN: a cabinet takes another Sub Task of the same cabinet; a template takes any template's Sub Task (a ready-made set)
        return ['move' => $move, 'copyTo' => $copyTo, 'copyFrom' => $kind === 'subtask' ? $move : $copyTo];
    }

    public function items(string $kind, Model $owner): array
    {
        if ($kind === 'template') {
            $list = $owner->relationLoaded('checklistTemplates')
                ? $owner->checklistTemplates->sortBy(fn ($c) => sprintf('%09d-%09d', $c->sequence ?? 0, $c->id))->values()
                : $owner->checklistTemplates()->orderBy('sequence')->orderBy('id')->get();

            return $list
                ->map(fn (ChecklistTemplate $c) => ['id' => $c->id, 'name' => $c->name, 'remark' => (string) $c->description])->all();
        }

        // a page that already loaded the list (with completedBy) is not asked again
        $list = $owner->relationLoaded('checklists')
            ? $owner->checklists->sortBy(fn ($c) => sprintf('%09d-%09d', $c->sequence ?? 0, $c->id))->values()
            : $owner->checklists()->with('completedBy:id,name')->orderBy('sequence')->orderBy('id')->get();

        // Settings > การส่งต่องาน: has the department that follows this one (e.g. คลัง after จัดซื้อ) dealt with each item yet
        $receipts = app(HandoffService::class)->receiptsFor($owner->setRelation('checklists', $list));

        return $list
            ->map(fn (CabinetChecklist $c) => [
                'id' => $c->id, 'name' => $c->name, 'remark' => (string) $c->remark, 'date' => $c->expected_date?->format('Y-m-d'),
                'done' => (bool) $c->is_completed, 'doneBy' => $c->completedBy?->name, 'doneAt' => $c->completed_at?->thaiDateTime(),
                'toggleUrl' => route('checklists.toggle', $c), 'handoff' => $receipts[$c->id] ?? [],
            ])->all();
    }

    /** Same cabinet / same template: the other Sub Tasks (move). */
    private function moveTargets(string $kind, Model $owner): array
    {
        if ($kind === 'template') {
            return CabinetSubtaskTemplate::whereHas('taskTemplate', fn ($q) => $q->where('cabinet_template_id', $owner->taskTemplate->cabinet_template_id))
                ->with('taskTemplate:id,name')->whereKeyNot($owner->id)->orderBy('id')->get()
                ->map(fn ($s) => ['id' => $s->id, 'label' => $s->taskTemplate->name.' › '.$s->name])->all();
        }

        return CabinetSubtask::whereHas('cabinetTask', fn ($q) => $q->where('cabinet_id', $owner->cabinetTask->cabinet_id))
            ->with('cabinetTask:id,name', 'department:id,name')->whereKeyNot($owner->id)->orderBy('id')->get()
            ->map(fn ($s) => ['id' => $s->id, 'label' => $s->cabinetTask->name.' › '.$s->name.($s->department ? ' ('.$s->department->name.')' : '')])->all();
    }

    /**
     * Copy: a cabinet's items go to the SAME Sub Task in the project's other cabinets (same template sub task, else same
     * name); a template's go to any Sub Task of any template (a ready-made set of checks can be reused anywhere).
     */
    private function copyTargets(string $kind, Model $owner): array
    {
        if ($kind === 'template') {
            return CabinetSubtaskTemplate::with('taskTemplate.cabinetTemplate:id,name', 'taskTemplate:id,name,cabinet_template_id')->whereKeyNot($owner->id)
                ->orderBy('id')->get()
                ->map(fn ($s) => ['id' => $s->id, 'label' => $s->taskTemplate->cabinetTemplate->name.' › '.$s->taskTemplate->name.' › '.$s->name])->all();
        }

        $cabinet = $owner->cabinetTask->cabinet;

        return CabinetSubtask::whereHas('cabinetTask.cabinet', fn ($q) => $q->where('project_id', $cabinet->project_id)->whereKeyNot($cabinet->id))
            ->where(fn ($q) => $owner->cabinet_subtask_template_id
                ? $q->where('cabinet_subtask_template_id', $owner->cabinet_subtask_template_id)->orWhere('name', $owner->name)
                : $q->where('name', $owner->name))
            ->with('cabinetTask.cabinet:id,mo_no,cabinet_name')->orderBy('id')->get()
            ->map(fn ($s) => ['id' => $s->id, 'label' => trim($s->cabinetTask->cabinet->mo_no.' '.$s->cabinetTask->cabinet->cabinet_name).' › '.$s->name])->all();
    }

    // ======================================================================= permissions

    public function canEdit(string $kind, Model $owner, User $user): bool
    {
        return $kind === 'template' ? $user->allows('edit.templates') : $owner->isChecklistEditableBy($user);
    }

    private function authorize(string $kind, Model $owner, User $user): void
    {
        if (! $this->canEdit($kind, $owner, $user)) {
            throw new PlanningRejected($kind === 'template'
                ? 'แก้ Checklist ของเทมเพลตได้เฉพาะ Admin / PM'
                : 'แก้ Checklist ได้เฉพาะแผนกที่รับงานแล้ว (หรือ Admin / PM)');
        }
        if ($kind === 'subtask') {
            ProjectLock::assertOpen($owner->cabinetTask->cabinet->project_id);
        }
    }

    // ======================================================================= writing

    /**
     * One operation. Returns ['message', 'items' (the list after), 'progress' (subtask kind: for the page's bars),
     * 'undo' (what a "ยกเลิก" sends back)].
     *
     * @throws PlanningRejected
     */
    public function apply(string $kind, Model $owner, User $user, array $op): array
    {
        $this->authorize($kind, $owner, $user);

        $result = DB::transaction(fn () => Model::withoutEvents(fn () => match ($op['type'] ?? null) {
            'add' => $this->add($kind, $owner, $op['items'] ?? []),
            'update' => $this->update($kind, $owner, $op),
            'delete' => $this->delete($kind, $owner, $op['ids'] ?? []),
            'restore' => $this->restore($kind, $owner, $op),
            'reorder' => $this->reorder($kind, $owner, $op['ids'] ?? []),
            'move' => $this->move($kind, $owner, $user, $op['ids'] ?? [], $op['to'] ?? null),
            'copy' => $this->copy($kind, $owner, $user, $op['ids'] ?? [], $op['to'] ?? []),
            'copyFrom' => $this->copyFrom($kind, $owner, $op['from'] ?? null),
            default => throw new PlanningRejected('ไม่รู้จักคำสั่งนี้'),
        }));

        $touched = array_unique(array_merge([$owner->id], $result['others'] ?? []));
        if ($kind === 'subtask') {
            foreach (CabinetSubtask::whereKey($touched)->get() as $s) {
                $this->progress->recalculateSubtask($s);
            }
            if (! empty($result['log'])) {
                ActivityLog::record($owner->cabinetTask->cabinet->project_id, $user->id, $owner, 'checklist_edited', $result['log']);
            }
        }
        PlanningBoard::touch();   // per-row events were off: the Planning board must still notice

        return [
            'message' => $result['message'] ?? null,
            'items' => $this->items($kind, $owner->fresh()),
            'progress' => $kind === 'subtask' ? $this->progressOf($owner->fresh()) : null,
            'undo' => $result['undo'] ?? null,
        ];
    }

    /** @param list<array{name?: string, remark?: string, date?: string}|string> $rows */
    private function add(string $kind, Model $owner, array $rows): array
    {
        $rows = collect($rows)->map(fn ($r) => is_array($r) ? $r : ['name' => $r])
            ->map(fn ($r) => ['name' => mb_substr(trim((string) ($r['name'] ?? '')), 0, 255), 'remark' => mb_substr(trim((string) ($r['remark'] ?? '')), 0, 1000), 'date' => $this->date($r['date'] ?? null)])
            ->filter(fn ($r) => $r['name'] !== '')->values();
        if ($rows->isEmpty()) {
            throw new PlanningRejected('พิมพ์ชื่อรายการอย่างน้อย 1 รายการ');
        }
        if ($rows->count() > self::MAX_ITEMS) {
            throw new PlanningRejected('เพิ่มได้ครั้งละไม่เกิน '.self::MAX_ITEMS.' รายการ');
        }
        $this->checkDates($kind, $owner, $rows->pluck('date')->filter()->isNotEmpty());

        $seq = (int) $this->query($kind, $owner)->max('sequence');
        $created = $rows->map(fn ($r) => $this->create($kind, $owner, $r, ++$seq));

        return ['message' => 'เพิ่ม '.$created->count().' รายการ', 'log' => 'เพิ่ม Checklist '.$created->count().' รายการใน "'.$owner->name.'"',
            'undo' => ['type' => 'delete', 'ids' => $created->pluck('id')->all()]];
    }

    private function update(string $kind, Model $owner, array $op): array
    {
        $item = $this->query($kind, $owner)->find((int) ($op['id'] ?? 0)) ?? throw new PlanningRejected('ไม่พบรายการนี้ (อาจถูกลบไปแล้ว)');
        $before = $this->snapshot($kind, $item);
        $changes = [];
        if (array_key_exists('name', $op)) {
            $name = mb_substr(trim((string) $op['name']), 0, 255);
            if ($name === '') {
                throw new PlanningRejected('ชื่อรายการต้องไม่ว่าง');
            }
            $changes['name'] = $name;
        }
        if (array_key_exists('remark', $op)) {
            $changes[$kind === 'template' ? 'description' : 'remark'] = mb_substr(trim((string) $op['remark']), 0, 1000) ?: null;
        }
        if (array_key_exists('date', $op) && $kind === 'subtask') {
            $date = $this->date($op['date']);
            if ($date !== $item->expected_date?->format('Y-m-d')) {   // (an undo sends the old, unchanged date back)
                $this->checkDates($kind, $owner, true);
                $changes['expected_date'] = $date;
            }
        }
        $item->forceFill($changes)->save();

        return ['undo' => ['type' => 'update', 'id' => $item->id] + $before,
            'log' => isset($changes['name']) && $changes['name'] !== $before['name'] ? "แก้ชื่อ Checklist \"{$before['name']}\" → \"{$changes['name']}\"" : null];
    }

    private function delete(string $kind, Model $owner, array $ids): array
    {
        $items = $this->query($kind, $owner)->whereKey($this->ids($ids))->get();
        if ($items->isEmpty()) {
            throw new PlanningRejected('ไม่พบรายการที่จะลบ');
        }
        $undo = $kind === 'template'
            ? ['type' => 'restore', 'items' => $items->map(fn ($c) => ['name' => $c->name, 'remark' => (string) $c->description, 'sequence' => $c->sequence])->values()->all()]
            : ['type' => 'restore', 'ids' => $items->pluck('id')->all()];
        $items->each->delete();   // a cabinet's items: soft (restorable); a template's: gone (undo re-creates them)

        return ['message' => 'ลบ '.$items->count().' รายการ', 'undo' => $undo,
            'log' => 'ลบ Checklist '.$items->count().' รายการจาก "'.$owner->name.'": '.$items->pluck('name')->take(5)->implode(', ').($items->count() > 5 ? ' …' : '')];
    }

    private function restore(string $kind, Model $owner, array $op): array
    {
        if ($kind === 'template') {
            $rows = collect($op['items'] ?? [])->take(self::MAX_ITEMS);
            $rows->each(fn ($r) => ChecklistTemplate::create(['cabinet_subtask_template_id' => $owner->id, 'name' => mb_substr((string) ($r['name'] ?? ''), 0, 255) ?: '-',
                'description' => ($r['remark'] ?? '') ?: null, 'sequence' => (int) ($r['sequence'] ?? 0)]));
            $n = $rows->count();
        } else {
            $n = CabinetChecklist::onlyTrashed()->where('cabinet_subtask_id', $owner->id)->whereKey($this->ids($op['ids'] ?? []))->restore();
        }

        return ['message' => "นำกลับมา {$n} รายการ", 'log' => "นำ Checklist ที่ลบกลับมา {$n} รายการใน \"{$owner->name}\""];
    }

    private function reorder(string $kind, Model $owner, array $ids): array
    {
        $ids = $this->ids($ids);
        $own = $this->query($kind, $owner)->pluck('id')->all();
        if (array_diff($ids, $own) || count($ids) !== count(array_unique($ids))) {
            throw new PlanningRejected('ลำดับไม่ถูกต้อง — รีเฟรชหน้าแล้วลองใหม่');
        }
        // what was not in the list sent (e.g. added by someone else meanwhile) keeps its place after it
        $order = array_merge($ids, array_values(array_diff($own, $ids)));
        foreach ($order as $i => $id) {
            $this->query($kind, $owner)->whereKey($id)->update(['sequence' => $i + 1]);
        }

        return [];
    }

    private function move(string $kind, Model $owner, User $user, array $ids, mixed $to): array
    {
        $target = collect($this->targets($kind, $owner)['move'])->firstWhere('id', (int) $to) ? $this->owner($kind, (int) $to) : null;
        if (! $target) {
            throw new PlanningRejected('เลือก Sub Task ปลายทางในตู้ / เทมเพลตเดียวกัน');
        }
        $this->authorize($kind, $target, $user);
        $items = $this->query($kind, $owner)->whereKey($this->ids($ids))->orderBy('sequence')->get();
        $seq = (int) $this->query($kind, $target)->max('sequence');
        $fk = $kind === 'template' ? 'cabinet_subtask_template_id' : 'cabinet_subtask_id';
        $items->each(fn ($c) => $c->forceFill([$fk => $target->id, 'sequence' => ++$seq])->save());

        return ['message' => 'ย้าย '.$items->count().' รายการไป "'.$target->name.'"', 'others' => [$target->id],
            'log' => 'ย้าย Checklist '.$items->count().' รายการจาก "'.$owner->name.'" ไป "'.$target->name.'"'];
    }

    private function copy(string $kind, Model $owner, User $user, array $ids, array $to): array
    {
        $allowed = collect($this->targets($kind, $owner)['copyTo'])->pluck('id')->all();
        $targets = collect($this->ids($to))->filter(fn ($id) => in_array($id, $allowed, true))->map(fn ($id) => $this->owner($kind, $id))->filter();
        if ($targets->isEmpty()) {
            throw new PlanningRejected('เลือกปลายทางอย่างน้อย 1 รายการ');
        }
        $items = $this->query($kind, $owner)->whereKey($this->ids($ids))->orderBy('sequence')->get();
        $added = 0;
        $skipped = 0;
        foreach ($targets as $target) {
            $this->authorize($kind, $target, $user);
            $have = $this->query($kind, $target)->pluck('name')->map(fn ($n) => mb_strtolower(trim($n)))->all();
            $seq = (int) $this->query($kind, $target)->max('sequence');
            foreach ($items as $c) {
                if (in_array(mb_strtolower(trim($c->name)), $have, true)) {
                    $skipped++;

                    continue;   // already there
                }
                $this->create($kind, $target, ['name' => $c->name, 'remark' => $kind === 'template' ? (string) $c->description : '', 'date' => null], ++$seq);
                $added++;
            }
            if ($kind === 'subtask') {
                ActivityLog::record($target->cabinetTask->cabinet->project_id, $user->id, $target, 'checklist_edited', "คัดลอก Checklist {$items->count()} รายการมาจาก \"{$owner->name}\" ({$owner->cabinetTask->cabinet->mo_no})");
            }
        }

        return ['message' => "คัดลอกไป {$targets->count()} ที่ · เพิ่ม {$added} รายการ".($skipped ? " · ข้าม {$skipped} ที่มีอยู่แล้ว" : ''), 'others' => $targets->pluck('id')->all()];
    }

    /** Every item of another list (same cabinet / any template) added here; ones already here (same name) are skipped. */
    private function copyFrom(string $kind, Model $owner, mixed $from): array
    {
        $source = collect($this->targets($kind, $owner)['copyFrom'])->firstWhere('id', (int) $from) ? $this->owner($kind, (int) $from) : null;
        if (! $source) {
            throw new PlanningRejected('เลือกรายการต้นทาง');
        }
        $have = $this->query($kind, $owner)->pluck('name')->map(fn ($n) => mb_strtolower(trim($n)))->all();
        $seq = (int) $this->query($kind, $owner)->max('sequence');
        $new = $this->query($kind, $source)->orderBy('sequence')->orderBy('id')->get()
            ->reject(fn ($c) => in_array(mb_strtolower(trim($c->name)), $have, true))
            ->map(fn ($c) => $this->create($kind, $owner, ['name' => $c->name, 'remark' => $kind === 'template' ? (string) $c->description : '', 'date' => null], ++$seq));

        return ['message' => "เพิ่ม {$new->count()} รายการจาก \"{$source->name}\"", 'undo' => ['type' => 'delete', 'ids' => $new->pluck('id')->all()],
            'log' => "คัดลอก Checklist {$new->count()} รายการจาก \"{$source->name}\" มาที่ \"{$owner->name}\""];
    }

    // ======================================================================= helpers

    public function owner(string $kind, int $id): ?Model
    {
        return $kind === 'template' ? CabinetSubtaskTemplate::find($id) : CabinetSubtask::with('cabinetTask.cabinet')->find($id);
    }

    private function query(string $kind, Model $owner)
    {
        return $kind === 'template' ? ChecklistTemplate::where('cabinet_subtask_template_id', $owner->id) : CabinetChecklist::where('cabinet_subtask_id', $owner->id);
    }

    private function create(string $kind, Model $owner, array $r, int $seq): Model
    {
        return $kind === 'template'
            ? ChecklistTemplate::create(['cabinet_subtask_template_id' => $owner->id, 'name' => $r['name'], 'description' => $r['remark'] ?: null, 'sequence' => $seq])
            : CabinetChecklist::forceCreate(['cabinet_subtask_id' => $owner->id, 'name' => $r['name'], 'remark' => $r['remark'] ?: null,
                'expected_date' => $r['date'], 'is_completed' => false, 'sequence' => $seq]);
    }

    private function snapshot(string $kind, Model $item): array
    {
        return $kind === 'template'
            ? ['name' => $item->name, 'remark' => (string) $item->description]
            : ['name' => $item->name, 'remark' => (string) $item->remark, 'date' => $item->expected_date?->format('Y-m-d')];
    }

    /** A date can be given only where Settings > การส่งต่องาน asks this department for one. */
    private function checkDates(string $kind, Model $owner, bool $any): void
    {
        if ($any && ($kind === 'template' || ! app(HandoffService::class)->dateLabel($owner->department_id))) {
            throw new PlanningRejected('รายการของ Sub Task นี้ไม่มีช่องวันที่ (ตั้งค่า › การส่งต่องาน)');
        }
    }

    private function date(mixed $v): ?string
    {
        if ($v === null || $v === '') {
            return null;
        }
        $v = trim((string) $v);
        // dd/mm/yyyy (พ.ศ. or ค.ศ.) as pasted from Excel, or yyyy-mm-dd
        if (preg_match('#^(\d{1,2})/(\d{1,2})/(\d{4})$#', $v, $m)) {
            $y = (int) $m[3] > 2400 ? (int) $m[3] - 543 : (int) $m[3];

            return checkdate((int) $m[2], (int) $m[1], $y) ? sprintf('%04d-%02d-%02d', $y, $m[2], $m[1]) : throw new PlanningRejected("วันที่ไม่ถูกต้อง: {$v}");
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) && strtotime($v)) {
            return $v;
        }
        throw new PlanningRejected("วันที่ไม่ถูกต้อง: {$v} (ใช้ dd/mm/yyyy)");
    }

    /** @return list<int> */
    private function ids(array $ids): array
    {
        return collect($ids)->map(fn ($id) => (int) $id)->filter()->unique()->values()->take(self::MAX_ITEMS)->all();
    }

    /** The bars the cabinet page shows (same shape as the tick endpoint's answer). */
    public function progressOf(CabinetSubtask $s): array
    {
        $task = $s->cabinetTask->fresh('subtasks.checklists');
        $cabinet = $task->cabinet->fresh('tasks.subtasks.checklists');
        $row = fn ($m, array $c) => ['id' => $m->id, 'progress' => $m->progress, 'status' => $m->status, 'completed_checklists' => $c['completed'], 'total_checklists' => $c['total']];

        return [
            'subtask' => $row($s, ['completed' => $s->checklists()->where('is_completed', true)->count(), 'total' => $s->checklists()->count()]),
            'task' => $row($task, $task->checklist_counts),
            'cabinet' => $row($cabinet, $cabinet->checklist_counts),
        ];
    }
}
