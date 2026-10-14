<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\CabinetSubtask;
use App\Models\Department;
use App\Models\Project;
use App\Services\CommentAttachmentUploader;
use App\Services\DepartmentDashboard;
use App\Services\DepartmentSchedule;
use App\Services\NotificationService;
use App\Services\ProjectEditor;
use App\Services\ProjectModal;
use App\Support\Csv;
use Carbon\Carbon;
use Illuminate\Http\Request;

/**
 * "My Department" calendar/list. Pure read-side view over the existing
 * cabinet_subtasks + Department Assignment data - nothing is stored for it.
 * Accept/start/complete and checklist toggles reuse the existing endpoints.
 * The scoping/query/serialising lives in DepartmentSchedule and ProjectModal.
 */
class MyDepartmentController extends Controller
{
    public function __construct(
        private DepartmentSchedule $schedule,
        private ProjectModal $modal,
    ) {}

    public function index(Request $request)
    {
        $user = $request->user();
        $canViewOthers = $user->canViewOtherDepartments();
        $department = ($canViewOthers || $user->department_id) ? $this->resolveDepartment($request) : null;

        return view('my-department.index', [
            // $department is null in two different cases the view must tell
            // apart: "no department at all" (blocked, see the @if above in
            // the view) vs "PMO viewing ทุกแผนก" (canViewOthers true) - the
            // view already branches on canViewOthers for that reason.
            'department' => $department,
            'canViewOthers' => $canViewOthers,
            'departments' => $canViewOthers ? Department::where('is_active', true)->orderBy('name')->get() : collect(),
            // Project filter - available to everyone, not just PMO:
            // it only narrows further within whatever department scope already
            // applies, same as Status, so it can't leak another department's data.
            'projects' => Project::visibleTo($user)->orderBy('project_no')->get(['id', 'project_no', 'project_name']),
        ]);
    }

    /**
     * JSON for one calendar window (the visible month grid), scoped to a single
     * department on the server. Also returned regardless of window: overdue
     * work (so the list/"เกินกำหนด" filter never hides it) and undated work.
     */
    public function tasks(Request $request)
    {
        [$department, $subtasks, $today] = $this->scheduleQuery($request);
        $user = $request->user();

        return response()->json([
            'department' => $this->departmentRef($department),
            'is_all_departments' => $department === null,
            'today' => $today->format('Y-m-d'),
            'items' => $subtasks->map(fn ($s) => $this->schedule->item($s, $user, $today))->values(),
        ]);
    }

    // ---- Project modal (the wide "โครงการ" dialog opened from a calendar bar) ----

    public function project(Request $request, Project $project)
    {
        $this->authorizeProject($request, $project);

        return response()->json($this->modal->payload($project, $request->user()));
    }

    /** Autosave from the modal: one or more fields of the Project, PMO-level roles only. */
    public function updateProject(Request $request, Project $project, ProjectEditor $editor)
    {
        abort_unless($request->user()->canDispatchWork(), 403, 'เฉพาะผู้ดูแลระบบ (Admin) หรือ Project Manager เท่านั้นที่แก้ไขโครงการได้');

        $editor->update($project, $request->all());

        return response()->json(['project' => $this->modal->block($project->fresh(), $request->user())]);
    }

    /** A comment (optionally with files, optionally sent as an alert to a department) on the Project's Activity. */
    public function storeProjectNote(Request $request, Project $project, CommentAttachmentUploader $uploader, NotificationService $notifications)
    {
        $this->authorizeProject($request, $project);
        $user = $request->user();

        $data = $request->validate([
            'note' => ['required', 'string', 'max:2000'],
            'attachments' => ['nullable', 'array', 'max:5'],
            'attachments.*' => ['file', 'max:20480', 'mimes:'.CommentAttachmentUploader::ALLOWED_MIMES],
            'alert_department_id' => ['nullable', 'integer', 'exists:departments,id'],
        ]);

        $alertTo = ! empty($data['alert_department_id']) ? Department::find($data['alert_department_id']) : null;
        abort_if($alertTo && ! $user->canDispatchWork(), 403, 'เฉพาะผู้ดูแลระบบ (Admin) หรือ Project Manager เท่านั้นที่ส่งแจ้งเตือนไปแผนกได้');

        $log = ActivityLog::record($project->id, $user->id, $project, 'note', $data['note']);
        foreach ($request->file('attachments', []) as $file) {
            $uploader->store($log, $file, $user->id);
        }

        $notified = $alertTo ? $notifications->projectAlert($project, $alertTo, $user, $data['note']) : 0;
        $notifications->commented($project, $user, $log, $alertTo ? \App\Models\User::where('department_id', $alertTo->id)->pluck('id')->all() : []);

        return response()->json([
            'activity' => $this->modal->activityItem($log->load('user', 'attachments')),
            'notified' => $notified,
        ]);
    }

    /**
     * Numbers for the page's "แดชบอร์ด" tab. Department scope comes from resolveDepartment() (the one place that
     * decides what this user may look at), so a department user cannot ask for another department's figures.
     */
    public function dashboard(Request $request, DepartmentDashboard $dashboard)
    {
        $department = $this->resolveDepartment($request);
        $scope = $department ? [$department->id] : null;
        $today = today();

        return response()->json($dashboard->build($scope, $today) + [
            'projects' => $dashboard->projects($scope, $today),
            'department' => $this->departmentRef($department),
        ]);
    }

