<?php

namespace App\Services;

use App\Models\Cabinet;
use App\Models\CabinetSubtask;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The dashboard's "ภาพรวมผู้บริหาร" view and the printable CEO report (reports/ceo): what to know first (sentences
 * written from the data), the key numbers, every running project on one line, cabinets due per month, which
 * departments hold the late work, and the cabinets to follow. Read only; covers the projects this person may see.
 */
class ExecutiveReport
{
    /** How far (percentage points) production may trail the time used before a project reads "เฝ้าระวัง". */
    private const PACE_WATCH_POINTS = 10;

    /** Cabinets due within this many days are listed under "ตู้ที่ต้องติดตาม". */
    private const FOLLOW_DAYS = 30;

    private const FOLLOW_LIMIT = 8;

    private const HIGHLIGHT_LIMIT = 5;

    private const MONTHS = ['ม.ค.', 'ก.พ.', 'มี.ค.', 'เม.ย.', 'พ.ค.', 'มิ.ย.', 'ก.ค.', 'ส.ค.', 'ก.ย.', 'ต.ค.', 'พ.ย.', 'ธ.ค.'];

    /** @param ReportFilter|null $filter the CEO report's "เลือก…" (some SO / departments / a hand-over period); null = all */
    public function build(User $user, ?Carbon $now = null, ?ReportFilter $filter = null): array
    {
        $now ??= now();
        $today = $now->copy()->startOfDay();
        $filter ??= new ReportFilter;

        $visible = $filter->projects(Project::visibleTo($user)->where('status', '!=', Project::STATUS_CANCELLED));
        // open = not closed: a department's late work counts whatever stage its project is at (as on the department tab);
        // running = in production or paused: the projects listed and rated one by one (draft / planning have not started)
        $open = (clone $visible)->where('status', '!=', Project::STATUS_COMPLETED)
            ->with(['cabinets' => fn ($q) => $filter->cabinets($q), 'cabinets.tasks.subtasks.department', 'projectManager'])->withAvg('cabinets', 'progress')->get();
        $active = $open->whereIn('status', [Project::STATUS_IN_PROGRESS, Project::STATUS_ON_HOLD])->values();
        $colors = PlanningBoard::departmentColors();

        $isLate = fn (CabinetSubtask $s) => $s->isLateOn($today) && $filter->countsDepartment($s->department_id);
        $subtasksOf = fn ($cabinets) => $cabinets->flatMap->tasks->flatMap->subtasks;
        $cabinets = $active->flatMap->cabinets;
        $taskCabinet = $open->flatMap->cabinets->flatMap->tasks->pluck('cabinet_id', 'id');
        $cabinetOf = fn (CabinetSubtask $s) => $taskCabinet[$s->cabinet_task_id] ?? null;
        $late = $subtasksOf($open->flatMap->cabinets)->filter($isLate);

        $projects = $active->map(fn (Project $p) => $this->projectRow($p, $today, $subtasksOf($p->cabinets)->filter($isLate), $cabinetOf))
            ->sortBy(fn ($r) => [['late' => 0, 'watch' => 1, 'ok' => 2, 'close' => 3][$r['state']], $r['dueKey']])->values();

        // cabinets due per month, from last month to four months ahead (every visible project, closed ones too)
        $from = $today->copy()->startOfMonth()->subMonth();
        $to = $from->copy()->addMonths(6);
        $due = $filter->cabinets(Cabinet::whereIn('project_id', (clone $visible)->select('id')))->whereNotNull('due_date')
            ->where('due_date', '>=', $from)->where('due_date', '<', $to)->get(['id', 'status', 'due_date']);
        $months = collect(range(0, 5))->map(function ($i) use ($from, $due, $today) {
            $m = $from->copy()->addMonths($i);
            $list = $due->filter(fn ($c) => $c->due_date->isSameMonth($m));

            return ['label' => self::MONTHS[$m->month - 1], 'year' => ($m->year + 543) % 100, 'key' => $m->format('Y-m'), 'planned' => $list->count(),
                'done' => $list->where('status', Cabinet::STATUS_COMPLETED)->count(), 'current' => $m->isSameMonth($today)];
        })->all();
        $thisMonth = collect($months)->firstWhere('current', true);

        $departments = $late->groupBy('department_id')->map(fn ($list, $id) => [
            'id' => $id ? (int) $id : null,
            'name' => $list->first()->department?->name ?? 'ยังไม่จ่ายงาน',
            'color' => $colors[$id] ?? '#94a3b8',
            'late' => $list->count(),
            'cabinets' => $list->map($cabinetOf)->unique()->count(),
            'days' => (int) $list->max(fn ($s) => $s->due_date->diffInDays($today)),
        ])->sortByDesc('late')->values()->all();

        $follow = $cabinets->filter(fn (Cabinet $c) => $c->status !== Cabinet::STATUS_COMPLETED && $c->due_date && $c->due_date->lte($today->copy()->addDays(self::FOLLOW_DAYS)))
            ->sortBy('due_date')->take(self::FOLLOW_LIMIT)
            ->map(function (Cabinet $c) use ($active, $today, $subtasksOf, $isLate) {
                $days = (int) $today->diffInDays($c->due_date, false);
                $lateHere = $subtasksOf(collect([$c]))->filter($isLate)->count();

                return [
                    'mo' => $c->mo_no, 'name' => $c->cabinet_name, 'url' => route('cabinets.show', $c),
                    'project' => $active->firstWhere('id', $c->project_id)?->project_name,
                    'due' => $c->due_date->thaiDate(), 'progress' => (int) $c->progress,
                    'tone' => $days < 0 ? 'red' : ($lateHere ? 'orange' : 'slate'),
                    'why' => match (true) {
                        $days < 0 => 'ตู้เลยกำหนด '.abs($days).' วัน',
                        $lateHere > 0 => 'อีก '.$days.' วัน · งานย่อยเลยกำหนด '.$lateHere.' งาน',
                        default => 'อีก '.$days.' วัน',
                    },
                ];
            })->values()->all();

        $done7 = $this->subtasksCompletedSince($visible, $now->copy()->subDays(7));
        $done30 = $this->subtasksCompletedSince($visible, $now->copy()->subDays(30));

        // the dashboard's overview: every visible project in exactly one bucket (they add up to the total)
        $byStatus = (clone $visible)->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status');
        $delayed = (clone $visible)->runningLate()->count();
        $statusCounts = [
            'total' => (int) $byStatus->sum(),
            'inProgress' => (int) ($byStatus[Project::STATUS_IN_PROGRESS] ?? 0) - $delayed,
            'delayed' => $delayed,
            'completed' => (int) ($byStatus[Project::STATUS_COMPLETED] ?? 0),
            'onHold' => (int) ($byStatus[Project::STATUS_ON_HOLD] ?? 0),
            'notStarted' => (int) ($byStatus[Project::STATUS_DRAFT] ?? 0) + (int) ($byStatus[Project::STATUS_PLANNING] ?? 0),
        ];
        // open projects each department still has unfinished work in
        $deptProjects = $open->flatMap(fn (Project $p) => $subtasksOf($p->cabinets)
            ->filter(fn ($s) => $s->department_id && ! $s->isDone() && $filter->countsDepartment($s->department_id))
            ->unique('department_id')->map(fn ($s) => ['id' => $s->department_id, 'name' => $s->department?->name]))
            ->groupBy('id')->map(fn ($list, $id) => ['id' => (int) $id, 'name' => $list->first()['name'] ?? '-', 'color' => $colors[$id] ?? '#94a3b8', 'projects' => $list->count()])
            ->sortByDesc('projects')->values()->all();

        $canFinance = $user->allows('data.finance');
        $valued = $active->whereNotNull('contract_value');
        $lastClosed = (clone $visible)->where('status', Project::STATUS_COMPLETED)->withCount('cabinets')->latest('updated_at')->first();

        return [
            'asOf' => $now,
            'highlights' => $this->highlights($projects, $departments, $done7, $done30),
            'kpis' => [
                'projects' => $active->count(),
                'urgent' => $active->where('priority', Project::PRIORITY_URGENT)->count(),
                'notStarted' => (clone $visible)->whereIn('status', [Project::STATUS_DRAFT, Project::STATUS_PLANNING])->count(),
                'lateProjects' => $projects->where('state', 'late')->count(),
                'cabinetsOpen' => $cabinets->where('status', '!=', Cabinet::STATUS_COMPLETED)->count(),
                'cabinetsDone' => $cabinets->where('status', Cabinet::STATUS_COMPLETED)->count(),
                'cabinetsTotal' => $cabinets->count(),
                'monthPlanned' => $thisMonth['planned'] ?? 0,
                'monthDone' => $thisMonth['done'] ?? 0,
                'monthLabel' => $thisMonth['label'] ?? '',
                'monthOverdue' => $cabinets->filter(fn ($c) => $c->is_overdue && $c->due_date->isSameMonth($today))->count(),
                'lateSubtasks' => $late->count(),
                'lateCabinets' => $late->map($cabinetOf)->unique()->count(),
                'lateInProjects' => $projects->where('lateSubtasks', '>', 0)->count(),
                // null = this person may not see money (Settings > สิทธิ์การใช้งาน); 0 projects valued = nobody filled it in
                'value' => $canFinance ? ['sum' => (float) $valued->sum('contract_value'), 'projects' => $valued->count()] : null,
            ],
            'projects' => $projects->all(),
            'statusCounts' => $statusCounts,
            'deptProjects' => $deptProjects,
            'months' => $months,
            'departments' => $departments,
            'follow' => $follow,
            'lastClosed' => $lastClosed ? $lastClosed->project_name.' ('.$lastClosed->cabinets_count.' ตู้)' : null,
        ];
    }

