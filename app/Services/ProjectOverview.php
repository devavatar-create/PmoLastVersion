<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Cabinet;
use App\Models\CabinetSubtask;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * What the project page's "ภาพรวม" tab shows: whether delivery is at risk and why, every cabinet as one row with a
 * cell per step (grouped by template, then by delivery date), work held per department, what falls due this week
 * and the latest activity. Read only; the page renders it as is. Expects the project with cabinets.tasks.subtasks.department.
 */
class ProjectOverview
{
    /** Activity rows worth showing on the overview (no field-by-field edits, no checklist ticks). */
    private const FEED_ACTIONS = ['assigned', 'accepted', 'started', 'completed', 'note', 'files', 'reminded'];

    /** @var array<int, string> department id => colour, for this build */
    private array $colors = [];

    private const OPEN_ASSIGNMENTS = [CabinetSubtask::ASSIGNMENT_ASSIGNED, CabinetSubtask::ASSIGNMENT_ACCEPTED, CabinetSubtask::ASSIGNMENT_IN_PROGRESS];

    public function build(Project $project, User $user): array
    {
        $today = now()->startOfDay();
        $cabinets = $project->cabinets;
        $subtasks = $cabinets->flatMap->tasks->flatMap->subtasks;
        $late = fn (CabinetSubtask $s) => $s->due_date && $s->due_date->lt($today) && $s->status !== CabinetSubtask::STATUS_COMPLETED;
        $short = $this->shortNames($cabinets);
        $this->colors = PlanningBoard::departmentColors();   // the same department dots as Planning

        $rows = $cabinets->map(fn (Cabinet $c) => $this->row($c, $project, $short[$c->id] ?? $c->mo_no, $late, $today));

        return [
            'health' => $this->health($project, $rows, $subtasks->filter($late), $user),
            'timeline' => $this->timeline($project, $today),
            'groups' => $this->groups($rows),
            'cabinetCount' => $cabinets->count(),
            'canDispatch' => $user->canDispatchWork() && ! $project->isLocked(),
            'departments' => $this->departments($subtasks, $late, $user),
            'startedNotAccepted' => $subtasks->where('assignment_status', CabinetSubtask::ASSIGNMENT_ASSIGNED)->where('progress', '>', 0)->count(),
            'unassigned' => $subtasks->where('assignment_status', CabinetSubtask::ASSIGNMENT_UNASSIGNED)->count(),
            'upcoming' => $this->upcoming($subtasks, $today),
            'feed' => $user->allows('data.comments') ? $this->feed($project) : [],
        ];
    }

