<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\JobType;
use App\Models\Project;
use App\Models\User;
use App\Services\CommentAttachmentUploader;
use App\Support\Csv;
use Illuminate\Http\Request;

class ProjectController extends Controller
{
    public function index(Request $request)
    {
        $query = $this->filteredProjects($request);

        // Nearest due date first (overdue projects surface at the top). Projects
        // with no due date come after those with one, and finished/cancelled
        // projects sink to the bottom since their due date no longer matters.
        $projects = $query
            ->orderByRaw('CASE WHEN status IN (?, ?) THEN 1 ELSE 0 END', [Project::STATUS_COMPLETED, Project::STATUS_CANCELLED])
            ->orderByRaw('CASE WHEN due_date IS NULL THEN 1 ELSE 0 END')
            ->orderBy('due_date')
            ->latest()
            ->paginate(15)
            ->withQueryString();

        // which departments have dispatched work in each listed project (the coloured dots, as on Planning)
        $deptIds = \App\Models\CabinetSubtask::query()
            ->join('cabinet_tasks', 'cabinet_tasks.id', '=', 'cabinet_subtasks.cabinet_task_id')
            ->join('cabinets', 'cabinets.id', '=', 'cabinet_tasks.cabinet_id')
            ->whereIn('cabinets.project_id', $projects->pluck('id'))
            ->whereNull('cabinet_tasks.deleted_at')->whereNull('cabinets.deleted_at')
            ->whereNotNull('cabinet_subtasks.department_id')
            ->whereIn('cabinet_subtasks.assignment_status', \App\Services\WorkScope::ACTIVE_STATUSES)
            ->distinct()->get(['cabinets.project_id', 'cabinet_subtasks.department_id'])
            ->groupBy('project_id')->map(fn ($rows) => $rows->pluck('department_id')->all());

        return view('projects.index', [
            'projects' => $projects,
            'projectDepartments' => $deptIds,
            'departmentColors' => \App\Services\PlanningBoard::departmentColors(),
            'departmentNames' => \App\Models\Department::pluck('name', 'id'),
            'statuses' => Project::$statuses,
            'filters' => $request->only(['q', 'status', 'priority']),
        ]);
    }

    /**
     * Same search/status filters as the list, as a CSV (all matching rows, not just the page).
     */
    public function export(Request $request)
    {
        $projects = $this->filteredProjects($request)->with('projectManager', 'jobType')->orderBy('project_no')->get();

        $rows = $projects->map(fn (Project $p) => [
            $p->project_no,
            $p->project_name,
            $p->customer_name,
            $p->po_no,
            Project::$statuses[$p->status] ?? $p->status,
            Project::$priorities[$p->priority] ?? $p->priority,
            $p->projectManager?->name,
            $p->jobType?->name,
            $p->start_date?->thaiDate(),
            $p->due_date?->thaiDate(),
            $p->cabinets_count,
            $p->production_progress.'%',
            ['overdue' => 'Delayed', 'at_risk' => 'At Risk'][$p->risk_level] ?? 'On Track',
        ]);

        return Csv::download(
            'โครงการ-'.today()->format('Y-m-d').'.csv',
            ['Project No.', 'ชื่อโครงการ', 'ลูกค้า', 'PO No.', 'สถานะ', 'ความสำคัญ', 'Project Manager', 'ประเภทงาน', 'เริ่ม', 'กำหนดส่ง', 'จำนวนตู้', 'ความคืบหน้าการผลิต', 'ความเสี่ยง'],
            $rows
        );
    }

    private function filteredProjects(Request $request)
    {
        $query = Project::visibleTo($request->user())   // the list and its CSV: only the projects this person may see
            ->withAvg('cabinets', 'progress')
            ->withAvg('projectTasks', 'progress')
            ->withCount('cabinets');

        if ($search = $request->get('q')) {
            $query->where(function ($q) use ($search) {
                $q->where('project_no', 'like', "%{$search}%")
                    ->orWhere('project_name', 'like', "%{$search}%")
                    ->orWhere('customer_name', 'like', "%{$search}%");
            });
        }

        if ($status = $request->get('status')) {
            $query->where('status', $status);
        }

        if (in_array($priority = $request->get('priority'), [Project::PRIORITY_URGENT, Project::PRIORITY_HIGH], true)) {   // the ⚑ ด่วน / ⚑ สูง buttons
            $query->where('priority', $priority);
        }

        return $query;
    }

