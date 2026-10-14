<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\BoardLabel;
use App\Models\BoardStatus;
use App\Models\Cabinet;
use App\Models\DepartmentField;
use App\Models\ProjectDepartmentStatus;
use App\Models\ProjectFieldValue;
use App\Models\CabinetSubtask;
use App\Models\Department;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Turns PmoLastVersion's own data into the "board" JSON that the Avatar Planning single-page app (resources/planning/
 * index.html, served at /planning) expects, so that page can run on OUR data instead of Supabase.
 *
 *   Project               -> task      (SO = project_no, dates, priority, comments = Activity notes)
 *   Cabinet               -> subtask   (MO = mo_no; checklist = the checklist items of its dispatched Sub Tasks)
 *   Sub Tasks of one dept -> deptStage (+ per-cabinet deptDates)   -> "ส่งต่อแผนก" chips and the department calendar
 *   workflow status       -> one of 5 fixed statuses (the five colour dots)
 *
 * The page's edits are NOT written by this class or by the page: they come back as operations to PlanningSync, which applies
 * them through PMO's own services and rules. The profile's perm only tells the page which controls to offer:
 * PMO roles 'full', everyone else 'checklist' (tick checklist items + comment) - the server enforces the real rules either way.
 * What a user receives follows the same visibility rule as My Department (DepartmentDashboard::scopeFor): PMO roles
 * get every department, everyone else only their own department's Sub Tasks, checklist items and stages.
 */
class PlanningBoard
{
    public const BOARD_ID = 'pmo';

    private const ACTIVE = [
        CabinetSubtask::ASSIGNMENT_ASSIGNED,
        CabinetSubtask::ASSIGNMENT_ACCEPTED,
        CabinetSubtask::ASSIGNMENT_IN_PROGRESS,
        CabinetSubtask::ASSIGNMENT_COMPLETED,
    ];

    private const PALETTE = ['#a855f7', '#3b82f6', '#f59e0b', '#10b981', '#ec4899', '#14b8a6', '#f97316', '#06b6d4', '#84cc16', '#6366f1'];

    /**
     * Each active department's colour as Planning shows it (Settings > แผนก, else a stable palette colour by position),
     * so other pages can use the same dots.
     *
     * @return array<int, string> department id => #hex
     */
    public static function departmentColors(): array
    {
        return once(fn () => Department::where('is_active', true)->orderBy('id')->get(['id', 'color'])->values()   // once per request: rows render it many times
            ->mapWithKeys(fn (Department $d, int $i) => [$d->id => $d->color ?: self::PALETTE[$i % count(self::PALETTE)]])->all());
    }

    /** PMO project status => colour, the same set Planning shows for a project with no status picked by hand. */
    public static function statusColor(?string $status): string
    {
        return collect(self::pmoStatuses())->firstWhere('id', 'pmo_'.$status)['color'] ?? '#94a3b8';
    }

    /** The profile the page runs as: read-only, and "no department" (= sees every department) for PMO roles. */
    public function profile(User $user): array
    {
        return [
            'id' => 'u'.$user->id,
            'email' => $user->email,
            'display_name' => $user->name,
            'role' => 'member',
            'perm' => $user->canDispatchWork() ? 'full' : 'checklist',
            'approved' => true,
            'blocked' => false,
            'name_set' => true,
            'dept_id' => ($user->canSeeAllWork() || ! $user->department_id) ? '' : self::deptKey($user->department_id),
        ];
    }

