<div class="px-4 py-3" data-subtask-row="{{ $subtaskTemplate->id }}" x-data="{ editSubtask: false }">
    <div class="flex items-center justify-between">
        <div class="flex items-center gap-2 min-w-0">
            <span class="drag-handle shrink-0 select-none" title="ลากเพื่อจัดลำดับ">⋮⋮</span>
            <span class="text-sm font-medium text-slate-700" data-subtask-name="{{ $subtaskTemplate->id }}">{{ $subtaskTemplate->name }}</span>
            {{-- the page's script rewrites this text after an edit; empty = no department, then hidden --}}
            <span class="shrink-0 rounded-full bg-indigo-50 px-2 py-0.5 text-[11px] font-medium text-indigo-700 empty:hidden" title="แผนกที่ทำ" data-subtask-department="{{ $subtaskTemplate->id }}">{{ $subtaskTemplate->department?->name }}</span>
        </div>
        <div class="flex items-center gap-1 shrink-0 text-xs">
            @edittemplates
            <button type="button" @click="editSubtask = !editSubtask" class="rounded-md px-2 py-1 font-medium text-slate-600 hover:bg-slate-100">แก้ไข</button>
            <form method="POST" action="{{ route('subtask-templates.duplicate', $subtaskTemplate) }}">@csrf<button class="rounded-md px-2 py-1 font-medium text-slate-500 hover:bg-slate-100 hover:text-blue-600" title="ทำสำเนา Sub Task นี้ พร้อม Checklist">ทำสำเนา</button></form>
            <form class="subtask-template-delete-form" method="POST" action="{{ route('subtask-templates.destroy', $subtaskTemplate) }}" data-confirm="ลบ Sub Task นี้พร้อม Checklist ทั้งหมด?">
                @csrf @method('DELETE')
                <button class="rounded-md px-2 py-1 font-medium text-red-600 hover:bg-red-50">ลบ</button>
            </form>
            @endedittemplates
        </div>
    </div>

    {{-- "เทียบกับ…": the other Sub Tasks whose checklist cabinets made from this template show next to this one's --}}
    @php
        $pairChosen = $subtaskTemplate->pairSources->map(fn ($s) => ['id' => $s->id, 'name' => $s->name, 'dept' => (string) $s->department?->name])->values();
        $pairFeeds = $subtaskTemplate->pairReceivers->pluck('name');
    @endphp
    @php $canEditTemplates = auth()->user()->allows('edit.templates'); @endphp
    <x-pair-picker class="mt-1.5 ml-6" :url="route('subtask-templates.pairs', $subtaskTemplate)" :chosen="$pairChosen" :can-edit="$canEditTemplates"
                   :options="($pairOptions ?? collect())->where('id', '!=', $subtaskTemplate->id)->values()">
        @if ($pairFeeds->isNotEmpty())
            <span class="text-slate-500">ส่งรายการให้ <span class="font-medium text-slate-700">{{ $pairFeeds->implode(', ') }}</span> เทียบ</span>
        @endif
    </x-pair-picker>

    <div x-show="editSubtask" x-cloak class="mt-2 mb-2 p-3 bg-slate-50 rounded-lg">
        <form method="POST" action="{{ route('subtask-templates.update', $subtaskTemplate) }}" class="subtask-template-edit-form flex flex-wrap items-end gap-2" data-subtask-id="{{ $subtaskTemplate->id }}">
            @csrf @method('PUT')
            <div class="flex-1 min-w-[160px]">
                <x-input-label value="ชื่อ Sub Task" class="text-xs" />
                <x-text-input name="name" class="mt-1 block w-full text-sm" value="{{ $subtaskTemplate->name }}" required />
            </div>
            <div class="flex-1 min-w-[160px]">
                <x-input-label value="แผนกที่ทำ (ค่าเริ่มต้น)" class="text-xs" />
                <select name="department_id" class="mt-1 block w-full rounded-lg border-slate-300 text-sm">
                    <option value="">-</option>
                    @foreach ($departments as $department)
                        <option value="{{ $department->id }}" @selected($subtaskTemplate->department_id === $department->id)>{{ $department->name }}</option>
                    @endforeach
                </select>
            </div>
            <button class="px-3 py-2 rounded-lg bg-blue-600 text-white text-xs font-semibold hover:bg-blue-700">บันทึก</button>
        </form>
    </div>

    {{-- the one checklist editor: edit in place, add many / paste from Excel, ready-made sets from any template, move / copy, undo --}}
    <x-checklist-editor kind="template" :owner="$subtaskTemplate" class="mt-2 ml-4"
                        :items="app(\App\Services\ChecklistEditor::class)->items('template', $subtaskTemplate)"
                        :editable="(bool) auth()->user()?->allows('edit.templates')" />
</div>
