<?php

namespace App\Services;

use App\Models\CabinetChecklist;
use App\Models\CabinetSubtask;
use App\Models\DepartmentLink;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Settings > การส่งต่องาน: work passed on from one department to the next (e.g. จัดซื้อ → คลัง → Production). The receiving
 * department sees the sending one's checklist read only - the same rows, never a copy - with the date the sender gave each
 * item (e.g. "วันของเข้า"), and hears when the sender sets / moves those dates and ticks items. Nothing is blocked: this only
 * lets people follow the work; the real stock / withdrawals stay in the ERP.
 *
 * Only for projects the two departments both have work on, and only departments - PMO roles already see everything.
 */
class HandoffService
{
    private ?Collection $links = null;

    public function links(): Collection
    {
        return $this->links ??= DepartmentLink::with('from:id,name', 'to:id,name')->orderBy('id')->get();
    }

    /** @return list<int> the departments whose checklist $deptId may read */
    public function sourcesFor(?int $deptId): array
    {
        return $deptId ? $this->links()->where('to_department_id', $deptId)->pluck('from_department_id')->unique()->values()->all() : [];
    }

    /** @return list<int> the departments that follow $deptId's work */
    public function targetsFor(?int $deptId): array
    {
        return $deptId ? $this->links()->where('from_department_id', $deptId)->pluck('to_department_id')->unique()->values()->all() : [];
    }

    /** The date a sending department fills in per item ("วันของเข้า"), or null when it has none. */
    public function dateLabel(?int $deptId): ?string
    {
        return $this->links()->where('from_department_id', $deptId)->pluck('date_label')->filter()->first();
    }

    private static function norm(string $s): string
    {
        return mb_strtolower(preg_replace('/\s+/u', ' ', trim($s)));
    }

    /**
     * Has the receiving side dealt with this sender's item: the receiver ticked an item of the same name in the same cabinet,
     * or ticked everything it has there.
     *
     * @param  Collection<int, CabinetChecklist>  $receiverItems  the receiving department's items in the same cabinet
     */
    public static function received(CabinetChecklist $item, Collection $receiverItems): bool
    {
        if ($receiverItems->isEmpty()) {
            return false;
        }
        $same = $receiverItems->filter(fn ($r) => self::norm($r->name) === self::norm($item->name));

        return $same->isNotEmpty() ? $same->contains('is_completed', true) : $receiverItems->every('is_completed');
    }

    /** Dispatched Sub Tasks of these departments in these cabinets, with their checklist. */
    private function rows(array $deptIds, Collection $cabinetIds): Collection
    {
        if (! $deptIds || $cabinetIds->isEmpty()) {
            return collect();
        }

        return CabinetSubtask::query()->whereIn('department_id', $deptIds)->whereIn('assignment_status', WorkScope::ACTIVE_STATUSES)
            ->whereHas('cabinetTask', fn ($q) => $q->whereIn('cabinet_id', $cabinetIds))
            ->with('cabinetTask:id,cabinet_id,name,sequence', 'checklists')->get();
    }

    /**
     * What a department member sees of the departments passing work to them, per cabinet (for the Planning board):
     * cabinet id => [ { deptId, items: [ {id, text, done, remark, expected, received} ], done, total } ].
     */
    public function linkedFor(User $user, Collection $cabinetIds): Collection
    {
        return $user->canSeeAllWork() ? collect() : $this->linkedForDepartment($user->department_id, $cabinetIds);
    }

