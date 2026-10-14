<x-app-layout>
    <x-slot name="header">
        <h2 class="text-lg font-semibold text-slate-900">ตั้งค่า</h2>
    </x-slot>

    <div class="max-w-none mx-auto space-y-4">
        <x-settings-nav />
        <x-card title="Departments">
            <x-slot name="actions">
                <button type="button" x-data @click="$dispatch('open-modal', 'add-department')" class="text-sm font-medium text-blue-600 hover:underline">+ Add Department</button>
            </x-slot>

            <div class="divide-y divide-slate-100 -mx-5 -mb-5">
                @foreach ($departments as $department)
                    <div class="px-5 py-3 flex items-center justify-between">
                        <div>
                            <p class="font-medium text-slate-800">{{ $department->name }} <span class="text-xs text-slate-400">({{ $department->code }})</span></p>
                            @if ($department->description)
                                <p class="text-xs text-slate-500">{{ $department->description }}</p>
                            @endif
                            @if ($department->users_count)
                                <p class="text-xs text-slate-400 mt-0.5">{{ $department->users_count }} ผู้ใช้งาน</p>
                            @else
                                <p class="mt-0.5 inline-block rounded bg-amber-100 px-1.5 text-xs font-medium text-amber-800" title="งานและแจ้งเตือนที่ส่งมาแผนกนี้จะไม่มีใครเห็น">ยังไม่มีผู้ใช้ — ไม่มีใครได้รับงาน/แจ้งเตือน</p>
                            @endif
                        </div>
                        <div class="flex items-center gap-3">
                            <button type="button" x-data @click="$dispatch('open-modal', 'edit-department-{{ $department->id }}')" class="text-xs font-medium text-blue-600 hover:underline">Edit</button>
                            <form method="POST" action="{{ route('settings.departments.destroy', $department) }}" onsubmit="return confirm('ลบแผนกนี้?')">
                                @csrf @method('DELETE')
                                <button class="text-xs font-medium text-red-600 hover:underline">Delete</button>
                            </form>
                        </div>
                    </div>
                @endforeach
            </div>
        </x-card>
    </div>

    <x-modal name="add-department">
        <form method="POST" action="{{ route('settings.departments.store') }}" class="p-6">
            @csrf
            <h3 class="text-base font-semibold text-slate-900 mb-4">Add Department</h3>
            <div class="space-y-4">
                <div>
                    <x-input-label value="Name" />
                    <x-text-input name="name" class="mt-1 block w-full" required />
                </div>
                <div>
                    <x-input-label value="Code" />
                    <x-text-input name="code" class="mt-1 block w-full" required />
                </div>
                <div>
                    <x-input-label value="Description" />
                    <textarea name="description" rows="2" class="mt-1 block w-full rounded-lg border-slate-300 text-sm"></textarea>
                </div>
                <div class="flex gap-4">
                    <div>
                        <x-input-label value="สี" />
                        <input type="color" name="color" value="{{ '#7b68ee' }}" class="mt-1 h-9 w-16 rounded border-slate-300">
                    </div>
                    <div class="flex-1">
                        <x-input-label value="ไอคอน (อีโมจิ)" />
                        <x-text-input name="icon" maxlength="8" class="mt-1 block w-full" :value="''" />
                    </div>
                </div>
                <label class="flex items-start gap-2 text-sm text-slate-600">
                    <input type="checkbox" name="sees_all" value="1"  class="mt-0.5 rounded border-slate-300 text-blue-600">
                    <span>เห็นงานของทุกแผนก (อ่านอย่างเดียว เหมือน PM มองเห็น แต่ไม่ได้สิทธิ์จ่ายงาน/ลบ) — ช่องเดียวกับ “เห็นทุกโครงการ” ใน <a href="{{ route('settings.permissions.edit') }}" class="text-blue-600 hover:underline">สิทธิ์การใช้งาน</a></span>
                </label>

            </div>
            <div class="mt-6 flex justify-end gap-3">
                <button type="button" x-data @click="$dispatch('close-modal', 'add-department')" class="px-4 py-2 text-sm font-medium text-slate-600">ยกเลิก</button>
                <button type="submit" class="px-4 py-2 rounded-lg bg-blue-600 text-white text-sm font-semibold hover:bg-blue-700">Add</button>
            </div>
        </form>
    </x-modal>

    @foreach ($departments as $department)
        <x-modal :name="'edit-department-'.$department->id">
            <form method="POST" action="{{ route('settings.departments.update', $department) }}" class="p-6">
                @csrf @method('PUT')
                <h3 class="text-base font-semibold text-slate-900 mb-4">Edit Department</h3>
                <div class="space-y-4">
                    <div>
                        <x-input-label value="Name" />
                        <x-text-input name="name" class="mt-1 block w-full" :value="$department->name" required />
                    </div>
                    <div>
                        <x-input-label value="Code" />
                        <x-text-input name="code" class="mt-1 block w-full" :value="$department->code" required />
                    </div>
                    <div>
                        <x-input-label value="Description" />
                        <textarea name="description" rows="2" class="mt-1 block w-full rounded-lg border-slate-300 text-sm">{{ $department->description }}</textarea>
                    </div>
                <div class="flex gap-4">
                    <div>
                        <x-input-label value="สี" />
                        <input type="color" name="color" value="{{ $department->color ?: '#7b68ee' }}" class="mt-1 h-9 w-16 rounded border-slate-300">
                    </div>
                    <div class="flex-1">
                        <x-input-label value="ไอคอน (อีโมจิ)" />
                        <x-text-input name="icon" maxlength="8" class="mt-1 block w-full" :value="$department->icon" />
                    </div>
                </div>
                <label class="flex items-start gap-2 text-sm text-slate-600">
                    <input type="checkbox" name="sees_all" value="1" @checked($department->sees_all) class="mt-0.5 rounded border-slate-300 text-blue-600">
                    <span>เห็นงานของทุกแผนก (อ่านอย่างเดียว เหมือน PM มองเห็น แต่ไม่ได้สิทธิ์จ่ายงาน/ลบ) — ช่องเดียวกับ “เห็นทุกโครงการ” ใน <a href="{{ route('settings.permissions.edit') }}" class="text-blue-600 hover:underline">สิทธิ์การใช้งาน</a></span>
                </label>

                    <label class="flex items-center gap-2 text-sm text-slate-600">
                        <input type="checkbox" name="is_active" value="1" @checked($department->is_active) class="rounded border-slate-300 text-blue-600">
                        Active
                    </label>
                </div>
                <div class="mt-6 flex justify-end gap-3">
                    <button type="button" x-data @click="$dispatch('close-modal', 'edit-department-{{ $department->id }}')" class="px-4 py-2 text-sm font-medium text-slate-600">ยกเลิก</button>
                    <button type="submit" class="px-4 py-2 rounded-lg bg-blue-600 text-white text-sm font-semibold hover:bg-blue-700">Save</button>
                </div>
            </form>
        </x-modal>
    @endforeach
</x-app-layout>