    /** @param  int|null|string  $board  null = the main board, an id = that extra board, 'any' = no board filter */
    public function build(User $user, ?\Carbon\Carbon $today = null, int|string|null $board = null): array
    {
        $today ??= today();

        $handoff = app(HandoffService::class);
        $fieldsByDept = DepartmentField::with('options')->orderBy('sort')->orderBy('id')->get()->groupBy('department_id');
        $colors = self::departmentColors();
        $departments = Department::where('is_active', true)->orderBy('id')->get(['id', 'name', 'code', 'color', 'icon', 'sees_all'])
            ->values()
            ->map(fn (Department $d) => [
                'id' => self::deptKey($d->id),
                'name' => $d->name,
                'code' => (string) $d->code,
                'color' => $colors[$d->id],   // Settings > แผนก, else a stable palette colour
                'icon' => (string) $d->icon,
                'checklist' => [],
                'fields' => ($fieldsByDept->get($d->id) ?? collect())->map(fn (DepartmentField $f) => self::fieldShape($f))->values()->all(),
                'seeAll' => (bool) $d->sees_all,
                // Settings > การส่งต่องาน: the date this department gives each checklist item for the next one (null = none) and who follows it
                'dateLabel' => $handoff->dateLabel($d->id),
                'passesTo' => array_map(fn ($id) => self::deptKey($id), $handoff->targetsFor($d->id)),
            ])->all();

        $tasks = $this->tasks($user, null, $today, $board);

        return [
            'activeBoard' => self::BOARD_ID,
            'boardMeta' => [['id' => self::BOARD_ID, 'name' => 'PMO']],
            'boards' => [self::BOARD_ID => [
                'statuses' => self::statuses(),
                'pmoStatusList' => self::pmoStatuses(),
                'labels' => BoardLabel::orderBy('sort')->orderBy('id')->get()->map(fn (BoardLabel $l) => ['id' => 'l'.$l->id, 'name' => $l->name, 'color' => $l->color])->all(),
                'departments' => $departments,
                'tasks' => $tasks,
                'trash' => [],
                'settings' => [
                    'currentUser' => $user->name,
                    'myDept' => ($user->department_id && ! $user->canSeeAllWork()) ? self::deptKey($user->department_id) : '',
                    'defaultStages' => [],
                    'dateLimit' => ['enabled' => false, 'anchorDept' => null, 'blockedDepts' => []],
                ],
            ]],
        ];
    }

    /**
     * The board's tasks for this user - all of them, or only the given project ids (what a sync answer needs: just the
     * projects that were edited). A requested project the user cannot see (or that no longer exists) is simply absent.
     * PMO roles also get open projects that have no work dispatched yet, and every cabinet of a project (even an empty one),
     * so they can build a project up from this page; everyone else sees only their department's dispatched work.
     *
     * @param  list<int>|null  $projectIds
     * @param  int|null|string  $board  null = the main board, an id = that extra board, 'any' = every board
     */
    public function tasks(User $user, ?array $projectIds = null, ?\Carbon\Carbon $today = null, int|string|null $board = 'any'): array
    {
        $today ??= today();
        $scope = DepartmentDashboard::scopeFor($user);   // null = every department, [] = none, [ids] = those
        $onBoard = fn ($q) => $q->when($board !== 'any', fn ($q) => $board === null ? $q->whereNull('board_id') : $q->where('board_id', $board));

        $subtasks = $scope === [] || $projectIds === [] ? collect() : CabinetSubtask::query()
            ->whereNotNull('department_id')
            ->whereIn('assignment_status', self::ACTIVE)
            ->when($scope !== null, fn ($q) => $q->whereIn('department_id', $scope))
            ->whereHas('cabinetTask.cabinet', fn ($c) => $c
                ->when($projectIds !== null, fn ($c) => $c->whereIn('project_id', $projectIds))
                ->whereHas('project', $onBoard))
            ->with('cabinetTask.cabinet.project.boardStatus', 'cabinetTask.cabinet.project.boardLabels', 'cabinetTask.cabinet.project.assignees:id', 'cabinetTask.cabinet.boardStatus', 'checklists')
            ->orderBy('id')
            ->get();

        $byProject = $subtasks->groupBy(fn ($s) => $s->cabinetTask->cabinet->project_id);
        $builder = $user->canDispatchWork() && $projectIds !== [];

        // PMO: open projects nobody has been given work on yet (they still need to appear so work can be added)
        $empty = $projectIds === [] ? collect() : Project::query()
            ->whereNotIn('status', Project::LOCKED_STATUSES)
            ->whereNotIn('id', $byProject->keys())
            // PMO: every open project; everyone else: the ones they were made a person in charge of
            ->when(! $builder, fn ($q) => $q->whereHas('assignees', fn ($a) => $a->whereKey($user->id)))
            ->when($projectIds !== null, fn ($q) => $q->whereIn('id', $projectIds))
            ->tap($onBoard)
            ->with('boardStatus', 'boardLabels', 'assignees:id')->orderBy('id')->get();

        $ids = $byProject->keys()->merge($empty->pluck('id'));
        $comments = $this->comments($ids, $user);
        $this->fieldValues = $this->loadFieldValues($ids);
        // how many files each project has that this person may see (current versions, not in the trash) - the project window's "📎 ไฟล์ (n)"
        $this->fileCounts = collect(app(ProjectFiles::class)->counts($ids->all(), $user));
        $this->deptStatuses = $ids->isEmpty() ? collect() : ProjectDepartmentStatus::whereIn('project_id', $ids)->with('boardStatus')->get()
            ->groupBy('project_id')->map(fn (Collection $g) => $g->mapWithKeys(fn ($r) => [$r->department_id => $r->boardStatus?->pageId()])->filter());
        // PMO: sub tasks nobody was given yet (e.g. straight from a template), so they can be handed out from the page
        $this->free = ! $builder ? collect() : CabinetSubtask::query()
            ->where(fn ($q) => $q->whereNull('department_id')->orWhere('assignment_status', CabinetSubtask::ASSIGNMENT_UNASSIGNED))
            ->whereHas('cabinetTask.cabinet', fn ($q) => $q->whereIn('project_id', $ids))
            ->with('cabinetTask:id,cabinet_id,name')->withCount('checklists')->orderBy('id')->get()
            ->groupBy(fn ($s) => $s->cabinetTask->cabinet_id);
        $this->usual = $this->usualDepartments($this->free->flatten());
        // Settings > การส่งต่องาน: what the departments passing work to this user's department have done, per cabinet (read only)
        $this->linked = app(HandoffService::class)->linkedFor($user, $byProject->flatten(1)->map(fn ($s) => $s->cabinetTask->cabinet_id)->unique()->values());
        $cabinets = $builder ? Cabinet::with('boardStatus')->whereIn('project_id', $ids)->orderBy('sequence')->orderBy('id')->get()->groupBy('project_id') : null;

        $tasks = $byProject->map(fn (Collection $rows) => $this->task($rows->first()->cabinetTask->cabinet->project, $rows, $comments, $today, $user, $cabinets))
            ->concat($empty->map(fn (Project $p) => $this->task($p, collect(), $comments, $today, $user, $cabinets)));

        return $tasks->sortBy(fn (array $t) => (int) substr($t['id'], 1))->values()->all();
    }

