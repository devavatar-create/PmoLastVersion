<?php

namespace App\Services;

use App\Models\Cabinet;
use App\Models\CabinetSubtask;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * What the project page's "ข้อมูลโครงการ" tab shows: progress and cabinet counts, the alert icons (each with its list),
 * the cabinet / MO table, and the steps the "จ่ายงานทั้งขั้น" dialog offers. Read only - every number comes from the
 * models' own logic (cabinet status, Cabinet::is_overdue, sub task due dates and assignment states).
 * Expects the project with cabinets.tasks.subtasks.{department,owner} and cabinets.cabinetTemplate.
 */
class ProjectOverview
{
    /** How many entries an alert's popover lists before "ดูทั้งหมด". */
    private const ALERT_LIMIT = 8;

    /** How far (percentage points) production may trail the time used before the pace reads "ช้ากว่าเวลา" instead of "เฝ้าระวัง". */
    public const PACE_WATCH_POINTS = 10;

    /** @var array<int, string> department id => colour, for this build */
    private array $colors = [];

    public function build(Project $project, User $user): array
    {
        $today = now()->startOfDay();
        $cabinets = $project->cabinets;
        $this->colors = PlanningBoard::departmentColors();   // the same department dots as Planning
        $late = fn (CabinetSubtask $s) => $s->isLateOn($today);

        // the alerts list sub tasks: everyone in PMO sees all of them, a department member only their own department's
        $visible = $cabinets->flatMap->tasks->flatMap->subtasks
            ->when(! $user->canSeeAllWork(), fn ($c) => $c->where('department_id', $user->department_id));

        $rows = $cabinets->map(fn (Cabinet $c) => $this->row($c, $project, $late, $today));
        $prefix = $this->sharedPrefix($rows->pluck('mo'));

        return [
            'summary' => $this->summary($project, $cabinets, $today),
            'alerts' => $this->alerts($cabinets, $visible, $late, $today),
            // 'no': the cabinet's place in the project (by sequence), kept when the table is searched or filtered
            'rows' => $rows->values()->map(fn ($r, $i) => collect($r)->except(['steps'])->put('no', $i + 1)->put('site', $prefix === '' ? $r['mo'] : mb_substr($r['mo'], mb_strlen($prefix)))->all())->all(),
            'moPrefix' => trim($prefix),
            'types' => $cabinets->pluck('cabinet_type')->filter()->unique()->sort()->values()->all(),
            'groups' => $this->groups($rows),
            'cabinetCount' => $cabinets->count(),
            'canDispatch' => $user->canDispatchWork() && ! $project->isLocked(),
        ];
    }

    /** Progress and the cabinet counts: completed / in progress / not started are statuses, delayed is a separate risk count. */
    private function summary(Project $project, Collection $cabinets, Carbon $today): array
    {
        $counts = $project->cabinet_summary;   // the model's own count by status
        $progress = $project->production_progress;
        $elapsed = $this->timeElapsed($project, $today);

        return [
            'progress' => $progress,
            // production against the share of the project's time already used (start → due); null when there is nothing to compare
            'timeElapsed' => $elapsed,
            'pace' => $elapsed === null ? null : match (true) {
                $progress >= $elapsed => 'on_track',
                $elapsed - $progress <= self::PACE_WATCH_POINTS => 'watch',
                default => 'behind',
            },
            'total' => $counts['total'],
            'completed' => $counts['completed'],
            'inProgress' => $counts['in_progress'],
            'notStarted' => $counts['not_started'],
            'onHold' => $counts['on_hold'],
            'delayed' => $cabinets->filter(fn (Cabinet $c) => $c->is_overdue)->count(),   // Cabinet::is_overdue: due date passed, not completed
            // Mon-Fri from today to the due date (WorkingDaysCalculator - no holiday calendar yet)
            'workingDaysLeft' => $project->due_date && ! $project->isLocked()
                ? ($project->due_date->lt($today) ? 0 : app(WorkingDaysCalculator::class)->countWorkingDays($today, $project->due_date))
                : null,
        ];
    }

    /** % of the start → due span used by today (0-100), or null for a closed project or one without both dates. */
    private function timeElapsed(Project $project, Carbon $today): ?int
    {
        if ($project->isLocked() || ! $project->start_date || ! $project->due_date || ! $project->due_date->gt($project->start_date)) {
            return null;
        }

        $span = $project->start_date->diffInDays($project->due_date);
        $used = $project->start_date->diffInDays($today, false);

        return (int) round(max(0, min(100, $used / $span * 100)));
    }

