<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Cabinet;
use App\Models\CabinetSubtask;
use App\Models\CabinetTemplate;
use App\Models\Department;
use App\Models\Project;
use App\Services\CabinetCopyService;
use App\Services\CabinetTemplateService;
use App\Services\CommentAttachmentUploader;
use App\Services\SubtaskAssignmentService;
use Illuminate\Http\Request;

class CabinetController extends Controller
{
    public function index(Request $request)
    {
        $query = Cabinet::with('project')->whereHas('project', fn ($q) => $q->visibleTo($request->user()));   // only the projects this person may see

        if ($status = $request->get('status')) {
            $query->where('status', $status);
        }

        if ($search = $request->get('q')) {
            $query->where(function ($q) use ($search) {
                $q->where('mo_no', 'like', "%{$search}%")
                    ->orWhere('cabinet_name', 'like', "%{$search}%");
            });
        }

        // Nearest due date first (overdue cabinets surface at the top). Cabinets
        // without a due date follow, and completed ones sink to the bottom.
        $cabinets = $query
            ->orderByRaw('CASE WHEN status = ? THEN 1 ELSE 0 END', [Cabinet::STATUS_COMPLETED])
            ->orderByRaw('CASE WHEN due_date IS NULL THEN 1 ELSE 0 END')
            ->orderBy('due_date')
            ->latest()
            ->paginate(20)
            ->withQueryString();

        return view('cabinets.index', [
            'cabinets' => $cabinets,
            'statuses' => Cabinet::$statuses,
            'filters' => $request->only(['q', 'status']),
        ]);
    }

    public function store(Request $request, Project $project, CabinetTemplateService $templateService)
    {
        $data = $request->validate([
            'mo_no' => ['required', 'string', 'max:100', 'unique:cabinets,mo_no,NULL,id,deleted_at,NULL'],
            'cabinet_name' => ['required', 'string', 'max:255'],
            'cabinet_type' => ['nullable', 'string', 'max:100'],
            'size' => ['nullable', 'string', 'max:100'],
            'description' => ['nullable', 'string'],
            'start_date' => ['nullable', 'date'],
            'due_date' => ['nullable', 'date'],
            'expected_completion_date' => ['nullable', 'date', 'before_or_equal:due_date'],
            'cabinet_template_id' => ['nullable', 'exists:cabinet_templates,id'],
        ], [
            'expected_completion_date.before_or_equal' => 'วันคาดว่าจะเสร็จต้องไม่เกินวันครบกำหนด (Due Date)',
        ]);

        $templateId = $data['cabinet_template_id'] ?? CabinetTemplate::where('is_default', true)->value('id');
        unset($data['cabinet_template_id']);

        $data['sequence'] = $project->cabinets()->max('sequence') + 1;

        $cabinet = $project->cabinets()->create($data);

        if ($templateId) {
            $template = CabinetTemplate::with('taskTemplates.subtaskTemplates.checklistTemplates')->find($templateId);
            if ($template) {
                $templateService->applyToCabinet($cabinet, $template);
            }
        }

        return redirect()->route('cabinets.show', $cabinet)->with('success', 'สร้างตู้ไฟฟ้าเรียบร้อยแล้ว');
    }

    public function copy(Request $request, Cabinet $cabinet, CabinetCopyService $copyService)
    {
        $data = $request->validate([
            'mo_no' => ['required', 'string', 'max:100', 'unique:cabinets,mo_no,NULL,id,deleted_at,NULL'],
            'cabinet_name' => ['required', 'string', 'max:255'],
        ]);

        $project = $cabinet->project;

        $newCabinet = $project->cabinets()->create([
            'cabinet_template_id' => $cabinet->cabinet_template_id,
            'mo_no' => $data['mo_no'],
            'cabinet_name' => $data['cabinet_name'],
            'cabinet_type' => $cabinet->cabinet_type,
            'size' => $cabinet->size,
            'quantity' => $cabinet->quantity,
            'description' => $cabinet->description,
            'start_date' => $cabinet->start_date,
            'due_date' => $cabinet->due_date,
            'expected_completion_date' => $cabinet->expected_completion_date,
            'status' => 'not_started',
            'progress' => 0,
            'sequence' => $project->cabinets()->max('sequence') + 1,
        ]);

        $cabinet->loadMissing('tasks.subtasks.checklists');
        $copyService->copyFrom($cabinet, $newCabinet);

        return redirect()->route('projects.show', ['project' => $project, 'tab' => 'cabinets'])
            ->with('success', "คัดลอกตู้ไฟฟ้าเรียบร้อยแล้ว - ตู้ใหม่: {$newCabinet->mo_no}");
    }

