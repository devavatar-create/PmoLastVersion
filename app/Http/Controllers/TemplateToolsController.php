<?php

namespace App\Http\Controllers;

use App\Exceptions\PlanningRejected;
use App\Models\CabinetSubtaskTemplate;
use App\Models\CabinetTaskTemplate;
use App\Models\CabinetTemplate;
use App\Services\TemplateTools;
use App\Support\Csv;
use Illuminate\Http\Request;

/** Template tools on the template pages (Admin / PM - route middleware 'pmo'): send new items to cabinets, duplicate, Excel. */
class TemplateToolsController extends Controller
{
    public function __construct(private TemplateTools $tools) {}

    public function syncPreview(CabinetTemplate $cabinetTemplate)
    {
        return response()->json(['cabinets' => $this->tools->syncPreview($cabinetTemplate)]);
    }

    public function syncApply(Request $request, CabinetTemplate $cabinetTemplate)
    {
        $data = $request->validate(['cabinet_ids' => ['required', 'array', 'min:1'], 'cabinet_ids.*' => ['integer']]);
        $r = $this->tools->syncApply($cabinetTemplate, $request->user(), $data['cabinet_ids']);

        return response()->json(['ok' => true, 'message' => "เพิ่ม {$r['added']} รายการใน {$r['cabinets']} ตู้แล้ว"] + $r);
    }

    public function duplicateTemplate(Request $request, CabinetTemplate $cabinetTemplate)
    {
        $copy = $this->tools->duplicateTemplate($cabinetTemplate, $request->user());

        return redirect()->route('cabinet-templates.show', $copy)->with('success', "ทำสำเนาเทมเพลตแล้ว — {$copy->name}");
    }

    public function duplicateTask(Request $request, CabinetTaskTemplate $taskTemplate)
    {
        $this->tools->duplicateTask($taskTemplate, $request->user());

        return redirect()->route('cabinet-templates.show', $taskTemplate->cabinet_template_id)->with('success', "ทำสำเนา Task \"{$taskTemplate->name}\" แล้ว (อยู่ท้ายสุด)");
    }

    public function duplicateSubtask(Request $request, CabinetSubtaskTemplate $subtaskTemplate)
    {
        $this->tools->duplicateSubtask($subtaskTemplate, $request->user());

        return redirect()->route('cabinet-templates.show', $subtaskTemplate->taskTemplate->cabinet_template_id)->with('success', "ทำสำเนา Sub Task \"{$subtaskTemplate->name}\" แล้ว");
    }

    public function export(CabinetTemplate $cabinetTemplate)
    {
        return Csv::download('เทมเพลต-'.$cabinetTemplate->code.'.csv', TemplateTools::CSV_HEADER, $this->tools->exportRows($cabinetTemplate));
    }

    public function import(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'file' => ['required', 'file', 'max:5120', 'mimetypes:text/csv,text/plain,application/csv,application/vnd.ms-excel,text/tab-separated-values'],
        ], ['file.mimetypes' => 'ใช้ไฟล์ CSV (Excel: บันทึกเป็น "CSV UTF-8")']);
        try {
            $r = $this->tools->import($data['file'], $data['name'], $request->user());
        } catch (PlanningRejected $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('cabinet-templates.show', $r['template'])
            ->with('success', 'นำเข้าเทมเพลตแล้ว'.($r['warnings'] ? ' — '.implode(' · ', array_slice($r['warnings'], 0, 5)) : ''));
    }
}