    public function create()
    {
        return view('projects.create', [
            'users' => $this->managers(),
            'statuses' => Project::$statuses,
            'priorities' => Project::$priorities,
            'jobTypes' => JobType::where('is_active', true)->orderBy('name')->get(),
            'boards' => \App\Models\Board::orderBy('sort')->orderBy('id')->get(),
        ]);
    }

    public function store(Request $request)
    {
        $data = $this->validateData($request);

        $project = Project::create($data);

        return redirect()->route('projects.show', $project)->with('success', 'สร้างโครงการเรียบร้อยแล้ว');
    }

    public function show(Project $project)
    {
        $project->load([
            'projectTasks.owner',
            'cabinets.tasks.subtasks.department', 'cabinets.tasks.subtasks.owner', 'cabinets.cabinetTemplate',
            'projectManager', 'jobType',
        ]);

        $project->cabinets->each(
            fn ($cabinet) => $cabinet->tasks->each(fn ($task) => $task->setRelation('cabinet', $cabinet))
        );

        // Comments: scoped strictly to this Project itself (not its Cabinets, which
        // have their own separate Comment tab) - the user's own written notes.
        $comments = ActivityLog::where('loggable_type', Project::class)
            ->where('loggable_id', $project->id)
            ->where('action', 'note')
            ->with(['user', 'attachments'])
            ->latest()
            ->paginate(30, pageName: 'comment_page');

        return view('projects.show', [
            'project' => $project,
            'projectTaskStatuses' => \App\Models\ProjectTask::$statuses,
            'users' => User::orderBy('name')->get(),
            'cabinetTemplates' => \App\Models\CabinetTemplate::where('is_active', true)->get(),
            // For the Cabinet Quick Detail Panel's Assignment Modal (department
            // select) - same unfiltered query the Cabinet full page/Dispatch page
            // already use, not a new Department query.
            'departments' => \App\Models\Department::orderBy('name')->get(),
            'comments' => $comments,
            'overview' => app(\App\Services\ProjectOverview::class)->build($project, auth()->user()),
        ]);
    }

    public function edit(Project $project)
    {
        return view('projects.edit', [
            'project' => $project,
            'users' => $this->managers($project),
            'statuses' => Project::$statuses,
            'priorities' => Project::$priorities,
            'jobTypes' => JobType::where('is_active', true)->orderBy('name')->get(),
            'boards' => \App\Models\Board::orderBy('sort')->orderBy('id')->get(),
        ]);
    }

    public function update(Request $request, Project $project)
    {
        $data = $this->validateData($request, $project);

        $project->update($data);

        return redirect()->route('projects.show', $project)->with('success', 'บันทึกการแก้ไขโครงการเรียบร้อยแล้ว');
    }

    public function destroy(Project $project)
    {
        $project->delete();

        return redirect()->route('projects.index')->with('success', 'ลบโครงการเรียบร้อยแล้ว');
    }

    /**
     * Manual entry in the project's Activity Log - the replacement for the
     * old single-value Remark field. Append-only: no update/destroy.
     */
    public function storeNote(Request $request, Project $project, CommentAttachmentUploader $uploader)
    {
        $data = $request->validate([
            'note' => ['required', 'string', 'max:2000'],
            'attachments' => ['nullable', 'array', 'max:5'],
            'attachments.*' => ['file', 'max:20480', 'mimes:'.CommentAttachmentUploader::ALLOWED_MIMES],
        ]);

        $log = ActivityLog::record($project->id, auth()->id(), $project, 'note', $data['note']);

        foreach ($request->file('attachments', []) as $file) {
            $uploader->store($log, $file, auth()->id());
        }
        app(\App\Services\NotificationService::class)->commented($project, $request->user(), $log);   // the people following the project hear it

        // Comment tab is client-side (Alpine) state, not reflected in the URL -
        // redirect straight back to it (rather than back()/Referer, which would
        // just land on the page's default tab) so posting a comment doesn't
        // visually bounce the user away from the Comment tab.
        return redirect()->route('projects.show', ['project' => $project, 'tab' => 'comment'])
            ->with('success', 'บันทึกรายการเรียบร้อยแล้ว');
    }

