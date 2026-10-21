<?php

namespace App\Services;

use App\Models\Cabinet;
use App\Models\CabinetSubtask;
use App\Models\CabinetTask;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * "รายงานการผลิต" (reports/production): the open production orders answered as six questions - how many SO / MO /
 * cabinets, their value, which step and department holds them, which are late or at risk, which are produced but not
 * yet taken by the customer, and what is due in 7 / 15 / 30 days. Read only; the projects this person may see.
 *
 * A cabinet's step is its first Task (by sequence) with work not done. "Produced, waiting for the customer" = every
 * Task done but the template's last one (e.g. "ส่งมอบ / ติดตั้ง Onsite"). Done follows CabinetSubtask::isDone().
 * A cabinet reads, worst first: ล่าช้า (past its due date) · ต้องเร่ง (due within 7 days, under 80% made) · เสี่ยง
 * (work inside it past its own due date, or made slower than the time used, as the dashboard's "เฝ้าระวัง") · ทัน.
 */
class ProductionReport
{
    /** Production may trail the time used by this many points before a cabinet reads "เสี่ยง" (as ExecutiveReport). */
    private const PACE_POINTS = 10;

    /** Due within this many days and produced below RUSH_PROGRESS % = "ต้องเร่ง" (as Project::risk_level at_risk). */
    private const RUSH_DAYS = 7;

    private const RUSH_PROGRESS = 80;

    /** Waiting this many days for the customer reads red. */
    public const WAIT_RED_DAYS = 7;

    private const DAYS = ['อา.', 'จ.', 'อ.', 'พ.', 'พฤ.', 'ศ.', 'ส.'];

    private ReportFilter $filter;

    private const MONTHS = ['ม.ค.', 'ก.พ.', 'มี.ค.', 'เม.ย.', 'พ.ค.', 'มิ.ย.', 'ก.ค.', 'ส.ค.', 'ก.ย.', 'ต.ค.', 'พ.ย.', 'ธ.ค.'];

    /**
     * @param list<int> $projectIds only these projects (the "เลือกโครงการ" filter); empty = all
     * @param ReportFilter|null $filter the CEO report's fuller "เลือก…" (SO / departments / period); wins over $projectIds
     */
    public function build(User $user, ?Carbon $now = null, array $projectIds = [], ?ReportFilter $filter = null): array
    {
        $today = ($now ?? now())->copy()->startOfDay();
        $this->filter = $filter ?? new ReportFilter($projectIds);
        $openQuery = Project::visibleTo($user)->whereNotIn('status', [Project::STATUS_COMPLETED, Project::STATUS_CANCELLED]);
        $options = (clone $openQuery)->orderBy('project_no')->get(['id', 'project_no', 'project_name', 'customer_name'])
            ->map(fn ($p) => ['id' => $p->id, 'no' => (string) $p->project_no, 'name' => (string) ($p->customer_name ?: $p->project_name)])->all();
        $projects = $this->filter->projects($openQuery)
            ->with(['cabinets' => fn ($q) => $this->filter->cabinets($q)->orderBy('due_date'), 'cabinets.tasks' => fn ($q) => $q->orderBy('sequence')->orderBy('id'),
                'cabinets.tasks.subtasks.department:id,name'])
            ->get();

        $rows = $projects->flatMap(fn (Project $p) => $p->cabinets->map(fn (Cabinet $c) => $this->cabinetRow($p, $c, $today)))->values();
        $open = $rows->where('done', false);

        $late = $open->where('state', 'late')->sortBy('days')->values();
        $rush = $open->where('state', 'rush')->sortBy('days')->values();
        $risk = $open->where('state', 'risk')->sortByDesc('gap')->values();
        $ready = $rows->where('ready', true)->sortByDesc('waitDays')->values();
        $due = $open->filter(fn ($r) => $r['days'] !== null && $r['days'] >= 0 && $r['days'] <= 30)->sortBy('days')->values();

        $valued = $projects->filter(fn (Project $p) => (float) $p->contract_value > 0);
        $value = (float) $valued->sum('contract_value');
        $produced = $valued->sum(fn (Project $p) => (float) $p->contract_value * $this->progressOf($p) / 100);

        $stages = $this->stages($open);
        $departments = $this->departments($projects, $today);
        $topDept = collect($departments)->sortByDesc(fn ($d) => $d['wait'] + $d['doing'] + $d['late'])->first();

        $byProject = $this->projectRows($projects, $rows);

        return [
            'asOf' => now(),
            'projectOptions' => $options,
            'selected' => array_values(array_intersect(array_column($options, 'id'), $this->filter->projects)),
            'projects' => $byProject,
            'projectCount' => [
                'late' => collect($byProject)->where('state', 'late')->count(),
                'watch' => collect($byProject)->whereIn('state', ['rush', 'risk'])->count(),
                'rush' => collect($byProject)->where('state', 'rush')->count(),
                'risk' => collect($byProject)->where('state', 'risk')->count(),
                'ready' => collect($byProject)->where('ready', '>', 0)->count(),
                'due' => collect([7, 15, 30])->mapWithKeys(fn ($n) => [$n => collect($byProject)->filter(fn ($p) => $p['nextDays'] !== null && $p['nextDays'] <= $n)->count()])->all(),
            ],
            'so' => $projects->count(),
            'mo' => $rows->count(),
            'cabinets' => (int) $rows->sum('qty'),
            'avgProgress' => (int) round($open->avg('progress') ?? 0),   // the cabinets still open
            'value' => $value,
            'produced' => $produced,
            'producedPct' => $value > 0 ? (int) round($produced / $value * 100) : null,
            'valueMissing' => $projects->count() - $valued->count(),
            'late' => $late->all(), 'rush' => $rush->all(), 'risk' => $risk->all(),
            'ready' => $ready->all(),
            'readyMaxWait' => (int) $ready->max('waitDays'),
            'due' => $due->all(),
            'dueCount' => collect([7, 15, 30])->mapWithKeys(fn ($n) => [$n => $due->where('days', '<=', $n)->count()])->all(),
            'weeks' => $this->weeks($due, $today),
            'stages' => $stages,
            'topStage' => collect($stages)->reject(fn ($s) => $s['last'])->sortByDesc('count')->first(),
            'departments' => $departments,
            'topDept' => $topDept,
        ];
    }

    /** One cabinet: where it is, how it is doing against its dates, and whether it waits for the customer. */
    private function cabinetRow(Project $p, Cabinet $c, Carbon $today): array
    {
        $tasks = $c->tasks->filter(fn (CabinetTask $t) => $t->subtasks->isNotEmpty())->values();
        $taskDone = fn (CabinetTask $t) => $t->subtasks->every(fn (CabinetSubtask $s) => $s->isDone());
        $current = $tasks->first(fn ($t) => ! $taskDone($t));
        $done = $c->status === Cabinet::STATUS_COMPLETED || ($tasks->isNotEmpty() && ! $current);

        // produced: every Task done but the last, which is not (the hand-over to the customer)
        $last = $tasks->last();
        $ready = ! $done && $tasks->count() > 1 && $current && $current->is($last);
        $producedAt = $ready ? $tasks->slice(0, -1)->flatMap->subtasks->max('completed_at') : null;

        $due = $c->due_date ?? $p->due_date;
        $start = $c->start_date ?? $p->start_date;
        $days = $due ? (int) $today->diffInDays($due, false) : null;
        $progress = (int) round((float) $c->progress);
        $elapsed = ($start && $due && $due->gt($start)) ? (int) round(max(0, min(100, $start->diffInDays($today, false) / $start->diffInDays($due) * 100))) : null;
        $lateWork = $done ? collect() : $tasks->flatMap->subtasks->filter(fn (CabinetSubtask $s) => $s->isLateOn($today) && $this->filter->countsDepartment($s->department_id));
        $slow = $elapsed !== null && $elapsed - $progress > self::PACE_POINTS;
        $state = match (true) {
            $done => 'done',
            $ready => 'ready',
            $days !== null && $days < 0 => 'late',
            $days !== null && $days <= self::RUSH_DAYS && $progress < self::RUSH_PROGRESS => 'rush',
            $lateWork->isNotEmpty() || $slow => 'risk',
            default => 'ok',
        };
        // who to chase: the departments holding its late work, else the ones on its current step
        $holding = $lateWork->isNotEmpty() ? $lateWork : ($current ? $current->subtasks->reject(fn ($s) => $s->isDone()) : collect());
        $holders = $holding->map(fn ($s) => $s->department?->name ?? 'ยังไม่จ่ายแผนก')->countBy()->sortDesc()->keys()->values()->all();

        return [
            'id' => $c->id, 'mo' => (string) $c->mo_no, 'name' => (string) $c->cabinet_name, 'qty' => max(1, (int) $c->quantity),
            'projectId' => $p->id, 'so' => (string) $p->project_no, 'customer' => (string) ($p->customer_name ?: $p->project_name),
            'url' => route('cabinets.show', $c),
            'done' => $done, 'ready' => $ready, 'state' => $state,
            'stage' => $current?->name ?? 'เสร็จทุกขั้นตอน',
            // where the step sits in its own template, 0 = first … 1 = last (templates have different numbers of steps)
            'stagePos' => $current ? $tasks->search(fn ($t) => $t->is($current)) / max(1, $tasks->count() - 1) : 2,
            'isLastStage' => $ready,
            'holders' => $holders,
            'progress' => $progress, 'elapsed' => $elapsed, 'gap' => $elapsed !== null ? $elapsed - $progress : 0,
            'due' => $due?->format('Y-m-d'), 'dueText' => $due ? $this->thaiDate($due, true) : '-', 'days' => $days,
            'producedText' => $producedAt ? $this->thaiDate(Carbon::parse($producedAt), false) : '-',
            'waitDays' => $producedAt ? (int) Carbon::parse($producedAt)->startOfDay()->diffInDays($today) : 0,
            'lateWork' => $lateWork->count(),
            // its late work per department, most first: "จัดซื้อ 4 · QC 3" (the CEO report's "เหตุผล · ตามที่")
            'lateByDept' => $lateWork->map(fn ($s) => $s->department?->name ?? 'ยังไม่จ่ายแผนก')->countBy()->sortDesc()->all(),
            // what happened, in a few words (progress has its own column there)
            'brief' => match (true) {
                $state === 'late' => 'เลยวันส่ง '.abs((int) $days).' วัน',
                $state === 'rush' => 'ต้องส่งใน '.$days.' วัน',
                $state === 'risk' && $lateWork->isEmpty() => 'ผลิตช้ากว่าเวลา (ใช้เวลาไป '.$elapsed.'%)',
                default => '',
            }.($lateWork->isNotEmpty() ? ($state === 'risk' ? '' : ' · ').'เลยกำหนด '.$lateWork->count().' งาน' : ''),
            'why' => match (true) {
                $state === 'late' => 'เลยวันส่งมา '.abs((int) $days).' วัน · ผลิต '.$progress.'%',
                $state === 'rush' => 'ต้องส่งใน '.$days.' วัน · ผลิตได้ '.$progress.'%',
                $state === 'risk' && $lateWork->isNotEmpty() => 'งานย่อยเลยกำหนด '.$lateWork->count().' งาน · ผลิต '.$progress.'%',
                $state === 'risk' => 'ผลิต '.$progress.'% แต่เวลาผ่านไป '.$elapsed.'%',
                default => '',
            },
        ];
    }

    /**
     * The same cabinets read per project (SO): its worst state, how many cabinets in each, who to chase, the next
     * hand-over due, and how its cabinets spread over the steps.
     */
    private function projectRows(Collection $projects, Collection $rows): array
    {
        $rank = ['late' => 0, 'rush' => 1, 'risk' => 2, 'ok' => 3, 'ready' => 4, 'done' => 5];

        return $projects->map(function (Project $p) use ($rows, $rank) {
            $mine = $rows->where('projectId', $p->id);
            $open = $mine->where('done', false);
            $producing = $open->where('ready', false);
            $next = $producing->filter(fn ($r) => $r['days'] !== null && $r['days'] >= 0)->sortBy('days')->first();
            $state = $producing->isEmpty() ? ($open->isEmpty() ? 'done' : 'ready') : $producing->sortBy(fn ($r) => $rank[$r['state']])->first()['state'];
            $count = fn ($st) => $open->where('state', $st)->count();
            $parts = array_filter(['ล่าช้า '.$count('late').' ตู้' => $count('late'), 'ต้องเร่ง '.$count('rush').' ตู้' => $count('rush'), 'เสี่ยง '.$count('risk').' ตู้' => $count('risk')]);

            return [
                'id' => $p->id, 'so' => (string) $p->project_no, 'name' => (string) $p->project_name, 'customer' => (string) ($p->customer_name ?: $p->project_name),
                'url' => route('projects.show', $p),
                'cabinets' => $mine->count(), 'done' => $mine->where('done', true)->count(), 'progress' => (int) round($this->progressOf($p)),
                'state' => $state, 'late' => $count('late'), 'rush' => $count('rush'), 'risk' => $count('risk'),
                'ready' => $open->where('ready', true)->count(), 'readyMaxWait' => (int) $open->where('ready', true)->max('waitDays'),
                'why' => $parts ? implode(' · ', array_keys($parts)).' จาก '.$mine->count().' ตู้' : '',
                'holders' => $open->whereIn('state', ['late', 'rush', 'risk'])->flatMap(fn ($r) => $r['holders'])->countBy()->sortDesc()->keys()->take(3)->all(),
                'due' => $p->due_date?->format('Y-m-d'), 'dueText' => $p->due_date ? $this->thaiDate($p->due_date, true) : '-',
                'nextDays' => $next['days'] ?? null, 'nextText' => $next['dueText'] ?? null,
                'nextCabinets' => $next ? $producing->where('due', $next['due'])->count() : 0,
                'dueCabinets' => collect([7, 15, 30])->mapWithKeys(fn ($n) => [$n => $producing->filter(fn ($r) => $r['days'] !== null && $r['days'] >= 0 && $r['days'] <= $n)->count()])->all(),
                'stages' => $open->groupBy(fn ($r) => $r['ready'] ? 'รอลูกค้ารับ' : $r['stage'])->map->count()->all(),
            ];
        })->sortBy(fn ($r) => [$rank[$r['state']], $r['nextDays'] ?? 9999])->values()->all();
    }

    private function progressOf(Project $p): float
    {
        return $p->cabinets->isEmpty() ? 0 : (float) $p->cabinets->avg('progress');
    }

    /** Cabinets per step (its current Task's name), in the order the steps come in the templates; "รอลูกค้ารับ" last. */
    private function stages(Collection $open): array
    {
        $producing = $open->where('ready', false);
        // a step named with a number ("3. จัดทำ Drawing") goes by it; then by where it sits in its template
        $out = $producing->groupBy('stage')->map(fn ($g, $name) => [
            'name' => $name, 'last' => false, 'order' => [round($g->avg('stagePos'), 2), preg_match('/^\s*(\d+)/', $name, $m) ? (int) $m[1] : 999],
            'count' => (int) $g->count(), 'late' => $g->where('state', 'late')->count(), 'watch' => $g->whereIn('state', ['rush', 'risk'])->count(),
            'cabinets' => $g->sortBy('days')->values()->all(),
        ])->sortBy('order')->values();
        $ready = $open->where('ready', true);
        if ($ready->isNotEmpty()) {
            $out->push(['name' => 'รอลูกค้ารับ', 'last' => true, 'order' => [9, 9], 'count' => $ready->count(), 'late' => 0,
                'watch' => $ready->where('waitDays', '>=', self::WAIT_RED_DAYS)->count(), 'cabinets' => $ready->sortByDesc('waitDays')->values()->all()]);
        }

        return $out->all();
    }

    /** Open work per department: late first, then started, then waiting to be accepted (each Sub Task counted once). */
    private function departments(Collection $projects, Carbon $today): array
    {
        $subtasks = $projects->flatMap->cabinets->flatMap->tasks->flatMap->subtasks
            ->filter(fn (CabinetSubtask $s) => $s->department_id && ! $s->isDone() && $this->filter->countsDepartment($s->department_id));

        return $subtasks->groupBy('department_id')->map(function ($g) use ($today) {
            $late = $g->filter(fn ($s) => $s->isLateOn($today));
            $rest = $g->diffKeys($late);
            $doing = $rest->whereIn('assignment_status', [CabinetSubtask::ASSIGNMENT_ACCEPTED, CabinetSubtask::ASSIGNMENT_IN_PROGRESS]);

            return ['id' => (int) $g->first()->department_id, 'name' => (string) $g->first()->department?->name,
                'late' => $late->count(), 'doing' => $doing->count(), 'wait' => $rest->count() - $doing->count()];
        })->sortBy(fn ($d) => [-$d['late'], -($d['doing'] + $d['wait']), $d['name']])->values()->all();
    }

    /** The next 30 days in calendar weeks (Monday to Sunday): this week, next week, the one after, the rest. */
    private function weeks(Collection $due, Carbon $today): array
    {
        $endOf = fn (Carbon $d) => $d->copy()->endOfWeek(Carbon::SUNDAY)->startOfDay();
        $w1 = $endOf($today);
        $bounds = [['สัปดาห์นี้', $today, $w1], ['สัปดาห์หน้า', $w1->copy()->addDay(), $w1->copy()->addDays(7)],
            ['อีก 2 สัปดาห์', $w1->copy()->addDays(8), $w1->copy()->addDays(14)], ['ถึง 30 วัน', $w1->copy()->addDays(15), $today->copy()->addDays(30)]];

        return collect($bounds)->filter(fn ($b) => $b[1]->lte($b[2]))->map(fn ($b) => [
            'label' => $b[0], 'range' => $this->thaiDate($b[1], false).' – '.$this->thaiDate($b[2], false),
            'items' => $due->filter(fn ($r) => $r['due'] >= $b[1]->format('Y-m-d') && $r['due'] <= $b[2]->format('Y-m-d'))->values()->all(),
        ])->values()->all();
    }

    private function thaiDate(Carbon $d, bool $weekday): string
    {
        return ($weekday ? self::DAYS[$d->dayOfWeek].' ' : '').$d->day.' '.self::MONTHS[$d->month - 1];
    }
}
