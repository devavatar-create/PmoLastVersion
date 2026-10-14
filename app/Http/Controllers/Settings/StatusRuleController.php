<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\AppSetting;
use App\Models\BoardStatus;
use App\Models\Department;
use App\Models\StatusRule;
use Illuminate\Http\Request;

/** Settings > กฎสถานะ (admin routes): "when … -> set the Planning status of the cabinet / project", plus statuses rules never touch. */
class StatusRuleController extends Controller
{
    public function index()
    {
        return view('settings.status-rules', [
            'rules' => StatusRule::with('boardStatus')->orderBy('id')->get(),
            'statuses' => BoardStatus::orderBy('sort')->orderBy('id')->get(),
            'departments' => Department::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'locked' => array_map('intval', (array) AppSetting::get('status_rules_locked')),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'target' => ['required', 'in:'.implode(',', array_keys(StatusRule::TARGETS))],
            'logic' => ['required', 'in:and,or'],
            'board_status_id' => ['required', 'exists:board_statuses,id'],
            'conditions' => ['required', 'array', 'max:5'],
            'conditions.*.when' => ['nullable', 'in:'.implode(',', array_keys(StatusRule::WHEN))],
            'conditions.*.department_id' => ['nullable', 'exists:departments,id'],
            'conditions.*.item' => ['nullable', 'string', 'max:255'],
            'conditions.*.status_id' => ['nullable', 'exists:board_statuses,id'],
        ]);

        // keep only filled-in rows, with only the fields their kind uses
        $conditions = collect($data['conditions'])->filter(fn ($c) => ! empty($c['when']))->map(fn ($c) => array_filter([
            'when' => $c['when'],
            'department_id' => in_array($c['when'], ['dept_done', 'dept_started', 'item'], true) ? (int) ($c['department_id'] ?? 0) ?: null : null,
            'item' => $c['when'] === 'item' ? trim((string) ($c['item'] ?? '')) : null,
            'status_id' => in_array($c['when'], ['status', 'not_status'], true) ? (int) ($c['status_id'] ?? 0) ?: null : null,
        ], fn ($v) => $v !== null && $v !== ''))->values();

        $broken = $conditions->first(fn ($c) => (in_array($c['when'], ['dept_done', 'dept_started', 'item'], true) && empty($c['department_id']))
            || ($c['when'] === 'item' && empty($c['item'])) || (in_array($c['when'], ['status', 'not_status'], true) && empty($c['status_id'])));
        if ($conditions->isEmpty() || $broken) {
            return back()->withErrors(['conditions' => 'กรอกเงื่อนไขให้ครบ (แผนก / ชื่อรายการ / สถานะ ตามชนิดเงื่อนไข)'])->withInput();
        }

        StatusRule::create(['target' => $data['target'], 'logic' => $data['logic'], 'conditions' => $conditions->all(), 'board_status_id' => $data['board_status_id'], 'is_active' => true]);

        return back()->with('success', 'เพิ่มกฎแล้ว');
    }

    public function toggle(StatusRule $rule)
    {
        $rule->update(['is_active' => ! $rule->is_active]);

        return back();
    }

    public function destroy(StatusRule $rule)
    {
        $rule->delete();

        return back()->with('success', 'ลบกฎแล้ว');
    }

    public function locked(Request $request)
    {
        $data = $request->validate(['locked' => ['nullable', 'array'], 'locked.*' => ['integer', 'exists:board_statuses,id']]);
        AppSetting::put('status_rules_locked', array_values(array_map('intval', $data['locked'] ?? [])));

        return back()->with('success', 'บันทึกแล้ว');
    }
}
