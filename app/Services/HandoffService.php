<?php

namespace App\Services;

use App\Models\CabinetChecklist;
use App\Models\CabinetSubtask;
use App\Models\Department;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Checklists compared side by side: a Sub Task paired with another in the same cabinet (set on the cabinet template,
 * SubtaskPairs) shows that one's checklist next to its own - read only, the same rows, never a copy - with the date its
 * department gives each item (Settings > แผนก, e.g. จัดซื้อ "วันของเข้า"), and its department hears when those dates are
 * set or moved and items ticked. Nothing is blocked: this only lets people follow the work; real stock stays in the ERP.
 */
class HandoffService
{
    private ?Collection $labels = null;

    /** @var array<int, Collection> cabinet id => its pairs [to => receiving sub task id, from => source sub task id] */
    private array $pairs = [];

    private ?array $targets = null;

    /** The date a department fills in per item for the ones comparing with it ("วันของเข้า"), or null when it has none. */
    public function dateLabel(?int $deptId): ?string
    {
        $this->labels ??= Department::whereNotNull('handoff_date_label')->where('handoff_date_label', '!=', '')->pluck('handoff_date_label', 'id');

        return $deptId ? ($this->labels[$deptId] ?? null) : null;
    }

    /** The date label a Sub Task's items carry: its department's, while another Sub Task compares with it. */
    public function dateLabelFor(CabinetSubtask $subtask): ?string
    {
        return $this->receiverIds($subtask)->isNotEmpty() ? $this->dateLabel($subtask->department_id) : null;
    }

    /** @return list<int> the departments whose Sub Tasks compare with $deptId's somewhere in a template (Planning) */
    public function targetsFor(?int $deptId): array
    {
        $this->targets ??= DB::table('cabinet_subtask_template_pairs as p')
            ->join('cabinet_subtask_templates as r', 'r.id', '=', 'p.cabinet_subtask_template_id')
            ->join('cabinet_subtask_templates as s', 's.id', '=', 'p.source_subtask_template_id')
            ->whereNotNull('r.department_id')->whereNotNull('s.department_id')->whereColumn('r.department_id', '!=', 's.department_id')
            ->distinct()->get(['s.department_id as from', 'r.department_id as to'])
            ->groupBy('from')->map(fn ($g) => $g->pluck('to')->map(fn ($id) => (int) $id)->unique()->values()->all())->all();

        return $deptId ? ($this->targets[$deptId] ?? []) : [];
    }

    private static function norm(string $s): string
    {
        return SubtaskPairs::norm($s);
    }

    /**
     * Has the receiving side dealt with this item: the receiver ticked its copy of it (SharedChecklist), else an item of the
     * same name, else everything it has.
     *
     * @param  Collection<int, CabinetChecklist>  $receiverItems  the receiving Sub Task's items
     */
    public static function received(CabinetChecklist $item, Collection $receiverItems): bool
    {
        if ($receiverItems->isEmpty()) {
            return false;
        }
        $copies = $receiverItems->where('source_checklist_id', $item->id);
        if ($copies->isNotEmpty()) {
            return $copies->contains('is_completed', true);
        }
        $same = $receiverItems->filter(fn ($r) => self::norm($r->name) === self::norm($item->name));

        return $same->isNotEmpty() ? $same->contains('is_completed', true) : $receiverItems->every('is_completed');
    }

    // ---- the pairs ----

    /** Load the pairs of these cabinets in one query (Planning has many). */
    public function preload(Collection $cabinetIds): void
    {
        $missing = $cabinetIds->map(fn ($id) => (int) $id)->reject(fn ($id) => isset($this->pairs[$id]))->values();
        if ($missing->isEmpty()) {
            return;
        }
        $rows = DB::table('cabinet_subtask_pairs as p')
            ->join('cabinet_subtasks as r', 'r.id', '=', 'p.cabinet_subtask_id')
            ->join('cabinet_tasks as t', 't.id', '=', 'r.cabinet_task_id')
            ->whereIn('t.cabinet_id', $missing)->whereNull('r.deleted_at')
            ->get(['t.cabinet_id as cabinet', 'p.cabinet_subtask_id as to', 'p.source_subtask_id as from'])
            ->groupBy('cabinet');
        foreach ($missing as $id) {
            $this->pairs[$id] = ($rows->get($id) ?? collect())->map(fn ($r) => ['to' => (int) $r->to, 'from' => (int) $r->from])->values();
        }
    }

