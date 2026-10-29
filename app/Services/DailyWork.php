<?php

namespace App\Services;

use App\Models\Cabinet;
use App\Models\CabinetSubtask;
use App\Models\Department;
use App\Models\Project;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * "สรุปงาน" (reports/today): per department, what came in on a day - or over a range of days - and what got done,
 * read at a glance (or from a screen on the wall). Read only. Which departments the viewer may see is decided by the caller with
 * DepartmentDashboard::scopeFor() and passed in as $departmentIds - this class never widens it.
 *
 *  - received      a Sub Task dispatched to the department in the range (assigned_at; moving it to another department
 *                  dispatches it again, so it counts for the department that holds it now)
 *  - completed     a Sub Task done in the range by the one done rule (CabinetSubtask::isDone()): the department pressed
 *                  "เสร็จงาน" (completed_at) or its checklist became complete (completed_date, timed by its last tick).
 *                  The earlier of the two is the day it was done, so a Sub Task is done on one day only.
 *  - new cabinets  cabinets (ตู้ / อุปกรณ์, by quantity) added to a project in the range; a department sees only those
 *                  it has work in. Not per department: a new cabinet's work is spread over many of them.
 *  - pending       the work it holds right now, not on a closed project: waiting (ASSIGNED), in progress (ACCEPTED /
 *                  IN_PROGRESS), and of those, overdue (due before today) - the same counts as the dashboard's "งานตามแผนก"
 *  - new work      ("มีงานเข้า") pending work nobody has opened from this page yet: cabinet_subtasks.is_active = 0 until
 *                  someone in its department's scope clicks it here (acknowledge()); then 1. Not tied to the range.
 * "ตู้" is the cabinets' quantity, as in รายงานการผลิต.
 */
class DailyWork
{
    /** one day shown: its small chart covers this many days, ending on it */
    public const CHART_DAYS_FOR_ONE_DAY = 7;

    /** "ความเคลื่อนไหว": the latest this many work events; new cabinets are listed besides */
    public const FEED_WORK_LIMIT = 40;

    /** "ความเคลื่อนไหว": at most this many new cabinets, newest first */
    public const FEED_NEW_CABINET_LIMIT = 200;

    /** "มีงานเข้า": per department, at most this many of the work not opened yet are listed, newest first */
    public const NEW_WORK_LIMIT = 30;

    public const EVENT_RECEIVED = 'received';

    public const EVENT_ACCEPTED = 'accepted';

    public const EVENT_STARTED = 'started';

    public const EVENT_COMPLETED = 'completed';

    public const EVENT_NEW_CABINET = 'new_cabinet';

    /** feed event type => the label on its tag */
    public const EVENT_LABELS = [
        self::EVENT_RECEIVED => 'งานเข้า',
        self::EVENT_ACCEPTED => 'รับงาน',
        self::EVENT_STARTED => 'เริ่มทำ',
        self::EVENT_COMPLETED => 'เสร็จ',
        self::EVENT_NEW_CABINET => 'ของเข้า',
    ];

    private const SHORT_DAY_NAMES = ['อา.', 'จ.', 'อ.', 'พ.', 'พฤ.', 'ศ.', 'ส.'];

    private const DAY_NAMES = ['อาทิตย์', 'จันทร์', 'อังคาร', 'พุธ', 'พฤหัสบดี', 'ศุกร์', 'เสาร์'];

    private const SHORT_MONTH_NAMES = ['ม.ค.', 'ก.พ.', 'มี.ค.', 'เม.ย.', 'พ.ค.', 'มิ.ย.', 'ก.ค.', 'ส.ค.', 'ก.ย.', 'ต.ค.', 'พ.ย.', 'ธ.ค.'];

    /** dispatched and not finished: what a department holds */
    private const PENDING_ASSIGNMENT_STATUSES = [
        CabinetSubtask::ASSIGNMENT_ASSIGNED,
        CabinetSubtask::ASSIGNMENT_ACCEPTED,
        CabinetSubtask::ASSIGNMENT_IN_PROGRESS,
    ];