    /** One cabinet: a cell per step, where it is now, and the one status line that matters most. */
    private function row(Cabinet $c, Project $project, string $short, callable $late, Carbon $today): array
    {
        $steps = $c->tasks->values()->map(function ($task, $i) use ($late, $today) {
            $overdue = $task->subtasks->filter($late);
            $days = $overdue->map(fn ($s) => (int) $s->due_date->diffInDays($today))->max();
            $departments = $task->subtasks->map(fn ($s) => $s->department?->name)->filter()->unique()->implode(', ');
            $firstDept = $task->subtasks->first(fn ($s) => $s->department_id)?->department_id;
            $label = preg_match('/^\d+[.)]\s/u', $task->name) ? $task->name : ($i + 1).'. '.$task->name;   // templates often number their steps already

            return [
                'no' => $i + 1, 'name' => $task->name, 'label' => $label, 'pct' => (int) $task->progress, 'department' => $departments ?: '-',
                'color' => $firstDept ? ($this->colors[$firstDept] ?? '#94a3b8') : '#cbd5e1',
                'lateCount' => $overdue->count(), 'lateDays' => $days,
                'subtasks' => $task->subtasks->map(fn ($s) => ['id' => $s->id, 'name' => $s->name, 'department' => $s->department?->name, 'locked' => $s->is_department_locked])->all(),
                'tip' => $label.' · '.(int) $task->progress.'%'.($days !== null ? ' · เลยกำหนด '.$days.' วัน' : ''),
            ];
        });

        $current = $steps->first(fn ($s) => $s['pct'] < 100);
        $lateSteps = $steps->where('lateCount', '>', 0);
        $lateCount = $lateSteps->sum('lateCount');
        $worst = $lateSteps->max('lateDays');
        $days = $c->days_remaining;
        $afterProject = $c->due_date && $project->due_date && $c->due_date->gt($project->due_date) ? (int) $project->due_date->diffInDays($c->due_date) : 0;

        [$tone, $status] = match (true) {
            $c->status === Cabinet::STATUS_COMPLETED => ['done', 'เสร็จแล้ว'],
            $days !== null && $days < 0 => ['red', 'เลยกำหนด '.abs($days).' วัน'],
            $afterProject > 0 => ['red', 'เสร็จหลังวันส่งโครงการ '.$afterProject.' วัน'],
            $days !== null && $days <= 7 && $c->progress < 50 => ['amber', 'เสี่ยง · เหลือ '.$days.' วัน'],
            $lateCount > 0 => ['amber', 'งานย่อยเลยกำหนด '.$lateCount.' · ช้าสุด '.$worst.' วัน'],
            (int) $c->progress === 0 => ['idle', 'ยังไม่เริ่ม'.($days !== null ? ' · เหลือ '.$days.' วัน' : '')],
            default => ['ok', 'ตามแผน'.($days !== null ? ' · เหลือ '.$days.' วัน' : '')],
        };

        return [
            'cabinet' => $c,
            'short' => $short, 'model' => $c->cabinet_name,
            'progress' => (int) $c->progress,
            'due' => $c->due_date?->thaiDate(), 'dueKey' => $c->due_date?->format('Y-m-d') ?? '', 'daysLeft' => $c->due_date ? (int) $today->diffInDays($c->due_date, false) : null,
            'tone' => $tone, 'status' => $status, 'reason' => $this->reason($tone, $status, $c),
            'steps' => $steps->all(),
            'signature' => $steps->pluck('name')->implode("\n"),
            'templateName' => $c->cabinetTemplate?->name,
            'current' => $current ? $current['label'] : 'ครบทุกขั้นตอน',
            'currentColor' => $current['color'] ?? '#cbd5e1',
            'currentMeta' => $current ? $current['department'].' · '.$current['pct'].'% · ขั้น '.$current['no'].'/'.$steps->count() : 'เสร็จ '.$steps->count().'/'.$steps->count(),
            'stripLabel' => 'เสร็จ '.$steps->where('pct', 100)->count().' จาก '.$steps->count().' ขั้นตอน'.($lateSteps->count() ? ' · เลยกำหนด '.$lateSteps->count().' ขั้น' : ''),
        ];
    }

    private function reason(string $tone, string $status, Cabinet $c): string
    {
        return in_array($tone, ['red', 'amber'], true) ? $status.' (คืบหน้า '.(int) $c->progress.'%)' : '';
    }

