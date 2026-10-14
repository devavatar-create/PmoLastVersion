<?php

namespace App\Http\Controllers;

use App\Models\CabinetTask;
use App\Services\ProgressService;
use Illuminate\Http\Request;

class CabinetTaskController extends Controller
{
    public function update(Request $request, CabinetTask $cabinetTask)
    {
        $data = $request->validate([
            'start_date' => ['nullable', 'date'],
            'due_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'remark' => ['nullable', 'string'],
        ]);

        $cabinetTask->update($data);

        return back()->with('success', 'บันทึกงานเรียบร้อยแล้ว');
    }

    /**
     * Admin / PM (route: pmo): this cabinet's Task goes to the trash with its Sub Tasks and Checklists (same deleted_at, so
     * a restore brings back exactly them). Only this cabinet's copy - the template it came from is never touched.
     */
    public function destroy(CabinetTask $cabinetTask, ProgressService $progress)
    {
        $cabinet = $cabinetTask->cabinet;
        $name = $cabinetTask->name;
        $cabinetTask->delete();   // a closed project refuses (GuardsClosedProject -> flash error)
        $progress->recalculateCabinet($cabinet->fresh());

        return redirect()->route('cabinets.show', $cabinet)->with('success', "ลบ Task \"{$name}\" แล้ว — กู้คืนได้จากถังขยะ (เทมเพลตไม่เปลี่ยน)");
    }
}