    /**
     * @param  list<int>|null  $departmentIds  null = every department, [] = none
     * @param  Carbon  $firstDay  the first day shown
     * @param  Carbon|null  $lastDay  the last day shown (null = $firstDay only); the caller keeps it on or before today
     */
    public function build(?array $departmentIds, Carbon $firstDay, ?Carbon $lastDay = null, ?Carbon $now = null): array
    {
        $now = ($now ?? now())->copy();
        $today = $now->copy()->startOfDay();
        $rangeStart = $firstDay->copy()->startOfDay();
        $rangeEnd = ($lastDay ?? $firstDay)->copy()->endOfDay();
        $lastDay = $rangeEnd->copy()->startOfDay();
        $dayCount = (int) $rangeStart->diffInDays($lastDay) + 1;
        $chartBars = $this->chartBars($rangeStart, $rangeEnd, $dayCount);
        $chartStart = $chartBars[0]['start']->copy()->min($rangeStart);   // one day: its chart starts a week before

        $departments = $departmentIds === [] ? collect() : Department::query()->where('is_active', true)
            ->when($departmentIds !== null, fn ($query) => $query->whereIn('id', $departmentIds))
            ->orderBy('name')->get(['id', 'name', 'color', 'icon']);
        $shownDepartmentIds = $departments->pluck('id')->all();

        $movedSubtasks = $this->movedSubtasks($shownDepartmentIds, $chartStart, $rangeEnd);
        $receivedSubtasks = $movedSubtasks->filter(fn ($subtask) => $subtask->assigned_at && $subtask->assigned_at->between($chartStart, $rangeEnd));
        $completions = $movedSubtasks
            ->map(fn ($subtask) => ['subtask' => $subtask, 'completed' => $this->completion($subtask)])
            ->filter(fn ($completion) => $completion['completed'] && $completion['completed']['at']->between($chartStart, $rangeEnd));
        $newCabinets = $this->newCabinets($departmentIds, $shownDepartmentIds, $rangeStart, $rangeEnd);
        $pendingSubtasks = $this->pendingSubtasks($shownDepartmentIds);

        $receivedBetween = fn (Collection $subtasks, Carbon $start, Carbon $end) => $subtasks
            ->filter(fn ($subtask) => $subtask->assigned_at->between($start, $end));
        $completedBetween = fn (Collection $completions, Carbon $start, Carbon $end) => $completions
            ->filter(fn ($completion) => $completion['completed']['at']->between($start, $end))
            ->map(fn ($completion) => $completion['subtask']);

        $departmentRows = $departments->map(function (Department $department) use (
            $receivedSubtasks, $completions, $pendingSubtasks, $chartBars, $rangeStart, $rangeEnd, $today, $receivedBetween, $completedBetween
        ) {
            $received = $receivedSubtasks->where('department_id', $department->id);
            $completed = $completions->filter(fn ($completion) => $completion['subtask']->department_id === $department->id);
            $pending = $pendingSubtasks->where('department_id', $department->id);
            $receivedInRange = $receivedBetween($received, $rangeStart, $rangeEnd);
            $completedInRange = $completedBetween($completed, $rangeStart, $rangeEnd);
            $waitingCount = $pending->where('assignment_status', CabinetSubtask::ASSIGNMENT_ASSIGNED)->count();

            $row = [
                'id' => $department->id,
                'name' => (string) $department->name,
                'icon' => (string) $department->icon,
                'received' => $receivedInRange->count(),
                'receivedCabinets' => $this->cabinetQuantity($receivedInRange),
                'completed' => $completedInRange->count(),
                'completedCabinets' => $this->cabinetQuantity($completedInRange),
                'waiting' => $waitingCount,
                'inProgress' => $pending->count() - $waitingCount,
                'overdue' => $pending->filter(fn ($subtask) => $subtask->isLateOn($today))->count(),
                'pending' => $pending->count(),
                'unacknowledged' => $pending->filter(fn ($subtask) => ! $subtask->is_active)->count(),
                'chart' => array_map(fn ($bar) => [
                    'date' => $bar['start']->format('Y-m-d'),
                    'shortLabel' => $bar['shortLabel'],
                    'label' => $bar['label'],
                    'isHighlighted' => $bar['isHighlighted'],
                    'received' => $receivedBetween($received, $bar['start'], $bar['end'])->count(),
                    'completed' => $completedBetween($completed, $bar['start'], $bar['end'])->count(),
                ], $chartBars),
            ];
            $row['isQuiet'] = $row['received'] + $row['completed'] + $row['pending'] === 0;

            return $row;
        });

        // departments with work first, each group in name order, so cards stay put between refreshes
        $departmentRows = $departmentRows->sortBy(fn ($row) => [$row['isQuiet'] ? 1 : 0, $row['name']])->values();
        [$feed, $workEventCount] = $this->feed($movedSubtasks, $newCabinets, $rangeStart, $rangeEnd, $dayCount > 1);
        $shiftRange = fn (int $days) => [
            'from' => $rangeStart->copy()->addDays($days)->format('Y-m-d'),
            'to' => $lastDay->copy()->addDays($days)->format('Y-m-d'),
        ];
        $chartValues = $departmentRows->flatMap(fn ($row) => array_merge(array_column($row['chart'], 'received'), array_column($row['chart'], 'completed')));

        return [
            'range' => [
                'from' => $rangeStart->format('Y-m-d'),
                'to' => $lastDay->format('Y-m-d'),
                'dayCount' => $dayCount,
                'label' => $this->rangeLabel($rangeStart, $lastDay),
                'isLive' => $lastDay->isSameDay($now),                         // ends today: keep it live
                'isToday' => $dayCount === 1 && $rangeStart->isSameDay($now),
                'previous' => $shiftRange(-$dayCount),                          // the same length, just before / after
                'next' => $lastDay->copy()->addDays($dayCount)->lte($now) ? $shiftRange($dayCount) : null,
                'chartLabel' => $this->chartLabel($dayCount, $chartBars[0]['unit']),
            ],
            'today' => $now->format('Y-m-d'),
            'updatedAt' => $now->format('H:i:s'),
            'totals' => [
                'received' => $departmentRows->sum('received'),
                'receivedCabinets' => $this->cabinetQuantity($receivedBetween($receivedSubtasks, $rangeStart, $rangeEnd)),
                'completed' => $departmentRows->sum('completed'),
                'completedCabinets' => $this->cabinetQuantity($completedBetween($completions, $rangeStart, $rangeEnd)),
                'newCabinetQuantity' => (int) $newCabinets->sum(fn ($cabinet) => max(1, (int) $cabinet->quantity)),
                'newCabinetCount' => $newCabinets->count(),
                'newCabinetProjectCount' => $newCabinets->unique('project_id')->count(),
                'waiting' => $departmentRows->sum('waiting'),
                'inProgress' => $departmentRows->sum('inProgress'),
                'overdue' => $departmentRows->sum('overdue'),
                'pending' => $departmentRows->sum('pending'),
                'unacknowledged' => $departmentRows->sum('unacknowledged'),
                'activeDepartmentCount' => $departmentRows->filter(fn ($row) => $row['received'] + $row['completed'] > 0)->count(),
            ],
            'chartMax' => max(1, (int) $chartValues->max()),
            'departments' => $departmentRows->all(),
            'feed' => $feed,
            'workEventCount' => $workEventCount,
            'newWork' => $this->newWork($shownDepartmentIds),
        ];
    }