    // Lets a user tag a Project with its own color from My Department's Right
    // Detail Panel (overrides the auto-assigned Calendar color). Purely cosmetic,
    // so deliberately not in Project::activityLoggableFields() - a color swap
    // shouldn't clutter the Activity Log the way a status/priority change does.
    public function updateColor(Request $request, Project $project)
    {
        $data = $request->validate([
            'color' => ['nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'],
        ]);

        $project->update(['color' => $data['color'] ?? null]);

        if ($request->wantsJson()) {
            return response()->json(['color' => $project->color]);
        }

        return back()->with('success', 'อัปเดตสีโครงการเรียบร้อยแล้ว');
    }

    private function validateData(Request $request, ?Project $project = null): array
    {
        // amounts as typed ("1,250,000") -> plain numbers
        $request->merge(collect(['contract_value', 'margin'])->filter(fn ($k) => $request->has($k))->mapWithKeys(fn ($k) => [$k => Project::cleanAmount($request->input($k))])->all());

        $data = $request->validate([
            'project_no' => ['required', 'string', 'max:50', 'unique:projects,project_no,'.($project?->id ?? 'NULL').',id,deleted_at,NULL'],
            'project_name' => ['required', 'string', 'max:255'],
            'customer_name' => ['required', 'string', 'max:255'],
            'customer_phone' => ['nullable', 'string', 'max:50'],
            'contract_value' => ['nullable', 'numeric', 'min:0', 'max:9999999999999'],
            'margin' => ['nullable', 'numeric', 'max:9999999999999'],
            'po_no' => ['nullable', 'string', 'max:100'],
            'sales_order_no' => ['nullable', 'string', 'max:100'],
            'project_owner' => ['nullable', 'string', 'max:255'],
            'sales_person' => ['nullable', 'string', 'max:255'],
            // Admin / PM only (a manager already on the project before this rule may stay when it is edited)
            'project_manager_id' => ['nullable', 'exists:users,id', function (string $attr, mixed $value, \Closure $fail) use ($project) {
                if ($value && (int) $value !== $project?->project_manager_id && ! User::whereKey($value)->whereIn('role', Project::MANAGER_ROLES)->exists()) {
                    $fail('ผู้จัดการโครงการต้องเป็น Admin หรือ Project Manager');
                }
            }],
            'start_date' => ['nullable', 'date'],
            'due_date' => ['nullable', 'date'],
            'description' => ['nullable', 'string'],
            'payment_terms' => ['nullable', 'string', 'max:255'],
            'job_type_id' => ['nullable', 'exists:job_types,id'],
            'board_id' => ['nullable', 'exists:boards,id'],
            // a new project has no status field (it starts as Draft); it is changed on the edit page / Planning
            'status' => [$project ? 'required' : 'nullable', 'in:'.implode(',', array_keys(Project::$statuses))],
            'priority' => ['required', 'in:'.implode(',', array_keys(Project::$priorities))],
        ], ['contract_value.numeric' => 'มูลค่างานต้องเป็นตัวเลข', 'margin.numeric' => 'Margin ต้องเป็นตัวเลข']);

        if (! $project) {
            $data['status'] ??= Project::STATUS_DRAFT;
        }
        // what this person may not see, they do not change either (the old values stay) - Settings > สิทธิ์การใช้งาน
        if (! $request->user()->allows('data.finance')) {
            unset($data['contract_value'], $data['margin']);
        }
        if (! $request->user()->allows('data.contact')) {
            unset($data['customer_phone']);
        }

        return $data;
    }

    /** The people the form offers as Project Manager: Admin / PM, plus whoever already is this project's manager. */
    private function managers(?Project $project = null)
    {
        return User::where(fn ($q) => $q->whereIn('role', Project::MANAGER_ROLES)->where('is_active', true)
            ->when($project?->project_manager_id, fn ($q, $id) => $q->orWhere('id', $id)))->orderBy('name')->get();
    }
}
