<?php

namespace App\Http\Controllers;

use App\Models\CabinetSubtask;
use App\Models\DateLimitRule;
use App\Models\Department;
use App\Models\Project;
use App\Services\ProgressService;
use App\Services\SubtaskAssignmentService;
use App\Services\WorkScope;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class CabinetSubtaskController extends Controller
{
    public function __construct(private SubtaskAssignmentService $assignments) {}

    public function update(Request $request, CabinetSubtask $cabinetSubtask)
    {
        $data = $request->validate([
            'status' => ['required', 'in:'.implode(',', array_keys(CabinetSubtask::$statuses))],
            'department_id' => ['nullable', 'exists:departments,id'],
            'owner_id' => ['nullable', 'exists:users,id'],
            'start_date' => ['nullable', 'date'],
            'due_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'remark' => ['nullable', 'string'],
        ]);

        // Date limit rules (Settings > Automation): checked BEFORE anything is saved, against the department
        // this Sub Task will have after this request, and only when the due date is actually changing - so an
        // unrelated edit (remark, owner) is never blocked because some other department's date moved.
        if (! empty($data['due_date']) && $data['due_date'] !== $cabinetSubtask->due_date?->format('Y-m-d')) {
            $targetDepartment = $request->has('department_id')
                ? ($data['department_id'] ? (int) $data['department_id'] : null)
                : $cabinetSubtask->department_id;

            if ($message = DateLimitRule::violationFor($cabinetSubtask->cabinetTask->cabinet_id, $targetDepartment, $data['due_date'])) {
                throw ValidationException::withMessages(['due_date' => $message]);
            }
        }

        // Department goes through the assignment service so the lock rule
        // (no change once accepted) and its activity log apply here too.
        if ($request->has('department_id')) {
            $this->assignments->changeDepartment(
                $cabinetSubtask,
                $data['department_id'] ? (int) $data['department_id'] : null,
                $request->user()
            );
        }
        unset($data['department_id']);

        $dueBefore = $cabinetSubtask->due_date?->format('Y-m-d');
        $cabinetSubtask->update($data);
        if (array_key_exists('due_date', $data) && ($data['due_date'] ?: null) !== $dueBefore) {
            app(\App\Services\NotificationService::class)->dueChanged(collect([$cabinetSubtask->fresh('cabinetTask.cabinet.project')]), $request->user());
        }

        // Cabinet Quick Detail Panel's Assignment Modal submits this same
        // endpoint via fetch() (department + dates + remark in one request,
        // exactly like the Cabinet page's own edit-subtask modal) and needs a
        // JSON reply to update in place without a reload - respond() already
        // falls back to back()->with(...) for the existing non-JSON form post.
        return $this->respond($request, $cabinetSubtask, 'บันทึกงานย่อยเรียบร้อยแล้ว');
    }

    /**
     * Admin / PM (route: pmo): this cabinet's Sub Task goes to the trash with its Checklists. Only this cabinet's copy - the
     * template it came from is never touched.
     */
    public function destroy(CabinetSubtask $cabinetSubtask, ProgressService $progress)
    {
        $task = $cabinetSubtask->cabinetTask;
        $name = $cabinetSubtask->name;
        $cabinetSubtask->delete();   // a closed project refuses (GuardsClosedProject -> flash error)
        $progress->recalculateTask($task->fresh());

        return redirect()->route('cabinets.show', $task->cabinet_id)->with('success', "ลบ Sub Task \"{$name}\" แล้ว — กู้คืนได้จากถังขยะ (เทมเพลตไม่เปลี่ยน)");
    }

    public function updateDepartment(Request $request, CabinetSubtask $cabinetSubtask)
    {
        $data = $request->validate([
            'department_id' => ['nullable', 'integer', 'exists:departments,id'],
        ]);

        $changed = $this->assignments->changeDepartment(
            $cabinetSubtask,
            $data['department_id'] ?? null,
            $request->user()
        );

        return $this->respond($request, $cabinetSubtask, $changed ? 'เปลี่ยนแผนกเรียบร้อย' : 'ไม่มีการเปลี่ยนแปลง');
    }

    /** Dispatch several sub tasks to one department in one go (cabinet page ticks, or a whole step on the project page). */
    public function bulkDepartment(Request $request)
    {
        $data = $request->validate([
            'subtask_ids' => ['required', 'array', 'min:1', 'max:1000'],
            'subtask_ids.*' => ['integer'],
            'department_id' => ['nullable', 'integer', 'exists:departments,id'],
        ]);

        // no project in the URL, so the visibility middleware cannot see it: every project named here must be open to this person
        $projects = Project::whereHas('cabinets.tasks.subtasks', fn ($q) => $q->whereKey($data['subtask_ids']))->get();
        abort_if($projects->contains(fn ($p) => ! WorkScope::canSeeProject($p, $request->user())), 403, 'คุณไม่มีสิทธิ์เปิดโครงการนี้');

        $result = $this->assignments->changeDepartments($data['subtask_ids'], $data['department_id'] ?? null, $request->user());
        $changed = count($result['changed']);
        $department = isset($data['department_id']) ? Department::find($data['department_id'])?->name : null;
        $message = $changed
            ? ($department ? "จ่ายงาน {$changed} งานให้แผนก {$department} แล้ว" : "ยกเลิกการจ่ายงาน {$changed} งานแล้ว")
            : 'ไม่มีการเปลี่ยนแปลง';
        if ($result['locked']) {
            $message .= " · ข้าม {$result['locked']} งานที่แผนกรับไปแล้ว (เปลี่ยนแผนกไม่ได้)";
        }

        $departments = Department::orderBy('name')->get();

        return response()->json([
            'message' => $message,
            'changed' => $changed,
            'locked' => $result['locked'],
            'rows' => collect($result['changed'])->map(fn ($s) => [
                'assignment' => $this->assignments->payload($s, $request->user()),
                'html' => view('cabinets.partials._assignment', ['subtask' => $s, 'departments' => $departments])->render(),
            ])->values(),
        ]);
    }

    public function accept(Request $request, CabinetSubtask $cabinetSubtask)
    {
        $this->assignments->accept($cabinetSubtask, $request->user());

        return $this->respond($request, $cabinetSubtask, 'รับงานเรียบร้อย');
    }

    public function start(Request $request, CabinetSubtask $cabinetSubtask)
    {
        $this->assignments->start($cabinetSubtask, $request->user());

        return $this->respond($request, $cabinetSubtask, 'เริ่มงานเรียบร้อย');
    }

    public function complete(Request $request, CabinetSubtask $cabinetSubtask)
    {
        $this->assignments->complete($cabinetSubtask, $request->user());

        return $this->respond($request, $cabinetSubtask, 'บันทึกงานเสร็จเรียบร้อย');
    }

    private function respond(Request $request, CabinetSubtask $subtask, string $message)
    {
        if ($request->expectsJson()) {
            return response()->json([
                'message' => $message,
                'assignment' => $this->assignments->payload($subtask, $request->user()),
                'html' => view('cabinets.partials._assignment', [
                    'subtask' => $subtask,
                    'departments' => Department::orderBy('name')->get(),
                ])->render(),
            ]);
        }

        return back()->with('success', $message);
    }
}