    /**
     * Same window/department/project/status filters as the screen, as a CSV the
     * department can take to a meeting or into Excel.
     */
    public function export(Request $request)
    {
        [$department, $subtasks, $today] = $this->scheduleQuery($request);
        $user = $request->user();

        $rows = $subtasks->map(fn ($s) => $this->schedule->item($s, $user, $today))->map(fn ($i) => [
            $i['department_name'] ?? '-',
            $i['project_no'],
            $i['project_name'],
            $i['cabinet_mo'],
            $i['task_name'],
            $i['name'],
            $i['owner_name'] ?? '-',
            $i['start_date'] ? \Illuminate\Support\Carbon::parse($i['start_date'])->thaiDate() : '-',
            $i['due_date'] ? \Illuminate\Support\Carbon::parse($i['due_date'])->thaiDate() : '-',
            $i['assignment_status_label'],
            $i['is_overdue'] ? 'เกินกำหนด '.abs($i['days_remaining']).' วัน' : '',
            $i['progress'].'%',
            $i['checklists_completed'].'/'.$i['checklists_total'],
        ]);

        return Csv::download(
            'งานแผนก-'.($department?->name ?? 'ทุกแผนก').'-'.$today->format('Y-m-d').'.csv',
            ['แผนก', 'Project', 'ชื่อโครงการ', 'ตู้ (MO)', 'Task', 'Sub Task', 'ผู้รับผิดชอบ', 'เริ่ม', 'กำหนดส่ง', 'สถานะ', 'เกินกำหนด', 'ความคืบหน้า', 'Checklist เสร็จ/ทั้งหมด'],
            $rows
        );
    }

    /**
     * Sub task detail (with checklist) for the detail modal.
     */
    public function show(Request $request, CabinetSubtask $cabinetSubtask)
    {
        $user = $request->user();

        $this->authorizeSubtask($request, $cabinetSubtask);

        $cabinetSubtask->load('cabinetTask.cabinet.project', 'department', 'checklists.completedBy', 'acceptedBy', 'owner')
            ->loadCount([
                'checklists',
                'checklists as completed_checklists_count' => fn ($q) => $q->where('is_completed', true),
            ]);

        $checklistEditable = $cabinetSubtask->isChecklistEditableBy($user);

        return response()->json([
            'item' => $this->schedule->item($cabinetSubtask, $user, today()) + [
                'department_name' => $cabinetSubtask->department?->name,
                'cabinet_url' => route('cabinets.show', $cabinetSubtask->cabinetTask->cabinet),
                'accepted_by_name' => $cabinetSubtask->acceptedBy?->name,
                'checklist_editable' => $checklistEditable,
                'checklists' => $cabinetSubtask->checklists->map(fn ($c) => [
                    'id' => $c->id,
                    'name' => $c->name,
                    'is_completed' => $c->is_completed,
                    'completed_by_name' => $c->completedBy?->name,
                    'completed_at' => $c->completed_at?->thaiDateTime(),
                    'toggle_url' => route('checklists.toggle', $c),
                ])->values(),
            ],
        ]);
    }

    /**
     * Toggle the signed-in user's personal star on a Sub Task. Same visibility rule as
     * show(): you can only star work you are allowed to see.
     */
    public function toggleStar(Request $request, CabinetSubtask $cabinetSubtask)
    {
        $user = $request->user();

        $this->authorizeSubtask($request, $cabinetSubtask);

        $starred = ! $user->starredSubtasks()->where('cabinet_subtasks.id', $cabinetSubtask->id)->exists();
        $starred ? $user->starredSubtasks()->attach($cabinetSubtask->id) : $user->starredSubtasks()->detach($cabinetSubtask->id);

        return response()->json(['starred' => $starred]);
    }

    /**
     * Validates the window/filters, resolves the department and runs the one schedule
     * query shared by the calendar JSON and the CSV.
     *
     * @return array{0: ?Department, 1: \Illuminate\Support\Collection, 2: Carbon}
     */
    private function scheduleQuery(Request $request): array
    {
        $data = $request->validate([
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
            // 'all' (PMO only, resolveDepartment() enforces that) or a real id -
            // resolved below, so this stays a loose string rule here.
            'department_id' => ['nullable', 'string'],
            'project_id' => ['nullable', 'integer', 'exists:projects,id'],
            'status' => ['nullable', 'in:ASSIGNED,ACCEPTED,IN_PROGRESS,COMPLETED,OVERDUE'],
        ]);

        // null = "ทุกแผนก" (PMO only - resolveDepartment() already enforces
        // canViewOtherDepartments() before this can ever come back null).
        $department = $this->resolveDepartment($request);
        $today = today();

        return [$department, $this->schedule->subtasks($department, $data, $today), $today];
    }

    private function resolveDepartment(Request $request): ?Department
    {
        return $this->schedule->resolveDepartment($request->user(), $request->input('department_id'));
    }

    private function departmentRef(?Department $department): array
    {
        return $department ? ['id' => $department->id, 'name' => $department->name] : ['id' => null, 'name' => 'ทุกแผนก'];
    }

    private function authorizeProject(Request $request, Project $project): void
    {
        abort_unless($this->modal->canOpen($request->user(), $project), 403, 'คุณไม่มีสิทธิ์ดูโครงการนี้');
    }

    private function authorizeSubtask(Request $request, CabinetSubtask $subtask): void
    {
        abort_unless($this->schedule->canViewSubtask($request->user(), $subtask), 403, 'คุณไม่มีสิทธิ์ดูงานของแผนกนี้');
    }
}