    /**
     * The words every MO number starts with ("ถนนวิทยุ Modify "), so the table can show only what tells cabinets
     * apart. Cut at a space, and only when every cabinet keeps some text of its own.
     */
    private function sharedPrefix(Collection $mos): string
    {
        $mos = $mos->filter()->values();
        if ($mos->count() < 2) {
            return '';
        }

        $prefix = $mos->reduce(function (?string $carry, string $mo) {
            if ($carry === null) {
                return $mo;
            }
            $n = 0;
            $max = min(mb_strlen($carry), mb_strlen($mo));
            while ($n < $max && mb_substr($carry, $n, 1) === mb_substr($mo, $n, 1)) {
                $n++;
            }

            return mb_substr($carry, 0, $n);
        });

        $space = mb_strrpos($prefix, ' ');
        $prefix = $space === false ? '' : mb_substr($prefix, 0, $space + 1);

        return $mos->every(fn ($mo) => trim(mb_substr($mo, mb_strlen($prefix))) !== '') ? $prefix : '';
    }

    /**
     * The alert icons, each with what its popover lists (most urgent first) and where an entry leads. Every icon is
     * worked out from data the system keeps; there is no "waiting for material / drawing" state to count.
     */
    private function alerts(Collection $cabinets, Collection $subtasks, callable $late, Carbon $today): array
    {
        $subtaskItem = fn (CabinetSubtask $s, string $note) => [
            'label' => $s->name, 'sub' => $s->cabinetTask->cabinet->mo_no, 'note' => $note,
            'url' => route('cabinets.show', $s->cabinetTask->cabinet_id).'#subtask-'.$s->id,
            'color' => $s->department_id ? ($this->colors[$s->department_id] ?? '#94a3b8') : null,
        ];

        $delayed = $cabinets->filter(fn (Cabinet $c) => $c->is_overdue)->sortBy(fn (Cabinet $c) => $c->due_date)->values();
        $lateTasks = $subtasks->filter($late)->sortBy(fn ($s) => $s->due_date)->values();
        $soon = $subtasks->filter(fn ($s) => $s->due_date && ! $s->isDone()
            && $s->due_date->betweenIncluded($today, $today->copy()->addDays(StatusReport::SOON_DAYS - 1)))
            ->sortBy(fn ($s) => $s->due_date)->values();
        $waiting = $subtasks->where('assignment_status', CabinetSubtask::ASSIGNMENT_ASSIGNED)->filter(fn ($s) => $s->department_id);
        $unassigned = $subtasks->where('assignment_status', CabinetSubtask::ASSIGNMENT_UNASSIGNED)->values();

        return [
            [
                'key' => 'delayed', 'tone' => 'red', 'icon' => 'cabinet', 'title' => 'ตู้ล่าช้ากว่ากำหนด', 'count' => $delayed->count(),
                'filter' => 'delayed',   // "ดูทั้งหมด" = the table's ล่าช้า filter
                'items' => $delayed->take(self::ALERT_LIMIT)->map(fn (Cabinet $c) => [
                    'label' => $c->mo_no, 'sub' => $c->cabinet_name, 'note' => 'ล่าช้า '.(int) $c->due_date->diffInDays($today).' วัน',
                    'url' => route('cabinets.show', $c), 'color' => null,
                ])->all(),
            ],
            [
                'key' => 'late', 'tone' => 'red', 'icon' => 'task', 'title' => 'งานย่อยเลยกำหนด', 'count' => $lateTasks->count(),
                'filter' => 'late-tasks',
                'items' => $lateTasks->take(self::ALERT_LIMIT)->map(fn ($s) => $subtaskItem($s, 'ช้า '.(int) $s->due_date->diffInDays($today).' วัน'))->all(),
            ],
            [
                'key' => 'soon', 'tone' => 'orange', 'icon' => 'clock', 'title' => 'ใกล้ครบกำหนด (ภายใน '.StatusReport::SOON_DAYS.' วัน)', 'count' => $soon->count(),
                'filter' => null,
                'items' => $soon->take(self::ALERT_LIMIT)->map(fn ($s) => $subtaskItem($s, $s->due_date->isSameDay($today) ? 'วันนี้' : 'อีก '.(int) $today->diffInDays($s->due_date).' วัน'))->all(),
            ],
            [
                'key' => 'waiting', 'tone' => 'blue', 'icon' => 'inbox', 'title' => 'รอแผนกรับงาน', 'count' => $waiting->count(),
                'filter' => null,
                // one line per department, with "ทวงถาม" for Admin / PM
                'departments' => $waiting->groupBy('department_id')->map(fn ($list, $dept) => [
                    'name' => $list->first()->department?->name ?? '-', 'color' => $this->colors[$dept] ?? '#94a3b8', 'count' => $list->count(),
                    'days' => (int) $list->max(fn ($s) => $s->assigned_at ? (int) $s->assigned_at->diffInDays(now()) : 0),
                    'ids' => $list->pluck('id')->values()->all(),
                ])->sortByDesc('days')->values()->all(),
                'items' => [],
            ],
            [
                'key' => 'unassigned', 'tone' => 'gray', 'icon' => 'dot', 'title' => 'ยังไม่จ่ายงาน', 'count' => $unassigned->count(),
                'filter' => null,
                'items' => $unassigned->take(self::ALERT_LIMIT)->map(fn ($s) => $subtaskItem($s, ''))->all(),
            ],
        ];
    }