    /**
     * Someone opened work that came in: it is seen ("มีงานเข้า" goes away) for everyone. Only work within the viewer's
     * department scope, and not on a closed project (read only). A plain update: no activity log, updated_at untouched.
     *
     * @param  list<int>|null  $departmentIds  the viewer's scope (null = every department)
     * @return bool false = not the viewer's work, gone, or on a closed project
     */
    public function acknowledge(?array $departmentIds, int $subtaskId): bool
    {
        $subtask = CabinetSubtask::query()
            ->whereKey($subtaskId)
            ->whereNotNull('department_id')
            ->when($departmentIds !== null, fn ($query) => $query->whereIn('department_id', $departmentIds))
            ->whereHas('cabinetTask.cabinet.project', fn ($query) => $query->whereNotIn('status', Project::LOCKED_STATUSES))
            ->first(['id', 'is_active']);
        if (! $subtask) {
            return false;
        }
        if (! $subtask->is_active) {
            CabinetSubtask::query()->whereKey($subtask->id)->toBase()->update(['is_active' => true]);
        }

        return true;
    }

    /**
     * The bars of each department's small chart. One day: the week ending on it (that day stands out). A range: the
     * range itself, by day up to a month, by week up to half a year, by month beyond.
     *
     * @return list<array{start: Carbon, end: Carbon, shortLabel: string, label: string, isHighlighted: bool, unit: string}>
     */
    private function chartBars(Carbon $rangeStart, Carbon $rangeEnd, int $dayCount): array
    {
        $dayAndMonth = fn (Carbon $date) => $date->day.' '.self::SHORT_MONTH_NAMES[$date->month - 1];
        if ($dayCount === 1) {
            return array_map(function ($index) use ($rangeStart, $dayAndMonth) {
                $day = $rangeStart->copy()->subDays(self::CHART_DAYS_FOR_ONE_DAY - 1 - $index);

                return [
                    'start' => $day,
                    'end' => $day->copy()->endOfDay(),
                    'shortLabel' => self::SHORT_DAY_NAMES[$day->dayOfWeek],
                    'label' => self::SHORT_DAY_NAMES[$day->dayOfWeek].' '.$dayAndMonth($day),
                    'isHighlighted' => $index === self::CHART_DAYS_FOR_ONE_DAY - 1,
                    'unit' => 'day',
                ];
            }, range(0, self::CHART_DAYS_FOR_ONE_DAY - 1));
        }

        $unit = $dayCount <= 31 ? 'day' : ($dayCount <= 183 ? 'week' : 'month');
        $bars = [];
        for ($barStart = $rangeStart->copy(); $barStart->lte($rangeEnd); $barStart = $barEnd->copy()->addDay()->startOfDay()) {
            $barEnd = match ($unit) {
                'day' => $barStart->copy()->endOfDay(),
                'week' => $barStart->copy()->endOfWeek(Carbon::SUNDAY)->min($rangeEnd),
                'month' => $barStart->copy()->endOfMonth()->min($rangeEnd),
            };
            $bars[] = [
                'start' => $barStart->copy(),
                'end' => $barEnd->copy(),
                'shortLabel' => match ($unit) {
                    'day' => $dayCount <= 7 ? self::SHORT_DAY_NAMES[$barStart->dayOfWeek] : (string) $barStart->day,
                    'week' => $dayAndMonth($barStart),
                    'month' => self::SHORT_MONTH_NAMES[$barStart->month - 1],
                },
                'label' => match ($unit) {
                    'day' => self::SHORT_DAY_NAMES[$barStart->dayOfWeek].' '.$dayAndMonth($barStart),
                    'week' => $dayAndMonth($barStart).' – '.$dayAndMonth($barEnd),
                    'month' => self::SHORT_MONTH_NAMES[$barStart->month - 1].' '.($barStart->year + 543),
                },
                'isHighlighted' => true,
                'unit' => $unit,
            ];
        }

        return $bars;
    }

