<x-app-layout>
    <x-slot name="header">
        <div class="flex min-w-0 items-center gap-2">
            <x-back-link :href="route('projects.index')" label="รายการโครงการ" />
            <h2 class="text-lg font-semibold text-slate-900">{{ $project->project_no }}</h2>
            <x-project-status :status="$project->status" />
        </div>
    </x-slot>

    @php
        $tabs = array_merge(['info'], auth()->user()->allows('data.files') ? ['attachments'] : [], auth()->user()->allows('data.comments') ? ['comment', 'activity'] : []);
        // "ข้อมูลโครงการ" holds what the older "โปรเจ็ค", "ใบสั่งผลิต/MO" and "ภาพรวม" tabs showed; links that still name them land there
        $asked = in_array(request('tab'), ['project', 'cabinets', 'overview'], true) ? 'info' : request('tab');
        $initialTab = in_array($asked, $tabs, true) ? $asked : 'info';
    @endphp
    <div class="max-w-screen-2xl mx-auto space-y-5" x-data="{ tab: '{{ $initialTab }}' }" x-init="$watch('tab', (t) => keepTabInUrl(t, 'info'))">

        <x-closed-project-banner :project="$project" />

        <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
            <div class="min-w-0">
                <h1 class="text-xl font-bold text-ae-navy">{{ $project->project_name }}</h1>
                <p class="mt-0.5 text-sm text-slate-500">{{ $project->customer_name }}</p>
            </div>
            <div class="flex shrink-0 gap-2">
                @editplan
                <a href="{{ route('projects.edit', $project) }}" class="rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50">แก้ไขโครงการ</a>
                <form method="POST" action="{{ route('projects.destroy', $project) }}" onsubmit="return confirm('ยืนยันการลบโครงการนี้? ตู้และงานทั้งหมดใต้โครงการจะย้ายไปถังขยะ (Admin/PM กู้คืนได้)')">
                    @csrf @method('DELETE')
                    <button class="rounded-lg bg-red-50 px-3 py-2 text-sm font-medium text-red-600 hover:bg-red-100">ลบ</button>
                </form>
                @endeditplan
            </div>
        </div>

        <div class="flex gap-1 overflow-x-auto border-b border-slate-200" role="tablist" aria-label="ส่วนของโครงการ">
            <button type="button" @click="tab = 'info'" role="tab" :aria-selected="tab === 'info'"
                    :class="tab === 'info' ? 'border-blue-600 text-blue-600' : 'border-transparent text-slate-500 hover:text-slate-700'"
                    class="whitespace-nowrap px-4 py-2 text-sm font-medium border-b-2 -mb-px">ข้อมูลโครงการ</button>
            @if (auth()->user()->allows('data.files'))
            <button type="button" @click="tab = 'attachments'" role="tab" :aria-selected="tab === 'attachments'"
                    :class="tab === 'attachments' ? 'border-blue-600 text-blue-600' : 'border-transparent text-slate-500 hover:text-slate-700'"
                    class="whitespace-nowrap px-4 py-2 text-sm font-medium border-b-2 -mb-px">ไฟล์ ({{ app(\App\Services\ProjectFiles::class)->count($project, null, auth()->user()) }})</button>
            @endif
            @if (auth()->user()->allows('data.comments'))
            <button type="button" @click="tab = 'comment'" role="tab" :aria-selected="tab === 'comment'"
                    :class="tab === 'comment' ? 'border-blue-600 text-blue-600' : 'border-transparent text-slate-500 hover:text-slate-700'"
                    class="whitespace-nowrap px-4 py-2 text-sm font-medium border-b-2 -mb-px">Comment</button>
            <button type="button" @click="tab = 'activity'" role="tab" :aria-selected="tab === 'activity'"
                    :class="tab === 'activity' ? 'border-blue-600 text-blue-600' : 'border-transparent text-slate-500 hover:text-slate-700'"
                    class="whitespace-nowrap px-4 py-2 text-sm font-medium border-b-2 -mb-px">Activity Log</button>
            @endif
        </div>

        <div x-show="tab === 'info'" role="tabpanel">
            @include('projects.partials._info')
        </div>

        @if (auth()->user()->allows('data.files'))
        <div x-show="tab === 'attachments'" x-cloak>
            {{-- the project's files, in folders like a computer (one folder per cabinet; files attached to comments too) --}}
            <x-file-manager :project="$project" />
        </div>
        @endif

        @if (auth()->user()->allows('data.comments'))
        <div x-show="tab === 'comment'" x-cloak>
            <x-comment-thread :comments="$comments" note-route="projects.notes.store" :note-model="$project"
                               title="Comment" subtitle="ความคิดเห็น/บันทึกของโครงการนี้ เรียงล่าสุดก่อน" />
        </div>

        <div x-show="tab === 'activity'" x-cloak>
            <x-activity-tree :url="route('projects.activity-tree', $project)" :export-url="auth()->user()->allows('page.export') ? route('projects.activity-export', $project) : null" subtitle="ประวัติการเปลี่ยนแปลงทั้งหมดของโครงการนี้ จัดกลุ่มตามตู้/งาน เรียงล่าสุดก่อน" />
        </div>
        @endif

    </div>

    @editplan
        @include('projects.partials._checklist-bulk')
    @endeditplan

    <!-- Modal: Add Project Task -->
    <x-modal name="add-project-task">
        <form method="POST" action="{{ route('project-tasks.store', $project) }}" class="p-6">
            @csrf
            <h3 class="text-base font-semibold text-slate-900 mb-4">Add Project Task</h3>
            <div class="space-y-4">
                <div>
                    <x-input-label value="Task Name" />
                    <x-text-input name="name" class="mt-1 block w-full" required />
                </div>
                <div>
                    <x-input-label value="Owner" />
                    <select name="owner_id" class="mt-1 block w-full rounded-lg border-slate-300 text-sm">
                        <option value="">-</option>
                        @foreach ($users as $user)
                            <option value="{{ $user->id }}">{{ $user->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div>
                        <x-input-label value="Start Date" />
                        <x-text-input type="date" name="start_date" class="mt-1 block w-full" />
                    </div>
                    <div>
                        <x-input-label value="Due Date" />
                        <x-text-input type="date" name="due_date" class="mt-1 block w-full" />
                    </div>
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div>
                        <x-input-label value="Status" />
                        <select name="status" class="mt-1 block w-full rounded-lg border-slate-300 text-sm">
                            @foreach ($projectTaskStatuses as $key => $label)
                                <option value="{{ $key }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <x-input-label value="Priority" />
                        <select name="priority" class="mt-1 block w-full rounded-lg border-slate-300 text-sm">
                            <option value="low">Low</option>
                            <option value="normal" selected>Normal</option>
                            <option value="high">High</option>
                            <option value="urgent">Urgent</option>
                        </select>
                    </div>
                </div>
                <div>
                    <x-input-label value="Progress (%)" />
                    <x-text-input type="number" min="0" max="100" name="progress" value="0" class="mt-1 block w-full" />
                </div>
                <div>
                    <x-input-label value="Remark" />
                    <textarea name="remark" rows="2" class="mt-1 block w-full rounded-lg border-slate-300 text-sm"></textarea>
                </div>
            </div>
            <div class="mt-6 flex justify-end gap-3">
                <button type="button" x-data @click="$dispatch('close-modal', 'add-project-task')" class="px-4 py-2 text-sm font-medium text-slate-600">ยกเลิก</button>
                <button type="submit" class="px-4 py-2 rounded-lg bg-blue-600 text-white text-sm font-semibold hover:bg-blue-700">Add Task</button>
            </div>
        </form>
    </x-modal>

    <!-- Modals: Edit Project Task -->
    @foreach ($project->projectTasks as $task)
        <x-modal :name="'edit-project-task-'.$task->id">
            <form method="POST" action="{{ route('project-tasks.update', $task) }}" class="p-6">
                @csrf @method('PUT')
                <h3 class="text-base font-semibold text-slate-900 mb-4">Edit Task — {{ $task->name }}</h3>
                <div class="space-y-4">
                    <div>
                        <x-input-label value="Task Name" />
                        <x-text-input name="name" class="mt-1 block w-full" :value="$task->name" required />
                    </div>
                    <div>
                        <x-input-label value="Owner" />
                        <select name="owner_id" class="mt-1 block w-full rounded-lg border-slate-300 text-sm">
                            <option value="">-</option>
                            @foreach ($users as $user)
                                <option value="{{ $user->id }}" @selected($task->owner_id == $user->id)>{{ $user->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <div>
                            <x-input-label value="Start Date" />
                            <x-text-input type="date" name="start_date" class="mt-1 block w-full" :value="$task->start_date?->format('Y-m-d')" />
                        </div>
                        <div>
                            <x-input-label value="Due Date" />
                            <x-text-input type="date" name="due_date" class="mt-1 block w-full" :value="$task->due_date?->format('Y-m-d')" />
                        </div>
                    </div>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <div>
                            <x-input-label value="Status" />
                            <select name="status" class="mt-1 block w-full rounded-lg border-slate-300 text-sm">
                                @foreach ($projectTaskStatuses as $key => $label)
                                    <option value="{{ $key }}" @selected($task->status === $key)>{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <x-input-label value="Priority" />
                            <select name="priority" class="mt-1 block w-full rounded-lg border-slate-300 text-sm">
                                @foreach (['low' => 'Low', 'normal' => 'Normal', 'high' => 'High', 'urgent' => 'Urgent'] as $key => $label)
                                    <option value="{{ $key }}" @selected($task->priority === $key)>{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                    <div>
                        <x-input-label value="Progress (%)" />
                        <x-text-input type="number" min="0" max="100" name="progress" :value="$task->progress" class="mt-1 block w-full" />
                    </div>
                    <div>
                        <x-input-label value="Remark" />
                        <textarea name="remark" rows="2" class="mt-1 block w-full rounded-lg border-slate-300 text-sm">{{ $task->remark }}</textarea>
                    </div>
                </div>
                <div class="mt-6 flex justify-end gap-3">
                    <button type="button" x-data @click="$dispatch('close-modal', 'edit-project-task-{{ $task->id }}')" class="px-4 py-2 text-sm font-medium text-slate-600">ยกเลิก</button>
                    <button type="submit" class="px-4 py-2 rounded-lg bg-blue-600 text-white text-sm font-semibold hover:bg-blue-700">Save</button>
                </div>
            </form>
        </x-modal>
    @endforeach

    <!-- Modal: Add Cabinet -->
    <x-modal name="add-cabinet">
        <form method="POST" action="{{ route('cabinets.store', $project) }}" class="p-6">
            @csrf
            <h3 class="text-base font-semibold text-slate-900 mb-4">Add Cabinet</h3>
            <div class="space-y-4">
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div>
                        <x-input-label value="MO Number" />
                        <x-text-input name="mo_no" class="mt-1 block w-full" required />
                    </div>
                    <div>
                        <x-input-label value="Cabinet Name" />
                        <x-text-input name="cabinet_name" class="mt-1 block w-full" required />
                    </div>
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div>
                        <x-input-label value="Cabinet Type" />
                        <x-text-input name="cabinet_type" class="mt-1 block w-full" placeholder="MDB, DB, MCC, ..." />
                    </div>
                    <div>
                        <x-input-label value="ขนาดตู้" />
                        <x-text-input name="size" class="mt-1 block w-full" placeholder="เช่น 600x800x2000 มม." />
                    </div>
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div>
                        <x-input-label value="Start Date" />
                        <x-text-input type="date" name="start_date" class="mt-1 block w-full" />
                    </div>
                    <div>
                        <x-input-label value="Due Date" />
                        <x-text-input type="date" name="due_date" class="mt-1 block w-full" />
                    </div>
                </div>
                <div>
                    <x-input-label value="วันคาดว่าจะเสร็จ" />
                    <x-text-input type="date" name="expected_completion_date" class="mt-1 block w-full" />
                    <p class="text-xs text-slate-400 mt-1">ต้องไม่เกินวัน Due Date (ไม่บังคับ ใส่ทีหลังได้)</p>
                </div>
                <div>
                    <x-input-label value="Production Template" />
                    <select name="cabinet_template_id" class="mt-1 block w-full rounded-lg border-slate-300 text-sm">
                        @foreach ($cabinetTemplates as $template)
                            <option value="{{ $template->id }}" @selected($template->is_default)>{{ $template->name }}</option>
                        @endforeach
                    </select>
                    <p class="text-xs text-slate-400 mt-1">ระบบจะคัดลอก Task/Sub Task/Checklist จากเทมเพลตนี้มาเป็นของตู้นี้โดยเฉพาะ</p>
                </div>
            </div>
            <div class="mt-6 flex justify-end gap-3">
                <button type="button" x-data @click="$dispatch('close-modal', 'add-cabinet')" class="px-4 py-2 text-sm font-medium text-slate-600">ยกเลิก</button>
                <button type="submit" class="px-4 py-2 rounded-lg bg-blue-600 text-white text-sm font-semibold hover:bg-blue-700">Add Cabinet</button>
            </div>
        </form>
    </x-modal>

    <!-- Modals: Copy Cabinet -->
    @foreach ($project->cabinets as $cabinet)
        <x-modal :name="'copy-cabinet-'.$cabinet->id" :show="old('source_cabinet_id') == $cabinet->id">
            <form method="POST" action="{{ route('cabinets.copy', $cabinet) }}" class="p-6">
                @csrf
                <input type="hidden" name="source_cabinet_id" value="{{ $cabinet->id }}">
                <h3 class="text-base font-semibold text-slate-900 mb-1">คัดลอกตู้ — {{ $cabinet->mo_no }}</h3>
                <p class="text-xs text-slate-500 mb-4">ระบบจะคัดลอก Task/Sub Task/Checklist ทั้งหมดของตู้นี้ไปเป็นตู้ใหม่ (ไม่รวมวันที่ ความคืบหน้า และไฟล์แนบ)</p>
                <div class="space-y-4">
                    <div>
                        <x-input-label value="MO Number ใหม่" />
                        <x-text-input name="mo_no"
                                      value="{{ old('source_cabinet_id') == $cabinet->id ? old('mo_no') : '' }}"
                                      class="mt-1 block w-full" required />
                        @if (old('source_cabinet_id') == $cabinet->id)
                            <x-input-error :messages="$errors->get('mo_no')" class="mt-1" />
                        @endif
                    </div>
                    <div>
                        <x-input-label value="ชื่อตู้" />
                        <x-text-input name="cabinet_name"
                                      value="{{ old('source_cabinet_id') == $cabinet->id ? old('cabinet_name') : $cabinet->cabinet_name }}"
                                      class="mt-1 block w-full" required />
                    </div>
                </div>
                <div class="mt-6 flex justify-end gap-3">
                    <button type="button" x-data @click="$dispatch('close-modal', 'copy-cabinet-{{ $cabinet->id }}')" class="px-4 py-2 text-sm font-medium text-slate-600">ยกเลิก</button>
                    <button type="submit" class="px-4 py-2 rounded-lg bg-blue-600 text-white text-sm font-semibold hover:bg-blue-700">คัดลอกตู้</button>
                </div>
            </form>
        </x-modal>
    @endforeach

    @push('scripts')
        @include('cabinets.partials._assignment-scripts')
        <script>
            /** "ข้อมูลโครงการ": the cabinet table's search / filters / pages, and which alert popover is open (one at a time). */
            function pmoProjectInfo(rows) {
                const STATUS = { not_started: ['ยังไม่เริ่ม', 'bg-slate-100 text-slate-600'], in_progress: ['กำลังผลิต', 'bg-blue-50 text-blue-700'],
                    completed: ['เสร็จแล้ว', 'bg-emerald-50 text-emerald-700'], on_hold: ['พักไว้', 'bg-amber-50 text-amber-700'] };
                const RISK = { high: ['สูง', 'text-red-600'], watch: ['เฝ้าระวัง', 'text-orange-600'], ok: ['ปกติ', 'text-slate-500'], none: ['-', 'text-slate-400'] };
                return {
                    rows, q: '', status: '', type: '', risk: '', page: 1, per: 20, alert: null,
                    filtered() {
                        const q = this.q.trim().toLowerCase();
                        return this.rows.filter((r) => (! q || (r.mo + ' ' + (r.model || '')).toLowerCase().includes(q))
                            && (! this.status || r.status === this.status)
                            && (! this.type || r.type === this.type)
                            && (! this.risk || (this.risk === 'delayed' ? r.delayed : (this.risk === 'late-tasks' ? r.lateTasks > 0 : r.risk === this.risk))));
                    },
                    pages() { return Math.max(1, Math.ceil(this.filtered().length / this.per)); },
                    paged() { this.page = Math.min(this.page, this.pages()); return this.filtered().slice((this.page - 1) * this.per, this.page * this.per); },
                    statusLabel: (s) => (STATUS[s] || [s])[0], statusClass: (s) => (STATUS[s] || ['', 'bg-slate-100 text-slate-600'])[1],
                    riskLabel: (r) => RISK[r][0], riskClass: (r) => RISK[r][1],
                };
            }
        </script>
    @endpush
</x-app-layout>
