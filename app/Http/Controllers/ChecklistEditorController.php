<?php

namespace App\Http\Controllers;

use App\Exceptions\PlanningRejected;
use App\Exceptions\ProjectLockedException;
use App\Services\ChecklistEditor;
use Illuminate\Http\Request;

/** JSON behind the checklist editor (x-checklist-editor): {kind} = subtask (a cabinet's) | template (a cabinet template's). */
class ChecklistEditorController extends Controller
{
    public function __construct(private ChecklistEditor $editor) {}

    public function show(Request $request, string $kind, int $id)
    {
        $owner = $this->owner($kind, $id);
        // a cabinet's list: only for whoever may see that work (same rule as every other page); templates are open to all
        abort_if($kind === 'subtask' && ! $request->user()->canSeeAllWork()
            && ! \App\Services\WorkScope::projectSubtasks($owner->cabinetTask->cabinet->project, $request->user())->whereKey($owner->id)->exists(), 403);

        return response()->json($request->boolean('targets')
            ? $this->editor->targets($kind, $owner)
            : $this->editor->state($kind, $owner, $request->user()));
    }

    public function apply(Request $request, string $kind, int $id)
    {
        $op = $request->validate(['type' => ['required', 'string', 'max:20']]) + $request->except('_token');
        try {
            return response()->json(['ok' => true] + $this->editor->apply($kind, $this->owner($kind, $id), $request->user(), $op));
        } catch (PlanningRejected $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 422);
        } catch (ProjectLockedException $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 423);
        }
    }

    /**
     * The cabinet page asks every 15 s (and right after a change) whether any list of the cabinet moved: only the stamp when
     * nothing did, else every list again - so a list shared with another Sub Task (SharedChecklist) or ticked by someone
     * else shows without a reload.
     */
    public function live(Request $request, \App\Models\Cabinet $cabinet)
    {
        $version = $this->editor->cabinetVersion($cabinet);

        return response()->json(['version' => $version]
            + ($request->query('v') === $version ? [] : $this->editor->cabinetLists($cabinet)));
    }

    private function owner(string $kind, int $id)
    {
        abort_unless(in_array($kind, ['subtask', 'template'], true), 404);

        return $this->editor->owner($kind, $id) ?? abort(404, 'ไม่พบรายการนี้ (อาจถูกลบไปแล้ว)');
    }
}