    private function projectRow(Project $p, Carbon $today, Collection $late, callable $cabinetOf): array
    {
        $progress = $p->production_progress;
        $days = $p->due_date ? (int) $today->diffInDays($p->due_date, false) : null;
        $elapsed = $this->timeElapsed($p, $today);
        $lateCabinets = $late->map($cabinetOf)->unique()->count();
        $summary = $p->cabinet_summary;

        $state = match (true) {
            $summary['total'] > 0 && $progress >= 100 => 'close',
            $days !== null && $days < 0 => 'late',
            $late->isNotEmpty() || ($elapsed !== null && $elapsed - $progress > self::PACE_WATCH_POINTS) => 'watch',
            default => 'ok',
        };

        return [
            'id' => $p->id, 'url' => route('projects.show', $p),
            'name' => $p->project_name, 'no' => $p->project_no, 'customer' => $p->customer_name,
            'pm' => $p->projectManager?->name, 'status' => $p->status,
            'due' => $p->due_date?->thaiDate() ?? '-', 'dueKey' => $p->due_date?->format('Y-m-d') ?? '9999',
            'dueNote' => match (true) {
                $state === 'close' => 'ผลิตครบแล้ว',
                $days === null => '',
                $days < 0 => 'เลย '.abs($days).' วัน',
                $days === 0 => 'ครบกำหนดวันนี้',
                default => 'อีก '.$days.' วัน',
            },
            'days' => $days, 'progress' => $progress, 'timeElapsed' => $elapsed,
            'cabinets' => $summary['completed'].'/'.$summary['total'],
            'state' => $state, 'lateSubtasks' => $late->count(), 'lateCabinets' => $lateCabinets,
            'urgent' => $p->priority === Project::PRIORITY_URGENT,
            'issue' => match (true) {
                $state === 'close' => 'ผลิตครบแล้ว ยังไม่ได้ปิดโครงการในระบบ',
                $late->isNotEmpty() => 'งานย่อยเลยกำหนด '.$late->count().' งาน ใน '.$lateCabinets.' ตู้',
                $state === 'late' => 'ยังไม่เสร็จ '.($summary['total'] - $summary['completed']).' ตู้',
                $state === 'watch' => 'ผลิตช้ากว่าเวลา: ผลิต '.$progress.'% · เวลาผ่านไป '.$elapsed.'%',
                default => 'ไม่มีงานเลยกำหนด',
            },
            // the departments holding its late work, most first (for the summary sentence)
            'lateDepartments' => $late->groupBy(fn ($s) => $s->department?->name ?? 'ยังไม่จ่ายงาน')->map->count()->sortDesc()->keys()->take(2)->all(),
        ];
    }

