<?php

namespace App\Http\Controllers;

use App\Services\PlanningAssets;
use App\Services\PlanningBoard;
use App\Services\PlanningSync;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * /planning: the original Avatar Planning single-page app running on PMO's own data.
 *
 *  - index: a small per-user shell (resources/planning/shell.html, ~17 KB) with the user's board embedded as
 *           `window.__PMO__`; the page's CSS/JS are static, content-hashed files in public/planning-assets (browser-cached)
 *  - data:  the board again as JSON - the page polls it (with an ETag, so "nothing changed" costs a 304)
 *  - sync:  the page's edits, as a list of operations; each one is applied through PMO's own services and rules
 *           (PlanningSync). The answer carries only the projects that were edited, not the whole board.
 *
 * /planning/board is the same Planning page written natively (Blade partials + Alpine, like My Department): it reads
 * the same board (PlanningBoard) and writes through the same operations (PlanningSync, via `sync`).
 *
 * All three answer with a Server-Timing header (php total, board build, query count) so a slow host can be diagnosed
 * from the browser's Network tab instead of guessed at.
 */
class PlanningController extends Controller
{
    public function index(Request $request, PlanningBoard $board)
    {
        $user = $request->user();
        [$root, $timing] = $this->measure(fn () => $board->build($user));

        $config = [
            'profile' => $board->profile($user),
            'root' => $root,
            'homeUrl' => route('planning.board'),
            'csrf' => csrf_token(),
            'urls' => [
                'sync' => route('planning.sync'),
                'data' => route('planning.data'),
                'assign' => route('assignments.index'),
                'newProject' => route('projects.create'),
            ],
            'generatedAt' => now()->toIso8601String(),
        ];

        // The data holds user-written text (project names, comments), and it is placed inside a <script>: escape
        // < > & ' " as \uXXXX so nothing in it can ever close the tag or break out of the JSON.
        $json = json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR);

        // the generated shell if it exists (run `php artisan planning:build`), else the one-file source page
        $shell = resource_path(PlanningAssets::SHELL);
        $template = file_get_contents(is_file($shell) ? $shell : resource_path(PlanningAssets::SOURCE));
        // asset base first, the user's data LAST: nothing inside the data can then be mistaken for a placeholder
        $html = str_replace('__PLANNING_ASSETS__', rtrim(asset('planning-assets'), '/'), $template);
        $html = str_replace('/*__PMO_CONFIG__*/null', $json, $html);