    /** What the board is read from: [table, what to add up]. Soft-deleted rows count too (a delete / restore bumps updated_at). */
    private const STAMP_TABLES = [
        'projects' => 'id', 'cabinets' => 'id', 'cabinet_tasks' => 'id', 'cabinet_subtasks' => 'id', 'cabinet_checklists' => 'id',
        'activity_logs' => 'id', 'comment_attachments' => 'id', 'board_labels' => 'id', 'board_statuses' => 'id', 'boards' => 'id',
        'departments' => 'id', 'department_fields' => 'id', 'department_field_options' => 'id', 'project_field_values' => 'id',
        'project_department_statuses' => 'id', 'department_links' => 'id', 'users' => 'id', 'project_files' => 'id',
        // pivots: no id / timestamps - a swap (detach one, attach another) still changes the sum
        'project_user' => 'project_id * 100003 + user_id', 'board_label_project' => 'project_id * 100003 + board_label_id',
    ];

    /** How often a poll rebuilds the board anyway, whatever the stamp says (seconds) - the safety net for a change it cannot see. */
    public const STAMP_MAX_AGE = 120;

    /**
     * A cheap fingerprint of everything the board is built from - ONE query of counts / latest updated_at / sums, plus the
     * "board version" that every model save bumps (see touch()). The 15-second poll compares it with the page's copy and
     * answers 304 without building anything when nothing changed; building is ~30 queries and most of the CPU, which a
     * shared host (CloudLinux limits) feels when several tabs poll. Also changes with the day (late work) and every
     * STAMP_MAX_AGE seconds.
     */
    public function stamp(User $user, int|string|null $board, ?\Carbon\Carbon $today = null): string
    {
        $parts = collect(self::STAMP_TABLES)->map(fn (string $sum, string $table) => str_contains($sum, '*')   // the pivots have no timestamps
            ? "SELECT COUNT(*) AS n, NULL AS u, SUM({$sum}) AS s FROM {$table}"
            : "SELECT COUNT(*) AS n, MAX(updated_at) AS u, SUM({$sum}) AS s FROM {$table}");
        $rows = \Illuminate\Support\Facades\DB::select($parts->implode(' UNION ALL '));

        return md5(json_encode([
            $rows, \Illuminate\Support\Facades\Cache::get('planning:version', 0),
            $user->id, $board, ($today ?? today())->toDateString(), intdiv(now()->getTimestamp(), self::STAMP_MAX_AGE),
        ]));
    }

    /** Something was saved / deleted: bump the board version once, when the request ends (cheap; a request saves many models). */
    public static function touch(): void
    {
        $app = app();
        if ($app->bound('planning.touched')) {
            return;
        }
        $app->instance('planning.touched', true);
        $app->terminating(function () use ($app) {
            // terminating callbacks stay registered (they run again on a later terminate() in tests / long-running workers)
            if (! $app->bound('planning.touched')) {
                return;
            }
            $app->forgetInstance('planning.touched');
            \Illuminate\Support\Facades\Cache::forever('planning:version', \Illuminate\Support\Str::random(12));
        });
    }