    /** The one-line answer to "will this be delivered on time?", with up to three reasons. */
    private function health(Project $project, Collection $rows, Collection $lateSubtasks, User $user): array
    {
        $due = $project->due_date?->thaiDate();
        if ($project->isLocked()) {
            return ['tone' => 'none', 'title' => $project->status === Project::STATUS_COMPLETED ? 'โครงการเสร็จแล้ว' : 'โครงการถูกยกเลิก', 'note' => null, 'items' => []];
        }

        $red = $rows->where('tone', 'red');
        if ($red->isNotEmpty()) {
            return ['tone' => 'red', 'title' => $red->count().' ตู้ อาจทำให้ส่งมอบไม่ทัน'.($due ? ' '.$due : ''), 'note' => null,
                'items' => $red->take(3)->map(fn ($r) => ['label' => $r['short'], 'detail' => $r['reason']])->values()->all(),
                'more' => max(0, $red->count() - 3)];
        }

        if ($lateSubtasks->isNotEmpty()) {
            $next = $rows->where('tone', '!=', 'done')->whereNotNull('due')->sortBy('dueKey')->first();
            $showDepartments = $user->canSeeAllWork();
            $steps = $rows->flatMap(fn ($r) => collect($r['steps'])->where('lateCount', '>', 0))
                ->groupBy('label')
                ->map(fn ($list, $label) => ['label' => 'ขั้น '.$label, 'cabinets' => $list->count(), 'days' => $list->max('lateDays'), 'department' => $list->first()['department'], 'color' => $list->first()['color']])
                ->sortByDesc(fn ($s) => $s['cabinets'] * $s['days'])->values();   // most cabinet-days lost first

            return ['tone' => 'amber', 'title' => 'ทุกตู้ยังทันวันส่ง แต่มีงานย่อยเลยกำหนด '.$lateSubtasks->count().' งาน',
                'note' => $next ? 'ถ้าไม่เร่ง ตู้ที่ต้องส่งก่อน (กำหนด '.$next['due'].') จะเริ่มเสี่ยง' : null,
                'items' => $steps->take(3)->map(fn ($s) => ['label' => $s['label'], 'color' => $showDepartments ? $s['color'] : null,
                    'detail' => $s['cabinets'].' ตู้ · ช้าสุด '.$s['days'].' วัน'.($showDepartments ? ' · '.$s['department'] : '')])->all(),
                'more' => max(0, $steps->count() - 3)];
        }

        $risky = $rows->where('tone', 'amber');
        if ($risky->isNotEmpty()) {
            return ['tone' => 'amber', 'title' => 'ยังทันกำหนด แต่มี '.$risky->count().' ตู้ที่ต้องเร่ง', 'note' => null,
                'items' => $risky->take(3)->map(fn ($r) => ['label' => $r['short'], 'detail' => $r['reason']])->values()->all(),
                'more' => max(0, $risky->count() - 3)];
        }

        return ['tone' => 'ok', 'title' => $rows->isEmpty() ? 'ยังไม่มีตู้ในโครงการนี้' : 'ทุกตู้เป็นไปตามแผน', 'note' => null, 'items' => []];
    }

    /** How much of the start → due window has passed, to set beside production %. */
    private function timeline(Project $project, Carbon $today): ?array
    {
        if (! $project->start_date || ! $project->due_date || $project->due_date->lte($project->start_date)) {
            return null;
        }
        $total = $project->start_date->diffInDays($project->due_date);
        $passed = min($total, max(0, $project->start_date->diffInDays($today, false)));

        return ['elapsed' => (int) round($passed / $total * 100), 'start' => $project->start_date->thaiDate()];
    }

    /**
     * Cabinets whose steps line up (same step names, i.e. the same template) share one table header; inside it they
     * are grouped by due date, since big projects are delivered in batches.
     */
    private function groups(Collection $rows): array
    {
        return $rows->groupBy('signature')->map(function (Collection $list) {
            $first = $list->first();
            $batches = $list->groupBy('dueKey')
                ->sortBy(fn ($b, $key) => $key === '' ? '9999' : $key)
                ->map(fn (Collection $b) => [
                    'due' => $b->first()['due'], 'daysLeft' => $b->first()['daysLeft'], 'count' => $b->count(),
                    'avg' => (int) round($b->avg('progress')), 'rows' => $b->values()->all(),
                ])->values();

            return [
                'templateName' => $first['templateName'],
                'steps' => collect($first['steps'])->map(fn ($s, $i) => ['no' => $s['no'], 'label' => $s['label'], 'parts' => $this->stepParts($list, $i)])->all(),
                'count' => $list->count(),
                'batches' => $batches->all(),
                'showBatches' => $batches->count() > 1,
            ];
        })->values()->all();
    }

    /**
     * One step across every cabinet of a template, split by sub task name ("2.1 รื้อตู้ Housing", "2.2 รื้อตู้ RMU"): what the
     * project page's "จ่ายงานทั้งขั้น" offers. Ids of work a department already accepted are left out (it cannot move).
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

    /** Work each department holds here; whoever does not see every department only gets their own. */
    private function departments(Collection $subtasks, callable $late, User $user): array
    {
        return $subtasks->filter(fn ($s) => $s->department && in_array($s->assignment_status, self::OPEN_ASSIGNMENTS, true))
            ->when(! $user->canSeeAllWork(), fn ($c) => $c->where('department_id', $user->department_id))
            ->groupBy(fn ($s) => $s->department->name)
            ->map(function ($list, $name) use ($late) {
                $waiting = $list->where('assignment_status', CabinetSubtask::ASSIGNMENT_ASSIGNED);

                return ['name' => $name, 'color' => $this->colors[$list->first()->department_id] ?? '#94a3b8', 'open' => $list->count(), 'late' => $list->filter($late)->count(),
                    'waitingIds' => $waiting->pluck('id')->values()->all(),   // for "ทวงถาม"
                    'waitingDays' => (int) $waiting->max(fn ($s) => $s->assigned_at ? (int) $s->assigned_at->diffInDays(now()) : 0)];
            })
            ->sortByDesc(fn ($d) => [$d['late'], $d['open']])->values()->all();
    }