    /** The same for a department (the cabinet page shows it under that department's Sub Task, whoever looks). */
    public function linkedForDepartment(?int $deptId, Collection $cabinetIds): Collection
    {
        $sources = $this->sourcesFor($deptId);
        if (! $sources) {
            return collect();
        }
        $names = $this->links()->where('to_department_id', $deptId)->mapWithKeys(fn ($l) => [$l->from_department_id => (string) $l->from?->name]);
        $rows = $this->rows(array_merge($sources, [$deptId]), $cabinetIds)->groupBy(fn ($s) => $s->cabinetTask->cabinet_id);

        return $rows->map(function (Collection $cab) use ($sources, $deptId, $names) {
            $mine = $cab->where('department_id', $deptId)->flatMap->checklists;

            return $cab->whereIn('department_id', $sources)->groupBy('department_id')->map(function (Collection $subs, $deptId) use ($mine, $names) {
                $items = $subs->sortBy(fn ($s) => sprintf('%05d-%05d-%09d', $s->cabinetTask->sequence ?? 0, $s->sequence ?? 0, $s->id))
                    ->flatMap(fn ($s) => $s->checklists->sortBy(fn ($c) => sprintf('%05d-%09d', $c->sequence ?? 0, $c->id)))
                    ->map(fn (CabinetChecklist $c) => [
                        'id' => 'k'.$c->id, 'text' => $c->name, 'done' => (bool) $c->is_completed, 'remark' => (string) $c->remark,
                        'expected' => $c->expected_date?->format('Y-m-d'), 'received' => self::received($c, $mine),
                    ])->values();

                return ['deptId' => PlanningBoard::deptKey((int) $deptId), 'deptName' => $names[(int) $deptId] ?? '', 'dateLabel' => $this->dateLabel((int) $deptId),
                    'items' => $items->all(), 'done' => $items->where('done', true)->count(), 'total' => $items->count()];
            })->values()->all();
        })->filter();
    }

    /**
     * The sending side's view: for each item of this Sub Task, whether each department that follows it with a date (e.g.
     * จัดซื้อ -> คลัง "วันของเข้า") has dealt with it yet - only departments that have work in the same cabinet.
     *
     * @return array<int, list<array{dept: string, received: bool}>> checklist id => receivers
     */
    public function receiptsFor(CabinetSubtask $subtask): array
    {
        $targets = $this->links()->where('from_department_id', $subtask->department_id)->whereNotNull('date_label');
        if ($targets->isEmpty()) {
            return [];
        }
        $cabinetId = $subtask->cabinetTask->cabinet_id;
        $receivers = $this->rows($targets->pluck('to_department_id')->all(), collect([$cabinetId]))->groupBy('department_id');
        $out = [];
        foreach ($subtask->checklists as $item) {
            foreach ($targets as $link) {
                if ($theirs = $receivers->get($link->to_department_id)) {
                    $out[$item->id][] = ['dept' => (string) $link->to?->name, 'received' => self::received($item, $theirs->flatMap->checklists)];
                }
            }
        }

        return $out;
    }

    // ---- notices to the receiving departments (one per project, folded into its project notice) ----

    /** Receiving departments' members who have work on this project (they are the ones who can open it), minus the actor. */
    private function receivers(int $fromDeptId, int $projectId, ?User $actor): Collection
    {
        $targets = $this->targetsFor($fromDeptId);
        if (! $targets) {
            return collect();
        }
        $withWork = CabinetSubtask::whereIn('department_id', $targets)->whereIn('assignment_status', WorkScope::ACTIVE_STATUSES)
            ->whereHas('cabinetTask.cabinet', fn ($q) => $q->where('project_id', $projectId))->distinct()->pluck('department_id');

        return User::whereIn('department_id', $withWork)->where('is_active', true)->when($actor, fn ($q) => $q->where('id', '!=', $actor->id))->get();
    }

    /** A sender set / moved the dates of some of its items. @param Collection<int, CabinetChecklist> $items (saved) */
    public function datesChanged(Collection $items, User $actor): int
    {
        $sent = 0;
        $items = (new \Illuminate\Database\Eloquent\Collection($items->all()))->loadMissing('subtask.cabinetTask.cabinet.project', 'subtask.department');
        foreach ($items->groupBy(fn ($i) => $i->subtask->department_id.'|'.$i->subtask->cabinetTask->cabinet->project_id) as $group) {
            $sub = $group->first()->subtask;
            $label = $this->dateLabel($sub->department_id);
            if (! $label) {
                continue;
            }
            $project = $sub->cabinetTask->cabinet->project;
            $dates = $group->pluck('expected_date')->filter();
            $cabs = $group->map(fn ($i) => $i->subtask->cabinetTask->cabinet->mo_no)->filter()->unique()->implode(', ');
            $sent += app(NotificationService::class)->handoff($this->receivers($sub->department_id, $project->id, $actor), $project,
                "{$sub->department->name} ลง{$label} · {$project->project_no}",
                $group->count().' รายการ'.($cabs ? " · {$cabs}" : '').($dates->isNotEmpty() ? ' · '.$dates->min()->thaiDate().($dates->min()->ne($dates->max()) ? ' – '.$dates->max()->thaiDate() : '') : ' · ล้างวันที่'),
                ["handoff_date|{$sub->department->name}|{$label}" => $group->count()]);
        }

        return $sent;
    }