    /**
     * A date column as 'Y-m-d' straight from the row - the board reads thousands of these, and every read of a cast date
     * makes a new Carbon (most of the build time). Same value as ->format('Y-m-d'): Laravel does not shift dates on read.
     */
    private static function ymd(\Illuminate\Database\Eloquent\Model $m, string $key): ?string
    {
        $v = $m->getAttributes()[$key] ?? null;

        return $v === null || $v === '' ? null : substr((string) $v, 0, 10);
    }

    /** Whether a 'Y-m-d' due date is before $today - exactly as Carbon's lt() (a due date is that day at 00:00). */
    private static function before(string $due, \Carbon\Carbon $today): bool
    {
        return $due.' 00:00:00.000000' < $today->format('Y-m-d H:i:s.u');
    }

    /** Every status the page offers, in the order set in Settings > สถานะงาน (the five built-in ones plus any added by hand). */
    public static function statuses(): array
    {
        return BoardStatus::orderBy('sort')->orderBy('id')->get()
            ->map(fn (BoardStatus $s) => ['id' => $s->pageId(), 'name' => $s->name, 'color' => $s->color, 'isDone' => $s->is_done])->all();
    }

    /**
     * PMO's own project statuses, in the same shape as statuses(): what a project shows while Admin / PM has not picked a status
     * for it (ids "pmo_<status>"). Completed and cancelled count as finished.
     */
    public static function pmoStatuses(): array
    {
        $colors = [Project::STATUS_DRAFT => '#94a3b8', Project::STATUS_PLANNING => '#8b5cf6', Project::STATUS_IN_PROGRESS => '#3b82f6',
            Project::STATUS_ON_HOLD => '#f59e0b', Project::STATUS_COMPLETED => '#10b981', Project::STATUS_CANCELLED => '#64748b'];

        return collect(Project::$statuses)->map(fn (string $name, string $key) => [
            'id' => 'pmo_'.$key, 'name' => $name, 'color' => $colors[$key] ?? '#94a3b8', 'pmo' => true,
            'isDone' => in_array($key, [Project::STATUS_COMPLETED, Project::STATUS_CANCELLED], true),
        ])->values()->all();
    }

    /** A department field in the shape the page reads: select fields carry their options grouped (a blank group name = no heading). */
    private static function fieldShape(DepartmentField $f): array
    {
        $shape = ['id' => 'f'.$f->id, 'name' => $f->name, 'showOnChip' => (bool) $f->show_on_chip];
        if ($f->type !== 'select') {
            return $shape + ['type' => $f->type, 'groups' => []];
        }

        return $shape + ['groups' => $f->options->groupBy(fn ($o) => $o->group_name ?? '')->map(fn (Collection $os, string $g) => [
            'id' => 'g'.md5($f->id.$g), 'name' => $g,
            'options' => $os->map(fn ($o) => ['id' => 'o'.$o->id, 'label' => $o->label])->values()->all(),
        ])->values()->all()];
    }

    /** @var array<string, int> "t<template id>" / "n<name>" => the department that piece of work usually goes to */
    private array $usual = [];

    /**
     * For each piece of work not handed out yet: the department the same piece of work (same template sub task, else same name)
     * was most often given before - so "+ เพิ่มแผนก" can tick it for that department by itself.
     */
    private function usualDepartments(Collection $free): array
    {
        if ($free->isEmpty()) {
            return [];
        }
        $byTemplate = CabinetSubtask::query()->whereNotNull('department_id')
            ->whereIn('cabinet_subtask_template_id', $free->pluck('cabinet_subtask_template_id')->filter()->unique())
            ->selectRaw('cabinet_subtask_template_id as k, department_id, count(*) as n')->groupBy('cabinet_subtask_template_id', 'department_id')->get()
            ->groupBy('k')->mapWithKeys(fn ($rows, $k) => ['t'.$k => (int) $rows->sortByDesc('n')->first()->department_id]);
        $byName = CabinetSubtask::query()->whereNotNull('department_id')
            ->whereIn('name', $free->pluck('name')->unique())
            ->selectRaw('name as k, department_id, count(*) as n')->groupBy('name', 'department_id')->get()
            ->groupBy('k')->mapWithKeys(fn ($rows, $k) => ['n'.$k => (int) $rows->sortByDesc('n')->first()->department_id]);

        // plain arrays: these are Eloquent collections holding numbers, and Eloquent's merge() expects models ("t.." / "n.." keys never clash)
        return $byTemplate->all() + $byName->all();
    }

