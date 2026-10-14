{{-- the sub task list stays a direct child of [data-task-row]: the page's script adds / reorders rows there --}}
<div class="border border-slate-200 rounded-lg overflow-hidden" data-task-row="{{ $taskTemplate->id }}" x-data="{ addSubtask: false, editTask: false, open: true }" @tpl-fold.window="open = !$event.detail">
    <div class="flex items-center justify-between gap-3 px-4 py-2.5 bg-slate-50" :class="open ? 'border-b border-slate-200' : ''">
        <div class="flex items-center gap-2 min-w-0">
            <span class="drag-handle shrink-0 select-none" title="ลากเพื่อจัดลำดับ">⋮⋮</span>
            <button type="button" @click="open = !open" class="flex min-w-0 items-center gap-1.5 text-left" :aria-expanded="open">
                <span class="w-3 shrink-0 text-xs text-slate-400" x-text="open ? '▾' : '▸'"></span>
                <span class="truncate font-semibold text-slate-800" data-task-name="{{ $taskTemplate->id }}">{{ $taskTemplate->name }}</span>
            </button>
            <span class="shrink-0 rounded-full bg-white px-2 py-0.5 text-[11px] text-slate-500 ring-1 ring-slate-200">{{ $taskTemplate->subtaskTemplates->count() }} Sub Task</span>
        </div>
        <div class="flex items-center gap-1 shrink-0 text-xs">
            @edittemplates
            <button type="button" @click="editTask = !editTask" class="rounded-md px-2 py-1 font-medium text-slate-600 hover:bg-white">แก้ไข</button>
            <button type="button" @click="addSubtask = !addSubtask; open = true" class="rounded-md px-2 py-1 font-medium text-blue-600 hover:bg-white">+ Sub Task</button>
            <form method="POST" action="{{ route('task-templates.duplicate', $taskTemplate) }}">@csrf<button class="rounded-md px-2 py-1 font-medium text-slate-500 hover:bg-white hover:text-blue-600" title="ทำสำเนา Task นี้ พร้อม Sub Task และ Checklist">ทำสำเนา</button></form>
            <form class="task-template-delete-form" method="POST" action="{{ route('task-templates.destroy', $taskTemplate) }}" data-confirm="ลบ Task นี้พร้อม Sub Task/Checklist ทั้งหมด?">
                @csrf @method('DELETE')
                <button class="rounded-md px-2 py-1 font-medium text-red-600 hover:bg-red-50">ลบ</button>
            </form>
            @endedittemplates
        </div>
    </div>

    <div x-show="editTask" x-cloak class="px-4 py-3 border-b border-slate-100 bg-slate-50">
        <p class="text-xs text-red-600 mb-2 task-edit-error" data-task-id="{{ $taskTemplate->id }}" hidden></p>
        <form method="POST" action="{{ route('task-templates.update', $taskTemplate) }}" class="task-template-edit-form space-y-3" data-task-id="{{ $taskTemplate->id }}">
            @csrf @method('PUT')
            <div>
                <x-input-label value="ชื่อ Task" class="text-xs" />
                <x-text-input name="name" class="mt-1 block w-full text-sm" value="{{ $taskTemplate->name }}" required />
            </div>
            <div>
                <x-input-label value="คำอธิบาย" class="text-xs" />
                <textarea name="description" rows="2" class="mt-1 block w-full rounded-lg border-slate-300 text-sm">{{ $taskTemplate->description }}</textarea>
            </div>
            <button class="px-3 py-2 rounded-lg bg-blue-600 text-white text-xs font-semibold hover:bg-blue-700">บันทึก</button>
        </form>
    </div>

    <div x-show="addSubtask" x-cloak class="px-4 py-3 border-b border-slate-100 bg-white">
        <form class="subtask-template-add-form" method="POST" action="{{ route('subtask-templates.store', $taskTemplate) }}" data-task-id="{{ $taskTemplate->id }}">
            @csrf
            <div class="flex flex-wrap items-end gap-2">
                <div class="flex-1 min-w-[160px]">
                    <x-input-label value="ชื่อ Sub Task" class="text-xs" />
                    <x-text-input name="name" class="mt-1 block w-full text-sm" required />
                </div>
                <div class="flex-1 min-w-[160px]">
                    <x-input-label value="แผนกที่ทำ (ค่าเริ่มต้น)" class="text-xs" />
                    <select name="department_id" class="mt-1 block w-full rounded-lg border-slate-300 text-sm">
                        <option value="">-</option>
                        @foreach ($departments as $department)
                            <option value="{{ $department->id }}">{{ $department->name }}</option>
                        @endforeach
                    </select>
                </div>
                <button class="px-3 py-2 rounded-lg bg-blue-600 text-white text-xs font-semibold hover:bg-blue-700">เพิ่ม</button>
            </div>
        </form>
    </div>

    <div x-show="open" class="divide-y divide-slate-100" data-subtask-list data-reorder-url="{{ route('subtask-templates.reorder') }}">
        @forelse ($taskTemplate->subtaskTemplates as $subtaskTemplate)
            @include('cabinet-templates.partials._subtask', ['subtaskTemplate' => $subtaskTemplate, 'departments' => $departments])
        @empty
            <p class="px-4 py-3 text-xs text-slate-400" data-empty-placeholder>ยังไม่มี Sub Task</p>
        @endforelse
    </div>
</div>