    /** A sender ticked an item: the receivers hear it, and once more when the sender has finished everything in that cabinet. */
    public function ticked(CabinetChecklist $item, ?User $actor): int
    {
        $item->loadMissing('subtask.cabinetTask.cabinet.project', 'subtask.department');
        $sub = $item->subtask;
        if (! $sub->department_id || ! $this->targetsFor($sub->department_id)) {
            return 0;
        }
        $cabinet = $sub->cabinetTask->cabinet;
        $all = CabinetChecklist::whereHas('subtask', fn ($q) => $q->where('department_id', $sub->department_id)->whereIn('assignment_status', WorkScope::ACTIVE_STATUSES)
            ->whereHas('cabinetTask', fn ($t) => $t->where('cabinet_id', $cabinet->id)))->get(['id', 'is_completed']);
        $cabDone = $all->isNotEmpty() && $all->every('is_completed');
        $dept = $sub->department->name;

        return app(NotificationService::class)->handoff($this->receivers($sub->department_id, $cabinet->project_id, $actor), $cabinet->project,
            $cabDone ? "{$dept} ครบทั้งตู้ {$cabinet->mo_no} · {$cabinet->project->project_no}" : "{$dept} เสร็จ \"{$item->name}\" · {$cabinet->project->project_no}",
            $cabDone ? "{$cabinet->cabinet_name} · ".$all->count().' รายการครบแล้ว' : "{$cabinet->mo_no} · ".$all->where('is_completed', true)->count().'/'.$all->count(),
            array_filter(["handoff_done|{$dept}" => 1, "handoff_cab|{$dept}" => $cabDone ? 1 : null]));
    }

    /**
     * The daily round: items whose date is today or past and that the receiving side has not dealt with yet. Receivers get
     * "today" and "late"; the sender gets "late" (to chase it). One notice per department per project per day.
     */
    public function reminders(NotificationService $notices, Carbon $today): int
    {
        $sources = $this->links()->whereNotNull('date_label')->pluck('from_department_id')->unique()->values()->all();
        if (! $sources) {
            return 0;
        }
        $items = CabinetChecklist::query()->whereNotNull('expected_date')->whereDate('expected_date', '<=', $today)
            ->whereHas('subtask', fn ($q) => $q->whereIn('department_id', $sources)->whereIn('assignment_status', WorkScope::ACTIVE_STATUSES))
            ->with('subtask.cabinetTask.cabinet.project', 'subtask.department')->get()
            ->filter(fn ($i) => ($p = $i->subtask->cabinetTask?->cabinet?->project) && ! $p->isLocked());
        if ($items->isEmpty()) {
            return 0;
        }
        $receiverItems = $this->rows($this->links()->pluck('to_department_id')->unique()->values()->all(), $items->map(fn ($i) => $i->subtask->cabinetTask->cabinet_id)->unique()->values())
            ->groupBy(fn ($s) => $s->department_id.'|'.$s->cabinetTask->cabinet_id)->map(fn ($subs) => $subs->flatMap->checklists);

        $sent = 0;
        foreach ($items->groupBy(fn ($i) => $i->subtask->department_id.'|'.$i->subtask->cabinetTask->cabinet->project_id) as $group) {
            $sub = $group->first()->subtask;
            $project = $sub->cabinetTask->cabinet->project;
            $label = $this->dateLabel($sub->department_id) ?? 'วันที่';
            $open = fn (int $to) => $group->reject(fn ($i) => self::received($i, $receiverItems->get($to.'|'.$i->subtask->cabinetTask->cabinet_id, collect())));
            $lateForSender = 0;

            foreach ($this->receivers($sub->department_id, $project->id, null)->groupBy('department_id') as $to => $people) {
                $pending = $open((int) $to);
                $late = $pending->filter(fn ($i) => $i->expected_date->lt($today))->count();
                $now = $pending->count() - $late;
                $lateForSender = max($lateForSender, $late);
                if (! $pending->count()) {
                    continue;
                }
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