    /** what the small chart shows, in words: "7 วันล่าสุด", "รายวัน", "รายสัปดาห์", "รายเดือน" */
    private function chartLabel(int $dayCount, string $unit): string
    {
        if ($dayCount === 1) {
            return self::CHART_DAYS_FOR_ONE_DAY.' วันล่าสุด';
        }

        return ['day' => 'รายวัน', 'week' => 'รายสัปดาห์', 'month' => 'รายเดือน'][$unit];
    }

    /** "วันพุธที่ 21 ต.ค. 2569", or "1 – 21 ต.ค. 2569", "28 ก.ย. – 21 ต.ค. 2569", "28 ธ.ค. 2568 – 5 ม.ค. 2569" */
    private function rangeLabel(Carbon $firstDay, Carbon $lastDay): string
    {
        $buddhistYear = fn (Carbon $date) => ' '.($date->year + 543);
        $dayAndMonth = fn (Carbon $date) => $date->day.' '.self::SHORT_MONTH_NAMES[$date->month - 1];
        if ($firstDay->isSameDay($lastDay)) {
            return 'วัน'.self::DAY_NAMES[$firstDay->dayOfWeek].'ที่ '.$dayAndMonth($firstDay).$buddhistYear($firstDay);
        }
        if ($firstDay->year !== $lastDay->year) {
            return $dayAndMonth($firstDay).$buddhistYear($firstDay).' – '.$dayAndMonth($lastDay).$buddhistYear($lastDay);
        }
        $start = $firstDay->month === $lastDay->month ? $firstDay->day : $dayAndMonth($firstDay);

        return $start.' – '.$dayAndMonth($lastDay).$buddhistYear($lastDay);
    }