    /** @var Collection<int, Collection> cabinet id => its sub tasks with no department yet (PMO roles only; filled per tasks() call) */
    private Collection $free;

    /** @var Collection<int, int> project id => how many files it has (filled per tasks() call) */
    private Collection $fileCounts;

    /** @var Collection<int, array> cabinet id => the sending departments' checklists (filled per tasks() call) */
    private Collection $linked;

    /** @var Collection<int, Collection> project id => department id => page id of the status Admin / PM set for it (filled per tasks() call) */
    private Collection $deptStatuses;

    /** @var Collection<int, Collection> project id => department id => [field key => value] (filled per tasks() call) */
    private Collection $fieldValues;

    private function loadFieldValues(Collection $projectIds): Collection
    {
        if ($projectIds->isEmpty()) {
            return collect();
        }
        $fields = DepartmentField::get(['id', 'department_id', 'type'])->keyBy('id');

        return ProjectFieldValue::whereIn('project_id', $projectIds)->get()->groupBy('project_id')->map(
            fn (Collection $vals) => $vals->filter(fn ($v) => $fields->has($v->department_field_id))
                ->groupBy(fn ($v) => $fields[$v->department_field_id]->department_id)
                ->map(fn (Collection $vs) => $vs->mapWithKeys(fn ($v) => ['f'.$v->department_field_id => $fields[$v->department_field_id]->type === 'select' ? 'o'.$v->value : $v->value])->all())
        );
    }

    /**
     * "ของฉัน": the projects that are mine - my department still has unfinished work on them, I am their Project Manager, or someone
     * tagged / alerted me on them. (The people in charge are departments here, so "assigned to me" means my department.)
     *
     * @return list<int>
     */
    public function mineProjectIds(User $user): array
    {
        $ids = Project::where('project_manager_id', $user->id)->pluck('id')
            ->merge(Project::whereHas('assignees', fn ($q) => $q->whereKey($user->id))->pluck('id'));

        if ($user->department_id) {
            $ids = $ids->merge(CabinetSubtask::where('department_id', $user->department_id)
                ->whereIn('assignment_status', [CabinetSubtask::ASSIGNMENT_ASSIGNED, CabinetSubtask::ASSIGNMENT_ACCEPTED, CabinetSubtask::ASSIGNMENT_IN_PROGRESS])
                ->with('cabinetTask.cabinet:id,project_id')->get()->map(fn ($s) => $s->cabinetTask?->cabinet?->project_id));
        }

        $tagged = $user->notifications()->where('created_at', '>=', now()->subDays(60))->get()
            ->filter(fn ($n) => in_array($n->data['kind'] ?? null, ['mention', 'alert'], true))
            ->map(fn ($n) => $n->data['project_id'] ?? (preg_match('/[?&]project=(\d+)/', (string) ($n->data['url'] ?? ''), $m) ? (int) $m[1] : null));

        return $ids->merge($tagged)->filter()->unique()->values()->map(fn ($id) => (int) $id)->all();
    }

    public static function deptKey(int $id): string
    {
        return 'd_'.$id;
    }

