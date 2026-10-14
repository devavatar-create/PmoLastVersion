<li class="flex items-center justify-between text-xs text-slate-500 gap-2" data-checklist-row="{{ $checklistTemplate->id }}" x-data="{ editingChecklist: false }">
    <span class="drag-handle shrink-0 select-none" title="ลากเพื่อจัดลำดับ">⋮⋮</span>
    <span class="flex-1" x-show="!editingChecklist" data-checklist-template-name="{{ $checklistTemplate->id }}">☐ {{ $checklistTemplate->name }}</span>

    <form x-show="editingChecklist" x-cloak class="checklist-template-edit-form flex-1 flex items-center gap-2" data-checklist-template-id="{{ $checklistTemplate->id }}" method="POST" action="{{ route('checklist-templates.update', $checklistTemplate) }}">
        @csrf @method('PUT')
        <input type="text" name="name" value="{{ $checklistTemplate->name }}" required class="flex-1 rounded border-slate-300 text-xs py-0.5 px-1">
        <button class="text-blue-600 hover:underline shrink-0">Save</button>
    </form>

    <div class="flex items-center gap-2 shrink-0">
        @edittemplates
        <button type="button" @click="editingChecklist = !editingChecklist" class="text-blue-600 hover:underline">Edit</button>
        <form class="checklist-template-delete-form" method="POST" action="{{ route('checklist-templates.destroy', $checklistTemplate) }}" data-confirm="ลบ Checklist นี้?">
            @csrf @method('DELETE')
            <button class="text-red-500 hover:underline">Delete</button>
        </form>
        @endedittemplates
    </div>
</li>