    /**
     * The dispatched Sub Tasks of these departments that moved between $start and $end: dispatched, accepted, started
     * or finished. One query; every count and the feed are worked out from it.
     */
    private function movedSubtasks(array $departmentIds, Carbon $start, Carbon $end): Collection
    {
        if ($departmentIds === []) {
            return collect();
        }

        return CabinetSubtask::query()
            ->whereIn('department_id', $departmentIds)
            ->whereIn('assignment_status', WorkScope::ACTIVE_STATUSES)
            ->where(fn ($query) => $query->whereBetween('assigned_at', [$start, $end])
                ->orWhereBetween('accepted_at', [$start, $end])
                ->orWhereBetween('started_at', [$start, $end])
                ->orWhereBetween('completed_at', [$start, $end])
                ->orWhereBetween('completed_date', [$start, $end]))
            ->whereHas('cabinetTask.cabinet.project')
            ->withMax(['checklists as last_tick_at' => fn ($query) => $query->where('is_completed', true)], 'completed_at')
            ->with(['cabinetTask:id,cabinet_id', 'cabinetTask.cabinet:id,project_id,mo_no,cabinet_name,quantity', 'cabinetTask.cabinet.project:id,project_no,project_name'])
            ->get(['id', 'cabinet_task_id', 'name', 'department_id', 'assignment_status', 'status', 'is_active',
                'assigned_at', 'accepted_at', 'started_at', 'completed_at', 'completed_date']);
    }

    /**
     * When a Sub Task was done: the earlier of "เสร็จงาน" pressed and the checklist completed (that day's last tick
     * gives the time; without one the time is unknown). Null = not done, or done with no date at all (old data).
     *
     * @return array{at: Carbon, hasTime: bool}|null
     */
    private function completion(CabinetSubtask $subtask): ?array
    {
        if (! $subtask->isDone()) {
            return null;
        }
        $byChecklist = null;
        if ($subtask->completed_date) {
            $lastTick = $subtask->last_tick_at ? Carbon::parse($subtask->last_tick_at) : null;
            $byChecklist = $lastTick && $lastTick->isSameDay($subtask->completed_date)
                ? ['at' => $lastTick, 'hasTime' => true]
                : ['at' => $subtask->completed_date->copy()->startOfDay(), 'hasTime' => false];
        }
        $byButton = $subtask->completed_at ? ['at' => $subtask->completed_at, 'hasTime' => true] : null;

        if ($byButton && $byChecklist) {   // both: the earlier day; on the same day "เสร็จงาน" (it has a real time)
            return $byChecklist['at']->copy()->startOfDay()->lt($byButton['at']->copy()->startOfDay()) ? $byChecklist : $byButton;
        }

        return $byButton ?? $byChecklist;
    }