    private function task(Project $project, Collection $rows, Collection $comments, \Carbon\Carbon $today, User $user, ?Collection $allCabinets): array
    {
        $dues = $rows->map(fn ($s) => self::ymd($s, 'due_date'))->filter();
        $starts = $rows->map(fn ($s) => self::ymd($s, 'start_date'))->filter();
        $date = $project->due_date?->format('Y-m-d') ?? $dues->max() ?? ($project->created_at ?? $today)->format('Y-m-d');
        $start = $project->start_date?->format('Y-m-d') ?? $starts->min();

        // PMO roles get every cabinet of the project (even one nothing was dispatched on yet); everyone else only those with their work
        $byCabinet = $rows->groupBy(fn ($s) => $s->cabinetTask->cabinet_id);
        $cabinets = $allCabinets
            ? ($allCabinets->get($project->id) ?? collect())->map(fn (Cabinet $c) => $this->cabinet($c, $byCabinet->get($c->id, collect()), $today))
            : $byCabinet->map(fn (Collection $cab) => $this->cabinet($cab->first()->cabinetTask->cabinet, $cab, $today));
        $stages = $rows->groupBy('department_id');

        return [
            'id' => 'p'.$project->id,
            'title' => $project->project_name,
            'so' => $project->project_no,
            'description' => (string) $project->description,
            'startDate' => ($start && $start <= $date) ? $start : null,
            'date' => $date,
            // the PROJECT's status: the one Admin / PM picked (Settings > สถานะงาน), else PMO's own project status - never the departments' work
            'statusId' => $project->boardStatus?->pageId() ?? 'pmo_'.$project->status,
            'manualStatus' => $project->boardStatus !== null,
            'boardNo' => $project->board_id,
            'priority' => ['low' => 'low', 'high' => 'high', 'urgent' => 'urgent'][$project->priority] ?? 'none',
            'labels' => $project->boardLabels->map(fn ($l) => 'l'.$l->id)->values()->all(),
            // the people in charge are the departments that hold work on the project
            'assignees' => $stages->keys()->map(fn ($id) => self::deptKey((int) $id))->values()->all(),
            'owners' => $project->assignees->pluck('id')->values()->all(),
            // the project's own record (shown / edited in "ข้อมูลโครงการ"; editing it is for Admin / PM)
            'info' => [
                // money and the customer's phone only for whom Settings > สิทธิ์การใช้งาน allows - left out of the JSON, not just hidden
                'customer' => (string) $project->customer_name, 'customerPhone' => $user->allows('data.contact') ? (string) $project->customer_phone : '', 'poNo' => (string) $project->po_no, 'soNo' => (string) $project->sales_order_no,
                'contractValue' => $user->allows('data.finance') && $project->contract_value !== null ? (float) $project->contract_value : null,
                'margin' => $user->allows('data.finance') && $project->margin !== null ? (float) $project->margin : null,
                'owner' => (string) $project->project_owner, 'salesPerson' => (string) $project->sales_person,
                'managerId' => $project->project_manager_id, 'paymentTerms' => (string) $project->payment_terms,
                'jobTypeId' => $project->job_type_id, 'pmoStatus' => $project->status,
            ],
            // what is still missing, for "ข้อมูลที่ยังขาด" (and the warning dot on the bar)
            'warnings' => array_filter([
                'undated' => $rows->filter(fn ($s) => ! self::ymd($s, 'due_date') && $s->assignment_status !== CabinetSubtask::ASSIGNMENT_COMPLETED)->count(),
                'free' => $allCabinets ? ($allCabinets->get($project->id) ?? collect())->sum(fn ($c) => ($this->free->get($c->id) ?? collect())->count()) : 0,
                'cabNoDue' => $allCabinets ? ($allCabinets->get($project->id) ?? collect())->whereNull('due_date')->count() : 0,
                'noManager' => $project->project_manager_id ? 0 : 1,
                'noDue' => $project->due_date ? 0 : 1,
            ]),   // people in charge (Planning: ผู้รับผิดชอบรายคน)
            'alertPending' => $comments->get($project->id, collect())->contains(fn ($c) => ($c['alert']['forMe'] ?? false) && ! $c['alert']['acked']),
            'subtasks' => $cabinets->values()->all(),
            'deptStages' => $stages->map(fn (Collection $d, $deptId) => [
                'deptId' => self::deptKey((int) $deptId),
                'due' => $d->map(fn ($s) => self::ymd($s, 'due_date'))->filter()->max() ?? ($project->due_date ?? $today)->format('Y-m-d'),
                'done' => $d->every(fn ($s) => $s->assignment_status === CabinetSubtask::ASSIGNMENT_COMPLETED),
                // the DEPARTMENT's status in this project: Admin / PM's pick, else worked out from its work (waiting / accepted / doing / done / late)
                'statusId' => ($own = $this->deptStatuses->get($project->id)?->get((int) $deptId)) ?? $this->statusFor($d, $today),
                'manualStatus' => $own !== null,
                // the department's own range in this project, from its work (null = nothing dated yet) - what the calendar draws
                'start' => $d->map(fn ($s) => self::ymd($s, 'start_date'))->filter()->min(),
                'end' => $d->map(fn ($s) => self::ymd($s, 'due_date'))->filter()->max(),
                'undated' => $d->filter(fn ($s) => ! self::ymd($s, 'due_date') && $s->assignment_status !== CabinetSubtask::ASSIGNMENT_COMPLETED)->count(),
                'fields' => (object) ($user->allows('data.other_fields') || (int) $deptId === (int) $user->department_id ? ($this->fieldValues->get($project->id)?->get((int) $deptId) ?? []) : []),
                'pmo' => $this->workflow($d, (int) $deptId, $user),
            ])->values()->all(),
            'comments' => $comments->get($project->id, collect())->values()->all(),
            // the change log ("การดำเนินการ") is the project page's Activity Log tree, fetched on demand; PMO roles only
            'logUrl' => $user->canViewOtherDepartments() && $user->allows('data.comments') ? route('projects.activity-tree', $project) : null,
            'createdAt' => ($project->created_at ?? $today)->getTimestampMs(),
            'updatedAt' => ($project->updated_at ?? $today)->getTimestampMs(),
            'pmoUrl' => route('projects.show', $project),
            'filesCount' => $user->allows('data.files') ? (int) ($this->fileCounts[$project->id] ?? 0) : null,   // null: no 📎 for them
        ];
    }

