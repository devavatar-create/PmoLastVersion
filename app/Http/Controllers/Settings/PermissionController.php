<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\AppSetting;
use App\Models\Department;
use App\Models\User;
use App\Support\Access;
use Illuminate\Http\Request;

/** Settings > สิทธิ์การใช้งาน (admin routes): what each department may open, see and change. Admin / PM may everything, always. */
class PermissionController extends Controller
{
    public function edit()
    {
        $people = User::where('is_active', true)->whereNotIn('role', Access::FULL_ROLES)
            ->selectRaw('department_id, COUNT(*) n')->groupBy('department_id')->pluck('n', 'department_id');
        $columns = Department::where('is_active', true)->orderBy('name')->get(['id', 'name'])
            ->map(fn ($d) => ['key' => (string) $d->id, 'name' => $d->name, 'people' => (int) ($people[$d->id] ?? 0)])
            ->push(['key' => Access::NO_DEPARTMENT, 'name' => 'ไม่มีแผนก', 'people' => (int) ($people[''] ?? 0)]);

        return view('settings.permissions', [
            'table' => Access::table(),
            'columns' => $columns,
            'fullRoles' => User::where('is_active', true)->whereIn('role', Access::FULL_ROLES)->count(),
        ]);
    }

    public function update(Request $request)
    {
        $request->validate(['perm' => ['nullable', 'array']]);
        Access::save((array) $request->input('perm', []));   // perm[column][key] = 1 for each ticked box; an unticked box is not sent

        return back()->with('success', 'บันทึกสิทธิ์การใช้งานแล้ว — มีผลทันทีกับทุกคน');
    }

    public function reset()
    {
        AppSetting::put('department_permissions', []);
        Department::query()->update(['sees_all' => false]);   // "เห็นทุกโครงการ" is off by default too

        return back()->with('success', 'กลับไปใช้ค่าเริ่มต้นแล้ว');
    }
}