    private function pairsIn(int $cabinetId): Collection
    {
        $this->preload(collect([$cabinetId]));

        return $this->pairs[$cabinetId];
    }

    private function sourceIds(CabinetSubtask $s): Collection
    {
        return $this->pairsIn((int) $s->cabinetTask->cabinet_id)->where('to', $s->id)->pluck('from');
    }

    private function receiverIds(CabinetSubtask $s): Collection
    {
        return $this->pairsIn((int) $s->cabinetTask->cabinet_id)->where('from', $s->id)->pluck('to');
    }

    /** The Sub Tasks with their checklists as they are now (not deleted). @return Collection<int, CabinetSubtask> */
    private function load(Collection $ids): Collection
    {
        return $ids->isEmpty() ? collect() : CabinetSubtask::whereIn('id', $ids->unique()->values())
            ->with('department:id,name', 'checklists', 'cabinetTask:id,cabinet_id,name,sequence')->get();
    }

    /** Pairs were added or changed (SubtaskPairs): read them again. */
    public function forgetPairs(): void
    {
        $this->pairs = [];
        $this->targets = null;
    }

    private static function order(CabinetSubtask $s): string
    {
        return sprintf('%05d-%05d-%09d', $s->cabinetTask->sequence ?? 0, $s->sequence ?? 0, $s->id);
    }

    // ---- what each side sees ----

    /**
     * Under a Sub Task: the lists it compares with, each with whether this Sub Task has dealt with each item.
     *
     * @return list<array{id: string, deptId: string, deptName: string, title: string, dateLabel: ?string, items: list<array>, done: int, total: int}>
     */
    public function linkedForSubtask(CabinetSubtask $receiver): array
    {
        $sources = $this->load($this->sourceIds($receiver));
        $mine = $receiver->checklists;

        return $sources->sortBy(fn ($s) => self::order($s))->map(function (CabinetSubtask $s) use ($mine) {
            $items = $s->checklists->sortBy(fn ($c) => sprintf('%05d-%09d', $c->sequence ?? 0, $c->id))->map(fn (CabinetChecklist $c) => [
                'id' => 'k'.$c->id, 'text' => $c->name, 'done' => (bool) $c->is_completed, 'remark' => (string) $c->remark,
                'expected' => $c->expected_date?->format('Y-m-d'), 'received' => self::received($c, $mine),
            ])->values();

            return ['id' => 's'.$s->id, 'deptId' => PlanningBoard::deptKey((int) $s->department_id), 'deptName' => (string) $s->department?->name,
                'title' => $s->name, 'dateLabel' => $this->dateLabel($s->department_id)] + $this->whereIs($s) + [
                'items' => $items->all(), 'done' => $items->where('done', true)->count(), 'total' => $items->count()];
        })->values()->all();
    }

    /** The other side of a pair at a glance: where its work is, when it is due, and a link to it (so a stalled one can be chased). */
    private function whereIs(CabinetSubtask $s): array
    {
        return [
            'state' => $s->isDone() ? 'เสร็จงานแล้ว' : (CabinetSubtask::$assignmentStatuses[$s->assignment_status] ?? ''),
            'late' => $s->isLateOn(today()),
            'due' => $s->due_date?->format('Y-m-d'),
            'href' => route('cabinets.show', $s->cabinetTask->cabinet_id).'#subtask-'.$s->id,
        ];
    }

    /**
     * The source side: the Sub Tasks comparing with this one.
     *
     * @return list<array{title: string, deptName: string, state: string, late: bool, due: ?string, href: string}>
     */
    public function receiversOf(CabinetSubtask $source): array
    {
        return $this->load($this->receiverIds($source))->sortBy(fn ($s) => self::order($s))
            ->map(fn (CabinetSubtask $s) => ['title' => $s->name, 'deptName' => (string) $s->department?->name] + $this->whereIs($s))->values()->all();
    }