    /** Sub tasks due in the next 7 days, by name, with how many cabinets each covers. */
    private function upcoming(Collection $subtasks, Carbon $today): array
    {
        return $subtasks->filter(fn ($s) => $s->due_date && $s->due_date->betweenIncluded($today, $today->copy()->addDays(7)) && $s->status !== CabinetSubtask::STATUS_COMPLETED)
            ->groupBy('name')
            ->map(fn ($list, $name) => ['name' => $name, 'cabinets' => $list->pluck('cabinet_task_id')->unique()->count(), 'due' => $list->min('due_date')])
            ->sortBy('due')->take(5)
            ->map(fn ($u) => ['name' => $u['name'], 'cabinets' => $u['cabinets'], 'due' => $u['due']->format('d/m')])
            ->values()->all();
    }

    private function feed(Project $project): array
    {
        return ActivityLog::where('project_id', $project->id)->whereIn('action', self::FEED_ACTIONS)
            ->with('user:id,name')->latest('id')->limit(5)->get()
            ->map(fn ($a) => ['at' => $a->created_at?->format('d/m H:i'), 'who' => $a->user?->name ?? 'ระบบ', 'text' => $a->description, 'note' => $a->action === 'note'])
            ->all();
    }

    /**
     * Big projects repeat the same words in every MO number ("ถนนวิทยุ Modify RMU (...) โรงแรม Indigo"); drop the part all
     * cabinets share (cut at a space) so the row leads with what tells them apart. The full number stays in the tooltip.
     *
     * @return array<int, string>
     */
    private function shortNames(Collection $cabinets): array
    {
        $names = $cabinets->pluck('mo_no', 'id')->map(fn ($n) => (string) $n);
        if ($names->count() < 2) {
            return $names->all();
        }

        $prefix = $names->reduce(function (?string $carry, string $name) {
            if ($carry === null) {
                return $name;
            }
            $i = 0;
            $max = min(mb_strlen($carry), mb_strlen($name));
            while ($i < $max && mb_substr($carry, $i, 1) === mb_substr($name, $i, 1)) {
                $i++;
            }

            return mb_substr($carry, 0, $i);
        });
        $cut = mb_strrpos((string) $prefix, ' ');
        if ($cut === false) {
            return $names->all();
        }
        $prefix = mb_substr($prefix, 0, $cut + 1);

        return $names->map(function ($n, $id) use ($prefix, $cabinets) {
            $rest = trim(mb_substr($n, mb_strlen($prefix)));

            return $rest === '' ? $n : $this->dropModelWords($rest, (string) $cabinets->firstWhere('id', $id)?->cabinet_name);
        })->all();
    }

    /**
     * "RMU (Schneider (Lahmeyer 2 Door) โรงแรม Indigo" with cabinet name "RMU Schneider 3 Function (Lahmeyer 2 Door)": the leading
     * words only repeat the cabinet name shown on the line below, so they go and "โรงแรม Indigo" stays.
     */
    private function dropModelWords(string $text, string $model): string
    {
        $bare = fn ($w) => mb_strtolower(trim($w, '()[]-,'));
        $known = array_filter(array_map($bare, preg_split('/\s+/u', $model)));
        $words = preg_split('/\s+/u', $text);
        $i = 0;
        while ($i < count($words) - 1 && ($bare($words[$i]) === '' || in_array($bare($words[$i]), $known, true))) {
            $i++;
        }

        return $i > 0 ? implode(' ', array_slice($words, $i)) : $text;
    }
}