    private function cabinet(Cabinet $cabinet, Collection $rows, \Carbon\Carbon $today): array
    {
        // in the cabinet's own order: Task, then its Sub Tasks (the checklist window shows Task › Sub Task › items)
        $rows = $rows->sortBy(fn ($s) => sprintf('%05d-%05d-%09d', $s->cabinetTask->sequence ?? 0, $s->sequence ?? 0, $s->id))->values();

        // { deptKey: 'YYYY-MM-DD' } - must serialise as a JSON object even when empty
        $deptDates = $rows->filter(fn ($s) => self::ymd($s, 'due_date'))->groupBy('department_id')
            ->mapWithKeys(fn ($d, $deptId) => [self::deptKey((int) $deptId) => $d->map(fn ($s) => self::ymd($s, 'due_date'))->max()])
            ->all();
        // each department's range in this cabinet (the calendar's "แยกรายตู้"); done = all of its work here finished
        $deptRanges = $rows->groupBy('department_id')->mapWithKeys(fn ($d, $deptId) => [self::deptKey((int) $deptId) => [
            'start' => $d->map(fn ($s) => self::ymd($s, 'start_date'))->filter()->min(),
            'end' => $d->map(fn ($s) => self::ymd($s, 'due_date'))->filter()->max(),
            'done' => $d->every(fn ($s) => $s->assignment_status === CabinetSubtask::ASSIGNMENT_COMPLETED),
        ]])->all();

        return [
            'id' => 'c'.$cabinet->id,
            'text' => $cabinet->cabinet_name,
            'mo' => $cabinet->mo_no,
            'statusId' => $cabinet->boardStatus?->pageId() ?? $this->statusFor($rows, $today),
            'manualStatus' => $cabinet->boardStatus !== null,
            'date' => $rows->map(fn ($s) => self::ymd($s, 'due_date'))->filter()->max(),
            'deptDates' => $deptDates ?: new \stdClass,
            'deptRanges' => $deptRanges ?: new \stdClass,
            'ownDue' => $cabinet->due_date?->format('Y-m-d'),
            'linked' => $this->linked->get($cabinet->id, []),   // from the departments passing work to mine (read only)   // the cabinet's own due date (its record), not its work's
            'free' => ($this->free->get($cabinet->id) ?? collect())->map(fn ($s) => ['id' => 's'.$s->id, 'name' => $s->name, 'task' => $s->cabinetTask->name, 'checklists' => $s->checklists_count,
                'usual' => ($u = $this->usual['t'.$s->cabinet_subtask_template_id] ?? $this->usual['n'.$s->name] ?? null) ? self::deptKey($u) : ''])->values()->all(),
            // the cabinet's dispatched work: Task › Sub Task (department, where it is in the workflow) - even one with no item yet
            'works' => $rows->map(fn ($s) => [
                'id' => 's'.$s->id, 'task' => (string) $s->cabinetTask->name, 'name' => $s->name,
                'deptId' => self::deptKey((int) $s->department_id), 'state' => $s->assignment_status,
            ])->all(),
            // every item of the cabinet (the biggest part of the page): read from the rows as they are, no cast per field
            'checklist' => $rows->flatMap(function ($s) {
                [$deptId, $subId] = [self::deptKey((int) $s->department_id), 's'.$s->id];

                return $s->checklists->map(fn ($c) => $c->getAttributes())->sortBy(fn ($a) => sprintf('%05d-%09d', $a['sequence'] ?? 0, $a['id']))->map(fn ($a) => [
                    'id' => 'k'.$a['id'],
                    'text' => $a['name'],
                    'done' => (bool) $a['is_completed'],
                    'remark' => (string) ($a['remark'] ?? ''),
                    'expected' => ($a['expected_date'] ?? '') !== '' ? substr((string) $a['expected_date'], 0, 10) : null,
                    'deptId' => $deptId,
                    'subId' => $subId,
                ]);
            })->values()->all(),
        ];
    }