    /**
     * Planning: per cabinet, the lists the user's department compares with (PMO roles already see every checklist):
     * cabinet id => [ { id, deptId, title, for, items: [ {id, text, done, remark, expected, received} ], done, total } ].
     */
    public function linkedFor(User $user, Collection $cabinetIds): Collection
    {
        if ($user->canSeeAllWork() || ! $user->department_id || $cabinetIds->isEmpty()) {
            return collect();
        }
        $this->preload($cabinetIds);
        $receiverIds = $cabinetIds->flatMap(fn ($id) => $this->pairs[(int) $id]->pluck('to'))->unique()->values();
        $mine = $this->load($receiverIds)->where('department_id', $user->department_id);

        return $mine->groupBy(fn ($s) => $s->cabinetTask->cabinet_id)->map(fn (Collection $subs) => $subs->sortBy(fn ($s) => self::order($s))
            ->flatMap(fn ($s) => array_map(fn ($l) => $l + ['for' => $s->name, 'forId' => 's'.$s->id], $this->linkedForSubtask($s)))
            ->unique(fn ($l) => $l['id'].'>'.$l['forId'])->values()->all());
    }

    /**
     * The source side: for each item of this Sub Task, whether each Sub Task comparing with it has dealt with it yet.
     *
     * @return array<int, list<array{dept: string, received: bool}>> checklist id => receivers
     */
    public function receiptsFor(CabinetSubtask $source): array
    {
        $receivers = $this->load($this->receiverIds($source));
        $out = [];
        foreach ($source->checklists as $item) {
            foreach ($receivers as $r) {
                $out[$item->id][] = ['dept' => (string) $r->department?->name, 'received' => self::received($item, $r->checklists)];
            }
        }

        return $out;
    }

    // ---- notices to the departments comparing (one per project, folded into its project notice) ----

    /** The members of the departments whose Sub Tasks compare with these, minus the actor. */
    private function people(Collection $receivers, ?User $actor): Collection
    {
        $depts = $receivers->pluck('department_id')->filter()->unique();

        return $depts->isEmpty() ? collect() : User::whereIn('department_id', $depts)->where('is_active', true)->when($actor, fn ($q) => $q->where('id', '!=', $actor->id))->get();
    }

    /** A source set / moved the dates of some of its items. @param Collection<int, CabinetChecklist> $items (saved) */
    public function datesChanged(Collection $items, User $actor): int
    {
        $sent = 0;
        $items = (new \Illuminate\Database\Eloquent\Collection($items->all()))->loadMissing('subtask.cabinetTask.cabinet.project', 'subtask.department');
        foreach ($items->groupBy(fn ($i) => $i->subtask->department_id.'|'.$i->subtask->cabinetTask->cabinet->project_id) as $group) {
            $sub = $group->first()->subtask;
            $label = $this->dateLabel($sub->department_id);
            $receivers = $group->pluck('subtask')->unique('id')->flatMap(fn ($s) => $this->load($this->receiverIds($s)));
            if (! $label || $receivers->isEmpty()) {
                continue;
            }
            $project = $sub->cabinetTask->cabinet->project;
            $dates = $group->pluck('expected_date')->filter();
            $cabs = $group->map(fn ($i) => $i->subtask->cabinetTask->cabinet->mo_no)->filter()->unique()->implode(', ');
            $sent += app(NotificationService::class)->handoff($this->people($receivers, $actor), $project,
                "{$sub->department->name} ลง{$label} · {$project->project_no}",
                $group->count().' รายการ'.($cabs ? " · {$cabs}" : '').($dates->isNotEmpty() ? ' · '.$dates->min()->thaiDate().($dates->min()->ne($dates->max()) ? ' – '.$dates->max()->thaiDate() : '') : ' · ล้างวันที่'),
                ["handoff_date|{$sub->department->name}|{$label}" => $group->count()]);
        }

        return $sent;
    }

