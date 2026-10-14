<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\AppSetting;
use App\Models\Department;
use App\Services\ProjectFiles;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/** Settings > ไฟล์และโฟลเดอร์ (admin routes): the standard folders every project has and who sees them, and the cabinets' folders. */
class FileSettingsController extends Controller
{
    public function edit()
    {
        return view('settings.files', [
            'rows' => ProjectFiles::standardFolders(),
            'cabinets' => AppSetting::get('file_cabinet_folders') === 'all' ? 'all' : 'work',
            'departments' => Department::where('is_active', true)->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function update(Request $request, ProjectFiles $files)
    {
        $data = $request->validate([
            'rows' => ['required', 'array', 'min:1', 'max:20'],
            'rows.*.name' => ['required', 'string', 'max:120'],
            'rows.*.from' => ['nullable', 'string', 'max:120'],
            'rows.*.visibility' => ['required', 'in:'.implode(',', ProjectFiles::VISIBILITIES)],
            'rows.*.departments' => ['nullable', 'array'],
            'rows.*.departments.*' => ['integer', 'exists:departments,id'],
            'cabinets' => ['required', 'in:work,all'],
        ], ['rows.required' => 'ต้องมีโฟลเดอร์มาตรฐานอย่างน้อย 1 โฟลเดอร์', 'rows.min' => 'ต้องมีโฟลเดอร์มาตรฐานอย่างน้อย 1 โฟลเดอร์', 'rows.*.name.required' => 'ตั้งชื่อโฟลเดอร์ให้ครบ']);

        $rows = collect($data['rows'])->values()->map(fn ($r) => [
            'name' => mb_substr(trim(str_replace(['/', '\\'], '-', $r['name'])), 0, 120),
            'visibility' => $r['visibility'],
            'departments' => $r['visibility'] === 'departments' ? collect($r['departments'] ?? [])->map(fn ($id) => (int) $id)->unique()->sort()->values()->all() : [],
            'from' => trim((string) ($r['from'] ?? '')) ?: null,
        ]);
        $errors = [];
        foreach ($rows as $i => $r) {
            if ($r['name'] === '') {
                $errors["rows.{$i}.name"] = 'ตั้งชื่อโฟลเดอร์ให้ครบ';
            } elseif ($rows->filter(fn ($o) => mb_strtolower($o['name']) === mb_strtolower($r['name']))->count() > 1) {
                $errors["rows.{$i}.name"] = "ชื่อ \"{$r['name']}\" ซ้ำกัน";
            }
            if ($r['visibility'] === 'departments' && $r['departments'] === []) {
                $errors["rows.{$i}.departments"] = "\"{$r['name']}\": เลือกอย่างน้อย 1 แผนก";
            }
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        AppSetting::put('file_default_folders', $rows->map(fn ($r) => array_diff_key($r, ['from' => 1]))->all());
        AppSetting::put('file_cabinet_folders', $data['cabinets']);
        $count = $files->applyStandardFolders($rows->all());

        return back()->with('success', "บันทึกแล้ว — ปรับโฟลเดอร์ใน {$count} โครงการ (โครงการที่ยังไม่เคยเปิดไฟล์จะได้ตอนเปิดครั้งแรก)");
    }
}