    /**
     * Cabinets added to a project in the range, newest first. Everyone with a department scope sees only the cabinets
     * it has dispatched work in, the same rule as its Sub Tasks (WorkScope).
     *
     * @param  list<int>|null  $departmentIds  the viewer's scope (null = every department)
     * @param  list<int>  $shownDepartmentIds  the active departments within it
     */
    private function newCabinets(?array $departmentIds, array $shownDepartmentIds, Carbon $start, Carbon $end): Collection
    {
        if ($departmentIds !== null && $shownDepartmentIds === []) {
            return collect();
        }

        return Cabinet::query()
            ->whereBetween('created_at', [$start, $end])
            ->whereHas('project')
            ->when($departmentIds !== null, fn ($query) => $query->whereHas('tasks.subtasks', fn ($subtasks) => $subtasks
                ->whereIn('department_id', $shownDepartmentIds)
                ->whereIn('assignment_status', WorkScope::ACTIVE_STATUSES)))
            ->with('project:id,project_no,project_name')
            ->latest()
            ->get(['id', 'project_id', 'mo_no', 'cabinet_name', 'quantity', 'created_at']);
    }

    /** The work these departments hold right now; a closed project's is finished business, as on the dashboard. */
    private function pendingSubtasks(array $departmentIds): Collection
    {
        if ($departmentIds === []) {
            return collect();
        }

        return CabinetSubtask::query()
            ->whereIn('department_id', $departmentIds)
            ->whereIn('assignment_status', self::PENDING_ASSIGNMENT_STATUSES)
            ->notDone()
            ->whereHas('cabinetTask.cabinet.project', fn ($query) => $query->whereNotIn('status', [Project::STATUS_COMPLETED, Project::STATUS_CANCELLED]))
            ->get(['id', 'department_id', 'assignment_status', 'status', 'due_date', 'is_active']);
    }

    /** "มีงานเข้า": the pending work not opened yet, per department newest first, as feed events (the page groups them by department) */
    private function newWork(array $departmentIds): array
    {
        if ($departmentIds === []) {
            return [];
        }

        return CabinetSubtask::query()
            ->whereIn('department_id', $departmentIds)
            ->whereIn('assignment_status', self::PENDING_ASSIGNMENT_STATUSES)
            ->where('is_active', false)
            ->notDone()
            ->whereHas('cabinetTask.cabinet.project', fn ($query) => $query->whereNotIn('status', [Project::STATUS_COMPLETED, Project::STATUS_CANCELLED]))
            ->with(['cabinetTask:id,cabinet_id', 'cabinetTask.cabinet:id,project_id,mo_no,cabinet_name,quantity', 'cabinetTask.cabinet.project:id,project_no,project_name'])
            ->orderByDesc('assigned_at')->orderByDesc('id')
            ->get(['id', 'cabinet_task_id', 'name', 'department_id', 'is_active', 'assigned_at'])
            ->groupBy('department_id')
            ->flatMap(fn ($subtasks) => $subtasks->take(self::NEW_WORK_LIMIT))
            ->map(fn ($subtask) => ['key' => 'new-'.$subtask->id]
                + $this->workEvent($subtask, self::EVENT_RECEIVED, $subtask->assigned_at, true, $subtask->assigned_at ? $this->dayAndMonth($subtask->assigned_at) : null))
            ->values()->all();
    }

    /**
     * one line of work in the feed; isNew = nobody has opened it from this page yet ("มีงานเข้า"). Its link opens the
     * cabinet at that Sub Task (#goto-subtask-{id}: the cabinet page starts at its top, then scrolls down and opens it)
     */
    private function workEvent(CabinetSubtask $subtask, string $type, ?Carbon $at, bool $hasTime, ?string $date): array
    {
        $cabinet = $subtask->cabinetTask?->cabinet;

        return [
            'key' => $type.'-'.$subtask->id,
            'type' => $type,
            'typeLabel' => self::EVENT_LABELS[$type],
            'time' => $at && $hasTime ? $at->format('H:i') : null,
            'date' => $date,
            'departmentId' => $subtask->department_id,
            'title' => (string) $subtask->name,
            'projectNo' => (string) $cabinet?->project?->project_no,
            'projectName' => (string) $cabinet?->project?->project_name,
            'detail' => (string) ($cabinet?->mo_no ?: $cabinet?->cabinet_name),
            'url' => $cabinet ? route('cabinets.show', $cabinet->id).'#goto-subtask-'.$subtask->id : null,
            'subtaskId' => $subtask->id,
            'isNew' => ! $subtask->is_active,
        ];
    }