    /** Where one department's Sub Tasks of this project are in the accept / start / complete workflow, and what this user may do now. */
    private function workflow(Collection $rows, int $deptId, User $user): array
    {
        $counts = $rows->countBy('assignment_status');   // once, not a pass per question below
        $n = fn (string $status) => (int) ($counts[$status] ?? 0);
        // the workflow actions belong to the department's own members (or Admin / PM, when Settings > กฎการทำงาน lets them act for it)
        $mine = $user->department_id === $deptId || ($user->canDispatchWork() && \App\Models\AppSetting::get('pmo_acts_for_departments'));

        return [
            'waiting' => $n(CabinetSubtask::ASSIGNMENT_ASSIGNED),
            'accepted' => $n(CabinetSubtask::ASSIGNMENT_ACCEPTED),
            'working' => $n(CabinetSubtask::ASSIGNMENT_IN_PROGRESS),
            'done' => $n(CabinetSubtask::ASSIGNMENT_COMPLETED),
            'canAccept' => $mine && $n(CabinetSubtask::ASSIGNMENT_ASSIGNED) > 0,
            // the oldest piece of work still waiting to be accepted (the "รอรับงาน" list: how long it has waited)
            'waitingSince' => ($w = $rows->where('assignment_status', CabinetSubtask::ASSIGNMENT_ASSIGNED)->map(fn ($s) => $s->getAttributes()['assigned_at'] ?? null)->filter()->min()) !== null ? substr((string) $w, 0, 10) : null,
            'canStart' => $mine && $n(CabinetSubtask::ASSIGNMENT_ACCEPTED) > 0,
            'canComplete' => $mine && $n(CabinetSubtask::ASSIGNMENT_IN_PROGRESS) > 0,
        ];
    }

    /** Worst-first: overdue, then all done, then in progress, then accepted, else waiting. Returns the page id of a built-in status. */
    private function statusFor(Collection $rows, \Carbon\Carbon $today): string
    {
        $done = fn ($s) => $s->assignment_status === CabinetSubtask::ASSIGNMENT_COMPLETED;
        $ids = BoardStatus::PAGE_IDS;

        return match (true) {
            $rows->isEmpty() => $ids['todo'],
            $rows->contains(fn ($s) => ! $done($s) && ($due = self::ymd($s, 'due_date')) !== null && self::before($due, $today)) => $ids['late'],
            $rows->every($done) => $ids['done'],
            $rows->contains(fn ($s) => $s->assignment_status === CabinetSubtask::ASSIGNMENT_IN_PROGRESS) => $ids['doing'],
            $rows->contains(fn ($s) => $s->assignment_status === CabinetSubtask::ASSIGNMENT_ACCEPTED) => $ids['accepted'],
            default => $ids['todo'],
        };
    }

    /**
     * The Activity box per project id: the discussion (notes) only - updates people post for everyone involved. Newest 30,
     * oldest first (the order the page expects). PMO's change log is not mixed in (see `logUrl`).
     */
    private function comments(Collection $projectIds, User $user): Collection
    {
        if ($projectIds->isEmpty() || ! $user->allows('data.comments')) {   // Settings > สิทธิ์การใช้งาน
            return collect();
        }

        return ActivityLog::query()->whereIn('project_id', $projectIds)->where('action', 'note')->with('user:id,name,avatar_path', 'attachments')->orderBy('id')->get()
            ->groupBy('project_id')->map(
                fn (Collection $logs) => $logs->take(-30)->values()->map(fn (ActivityLog $l) => [
                    'id' => 'a'.$l->id,
                    'author' => $l->user?->name ?? 'ระบบ',
                    'text' => $l->description,
                    'at' => $l->created_at->getTimestampMs(),
                    'userId' => $l->user_id,
                    'avatar' => $l->user?->avatar_url,
                    'mine' => $l->user_id === $user->id,
                    'edited' => (bool) ($l->changes['edited'] ?? false),
                    'replyTo' => isset($l->changes['reply_to']) ? 'a'.$l->changes['reply_to'] : null,
                    'alert' => empty($l->changes['alert_department_id']) ? null : [
                        'deptId' => self::deptKey((int) $l->changes['alert_department_id']),
                        'acks' => collect($l->changes['acks'] ?? [])->map(fn ($a) => ['name' => $a['name'] ?? '', 'at' => $a['at'] ?? null])->values()->all(),
                        'forMe' => (int) $l->changes['alert_department_id'] === (int) $user->department_id,
                        'acked' => collect($l->changes['acks'] ?? [])->contains('user_id', $user->id),
                    ],
                    'files' => $l->attachments->map(fn ($a) => ['name' => $a->original_name, 'url' => $a->url, 'size' => $a->size_for_humans, 'image' => str_starts_with((string) $a->mime_type, 'image/')])->values()->all(),
                ])
            );
    }
}