    /** What to know first, in plain sentences: late projects, finished but still open, late work inside, and a quiet week. */
    private function highlights(Collection $projects, array $departments, int $done7, int $done30): array
    {
        $out = collect();
        foreach ($projects->where('state', 'late') as $p) {
            $out->push(['tone' => 'red', 'title' => $p['name'].' เลยกำหนดส่ง '.abs($p['days']).' วัน', 'text' => 'ผลิตแล้ว '.$p['progress'].'% · ตู้เสร็จ '.$p['cabinets']]);
        }
        foreach ($projects->where('state', '!=', 'late')->where('lateSubtasks', '>', 0)->sortByDesc('lateSubtasks') as $p) {
            $pace = $p['timeElapsed'] !== null ? ' (ผลิต '.$p['progress'].'% · เวลาผ่านไป '.$p['timeElapsed'].'%)' : '';
            $out->push(['tone' => 'orange', 'title' => $p['name'].$pace,
                'text' => 'มีงานย่อยเลยกำหนด '.$p['lateSubtasks'].' งานใน '.$p['lateCabinets'].' ตู้'.($p['lateDepartments'] ? ' คอขวดที่ '.implode(' และ ', $p['lateDepartments']) : '')]);
        }
        foreach ($projects->where('state', 'close') as $p) {
            $out->push(['tone' => 'blue', 'title' => $p['name'].' ผลิตครบ 100% แล้ว', 'text' => 'ยังไม่ได้ปิดโครงการในระบบ']);
        }
        if ($done7 === 0 && $done30 > 0) {
            $out->push(['tone' => 'gray', 'title' => '7 วันที่ผ่านมาไม่มีงานย่อยถูกปิดในระบบ', 'text' => '30 วันก่อนหน้าปิดไป '.$done30.' งาน ควรเช็กว่าแผนกอัปเดตงานหรือไม่']);
        }

        return $out->take(self::HIGHLIGHT_LIMIT)->values()->all();
    }

    /** % of the start → due span used by today (0-100), or null without both dates. */
    private function timeElapsed(Project $p, Carbon $today): ?int
    {
        if (! $p->start_date || ! $p->due_date || ! $p->due_date->gt($p->start_date)) {
            return null;
        }

        return (int) round(max(0, min(100, $p->start_date->diffInDays($today, false) / $p->start_date->diffInDays($p->due_date) * 100)));
    }

    private function subtasksCompletedSince($projects, Carbon $since): int
    {
        return CabinetSubtask::where('completed_at', '>=', $since)
            ->whereHas('cabinetTask.cabinet', fn ($q) => $q->whereIn('project_id', (clone $projects)->select('id')))->count();
    }
}