    private function dayAndMonth(Carbon $date): string
    {
        return $date->day.' '.self::SHORT_MONTH_NAMES[$date->month - 1];
    }

    /** How many cabinets (their quantity) the Sub Tasks belong to, each cabinet once. */
    private function cabinetQuantity(Collection $subtasks): int
    {
        return (int) $subtasks->map(fn ($subtask) => $subtask->cabinetTask?->cabinet)->filter()->unique('id')
            ->sum(fn ($cabinet) => max(1, (int) $cabinet->quantity));
    }

    /**
     * What happened in the range, newest first; a checklist completed without a tick time comes last in its day
     * ("ไม่ทราบเวลา"). Over more than one day each event also says which day.
     *
     * @return array{0: list<array>, 1: int} the events listed, and how many work events (not cabinets) there were in all
     */
    private function feed(Collection $movedSubtasks, Collection $newCabinets, Carbon $start, Carbon $end, bool $showDate): array
    {
        $workEvents = collect();
        $dateLabel = fn (Carbon $at) => $showDate ? $this->dayAndMonth($at) : null;
        foreach ($movedSubtasks as $subtask) {
            $addEvent = function (string $type, Carbon $at, bool $hasTime = true) use ($workEvents, $subtask, $dateLabel) {
                $workEvents->push($this->workEvent($subtask, $type, $at, $hasTime, $dateLabel($at))
                    + ['sortAt' => $hasTime ? $at->getTimestamp() : $at->copy()->startOfDay()->getTimestamp()]);
            };
            $steps = [
                self::EVENT_RECEIVED => $subtask->assigned_at,
                self::EVENT_ACCEPTED => $subtask->accepted_at,
                self::EVENT_STARTED => $subtask->started_at,
            ];
            foreach ($steps as $type => $at) {
                if ($at && $at->between($start, $end)) {
                    $addEvent($type, $at);
                }
            }
            $completed = $this->completion($subtask);
            if ($completed && $completed['at']->between($start, $end)) {
                $addEvent(self::EVENT_COMPLETED, $completed['at'], $completed['hasTime']);
            }
        }

        $workEventCount = $workEvents->count();
        $events = $workEvents->sortByDesc('sortAt')->take(self::FEED_WORK_LIMIT);
        foreach ($newCabinets->take(self::FEED_NEW_CABINET_LIMIT) as $cabinet) {
            $events->push([
                'key' => self::EVENT_NEW_CABINET.'-'.$cabinet->id,
                'type' => self::EVENT_NEW_CABINET,
                'typeLabel' => self::EVENT_LABELS[self::EVENT_NEW_CABINET],
                'time' => $cabinet->created_at->format('H:i'),
                'date' => $dateLabel($cabinet->created_at),
                'sortAt' => $cabinet->created_at->getTimestamp(),
                'departmentId' => null,
                'title' => (string) $cabinet->cabinet_name,
                'projectNo' => (string) $cabinet->project?->project_no,
                'projectName' => (string) $cabinet->project?->project_name,
                'detail' => max(1, (int) $cabinet->quantity).' ตู้'.($cabinet->mo_no ? ' · '.$cabinet->mo_no : ''),
                'url' => route('cabinets.show', $cabinet->id),
                'subtaskId' => null,
                'isNew' => false,
            ]);
        }

        $events = $events->sortByDesc('sortAt')->map(fn ($event) => collect($event)->except('sortAt')->all())->values()->all();

        return [$events, $workEventCount];
    }
}
