<?php

namespace App\Http\Controllers;

use App\Models\CabinetSubtask;
use App\Models\CabinetSubtaskTemplate;
use App\Models\CabinetTemplate;
use App\Services\SubtaskPairs;
use Illuminate\Http\Request;

/** "เทียบกับ…": which Sub Task shows which other one's checklist next to its own - on the cabinet template (perm:edit.templates) or one cabinet (Admin / PM). */
class TemplatePairController extends Controller
{
    public function __construct(private SubtaskPairs $pairs) {}

    public function update(Request $request, CabinetSubtaskTemplate $subtaskTemplate)
    {
        $data = $request->validate(['sources' => ['present', 'array'], 'sources.*' => ['integer']]);
        $this->pairs->setTemplatePairs($subtaskTemplate, $data['sources']);
        $sources = $subtaskTemplate->pairSources()->with('department:id,name')->get();

        return response()->json([
            'ok' => true,
            'message' => $sources->isEmpty() ? 'ไม่เทียบกับงานใดแล้ว' : 'บันทึกคู่เทียบแล้ว',
            'sources' => $sources->map(fn ($s) => ['id' => $s->id, 'name' => $s->name, 'dept' => (string) $s->department?->name])->values(),
            'cabinets' => $this->pairs->cabinetsOf($subtaskTemplate->taskTemplate->cabinetTemplate)->count(),
        ]);
    }

    /** The cabinet page: change one cabinet's pairs (Admin / PM), the template stays as it is. */
    public function updateCabinet(Request $request, CabinetSubtask $cabinetSubtask)
    {
        abort_unless($request->user()->canDispatchWork(), 403, 'เฉพาะผู้ดูแลระบบ (Admin) หรือ Project Manager เท่านั้นที่แก้คู่เทียบได้');
        $data = $request->validate(['sources' => ['present', 'array'], 'sources.*' => ['integer']]);
        $this->pairs->setCabinetPairs($cabinetSubtask, $data['sources'], $request->user());
        $sources = $cabinetSubtask->pairSources()->with('department:id,name')->get();

        return response()->json([
            'ok' => true,
            'message' => $sources->isEmpty() ? 'ไม่เทียบกับงานใดแล้ว' : 'บันทึกคู่เทียบของตู้นี้แล้ว',
            'sources' => $sources->map(fn ($s) => ['id' => $s->id, 'name' => $s->name, 'dept' => (string) $s->department?->name])->values(),
        ]);
    }

    /** "ใช้คู่เทียบกับตู้เดิม": the cabinets made from this template earlier take its pairs. */
    public function apply(Request $request, CabinetTemplate $cabinetTemplate)
    {
        $n = $this->pairs->applyToCabinets($cabinetTemplate);

        return back()->with('success', $n ? "ใช้คู่เทียบกับตู้เดิมแล้ว {$n} ตู้" : 'ไม่มีตู้เดิมที่ต้องเปลี่ยน');
    }
}