    public function show(Request $request, Cabinet $cabinet)
    {
        $cabinet->load([
            'project',
            'tasks.subtasks.checklists.completedBy',
            'tasks.subtasks.department',
            'tasks.subtasks.owner',
            'tasks.subtasks.acceptedBy',
            'tasks.subtasks.startedBy',
            'tasks.subtasks.completedBy',
            'tasks.subtasks.pairSources',   // "แก้คู่เทียบ"
        ]);

        $cabinet->tasks->each(fn ($task) => $task->setRelation('cabinet', $cabinet));

        $comments = ActivityLog::where('loggable_type', Cabinet::class)
            ->where('loggable_id', $cabinet->id)
            ->where('action', 'note')
            ->with(['user', 'attachments'])
            ->latest()
            ->paginate(30, pageName: 'comment_page');

        // ‹ n / N › between the project's cabinets, in the order the project page lists them (by due date)
        $siblings = $cabinet->project->cabinets()->get(['id', 'mo_no', 'due_date'])
            ->sortBy(fn ($c) => [$c->due_date?->format('Y-m-d') ?? '9999', $c->id])->values();
        $at = $siblings->search(fn ($c) => $c->id === $cabinet->id);

        return view('cabinets.show', [
            'cabinet' => $cabinet,
            'siblings' => ['prev' => $at > 0 ? $siblings[$at - 1] : null, 'next' => $siblings[$at + 1] ?? null, 'at' => $at + 1, 'count' => $siblings->count()],
            'subtaskStatuses' => \App\Models\CabinetSubtask::$statuses,
            'departments' => \App\Models\Department::orderBy('name')->get(),
            'users' => \App\Models\User::orderBy('name')->get(),
            'comments' => $comments,
        ]);
    }
    public function edit(Cabinet $cabinet)
    {
        return view('cabinets.edit', [
            'cabinet' => $cabinet,
        ]);
    }

    public function update(Request $request, Cabinet $cabinet)
    {
        $data = $request->validate([
            'mo_no' => ['required', 'string', 'max:100', 'unique:cabinets,mo_no,'.$cabinet->id.',id,deleted_at,NULL'],
            'cabinet_name' => ['required', 'string', 'max:255'],
            'cabinet_type' => ['nullable', 'string', 'max:100'],
            'size' => ['nullable', 'string', 'max:100'],
            'description' => ['nullable', 'string'],
            'start_date' => ['nullable', 'date'],
            'due_date' => ['nullable', 'date'],
            'expected_completion_date' => ['nullable', 'date', 'before_or_equal:due_date'],
        ], [
            'expected_completion_date.before_or_equal' => 'วันคาดว่าจะเสร็จต้องไม่เกินวันครบกำหนด (Due Date)',
        ]);

        $cabinet->update($data);

        return redirect()->route('cabinets.show', $cabinet)->with('success', 'บันทึกข้อมูลตู้ไฟฟ้าเรียบร้อยแล้ว');
    }

    public function destroy(Cabinet $cabinet)
    {
        $project = $cabinet->project;
        $cabinet->delete();

        return redirect()->route('projects.show', $project)->with('success', 'ลบตู้ไฟฟ้าเรียบร้อยแล้ว');
    }

    /**
     * Manual entry in the cabinet's Activity Log - the replacement for the
     * old single-value Remark field. Append-only: no update/destroy.
     */
    public function storeNote(Request $request, Cabinet $cabinet, CommentAttachmentUploader $uploader)
    {
        $data = $request->validate([
            'note' => ['required', 'string', 'max:2000'],
            'attachments' => ['nullable', 'array', 'max:5'],
            'attachments.*' => ['file', 'max:20480', 'mimes:'.CommentAttachmentUploader::ALLOWED_MIMES],
        ]);

        $log = ActivityLog::record($cabinet->project_id, auth()->id(), $cabinet, 'note', $data['note']);

        foreach ($request->file('attachments', []) as $file) {
            $uploader->store($log, $file, auth()->id());
        }

        // Comment tab is client-side (Alpine) state, not reflected in the URL -
        // redirect straight back to it (rather than back()/Referer, which would
        // just land on the page's default tab) so posting a comment doesn't
        // visually bounce the user away from the Comment tab.
        return redirect()->route('cabinets.show', ['cabinet' => $cabinet, 'tab' => 'comment'])
            ->with('success', 'บันทึกรายการเรียบร้อยแล้ว');
    }
}
