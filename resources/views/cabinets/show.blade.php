<x-app-layout>
    <x-slot name="header">
        <div class="flex min-w-0 items-center gap-2">
            <x-back-link :href="route('projects.show', [$cabinet->project, 'tab' => 'cabinets'])" :label="$cabinet->project->project_no" />
            <a href="{{ route('projects.show', [$cabinet->project, 'tab' => 'cabinets']) }}" class="hidden text-sm text-slate-400 hover:text-slate-600 sm:inline">{{ $cabinet->project->project_no }}</a>
            <span class="hidden text-slate-300 sm:inline">/</span>
            <h2 class="text-lg font-semibold text-slate-900">{{ $cabinet->mo_no }}</h2>
        </div>
    </x-slot>

    @php
        $checklistEditor = app(\App\Services\ChecklistEditor::class);
        $handoff = app(\App\Services\HandoffService::class);   // one instance: Settings > การส่งต่องาน is read once for the page
        $linkedByDept = [];   // department id => what the departments passing work to it did in this cabinet (once per department)
        $files = app(\App\Services\ProjectFiles::class);
        $cabinetFolder = $files->cabinetFolder($cabinet);   // where the file manager opens - if this person may see it (else the project's top level)
        $cabinetFolder = $cabinetFolder && $files->sees($cabinetFolder, auth()->user()) ? $cabinetFolder : null;
        $canFiles = auth()->user()->allows('data.files');   // Settings > สิทธิ์การใช้งาน
        $canComments = auth()->user()->allows('data.comments');
        $initialTab = in_array(request('tab'), array_merge(['tasks'], $canFiles ? ['attachments'] : [], $canComments ? ['comment', 'activity'] : [])) ? request('tab') : 'tasks';
    @endphp
    <div class="max-w-screen-2xl mx-auto space-y-6" x-data="{ tab: '{{ $initialTab }}' }" x-init="$watch('tab', (t) => keepTabInUrl(t, 'tasks'))">
        <x-closed-project-banner :project="$cabinet->project" />

        <x-card>
            <div class="flex flex-col lg:flex-row lg:items-start justify-between gap-4">
                <div>
                    <div class="flex items-center gap-2">
                        <h1 class="text-xl font-bold text-slate-900">{{ $cabinet->cabinet_name }}</h1>
                        <x-status-badge :status="$cabinet->status" id="cabinet-{{ $cabinet->id }}" />
                    </div>
                    <dl class="grid grid-cols-2 sm:grid-cols-4 gap-x-6 gap-y-1 mt-3 text-sm">
                        <div><dt class="text-slate-400 text-xs">Cabinet Type</dt><dd class="font-medium text-slate-700">{{ $cabinet->cabinet_type ?? '-' }}</dd></div>
                        <div><dt class="text-slate-400 text-xs">ขนาดตู้</dt><dd class="font-medium text-slate-700">{{ $cabinet->size ?? '-' }}</dd></div>
                        <div><dt class="text-slate-400 text-xs">Quantity</dt><dd class="font-medium text-slate-700">{{ $cabinet->quantity }}</dd></div>
                        <div><dt class="text-slate-400 text-xs">Start Date</dt><dd class="font-medium text-slate-700">{{ optional($cabinet->start_date)->thaiDate() ?? '-' }}</dd></div>
                        <div><dt class="text-slate-400 text-xs">Due Date</dt><dd class="font-medium text-slate-700">{{ optional($cabinet->due_date)->thaiDate() ?? '-' }}</dd></div>
                        <div>
                            <dt class="text-slate-400 text-xs">วันคาดว่าจะเสร็จ</dt>
                            <dd class="font-medium {{ $cabinet->expected_completion_date && $cabinet->due_date && $cabinet->expected_completion_date->gt($cabinet->due_date) ? 'text-red-600' : 'text-slate-700' }}">
                                {{ optional($cabinet->expected_completion_date)->thaiDate() ?? '-' }}
                            </dd>
                        </div>
                        <div><dt class="text-slate-400 text-xs">วันทำงานทั้งหมด (ไม่รวมเสาร์-อาทิตย์)</dt><dd class="font-medium text-slate-700">{{ $cabinet->total_working_days !== null ? $cabinet->total_working_days.' วัน' : '- (ต้องตั้ง Start/Due Date ก่อน)' }}</dd></div>
                    </dl>
                </div>
                <div class="flex gap-2 shrink-0">
                    @editplan
                    <a href="{{ route('cabinets.edit', $cabinet) }}" class="px-3 py-2 rounded-lg bg-slate-100 text-sm font-medium text-slate-700 hover:bg-slate-200">Edit</a>
                    <form method="POST" action="{{ route('cabinets.destroy', $cabinet) }}" onsubmit="return confirm('ยืนยันการลบตู้ {{ $cabinet->mo_no }} นี้? Task/Sub Task/Checklist ทั้งหมดของตู้นี้จะย้ายไปถังขยะ (Admin/PM กู้คืนได้)')">
                        @csrf @method('DELETE')
                        <button class="px-3 py-2 rounded-lg bg-red-50 text-sm font-medium text-red-600 hover:bg-red-100">Delete</button>
                    </form>
                    @endeditplan
                </div>
            </div>

            <div class="mt-5">
                <div class="flex items-center justify-between mb-1">
                    <span class="text-xs font-medium text-slate-500">Cabinet Progress</span>
                    <div class="flex items-center gap-2">
                        <span class="text-sm font-bold text-slate-800" id="progress-value-cabinet-{{ $cabinet->id }}">{{ $cabinet->progress }}%</span>
                        <span class="inline-flex items-center gap-1.5 text-xs font-semibold text-blue-700 bg-blue-50 px-2.5 py-1 rounded-full">
                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                            <span id="checklist-count-cabinet-{{ $cabinet->id }}">{{ $cabinet->checklist_counts['completed'] }}/{{ $cabinet->checklist_counts['total'] }}</span> Checklists
                        </span>
                    </div>
                </div>
                <x-progress-bar :value="$cabinet->progress" id="cabinet-{{ $cabinet->id }}" />
            </div>

            @if ($cabinet->description)
                <div class="mt-4 pt-4 border-t border-slate-100 text-sm space-y-1">
                    <p class="text-slate-600">{{ $cabinet->description }}</p>
                </div>
            @endif
        </x-card>

        <div class="flex gap-1 border-b border-slate-200">
            <button type="button" @click="tab = 'tasks'"
                    :class="tab === 'tasks' ? 'border-blue-600 text-blue-600' : 'border-transparent text-slate-500 hover:text-slate-700'"
                    class="px-4 py-2 text-sm font-medium border-b-2 -mb-px">Tasks</button>
            @if ($canFiles)
            <button type="button" @click="tab = 'attachments'"
                    :class="tab === 'attachments' ? 'border-blue-600 text-blue-600' : 'border-transparent text-slate-500 hover:text-slate-700'"
                    class="px-4 py-2 text-sm font-medium border-b-2 -mb-px">ไฟล์ ({{ $cabinetFolder ? $files->count($cabinet->project, $cabinetFolder, auth()->user()) : 0 }})</button>
            @endif
            @if ($canComments)
            <button type="button" @click="tab = 'comment'"
                    :class="tab === 'comment' ? 'border-blue-600 text-blue-600' : 'border-transparent text-slate-500 hover:text-slate-700'"
                    class="px-4 py-2 text-sm font-medium border-b-2 -mb-px">Comment</button>
            <button type="button" @click="tab = 'activity'"
                    :class="tab === 'activity' ? 'border-blue-600 text-blue-600' : 'border-transparent text-slate-500 hover:text-slate-700'"
                    class="px-4 py-2 text-sm font-medium border-b-2 -mb-px">Activity Log</button>
            @endif
        </div>

        @if ($canComments)
        <div x-show="tab === 'comment'" x-cloak>
            <x-comment-thread :comments="$comments" note-route="cabinets.notes.store" :note-model="$cabinet"
                               title="Comment" subtitle="ความคิดเห็น/บันทึกของตู้นี้ เรียงล่าสุดก่อน" />
        </div>

        <div x-show="tab === 'activity'" x-cloak>
            <x-activity-tree :url="route('cabinets.activity-tree', $cabinet)" :export-url="auth()->user()->allows('page.export') ? route('cabinets.activity-export', $cabinet) : null" subtitle="ประวัติการเปลี่ยนแปลงทั้งหมดของตู้นี้ จัดกลุ่มตามงาน เรียงล่าสุดก่อน" />
        </div>

        @endif

        @if ($canFiles)
        <div x-show="tab === 'attachments'" x-cloak>
            {{-- the project's file manager, opened at this cabinet's own folder --}}
            <x-file-manager :project="$cabinet->project" :start="$cabinetFolder?->id" />
        </div>
        @endif

        <div x-show="tab === 'tasks'" class="space-y-4">
            @foreach ($cabinet->tasks as $task)
                <x-card class="!p-0">
                    <details open class="group">
                        <summary class="list-none cursor-pointer px-5 py-4 flex flex-wrap items-center justify-between gap-x-4 gap-y-2">
                            <div class="flex flex-wrap items-center gap-3 min-w-0">
                                <svg class="w-4 h-4 text-slate-400 shrink-0 transition-transform group-open:rotate-90" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" /></svg>
                                <h3 class="font-semibold text-slate-900">{{ $task->name }}</h3>
                                <x-status-badge :status="$task->status" id="task-{{ $task->id }}" />
                                @if ($task->start_date)
                                    <span class="text-xs text-slate-400 whitespace-nowrap">เริ่ม {{ $task->start_date->thaiDate() }}</span>
                                @endif
                                @if ($task->due_date)
                                    <span class="text-xs text-slate-400 whitespace-nowrap">จบ {{ $task->due_date->thaiDate() }}</span>
                                    @if ($task->status !== \App\Models\CabinetTask::STATUS_COMPLETED)
                                        <span class="text-xs font-medium px-1.5 py-0.5 rounded whitespace-nowrap {{ $task->days_remaining < 0 ? 'bg-red-100 text-red-700' : ($task->days_remaining <= 3 ? 'bg-amber-100 text-amber-700' : 'bg-slate-100 text-slate-500') }}">
                                            {{ $task->days_remaining >= 0 ? 'เหลือ '.$task->days_remaining.' วัน' : 'เลยกำหนด '.abs($task->days_remaining).' วัน' }}
                                        </span>
                                    @endif
                                @endif
                                @editplan
                                <button type="button" x-data @click.stop.prevent="$dispatch('open-modal', 'edit-task-{{ $task->id }}')" class="text-xs font-medium text-blue-600 hover:underline shrink-0">Edit</button>
                                @endeditplan
                            </div>
                            <div class="w-full sm:w-52 sm:shrink-0">
                                <div class="flex items-center justify-end gap-2 mb-1">
                                    <span class="text-sm font-bold text-slate-800" id="progress-value-task-{{ $task->id }}">{{ $task->progress }}%</span>
                                    <span class="inline-flex items-center gap-1 text-xs font-semibold text-blue-700 bg-blue-50 px-2 py-0.5 rounded-full">
                                        <span id="checklist-count-task-{{ $task->id }}">{{ $task->checklist_counts['completed'] }}/{{ $task->checklist_counts['total'] }}</span> Checklists
                                    </span>
                                </div>
                                <x-progress-bar :value="$task->progress" size="sm" id="task-{{ $task->id }}" />
                            </div>
                        </summary>

                        <div class="border-t border-slate-100 divide-y divide-slate-100">
                            @forelse ($task->subtasks as $subtask)
                                <details class="pl-12 pr-5 py-3 border-l-2 border-slate-100 ml-5" id="subtask-{{ $subtask->id }}">
                                    <summary class="list-none cursor-pointer flex flex-wrap items-center justify-between gap-x-4 gap-y-2">
                                        <div class="flex flex-wrap items-center gap-x-3 gap-y-1.5 sm:min-w-[22rem] flex-1">
                                            <span class="text-sm font-medium text-slate-800 min-w-[10rem] max-w-xs">{{ $subtask->name }}</span>
                                            <x-status-badge :status="$subtask->status" id="subtask-{{ $subtask->id }}" />
                                            <div class="flex flex-wrap items-center gap-2" data-assignment="{{ $subtask->id }}" @click.stop>
                                                @include('cabinets.partials._assignment', ['subtask' => $subtask, 'departments' => $departments])
                                            </div>
                                            @if ($subtask->start_date)
                                                <span class="text-xs text-slate-400 whitespace-nowrap">เริ่ม {{ $subtask->start_date->thaiDate() }}</span>
                                            @endif
                                            @if ($subtask->due_date)
                                                <span class="text-xs text-slate-400 whitespace-nowrap">จบ {{ $subtask->due_date->thaiDate() }}</span>
                                                @if ($subtask->status !== \App\Models\CabinetSubtask::STATUS_COMPLETED)
                                                    <span class="text-xs font-medium px-1.5 py-0.5 rounded whitespace-nowrap {{ $subtask->days_remaining < 0 ? 'bg-red-100 text-red-700' : ($subtask->days_remaining <= 3 ? 'bg-amber-100 text-amber-700' : 'bg-slate-100 text-slate-500') }}">
                                                        {{ $subtask->days_remaining >= 0 ? 'เหลือ '.$subtask->days_remaining.' วัน' : 'เลยกำหนด '.abs($subtask->days_remaining).' วัน' }}
                                                    </span>
                                                @endif
                                            @endif
                                        </div>
                                        <div class="flex flex-wrap items-center gap-3">
                                            <div class="w-full sm:w-44">
                                                <div class="flex items-center justify-end gap-2 mb-1">
                                                    <span class="text-sm font-bold text-slate-800" id="progress-value-subtask-{{ $subtask->id }}">{{ $subtask->progress }}%</span>
                                                    <span class="inline-flex items-center gap-1 text-xs font-semibold text-blue-700 bg-blue-50 px-2 py-0.5 rounded-full">
                                                        <span id="checklist-count-subtask-{{ $subtask->id }}">{{ $subtask->checklists->where('is_completed', true)->count() }}/{{ $subtask->checklists->count() }}</span> Checklists
                                                    </span>
                                                </div>
                                                <x-progress-bar :value="$subtask->progress" size="sm" id="subtask-{{ $subtask->id }}" />
                                            </div>
                                            @editplan
                                            <button type="button" x-data @click.stop.prevent="$dispatch('open-modal', 'edit-subtask-{{ $subtask->id }}')" class="text-xs font-medium text-blue-600 hover:underline">Edit</button>
                                            @endeditplan
                                        </div>
                                    </summary>

                                    {{-- the one checklist editor: tick, edit in place, add many / paste from Excel, select to move / copy / delete, undo --}}
                                    <x-checklist-editor kind="subtask" :owner="$subtask" class="mt-3 ml-7"
                                                        :items="$checklistEditor->items('subtask', $subtask)"
                                                        :editable="$subtask->isChecklistEditableBy(auth()->user())"
                                                        :date-label="$handoff->dateLabel($subtask->department_id)"
                                                        :linked="$linkedByDept[$subtask->department_id] ??= $handoff->linkedForDepartment($subtask->department_id, collect([$cabinet->id]))->get($cabinet->id, [])" />
                                </details>
                            @empty
                                <p class="px-5 py-4 text-sm text-slate-400">ยังไม่มี Sub Task</p>
                            @endforelse
                        </div>
                    </details>
                </x-card>
            @endforeach
        </div>
    </div>

    @foreach ($cabinet->tasks as $task)
        <x-modal :name="'edit-task-'.$task->id" :show="old('task_id') == $task->id">
            <form method="POST" action="{{ route('cabinet-tasks.update', $task) }}" class="p-6">
                @csrf @method('PUT')
                <input type="hidden" name="task_id" value="{{ $task->id }}">
                <h3 class="text-base font-semibold text-slate-900 mb-4">Edit Task — {{ $task->name }}</h3>
                <div class="space-y-4">
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <div>
                            <x-input-label value="Start Date" />
                            <input type="date" name="start_date"
                                   value="{{ old('task_id') == $task->id ? old('start_date') : optional($task->start_date)->format('Y-m-d') }}"
                                   class="mt-1 block w-full rounded-lg border-slate-300 text-sm">
                        </div>
                        <div>
                            <x-input-label value="Due Date" />
                            <input type="date" name="due_date"
                                   value="{{ old('task_id') == $task->id ? old('due_date') : optional($task->due_date)->format('Y-m-d') }}"
                                   class="mt-1 block w-full rounded-lg border-slate-300 text-sm">
                            @error('due_date')
                                <p class="text-xs text-red-600 mt-1">{{ $message }}</p>
                            @enderror
                        </div>
                    </div>
                    <div>
                        <x-input-label value="Remark" />
                        <textarea name="remark" rows="2" class="mt-1 block w-full rounded-lg border-slate-300 text-sm">{{ $task->remark }}</textarea>
                    </div>
                </div>
                <div class="mt-6 flex items-center justify-end gap-3">
                    {{-- submits the separate delete form below (a form cannot sit inside another) --}}
                    @editplan
                    <button type="submit" form="delete-task-{{ $task->id }}" class="mr-auto px-3 py-2 text-sm font-medium text-red-600 hover:underline">ลบ Task นี้</button>
                    @endeditplan
                    <button type="button" x-data @click="$dispatch('close-modal', 'edit-task-{{ $task->id }}')" class="px-4 py-2 text-sm font-medium text-slate-600">ยกเลิก</button>
                    <button type="submit" class="px-4 py-2 rounded-lg bg-blue-600 text-white text-sm font-semibold hover:bg-blue-700">Save</button>
                </div>
            </form>
            @editplan
            @php
                $taken = $task->subtasks->whereIn('assignment_status', [\App\Models\CabinetSubtask::ASSIGNMENT_ACCEPTED, \App\Models\CabinetSubtask::ASSIGNMENT_IN_PROGRESS, \App\Models\CabinetSubtask::ASSIGNMENT_COMPLETED])->count();
                $ask = 'ลบ Task "'.$task->name.'" ของตู้นี้ พร้อม Sub Task '.$task->subtasks->count().' งาน และ Checklist '.$task->subtasks->sum(fn ($s) => $s->checklists->count()).' รายการ?'
                    .($taken ? "\n\n⚠ มี ".$taken.' งานที่แผนกรับงานแล้ว — งานนั้นจะหายจากหน้าของแผนกด้วย' : '')
                    ."\n\nกู้คืนได้จากถังขยะ · เทมเพลตไม่เปลี่ยน";
            @endphp
            <form id="delete-task-{{ $task->id }}" method="POST" action="{{ route('cabinet-tasks.destroy', $task) }}" onsubmit="return confirm({{ \Illuminate\Support\Js::from($ask) }})" class="hidden">
                @csrf @method('DELETE')
            </form>
            @endeditplan
        </x-modal>

        @foreach ($task->subtasks as $subtask)
            <x-modal :name="'edit-subtask-'.$subtask->id" :show="old('subtask_id') == $subtask->id">
                <form method="POST" action="{{ route('cabinet-subtasks.update', $subtask) }}" class="p-6">
                    @csrf @method('PUT')
                    <input type="hidden" name="subtask_id" value="{{ $subtask->id }}">
                    <h3 class="text-base font-semibold text-slate-900 mb-4">Edit Sub Task — {{ $subtask->name }}</h3>
                    @error('due_date')
                        <p class="text-xs text-red-600 mb-3">{{ $message }}</p>
                    @enderror
                    <div class="space-y-4">
                        <div>
                            <x-input-label value="Status" />
                            <select name="status" class="mt-1 block w-full rounded-lg border-slate-300 text-sm">
                                @foreach ($subtaskStatuses as $key => $label)
                                    <option value="{{ $key }}" @selected($subtask->status === $key)>{{ $label }}</option>
                                @endforeach
                            </select>
                            <p class="text-xs text-slate-400 mt-1">Progress คำนวณจาก Checklist อัตโนมัติ สถานะจะอัปเดตตามเปอร์เซ็นต์ เว้นแต่ตั้งเป็น On Hold</p>
                        </div>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                            <div>
                                <x-input-label value="Department" />
                                {{-- Disabled once the department has accepted the work (backend rejects a change anyway), or if this user can't dispatch work - disabled fields aren't submitted either way. --}}
                                <select name="department_id" data-modal-department="{{ $subtask->id }}" @disabled($subtask->is_department_locked || ! auth()->user()?->canDispatchWork())
                                        @if ($subtask->is_department_locked) title="ไม่สามารถเปลี่ยนแผนกได้ เนื่องจากแผนกรับงานแล้ว"
                                        @elseif (! auth()->user()?->canDispatchWork()) title="เฉพาะผู้ดูแลระบบ (Admin) หรือ Project Manager เท่านั้นที่มีสิทธิ์เปลี่ยนแผนก" @endif
                                        class="mt-1 block w-full rounded-lg border-slate-300 text-sm disabled:bg-slate-100 disabled:text-slate-500 disabled:cursor-not-allowed">
                                    <option value="">-</option>
                                    @foreach ($departments as $department)
                                        <option value="{{ $department->id }}" @selected($subtask->department_id == $department->id)>{{ $department->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <x-input-label value="Owner" />
                                <select name="owner_id" class="mt-1 block w-full rounded-lg border-slate-300 text-sm">
                                    <option value="">-</option>
                                    @foreach ($users as $user)
                                        <option value="{{ $user->id }}" @selected($subtask->owner_id == $user->id)>{{ $user->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                        </div>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                            <div>
                                <x-input-label value="วันเริ่ม" />
                                <input type="date" name="start_date"
                                       value="{{ old('subtask_id') == $subtask->id ? old('start_date') : optional($subtask->start_date)->format('Y-m-d') }}"
                                       class="mt-1 block w-full rounded-lg border-slate-300 text-sm">
                                <p class="text-xs text-slate-400 mt-1">วันที่เริ่มทำงานย่อยนี้</p>
                            </div>
                            <div>
                                <x-input-label value="วันครบกำหนด" />
                                <input type="date" name="due_date"
                                       value="{{ old('subtask_id') == $subtask->id ? old('due_date') : optional($subtask->due_date)->format('Y-m-d') }}"
                                       class="mt-1 block w-full rounded-lg border-slate-300 text-sm">
                                <p class="text-xs text-slate-400 mt-1">วันที่ต้องทำงานย่อยนี้ให้เสร็จ</p>
                            </div>
                        </div>
                        <div>
                            <x-input-label value="Remark" />
                            <textarea name="remark" rows="2" class="mt-1 block w-full rounded-lg border-slate-300 text-sm">{{ $subtask->remark }}</textarea>
                        </div>
                    </div>
                    <div class="mt-6 flex items-center justify-end gap-3">
                        @editplan
                        <button type="submit" form="delete-subtask-{{ $subtask->id }}" class="mr-auto px-3 py-2 text-sm font-medium text-red-600 hover:underline">ลบ Sub Task นี้</button>
                        @endeditplan
                        <button type="button" x-data @click="$dispatch('close-modal', 'edit-subtask-{{ $subtask->id }}')" class="px-4 py-2 text-sm font-medium text-slate-600">ยกเลิก</button>
                        <button type="submit" class="px-4 py-2 rounded-lg bg-blue-600 text-white text-sm font-semibold hover:bg-blue-700">Save</button>
                    </div>
                </form>
                @editplan
                @php
                    $ask = 'ลบ Sub Task "'.$subtask->name.'" ของตู้นี้ พร้อม Checklist '.$subtask->checklists->count().' รายการ?'
                        .(in_array($subtask->assignment_status, [\App\Models\CabinetSubtask::ASSIGNMENT_ACCEPTED, \App\Models\CabinetSubtask::ASSIGNMENT_IN_PROGRESS, \App\Models\CabinetSubtask::ASSIGNMENT_COMPLETED], true)
                            ? "\n\n⚠ แผนก ".($subtask->department?->name ?? '-').' รับงานนี้แล้ว — งานจะหายจากหน้าของแผนกด้วย' : '')
                        ."\n\nกู้คืนได้จากถังขยะ · เทมเพลตไม่เปลี่ยน";
                @endphp
                <form id="delete-subtask-{{ $subtask->id }}" method="POST" action="{{ route('cabinet-subtasks.destroy', $subtask) }}" onsubmit="return confirm({{ \Illuminate\Support\Js::from($ask) }})" class="hidden">
                    @csrf @method('DELETE')
                </form>
                @endeditplan
            </x-modal>
        @endforeach
    @endforeach

    @push('scripts')
    @include('cabinets.partials._assignment-scripts')
    <script>
        // the checklist editor (x-checklist-editor) reports every change: the Sub Task / Task / cabinet bars follow
        window.addEventListener('checklist-progress', (e) => {
            const d = e.detail;
            ['subtask', 'task', 'cabinet'].forEach((k) => {
                if (!d[k]) return;
                updateProgress(k + '-' + d[k].id, d[k].progress);
                updateStatusBadge(k + '-' + d[k].id, d[k].status);
                updateChecklistCount(k + '-' + d[k].id, d[k].completed_checklists, d[k].total_checklists);
            });
            if (d.assignment && typeof applyAssignment === 'function') applyAssignment(d);   // Automation moved the work
        });

        function updateProgress(id, value) {
            const fill = document.getElementById('progress-fill-' + id);
            const label = document.getElementById('progress-value-' + id);
            if (fill) {
                fill.style.width = value + '%';
                fill.classList.remove('bg-emerald-500', 'bg-blue-500', 'bg-amber-500', 'bg-slate-300');
                fill.classList.add(value >= 100 ? 'bg-emerald-500' : value >= 60 ? 'bg-blue-500' : value >= 30 ? 'bg-amber-500' : 'bg-slate-300');
            }
            if (label) label.textContent = value + '%';
        }

        function updateChecklistCount(id, completed, total) {
            const el = document.getElementById('checklist-count-' + id);
            if (el) el.textContent = completed + '/' + total;
        }

        const STATUS_MAP = {
            not_started: ['bg-slate-100 text-slate-600', 'Not Started'],
            in_progress: ['bg-blue-100 text-blue-700', 'In Progress'],
            completed: ['bg-emerald-100 text-emerald-700', 'Completed'],
            on_hold: ['bg-amber-100 text-amber-700', 'On Hold'],
        };

        function updateStatusBadge(id, status) {
            const badge = document.getElementById('status-badge-' + id);
            if (!badge) return;
            const [classes, label] = STATUS_MAP[status] || ['bg-slate-100 text-slate-600', status];
            badge.className = 'inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium whitespace-nowrap ' + classes;
            badge.textContent = label;
        }
    </script>
    @endpush
</x-app-layout>
