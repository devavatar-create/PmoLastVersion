<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\DepartmentLink;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Settings > การส่งต่องาน (admin routes): which department's checklist the next one follows, e.g. จัดซื้อ → คลัง → Production. */
class HandoffController extends Controller
{
    public function index()
    {
        return view('settings.handoffs', [
            'links' => DepartmentLink::with('from:id,name', 'to:id,name')->orderBy('id')->get(),
            'departments' => Department::where('is_active', true)->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'from_department_id' => ['required', 'integer', 'exists:departments,id'],
            'to_department_id' => ['required', 'integer', 'exists:departments,id', 'different:from_department_id',
                Rule::unique('department_links')->where('from_department_id', $request->integer('from_department_id'))],
            'date_label' => ['nullable', 'string', 'max:40'],
        ], ['to_department_id.different' => 'เลือกแผนกปลายทางที่ไม่ใช่แผนกต้นทาง', 'to_department_id.unique' => 'มีการส่งต่อคู่นี้อยู่แล้ว']);
        DepartmentLink::create(['date_label' => trim((string) ($data['date_label'] ?? '')) ?: null] + $data);

        return back()->with('success', 'เพิ่มการส่งต่องานแล้ว');
    }

    public function update(Request $request, DepartmentLink $link)
    {
        $data = $request->validate(['date_label' => ['nullable', 'string', 'max:40']]);
        $link->update(['date_label' => trim((string) ($data['date_label'] ?? '')) ?: null]);

        return back()->with('success', 'บันทึกแล้ว');
    }

    public function destroy(DepartmentLink $link)
    {
        $link->delete();   // the dates already given stay on the items; they are just no longer passed on

        return back()->with('success', 'ลบการส่งต่องานแล้ว');
    }
}
