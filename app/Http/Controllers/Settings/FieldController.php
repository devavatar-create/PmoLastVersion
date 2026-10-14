<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\DepartmentField;
use App\Models\DepartmentFieldOption;
use App\Models\ProjectFieldValue;
use Illuminate\Http\Request;

/** Settings > ฟิลด์เพิ่มเติม (admin routes): the extra fields each department fills in on its part of a project. */
class FieldController extends Controller
{
    public function index(Request $request)
    {
        $departments = Department::where('is_active', true)->orderBy('name')->get(['id', 'name']);
        $current = $departments->firstWhere('id', $request->integer('department')) ?? $departments->first();

        return view('settings.fields', [
            'departments' => $departments,
            'current' => $current,
            'fields' => $current ? DepartmentField::where('department_id', $current->id)->with('options')->orderBy('sort')->orderBy('id')->get() : collect(),
            'types' => DepartmentField::TYPES,
        ]);
    }

    public function storeField(Request $request)
    {
        $data = $request->validate([
            'department_id' => ['required', 'exists:departments,id'],
            'name' => ['required', 'string', 'max:100'],
            'type' => ['required', 'in:'.implode(',', array_keys(DepartmentField::TYPES))],
        ]);
        $data['sort'] = (int) DepartmentField::where('department_id', $data['department_id'])->max('sort') + 1;

        DepartmentField::create($data);

        return redirect()->route('settings.fields.index', ['department' => $data['department_id']])->with('success', 'เพิ่มฟิลด์แล้ว');
    }

    public function destroyField(DepartmentField $field)
    {
        $department = $field->department_id;
        $field->delete();   // its options and every chosen value go with it (foreign keys cascade)

        return redirect()->route('settings.fields.index', ['department' => $department])->with('success', 'ลบฟิลด์แล้ว');
    }

    public function toggleChip(DepartmentField $field)
    {
        $field->update(['show_on_chip' => ! $field->show_on_chip]);

        return back();
    }

    /** One option per line, so a whole list can be pasted at once. */
    public function storeOption(Request $request, DepartmentField $field)
    {
        abort_unless($field->type === 'select', 422, 'ฟิลด์ประเภทนี้ไม่มีตัวเลือก');
        $data = $request->validate([
            'group_name' => ['nullable', 'string', 'max:100'],
            'labels' => ['required', 'string', 'max:5000'],
        ]);

        $sort = (int) $field->options()->max('sort');
        foreach (preg_split('/\R/u', $data['labels']) as $label) {
            if (($label = trim($label)) !== '') {
                $field->options()->create(['group_name' => $data['group_name'] ?: null, 'label' => mb_substr($label, 0, 255), 'sort' => ++$sort]);
            }
        }

        return redirect()->route('settings.fields.index', ['department' => $field->department_id])->with('success', 'เพิ่มตัวเลือกแล้ว');
    }

    public function destroyOption(DepartmentFieldOption $option)
    {
        $field = $option->field;
        ProjectFieldValue::where('department_field_id', $field->id)->where('value', (string) $option->id)->delete();
        $option->delete();

        return redirect()->route('settings.fields.index', ['department' => $field->department_id])->with('success', 'ลบตัวเลือกแล้ว');
    }
}