        return $this->timed(response($html)->header('Content-Type', 'text/html; charset=UTF-8'), $timing);
    }

    /**
     * One cabinet's record and every one of its sub tasks the user may see (the expanded cabinet row on the page): dates,
     * department, workflow state, the person doing it, remark. PMO roles also get the sub tasks nobody was given yet.
     */
    public function cabinet(Request $request, \App\Models\Cabinet $cabinet)
    {
        $user = $request->user();
        abort_unless(\App\Services\WorkScope::canSeeProject($cabinet->project, $user), 403, 'คุณไม่มีสิทธิ์ดูตู้นี้');

        $subtasks = \App\Models\CabinetSubtask::query()
            ->whereHas('cabinetTask', fn ($q) => $q->where('cabinet_id', $cabinet->id))
            ->when(! $user->canSeeAllWork(), fn ($q) => $q->where('department_id', $user->department_id))
            ->when(! $user->canDispatchWork(), fn ($q) => $q->whereNotNull('department_id'))
            ->with('cabinetTask:id,name,sequence', 'owner:id,name')->withCount(['checklists', 'checklists as checklists_done' => fn ($q) => $q->where('is_completed', true)])
            ->get()->sortBy(fn ($s) => sprintf('%05d-%05d-%07d', $s->cabinetTask->sequence ?? 0, $s->sequence ?? 0, $s->id))->values();

        return response()->json([
            'cabinet' => [
                'id' => 'c'.$cabinet->id, 'startDate' => $cabinet->start_date?->format('Y-m-d'), 'dueDate' => $cabinet->due_date?->format('Y-m-d'),
                'expectedDate' => $cabinet->expected_completion_date?->format('Y-m-d'), 'quantity' => $cabinet->quantity,
                'type' => (string) $cabinet->cabinet_type, 'size' => (string) $cabinet->size, 'description' => (string) $cabinet->description, 'remark' => (string) $cabinet->remark,
                'url' => route('cabinets.show', $cabinet),
            ],
            'subtasks' => $subtasks->map(fn ($s) => [
                'id' => 's'.$s->id, 'task' => $s->cabinetTask->name, 'name' => $s->name,
                'deptId' => $s->department_id ? PlanningBoard::deptKey($s->department_id) : '', 'state' => $s->assignment_status,
                'startDate' => $s->start_date?->format('Y-m-d'), 'dueDate' => $s->due_date?->format('Y-m-d'),
                'ownerId' => $s->owner_id, 'remark' => (string) $s->remark,
                'checklists' => $s->checklists_count, 'checklistsDone' => $s->checklists_done,
                'editable' => $user->canDispatchWork(),   // dates / person / remark: Admin / PM
            ])->all(),
        ]);
    }

    /**
     * Printable work order ("ใบสั่งงาน"): one department's unfinished work due on one day (and anything of theirs already late),
     * by project and cabinet, with its checklist as boxes to tick on paper. Same visibility rule as the page.
     */
    public function workOrder(Request $request)
    {
        $user = $request->user();
        $data = $request->validate(['dept' => ['required', 'string'], 'date' => ['required', 'date_format:Y-m-d']]);
        abort_unless(preg_match('/^d_(\d+)$/', $data['dept'], $m), 422);
        $department = \App\Models\Department::findOrFail((int) $m[1]);
        $scope = \App\Services\DepartmentDashboard::scopeFor($user);
        abort_unless($scope === null || in_array($department->id, $scope, true), 403, 'คุณไม่มีสิทธิ์ดูงานของแผนกนี้');

        $rows = \App\Models\CabinetSubtask::query()
            ->where('department_id', $department->id)
            ->whereIn('assignment_status', [\App\Models\CabinetSubtask::ASSIGNMENT_ASSIGNED, \App\Models\CabinetSubtask::ASSIGNMENT_ACCEPTED, \App\Models\CabinetSubtask::ASSIGNMENT_IN_PROGRESS])
            ->whereDate('due_date', '<=', $data['date'])
            ->with('cabinetTask.cabinet.project', 'checklists')
            ->orderBy('due_date')->get()
            ->groupBy(fn ($s) => $s->cabinetTask->cabinet->project_id);

        return view('planning.work-order', ['department' => $department, 'date' => \Carbon\Carbon::parse($data['date']), 'groups' => $rows, 'user' => $user]);
    }

    /** The extra board asked for in ?board=, or null for the main one (an unknown id is the main board too). */
    private function boardOf(Request $request): ?\App\Models\Board
    {
        return ($id = $request->integer('board')) ? \App\Models\Board::find($id) : null;
    }

    /** The native (Blade + Alpine) Planning page: no embedded SPA, the board is handed to one Alpine component. */
    public function board(Request $request, PlanningBoard $board)
    {
        $user = $request->user();
        $current = $this->boardOf($request);
        [$root, $timing] = $this->measure(fn () => $board->build($user, null, $current?->id));
        $profile = $board->profile($user);

        $response = response()->view('planning.board', ['noDepartment' => ! $user->canSeeAllWork() && ! $user->department_id, 'config' => [
            'boardId' => PlanningBoard::BOARD_ID,
            'root' => $root,
            'today' => today()->format('Y-m-d'),
            'canEdit' => $user->canDispatchWork(),          // project data and dates: Admin / Project Manager only (departments do their work: accept, checklists, fields, comments)
            'isPmo' => $user->canDispatchWork(),            // admin / PM only: delete, alert a department, dispatch; the server enforces it
            'myDept' => $profile['dept_id'],                // '' for PMO roles (they see every department)
            'userName' => $user->name,
            'userId' => $user->id,                          // keys the browser-local "★" list
            'boardNo' => $current?->id,                    // null = the main board
            'boards' => collect([['id' => 'main', 'name' => 'PMO', 'url' => route('planning.board')]])
                ->concat(\App\Models\Board::orderBy('sort')->orderBy('id')->get()->map(fn ($b) => ['id' => 'b'.$b->id, 'name' => $b->name, 'url' => route('planning.board', ['board' => $b->id])]))->all(),
            'mine' => $board->mineProjectIds($user),         // project ids for the "ของฉัน" button
            'me' => ['id' => $user->id, 'name' => $user->name, 'avatar' => $user->avatar_url, 'deptId' => $user->department_id ? PlanningBoard::deptKey($user->department_id) : ''],
            'canTrash' => $user->canManageTrash(),
            // Settings > สิทธิ์การใช้งาน: what the page offers (the server leaves the data out and refuses the change anyway)
            'perms' => ['finance' => $user->allows('data.finance'), 'contact' => $user->allows('data.contact'), 'files' => $user->allows('data.files'),
                'comments' => $user->allows('data.comments'), 'export' => $user->allows('page.export'), 'projects' => $user->allows('page.projects')],
            // in-page trash and the PM list of the "new project" window (PMO roles only)
            'trash' => $user->canManageTrash() ? \App\Models\Project::onlyTrashed()->latest('deleted_at')->limit(100)->get(['id', 'project_no', 'project_name', 'deleted_at'])
                ->map(fn ($p) => ['id' => 'p'.$p->id, 'so' => $p->project_no, 'title' => $p->project_name, 'at' => $p->deleted_at->getTimestampMs()])->all() : [],
            // the templates a new cabinet can start from, with their sub tasks (to hand to departments in the same window)
            'templates' => $user->canDispatchWork() ? \App\Models\CabinetTemplate::where('is_active', true)->with(['taskTemplates.subtaskTemplates' => fn ($q) => $q->withCount('checklistTemplates')])->orderByDesc('is_default')->orderBy('name')->get()
                ->map(fn ($t) => ['id' => $t->id, 'name' => $t->name, 'default' => (bool) $t->is_default,
                    'subtasks' => $t->taskTemplates->sortBy('sequence')->flatMap(fn ($task) => $task->subtaskTemplates->sortBy('sequence')->map(fn ($s) => [
                        'id' => $s->id, 'name' => $s->name, 'task' => $task->name, 'deptId' => $s->department_id ? PlanningBoard::deptKey($s->department_id) : '',
                        'checklists' => $s->checklist_templates_count,
                    ]))->values()->all()])->all() : [],
            'jobTypes' => \App\Models\JobType::where('is_active', true)->orderBy('name')->get(['id', 'name'])->all(),
            'pmoStatuses' => \App\Models\Project::$statuses,
            // who can be the person doing a sub task (people of its department, or PMO roles)
            'staff' => \App\Models\User::where('is_active', true)->orderBy('name')->get(['id', 'name', 'department_id', 'role'])
                ->map(fn ($u) => ['id' => $u->id, 'name' => $u->name, 'deptId' => $u->department_id ? PlanningBoard::deptKey($u->department_id) : '', 'pmo' => $u->canDispatchWork()])->all(),
            'urlsCabinet' => route('planning.cabinet', ['cabinet' => '__ID__']),
            'urlsCabinetPage' => route('cabinets.show', ['cabinet' => '__ID__']),   // the cabinet's own page (the full checklist editor)
            // a project's files (the same file manager as the project page, opened from the project window)
            'urlsFiles' => [
                'tree' => route('project-files.tree', ['project' => '__PID__']), 'index' => route('project-files.index', ['project' => '__PID__']),
                'upload' => route('project-files.upload', ['project' => '__PID__']), 'ops' => route('project-files.ops', ['project' => '__PID__']),
                'zip' => route('project-files.zip', ['project' => '__PID__']), 'versions' => route('project-files.versions', ['file' => '__ID__']),
            ],
            'managers' => $user->canDispatchWork() ? \App\Models\User::whereIn('role', [\App\Models\User::ROLE_ADMIN, \App\Models\User::ROLE_PROJECT_MANAGER])->where('is_active', true)->orderBy('name')->get(['id', 'name'])->all() : [],
            'people' => $this->mentionablePeople($user),     // for the @ picker (the server re-checks every tag)
            'open' => $request->integer('project') ?: null,  // a notification's link: open this project's window on load
            // ... and that department's window (?stage=d_5), and with ?accept=1 (the push notice's "รับงาน") ask to accept its work
            'openStage' => preg_match('/^d_\d+$/', (string) $request->query('stage')) ? (string) $request->query('stage') : null,
            'autoAccept' => $request->boolean('accept'),
            'urls' => [
                'sync' => route('planning.sync', array_filter(['board' => $current?->id])),
                'comment' => route('planning.comment', array_filter(['board' => $current?->id])),
                'data' => route('planning.data', array_filter(['board' => $current?->id])),
                'assign' => route('assignments.index'),
                'newProject' => route('projects.create', array_filter(['board' => $current?->id])),
                'home' => route('planning.board'),
                'export' => route('my-department.export'),
                'workOrder' => route('planning.work-order'),
                'settings' => route('settings.index'),
            ],
        ]]);

        return $this->timed($response, $timing);
    }

    /**
     * The people the @ picker offers: PMO roles may tag anyone active; everyone else only their own department and the
     * PMO roles (PlanningSync::mentionTargets enforces the same rule - and project access - when the comment is sent).
     */
    private function mentionablePeople($user): array
    {
        return \App\Models\User::query()->where('is_active', true)->where('id', '!=', $user->id)
            ->when(! $user->canViewOtherDepartments() && ! \App\Models\AppSetting::get('cross_mention'), fn ($q) => $q->where(fn ($w) => $w
                ->whereIn('role', [\App\Models\User::ROLE_ADMIN, \App\Models\User::ROLE_PROJECT_MANAGER])
                ->when($user->department_id, fn ($d) => $d->orWhere('department_id', $user->department_id))))
            ->with('department:id,name')->orderBy('name')->limit(300)->get(['id', 'name', 'role', 'department_id'])
            ->map(fn ($u) => ['id' => $u->id, 'name' => $u->name, 'dept' => $u->department?->name])->all();
    }

    public function data(Request $request, PlanningBoard $board)
    {
        $boardId = $this->boardOf($request)?->id;
        // the stamp is taken BEFORE building: a change made while building shows up as a new stamp on the next poll
        [$etag, $timing] = $this->measure(fn () => '"'.$board->stamp($request->user(), $boardId).'"');

        // unchanged since the page's last copy: no body and - the point - nothing built (the page sends the ETag it holds)
        if (trim((string) $request->header('If-None-Match')) === $etag) {
            return $this->timed(response('', 304)->header('ETag', $etag), $timing);
        }

        [$root, $timing] = $this->measure(fn () => $board->build($request->user(), null, $boardId));
        $json = json_encode(['root' => $root], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        return $this->timed(response($json)->header('Content-Type', 'application/json')->header('ETag', $etag), $timing);
    }

    public function sync(Request $request, PlanningSync $sync, PlanningBoard $board)
    {
        $data = $request->validate(['ops' => ['required', 'array', 'max:200']]);
        $user = $request->user();

        $results = $sync->apply($user, $data['ops']);

        return $this->answer($user, $board, $results, $this->touchedProjectIds($data['ops']), $sync->created());
    }

    /** A comment with photos / files: same rules as a plain comment (PlanningSync), the files hang on the note it wrote. */
    public function comment(Request $request, PlanningSync $sync, PlanningBoard $board, \App\Services\CommentAttachmentUploader $uploader)
    {
        $data = $request->validate([
            'taskId' => ['required', 'string'],
            'text' => ['nullable', 'string', 'max:2000'],
            'alertDept' => ['nullable', 'string'],
            'mentions' => ['nullable', 'array'],
            'replyTo' => ['nullable', 'string'],
            'files' => ['required', 'array', 'min:1', 'max:5'],
            'files.*' => ['file', 'max:20480', 'mimes:'.\App\Services\CommentAttachmentUploader::ALLOWED_MIMES],
        ]);
        $user = $request->user();

        $op = ['type' => 'comment', 'taskId' => $data['taskId'], 'text' => trim((string) ($data['text'] ?? '')) ?: '(แนบไฟล์)',
            'alertDept' => $data['alertDept'] ?? null, 'mentions' => $data['mentions'] ?? [], 'replyTo' => $data['replyTo'] ?? null];
        $results = $sync->apply($user, [$op]);

        if ($results[0]['ok'] && ($note = $sync->lastNote())) {
            foreach ($request->file('files') as $file) {
                $uploader->store($note, $file, $user->id);
            }
        }

        return $this->answer($user, $board, $results, $this->touchedProjectIds([$op]));
    }

    /**
     * Only the projects that were edited come back (a refused edit is part of that: the page needs the true copy to bounce back
     * to). `removed` = asked for but not there any more (deleted, or not visible to this user). If any operation does not say
     * which project it is about, answer with the whole board to stay correct.
     */
    private function answer($user, PlanningBoard $board, array $results, ?array $touched, ?int $created = null)
    {
        [$payload, $timing] = $this->measure(function () use ($board, $user, $touched) {
            if ($touched === null) {
                return ['root' => $board->build($user, null, $this->boardOf(request())?->id)];
            }
            $tasks = $board->tasks($user, $touched);
            $present = array_column($tasks, 'id');

            return [
                'partial' => true,
                'tasks' => $tasks,
                'removed' => array_values(array_diff(array_map(fn ($id) => 'p'.$id, $touched), $present)),
            ];
        });

        return $this->timed(response()->json(['results' => $results] + ($created ? ['created' => 'p'.$created] : []) + $payload), $timing);
    }

    /** @return list<int>|null project ids the operations are about; null when any operation cannot be tied to one */
    private function touchedProjectIds(array $ops): ?array
    {
        $ids = [];
        foreach ($ops as $op) {
            if (! is_array($op)) {
                continue;   // garbage: refused by PlanningSync, touches nothing
            }
            if (! isset($op['taskId']) || ! is_string($op['taskId']) || ! preg_match('/^p(\d+)$/', $op['taskId'], $m)) {
                return null;
            }
            $ids[(int) $m[1]] = true;
        }

        return array_keys($ids);
    }

    /** @return array{0: mixed, 1: array{build: float, queries: int}} */
    private function measure(callable $work): array
    {
        $queries = 0;
        DB::listen(function () use (&$queries) { $queries++; });
        $t = microtime(true);
        $result = $work();
        $ms = (microtime(true) - $t) * 1000;

        return [$result, ['build' => $ms, 'queries' => $queries]];
    }

    private function timed($response, array $timing)
    {
        $start = defined('LARAVEL_START') ? LARAVEL_START : ($_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true));
        $response->headers->set('Server-Timing', sprintf('app;dur=%.0f, build;dur=%.0f, db;desc="%d queries"', (microtime(true) - $start) * 1000, $timing['build'], $timing['queries']));

        return $response;
    }
}