    /** One cabinet as the table shows it, plus its steps for the "จ่ายงานทั้งขั้น" dialog. */
    private function row(Cabinet $c, Project $project, callable $late, Carbon $today): array
    {
        $steps = $c->tasks->values()->map(function ($task, $i) use ($late) {
            $first = $task->subtasks->first(fn ($s) => $s->department_id);
            $open = $task->subtasks->first(fn ($s) => ! $s->isDone());

            return [
                'no' => $i + 1,
                'label' => preg_match('/^\d+[.)]\s/u', $task->name) ? $task->name : ($i + 1).'. '.$task->name,   // templates often number their steps already
                'pct' => (int) $task->progress,
                'color' => $first ? ($this->colors[$first->department_id] ?? '#94a3b8') : '#cbd5e1',
                // who is on it: the first unfinished sub task's owner, else its department
                'responsible' => $open ? ($open->owner?->name ?? $open->department?->name ?? '-') : '-',
                'department' => ($open ?? $first)?->department?->name,
                'subtasks' => $task->subtasks->map(fn ($s) => ['id' => $s->id, 'name' => $s->name, 'department' => $s->department?->name, 'locked' => $s->is_department_locked])->all(),
            ];
        });

        $subtasks = $c->tasks->flatMap->subtasks;
        $lateCount = $subtasks->filter($late)->count();
        $current = $steps->first(fn ($s) => $s['pct'] < 100);
        $days = $c->due_date ? (int) $today->diffInDays($c->due_date, false) : null;
        $afterProject = $c->due_date && $project->due_date && $c->due_date->gt($project->due_date);

        // risk: high when the cabinet is late or will finish after the project; watch when work in it is late or time is short
        $risk = match (true) {
            $c->status === Cabinet::STATUS_COMPLETED => 'none',
            $c->is_overdue || $afterProject => 'high',
            $lateCount > 0 || ($days !== null && $days <= 7 && $c->progress < 50) => 'watch',
            default => 'ok',
        };

        return [
            'id' => $c->id, 'url' => route('cabinets.show', $c),
            'mo' => $c->mo_no, 'model' => $c->cabinet_name, 'type' => $c->cabinet_type,
            'progress' => (int) $c->progress,
            'current' => $current ? $current['label'] : 'ครบทุกขั้นตอน',
            'currentColor' => $current['color'] ?? '#cbd5e1',
            'currentDept' => $current['department'] ?? null,
            'responsible' => $current['responsible'] ?? '-',
            'due' => $c->due_date?->thaiDate(), 'dueKey' => $c->due_date?->format('Y-m-d') ?? '',
            'daysLeft' => $c->status === Cabinet::STATUS_COMPLETED ? null : $days,
            'status' => $c->status, 'delayed' => $c->is_overdue,
            'risk' => $risk,
            'pending' => $subtasks->reject->isDone()->count(),
            'lateTasks' => $lateCount,
            'steps' => $steps->all(),
            'signature' => $steps->pluck('label')->implode("\n"),
            'templateName' => $c->cabinetTemplate?->name,
        ];
    }

    /** The steps "จ่ายงานทั้งขั้น" offers: cabinets whose steps line up (the same template) form one group. */
    private function groups(Collection $rows): array
    {
        return $rows->groupBy('signature')->map(function (Collection $list) {
            $first = $list->first();

            return [
                'templateName' => $first['templateName'],
                'count' => $list->count(),
                'steps' => collect($first['steps'])->map(fn ($s, $i) => ['no' => $s['no'], 'label' => $s['label'], 'parts' => $this->stepParts($list, $i)])->all(),
            ];
        })->values()->all();
    }

    /**
     * One step across every cabinet of a template, split by sub task name ("2.1 รื้อตู้ Housing", "2.2 รื้อตู้ RMU").
     * Ids of work a department already accepted are left out (it cannot move).
     */
    private function stepParts(Collection $rows, int $index): array
    {
        return $rows->flatMap(fn ($r) => $r['steps'][$index]['subtasks'] ?? [])
            ->groupBy('name')
            ->map(fn ($list, $name) => [
                'name' => $name,
                'ids' => $list->where('locked', false)->pluck('id')->values()->all(),
                'locked' => $list->where('locked', true)->count(),
                'departments' => $list->pluck('department')->map(fn ($d) => $d ?? 'ยังไม่จ่าย')->countBy()->map(fn ($n, $d) => "{$d} ({$n})")->implode(', '),
            ])->values()->all();
    }
}