    /** A source ticked an item: the departments comparing hear it, and once more when the source has finished its list. */
    public function ticked(CabinetChecklist $item, ?User $actor): int
    {
        $item->loadMissing('subtask.cabinetTask.cabinet.project', 'subtask.department');
        $sub = $item->subtask;
        $receivers = $this->load($this->receiverIds($sub));
        if (! $sub->department_id || $receivers->isEmpty()) {
            return 0;
        }
        $cabinet = $sub->cabinetTask->cabinet;
        $all = $sub->checklists()->get(['id', 'is_completed']);
        $done = $all->isNotEmpty() && $all->every('is_completed');
        $dept = $sub->department->name;

        return app(NotificationService::class)->handoff($this->people($receivers, $actor), $cabinet->project,
            $done ? "{$dept} ครบ \"{$sub->name}\" ตู้ {$cabinet->mo_no} · {$cabinet->project->project_no}" : "{$dept} เสร็จ \"{$item->name}\" · {$cabinet->project->project_no}",
            $done ? "{$cabinet->cabinet_name} · ".$all->count().' รายการครบแล้ว' : "{$cabinet->mo_no} · ".$all->where('is_completed', true)->count().'/'.$all->count(),
            array_filter(["handoff_done|{$dept}" => 1, "handoff_cab|{$dept}" => $done ? 1 : null]));
    }

    /**
     * The daily round: dated items due today or past that a Sub Task comparing with them has not dealt with yet. Its
     * department gets "today" and "late"; the source department gets "late" (to chase it). One notice per department per
     * project per day.
     */
    public function reminders(NotificationService $notices, Carbon $today): int
    {
        $items = CabinetChecklist::query()->whereNotNull('expected_date')->whereDate('expected_date', '<=', $today)
            ->whereIn('cabinet_subtask_id', DB::table('cabinet_subtask_pairs')->select('source_subtask_id'))
            ->whereHas('subtask', fn ($q) => $q->whereIn('assignment_status', WorkScope::ACTIVE_STATUSES))
            ->with('subtask.cabinetTask.cabinet.project', 'subtask.department')->get()
            ->filter(fn ($i) => ($p = $i->subtask->cabinetTask?->cabinet?->project) && ! $p->isLocked());
        if ($items->isEmpty()) {
            return 0;
        }
        $this->preload($items->map(fn ($i) => $i->subtask->cabinetTask->cabinet_id)->unique()->values());

        $sent = 0;
        foreach ($items->groupBy(fn ($i) => $i->subtask->department_id.'|'.$i->subtask->cabinetTask->cabinet->project_id) as $group) {
            $sub = $group->first()->subtask;
            $project = $sub->cabinetTask->cabinet->project;
            $label = $this->dateLabel($sub->department_id) ?? 'วันที่';
            // each item against the Sub Tasks comparing with its own one, grouped by their department
            $pending = [];
            foreach ($group as $item) {
                foreach ($this->load($this->receiverIds($item->subtask)) as $r) {
                    if ($r->department_id && ! self::received($item, $r->checklists)) {
                        $pending[$r->department_id][$item->id] = $item;
                    }
                }
            }
            $lateForSender = 0;
            foreach ($pending as $to => $list) {
                $list = collect($list);
                $late = $list->filter(fn ($i) => $i->expected_date->lt($today))->count();
                $now = $list->count() - $late;
                $lateForSender = max($lateForSender, $late);
                $people = User::where('department_id', $to)->where('is_active', true)->get();
                $sent += $notices->handoff($people, $project,
                    $late ? "📦 เลย{$label} {$late} รายการ · {$project->project_no}" : "📦 {$label}วันนี้ {$now} รายการ · {$project->project_no}",
                    "{$project->project_name} · จาก{$sub->department->name}".($late && $now ? " · วันนี้อีก {$now} รายการ" : ''),
                    array_filter(['handoff_today' => $now ? ['n' => $now, 'label' => $label] : null, 'handoff_late' => $late ? ['n' => $late, 'label' => $label] : null]),
                    "handoff:{$to}:{$sub->department_id}:{$project->id}:".$today->toDateString(), 'handoff_due');
            }

            if ($lateForSender) {
                $people = User::where('department_id', $sub->department_id)->where('is_active', true)->get();
                $sent += $notices->handoff($people, $project, "📦 เลย{$label} {$lateForSender} รายการ · {$project->project_no}", "{$project->project_name} · ปลายทางยังไม่ได้รับ",
                    ['handoff_late' => ['n' => $lateForSender, 'label' => $label]], "handoff-sender:{$sub->department_id}:{$project->id}:".$today->toDateString(), 'handoff_due');
            }
        }

        return $sent;
    }
}
