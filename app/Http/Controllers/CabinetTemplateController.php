<?php

namespace App\Http\Controllers;

use App\Models\CabinetTemplate;
use Illuminate\Http\Request;

class CabinetTemplateController extends Controller
{
    public function index()
    {
        $templates = CabinetTemplate::withCount('cabinets')
            ->with(['taskTemplates.subtaskTemplates' => fn ($q) => $q->with('department:id,name')->withCount('checklistTemplates')])
            ->orderByDesc('is_default')->orderByDesc('is_active')->orderBy('name')
            ->get();

        return view('cabinet-templates.index', ['templates' => $templates]);
    }

    public function create()
    {
        return view('cabinet-templates.create');
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:50', 'unique:cabinet_templates,code'],
            'description' => ['nullable', 'string'],
        ]);
        $data['is_active'] = true;

        $template = CabinetTemplate::create($data);

        return redirect()->route('cabinet-templates.show', $template)->with('success', 'สร้างเทมเพลตเรียบร้อยแล้ว');
    }

    public function show(CabinetTemplate $cabinetTemplate)
    {
        $cabinetTemplate->load('taskTemplates.subtaskTemplates.checklistTemplates', 'taskTemplates.subtaskTemplates.department',
            'taskTemplates.subtaskTemplates.pairSources', 'taskTemplates.subtaskTemplates.pairReceivers');

        return view('cabinet-templates.show', [
            'template' => $cabinetTemplate,
            'pairCabinets' => app(\App\Services\SubtaskPairs::class)->cabinetsOf($cabinetTemplate)->count(),
            'departments' => \App\Models\Department::orderBy('name')->get(),
        ]);
    }

    public function edit(CabinetTemplate $cabinetTemplate)
    {
        return view('cabinet-templates.edit', ['template' => $cabinetTemplate]);
    }

    public function update(Request $request, CabinetTemplate $cabinetTemplate)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:50', 'unique:cabinet_templates,code,'.$cabinetTemplate->id],
            'description' => ['nullable', 'string'],
            'is_active' => ['sometimes', 'boolean'],
            'is_default' => ['sometimes', 'boolean'],
        ]);
        $data['is_active'] = $request->boolean('is_active');
        $data['is_default'] = $request->boolean('is_default');

        if ($data['is_default']) {
            CabinetTemplate::where('id', '!=', $cabinetTemplate->id)->update(['is_default' => false]);
        }

        $cabinetTemplate->update($data);

        return redirect()->route('cabinet-templates.show', $cabinetTemplate)->with('success', 'บันทึกเทมเพลตเรียบร้อยแล้ว');
    }

    /** The cabinets made from it keep their own tasks and checklists (they are copies) - see TemplateTools::deleteTemplate(). */
    public function destroy(CabinetTemplate $cabinetTemplate, \App\Services\TemplateTools $tools)
    {
        $name = $cabinetTemplate->name;
        $cabinets = $tools->deleteTemplate($cabinetTemplate);

        return redirect()->route('cabinet-templates.index')->with('success', "ลบเทมเพลต \"{$name}\" แล้ว".($cabinets ? " — ตู้ {$cabinets} ตู้ที่สร้างจากเทมเพลตนี้ยังอยู่ครบเหมือนเดิม" : ''));
    }
}
