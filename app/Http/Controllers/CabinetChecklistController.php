<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\CabinetChecklist;
use App\Models\CabinetSubtask;
use App\Services\AutomationService;
use App\Services\ProgressService;
use App\Services\SubtaskAssignmentService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

class CabinetChecklistController extends Controller
{
    /**
     * Add one or more checklist items directly to a cabinet's sub task,
     * independent of whatever template it was created from.
     */
    public function store(Request $request, CabinetSubtask $cabinetSubtask, ProgressService $progressService)
    {
        abort_unless($cabinetSubtask->isChecklistEditableBy($request->user()), 403, 'คุณไม่มีสิทธิ์เพิ่ม Checklist ของงานนี้ (ต้องเป็นแผนกที่รับงานแล้วเท่านั้น)');

        $request->validate([
            'names' => ['required', 'string'],
        ]);

        $names = collect(preg_split('/\r\n|\r|\n/', $request->input('names')))
            ->map(fn ($line) => trim($line))
            ->filter()
            ->values();

        if ($names->isEmpty()) {
            return back()->with('error', 'กรุณากรอกชื่อ Checklist อย่างน้อย 1 รายการ');
        }

        $sequence = $cabinetSubtask->checklists()->max('sequence') + 1;

        $names->each(function (string $name) use ($cabinetSubtask, &$sequence) {
            $cabinetSubtask->checklists()->create([
                'checklist_template_id' => null,
                'name' => $name,
                'is_completed' => false,
                'sequence' => $sequence++,
            ]);
        });

        $progressService->recalculateSubtask($cabinetSubtask);

        return back()->with('success', "เพิ่ม Checklist {$names->count()} รายการเรียบร้อยแล้ว");
    }

    /**
     * Copy every checklist item from another Sub Task in the same cabinet
     * into this one (unchecked) - for Sub Tasks that always need the same
     * checklist (e.g. Cutting & Bending vs Installation of the same part).
     */
    public function copyChecklists(Request $request, CabinetSubtask $cabinetSubtask, ProgressService $progressService)
    {
        abort_unless($cabinetSubtask->isChecklistEditableBy($request->user()), 403, 'คุณไม่มีสิทธิ์เพิ่ม Checklist ของงานนี้ (ต้องเป็นแผนกที่รับงานแล้วเท่านั้น)');

        $data = $request->validate([
            'source_subtask_id' => ['required', 'exists:cabinet_subtasks,id'],
        ]);

        $source = CabinetSubtask::with('checklists', 'cabinetTask.cabinet')->findOrFail($data['source_subtask_id']);
        $target = $cabinetSubtask->loadMissing('cabinetTask.cabinet');

        if ($source->id === $target->id) {
            return $this->copyError($request, 'เลือก Sub Task ต้นทางให้ต่างจากปลายทาง');
        }

        if ($source->cabinetTask->cabinet_id !== $target->cabinetTask->cabinet_id) {
            abort(403);
        }

        if ($source->checklists->isEmpty()) {
            return $this->copyError($request, "Sub Task \"{$source->name}\" ยังไม่มี Checklist ให้คัดลอก");
        }

        $sequence = $target->checklists()->max('sequence') + 1;
        $count = $source->checklists->count();

        // Suppressed: one entry per copied checklist row would spam the
        // timeline - a single consolidated entry replaces it below.
        Model::withoutEvents(function () use ($source, $target, &$sequence) {
            foreach ($source->checklists as $checklist) {
                $target->checklists()->create([
                    'checklist_template_id' => $checklist->checklist_template_id,
                    'name' => $checklist->name,
                    'description' => $checklist->description,
                    'sequence' => $sequence++,
                    'is_completed' => false,
                ]);
            }
        });

        $progressService->recalculateSubtask($target);

        ActivityLog::record(
            $target->cabinetTask->cabinet->project_id,
            auth()->id(),
            $target,
            'checklists_copied',
            "คัดลอก Checklist {$count} รายการจาก \"{$source->name}\" มาที่ \"{$target->name}\""
        );

        $message = "คัดลอก Checklist {$count} รายการเรียบร้อยแล้ว";

        if ($request->wantsJson()) {
            $target = $target->fresh('checklists');
            $task = $target->cabinetTask;
            $cabinet = $task->cabinet;
            $taskCounts = $task->fresh('subtasks.checklists')->checklist_counts;
            $cabinetCounts = $cabinet->fresh('tasks.subtasks.checklists')->checklist_counts;

            return response()->json([
                'message' => $message,
                'checklists' => $target->checklists->map(fn ($c) => [
                    'id' => $c->id,
                    'name' => $c->name,
                    'is_completed' => $c->is_completed,
                    'toggle_url' => route('checklists.toggle', $c),
                    'destroy_url' => route('checklists.destroy', $c),
                    'remark_url' => route('checklists.update-remark', $c),
                ]),
                'subtask' => [
                    'id' => $target->id,
                    'progress' => $target->progress,
                    'status' => $target->status,
                    'completed_checklists' => $target->checklists->where('is_completed', true)->count(),
                    'total_checklists' => $target->checklists->count(),
                ],
                'task' => [
                    'id' => $task->id,
                    'progress' => $task->progress,
                    'status' => $task->status,
                    'completed_checklists' => $taskCounts['completed'],
                    'total_checklists' => $taskCounts['total'],
                ],
                'cabinet' => [
                    'id' => $cabinet->id,
                    'progress' => $cabinet->progress,
                    'status' => $cabinet->status,
                    'completed_checklists' => $cabinetCounts['completed'],
                    'total_checklists' => $cabinetCounts['total'],
                ],
            ]);
        }

        return back()->with('success', $message);
    }

    private function copyError(Request $request, string $message)
    {
        if ($request->wantsJson()) {
            return response()->json(['message' => $message], 422);
        }

        return back()->with('error', $message);
    }

    /**
     * Set/clear this checklist's own remark - independent of the
     * name/copy flows, since it's meant to be filled in afterward
     * (whether the item was typed fresh or copied from another Sub Task).
     */
    public function updateRemark(Request $request, CabinetChecklist $checklist)
    {
        abort_unless($checklist->subtask->isChecklistEditableBy($request->user()), 403, 'คุณไม่มีสิทธิ์แก้ไข Checklist นี้ (ต้องเป็นแผนกที่รับงานแล้วเท่านั้น)');

        $data = $request->validate([
            'remark' => ['nullable', 'string', 'max:1000'],
        ]);

        $checklist->update($data);

        if ($request->wantsJson()) {
            return response()->json([
                'remark' => $checklist->remark,
            ]);
        }

        return back()->with('success', 'บันทึกหมายเหตุเรียบร้อยแล้ว');
    }

    public function toggle(Request $request, CabinetChecklist $checklist, ProgressService $progressService, AutomationService $automation, SubtaskAssignmentService $assignments)
    {
        abort_unless($checklist->subtask->isChecklistEditableBy($request->user()), 403, 'คุณไม่มีสิทธิ์แก้ไข Checklist นี้ (ต้องเป็นแผนกที่รับงานแล้วเท่านั้น)');

        $data = $request->validate([
            'is_completed' => ['required', 'boolean'],
        ]);

        $progressService->toggleChecklist($checklist, $data['is_completed'], $request->user());

        // Admin-defined rules (Settings > Automation) may start/close the Sub Task now. Ticking only -
        // un-ticking never reverts a workflow step.
        $statusChanged = $data['is_completed'] ? $automation->afterChecklistTicked($checklist->subtask, $request->user()) : false;

        $subtask = $checklist->subtask->fresh();
        $task = $subtask->cabinetTask;
        $cabinet = $task->cabinet;

        if ($request->wantsJson()) {
            $checklist->load('completedBy');

            $subtaskCounts = [
                'completed' => $subtask->checklists()->where('is_completed', true)->count(),
                'total' => $subtask->checklists()->count(),
            ];
            $taskCounts = $task->fresh('subtasks.checklists')->checklist_counts;
            $cabinetCounts = $cabinet->fresh('tasks.subtasks.checklists')->checklist_counts;

            return response()->json([
                // present only when a rule moved the Sub Task's workflow status, so the UI can re-render its buttons/badge
                'assignment' => $statusChanged ? $assignments->payload($subtask, $request->user()) : null,
                'checklist' => [
                    'id' => $checklist->id,
                    'is_completed' => $checklist->is_completed,
                    'completed_by_name' => $checklist->completedBy?->name,
                    'completed_at_formatted' => $checklist->completed_at?->thaiDateTime(),
                ],
                'subtask' => [
                    'id' => $subtask->id,
                    'progress' => $subtask->progress,
                    'status' => $subtask->status,
                    'completed_checklists' => $subtaskCounts['completed'],
                    'total_checklists' => $subtaskCounts['total'],
                ],
                'task' => [
                    'id' => $task->id,
                    'progress' => $task->progress,
                    'status' => $task->status,
                    'completed_checklists' => $taskCounts['completed'],
                    'total_checklists' => $taskCounts['total'],
                ],
                'cabinet' => [
                    'id' => $cabinet->id,
                    'progress' => $cabinet->progress,
                    'status' => $cabinet->status,
                    'completed_checklists' => $cabinetCounts['completed'],
                    'total_checklists' => $cabinetCounts['total'],
                ],
            ]);
        }

        return back();
    }

    public function destroy(CabinetChecklist $checklist, ProgressService $progressService)
    {
        $subtask = $checklist->subtask;

        abort_unless($subtask->isChecklistEditableBy(auth()->user()), 403, 'คุณไม่มีสิทธิ์ลบ Checklist นี้ (ต้องเป็นแผนกที่รับงานแล้วเท่านั้น)');

        $checklist->delete();

        $progressService->recalculateSubtask($subtask);

        return back()->with('success', 'ลบ Checklist เรียบร้อยแล้ว');
    }
}
