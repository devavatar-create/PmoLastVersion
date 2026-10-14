<x-app-layout>
    <x-slot name="header">
        <div class="flex min-w-0 items-center gap-2">
            <x-back-link :href="route('projects.index')" label="รายการโครงการ" />
            <h2 class="text-lg font-semibold text-slate-900">{{ $project->project_no }}</h2>
            <x-status-badge :status="$project->status" />
        </div>
    </x-slot>

    @php
        $tabs = array_merge(['project', 'cabinets'], auth()->user()->allows('data.files') ? ['attachments'] : [], auth()->user()->allows('data.comments') ? ['comment', 'activity'] : []);
        $initialTab = in_array(request('tab'), $tabs) ? request('tab') : 'project';
    @endphp
    <div class="max-w-7xl mx-auto space-y-6" x-data="{ tab: '{{ $initialTab }}' }" x-init="$watch('tab', (t) => keepTabInUrl(t, 'project'))">

        <x-closed-project-banner :project="$project" />

        <!-- Project Identity (always visible, not tab-dependent) -->
        <x-card>
            <div class="flex flex-col lg:flex-row lg:items-start justify-between gap-4">
                <div>
                    <h1 class="text-xl font-bold text-slate-900">{{ $project->project_name }}</h1>
                    <p class="text-sm text-slate-500 mt-0.5">{{ $project->customer_name }}</p>
                    <div class="flex items-center gap-4 mt-2 text-sm text-slate-500">
                        <span>Due: <span class="font-medium text-slate-700">{{ optional($project->due_date)->thaiDate() ?? '-' }}</span></span>
                        @if ($project->days_remaining !== null)
                            <span class="font-medium {{ $project->days_remaining < 0 ? 'text-red-600' : ($project->days_remaining <= 7 ? 'text-orange-500' : 'text-slate-600') }}">
                                {{ $project->days_remaining >= 0 ? 'เหลือ '.$project->days_remaining.' วัน' : 'เลยกำหนด '.abs($project->days_remaining).' วัน' }}
                            </span>
                        @endif
                        @php $risk = $project->risk_level; @endphp
                        @if ($risk === 'overdue')
                            <span class="inline-flex items-center gap-1 text-red-600 font-semibold"><span class="w-1.5 h-1.5 rounded-full bg-red-500"></span> Delayed</span>
                        @elseif ($risk === 'at_risk')
                            <span class="inline-flex items-center gap-1 text-orange-500 font-semibold"><span class="w-1.5 h-1.5 rounded-full bg-orange-500"></span> At Risk</span>
                        @elseif ($risk === 'on_track')
                            <span class="inline-flex items-center gap-1 text-emerald-600 font-semibold"><span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span> On Track</span>
                        @endif
                    </div>
                </div>
                <div class="flex gap-2 shrink-0 lg:order-last">
                    @editplan
                    <a href="{{ route('projects.edit', $project) }}" class="px-3 py-2 rounded-lg bg-slate-100 text-sm font-medium text-slate-700 hover:bg-slate-200">Edit</a>
                    <form method="POST" action="{{ route('projects.destroy', $project) }}" onsubmit="return confirm('ยืนยันการลบโครงการนี้? ตู้และงานทั้งหมดใต้โครงการจะย้ายไปถังขยะ (Admin/PM กู้คืนได้)')">
                        @csrf @method('DELETE')
                        <button class="px-3 py-2 rounded-lg bg-red-50 text-sm font-medium text-red-600 hover:bg-red-100">Delete</button>
                    </form>
                    @endeditplan
                </div>
            </div>

            {{-- มูลค่างาน (before VAT) · VAT 7% · รวม VAT · Margin - always in view, whatever the tab --}}
            @if (auth()->user()->allows('data.finance') && ($project->contract_value !== null || $project->margin !== null))   {{-- Settings > สิทธิ์การใช้งาน --}}
                @php $baht = fn ($v) => $v !== null ? number_format((float) $v, 2) : '-'; @endphp
                <dl class="mt-4 grid grid-cols-2 gap-3 border-t border-slate-100 pt-4 text-sm sm:grid-cols-4">
                    <div><dt class="text-xs text-slate-500">มูลค่างาน (ก่อน VAT)</dt><dd class="font-semibold tabular-nums text-slate-900">{{ $baht($project->contract_value) }} <span class="text-xs font-normal text-slate-400">บาท</span></dd></div>
                    <div><dt class="text-xs text-slate-500">VAT 7%</dt><dd class="font-semibold tabular-nums text-slate-900">{{ $baht($project->vatAmount()) }} <span class="text-xs font-normal text-slate-400">บาท</span></dd></div>
                    <div><dt class="text-xs text-slate-500">มูลค่างานรวม VAT 7%</dt><dd class="font-bold tabular-nums text-ae-navy">{{ $baht($project->contractValueWithVat()) }} <span class="text-xs font-normal text-slate-400">บาท</span></dd></div>
                    <div><dt class="text-xs text-slate-500">Margin</dt><dd class="font-semibold tabular-nums text-slate-900">{{ $baht($project->margin) }} <span class="text-xs font-normal text-slate-400">บาท</span>
                        @if ($project->marginPercent() !== null)<span class="ml-1 rounded bg-emerald-50 px-1.5 py-0.5 text-xs font-semibold text-emerald-700">{{ $project->marginPercent() }} %</span>@endif</dd></div>
                </dl>
            @endif
        </x-card>

        <div class="flex gap-1 border-b border-slate-200">
            <button type="button" @click="tab = 'project'"
                    :class="tab === 'project' ? 'border-blue-600 text-blue-600' : 'border-transparent text-slate-500 hover:text-slate-700'"
                    class="px-4 py-2 text-sm font-medium border-b-2 -mb-px">โปรเจ็ค</button>
            <button type="button" @click="tab = 'cabinets'"
                    :class="tab === 'cabinets' ? 'border-blue-600 text-blue-600' : 'border-transparent text-slate-500 hover:text-slate-700'"
                    class="px-4 py-2 text-sm font-medium border-b-2 -mb-px">ใบสั่งผลิต/MO</button>
            @if (auth()->user()->allows('data.files'))
            <button type="button" @click="tab = 'attachments'"
                    :class="tab === 'attachments' ? 'border-blue-600 text-blue-600' : 'border-transparent text-slate-500 hover:text-slate-700'"
                    class="px-4 py-2 text-sm font-medium border-b-2 -mb-px">ไฟล์ ({{ app(\App\Services\ProjectFiles::class)->count($project, null, auth()->user()) }})</button>
            @endif
            @if (auth()->user()->allows('data.comments'))
            <button type="button" @click="tab = 'comment'"
                    :class="tab === 'comment' ? 'border-blue-600 text-blue-600' : 'border-transparent text-slate-500 hover:text-slate-700'"
                    class="px-4 py-2 text-sm font-medium border-b-2 -mb-px">Comment</button>
            <button type="button" @click="tab = 'activity'"
                    :class="tab === 'activity' ? 'border-blue-600 text-blue-600' : 'border-transparent text-slate-500 hover:text-slate-700'"
                    class="px-4 py-2 text-sm font-medium border-b-2 -mb-px">Activity Log</button>
            @endif
        </div>

        <div x-show="tab === 'project'" class="space-y-6">

        <x-card class="cursor-pointer hover:border-blue-300 hover:shadow-sm transition"
                @click="tab = 'cabinets'; $nextTick(() => document.getElementById('cabinets-section')?.scrollIntoView({ behavior: 'smooth', block: 'start' }))">
            <x-progress-bar :value="$project->production_progress" label="Production Progress" />
            <div class="flex items-center justify-end gap-1 mt-3 text-xs font-medium text-blue-600">
                <span>ดูตู้ไฟฟ้าทั้งหมด</span>
                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" /></svg>
            </div>
        </x-card>

        <!-- Project Info -->
        <x-card title="ข้อมูลโครงการ">
            <dl class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4 text-sm">
                @if (auth()->user()->allows('data.contact'))
                <div><dt class="text-slate-500">เบอร์ติดต่อลูกค้า</dt><dd class="font-medium text-slate-800">
                    @if ($project->customer_phone)<a href="tel:{{ preg_replace('/[^0-9+]/', '', $project->customer_phone) }}" class="text-blue-600 hover:underline">{{ $project->customer_phone }}</a>@else - @endif</dd></div>
                @endif
                <div><dt class="text-slate-500">เลขที่ใบสั่งซื้อ (PO)</dt><dd class="font-medium text-slate-800">{{ $project->po_no ?? '-' }}</dd></div>
                <div><dt class="text-slate-500">ความสำคัญ</dt><dd class="font-medium text-slate-800">{{ \App\Models\Project::$priorities[$project->priority] ?? $project->priority }}</dd></div>
                <div><dt class="text-slate-500">พนักงานขาย</dt><dd class="font-medium text-slate-800">{{ $project->sales_person ?? '-' }}</dd></div>
                <div><dt class="text-slate-500">ผู้จัดการโครงการ (PM)</dt><dd class="font-medium text-slate-800">{{ $project->projectManager?->name ?? '-' }}</dd></div>
                <div><dt class="text-slate-500">ประเภทงาน</dt><dd class="font-medium text-slate-800">{{ $project->jobType?->name ?? '-' }}</dd></div>
                <div><dt class="text-slate-500">เงื่อนไขการชำระ</dt><dd class="font-medium text-slate-800">{{ $project->payment_terms ?? '-' }}</dd></div>
                <div><dt class="text-slate-500">วันเริ่ม</dt><dd class="font-medium text-slate-800">{{ optional($project->start_date)->thaiDate() ?? '-' }}</dd></div>
                <div><dt class="text-slate-500">วันครบกำหนด</dt><dd class="font-medium text-slate-800">{{ optional($project->due_date)->thaiDate() ?? '-' }}</dd></div>
                {{-- no longer asked for on the form: shown only for an older project that still has a value --}}
                @if ($project->sales_order_no)
                    <div><dt class="text-slate-500">ใบสั่งขาย / อ้างอิง</dt><dd class="font-medium text-slate-800">{{ $project->sales_order_no }}</dd></div>
                @endif
                @if ($project->project_owner)
                    <div><dt class="text-slate-500">เจ้าของโครงการ</dt><dd class="font-medium text-slate-800">{{ $project->project_owner }}</dd></div>
                @endif
                @if ($project->description)
                    <div class="sm:col-span-2 lg:col-span-3"><dt class="text-slate-500">คำอธิบาย</dt><dd class="text-slate-700 mt-0.5">{{ $project->description }}</dd></div>
                @endif
            </dl>
        </x-card>

        <!-- Project Tasks -->
        <x-card title="Project Tasks" subtitle="งานบริหารโครงการ (แยกจากงานผลิตตู้)">
            <x-slot name="actions">
                @editplan
                <button type="button" x-data @click="$dispatch('open-modal', 'add-project-task')" class="text-sm font-medium text-blue-600 hover:underline">+ Add Task</button>
                @endeditplan
            </x-slot>

            <div class="overflow-x-auto -mx-5 -mb-5">
                <table class="min-w-full text-sm">
                    <thead>
                        <tr class="text-left text-xs font-semibold text-slate-500 uppercase tracking-wide border-b border-slate-100">
                            <th class="px-5 py-2">Task</th>
                            <th class="px-5 py-2">Owner</th>
                            <th class="px-5 py-2">Due Date</th>
                            <th class="px-5 py-2">Status</th>
                            <th class="px-5 py-2 w-40">Progress</th>
                            <th class="px-5 py-2"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @forelse ($project->projectTasks as $task)
                            <tr class="hover:bg-blue-50 {{ $task->is_overdue ? 'bg-red-50' : '' }}">
                                <td class="px-5 py-3 font-medium text-slate-800">{{ $task->name }}</td>
                                <td class="px-5 py-3 text-slate-600">{{ $task->owner?->name ?? '-' }}</td>
                                <td class="px-5 py-3 text-slate-600">
                                    {{ optional($task->due_date)->thaiDate() ?? '-' }}
                                    @if ($task->is_overdue)<span class="text-xs text-red-600 font-semibold ml-1">Late</span>@endif
                                </td>
                                <td class="px-5 py-3"><x-status-badge :status="$task->status" /></td>
                                <td class="px-5 py-3"><x-progress-bar :value="$task->progress" size="sm" /></td>
                                <td class="px-5 py-3 text-right">
                                    @editplan
                                    <button type="button" x-data @click="$dispatch('open-modal', 'edit-project-task-{{ $task->id }}')" class="text-xs font-medium text-blue-600 hover:underline">Edit</button>
                                    <form method="POST" action="{{ route('project-tasks.destroy', $task) }}" class="inline" onsubmit="return confirm('ลบงานนี้?')">
                                        @csrf @method('DELETE')
                                        <button class="text-xs font-medium text-red-600 hover:underline ml-2">Delete</button>
                                    </form>
                                    @endeditplan
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="px-5 py-6 text-center text-slate-400">ยังไม่มีงานโครงการ</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </x-card>

        </div>

        <div x-show="tab === 'cabinets'" x-cloak class="space-y-6">

        <x-card>
            <x-progress-bar :value="$project->production_progress" label="Production Progress" />

            @php $summary = $project->cabinet_summary; @endphp
            <div class="grid grid-cols-2 sm:grid-cols-4 gap-4 mt-6 pt-6 border-t border-slate-100">
                <div>
                    <p class="text-xs text-slate-500">Total Cabinets</p>
                    <p class="text-lg font-bold text-slate-900">{{ $summary['total'] }}</p>
                </div>
                <div>
                    <p class="text-xs text-slate-500">Completed</p>
                    <p class="text-lg font-bold text-emerald-600">{{ $summary['completed'] }}</p>
                </div>
                <div>
                    <p class="text-xs text-slate-500">In Progress</p>
                    <p class="text-lg font-bold text-blue-600">{{ $summary['in_progress'] }}</p>
                </div>
                <div>
                    <p class="text-xs text-slate-500">Not Started</p>
                    <p class="text-lg font-bold text-slate-500">{{ $summary['not_started'] }}</p>
                </div>
            </div>
        </x-card>

        <!-- Cabinets Overview -->
        <x-card id="cabinets-section" title="Cabinets" :subtitle="$project->cabinets->count().' ตู้ในโครงการนี้'">
            <x-slot name="actions">
                @editplan
                <button type="button" x-data @click="$dispatch('open-modal', 'checklist-bulk')" class="mr-4 text-sm font-medium text-blue-600 hover:underline">แก้ Checklist ทั้งโครงการ</button>
                <button type="button" x-data @click="$dispatch('open-modal', 'add-cabinet')" class="text-sm font-medium text-blue-600 hover:underline">+ Add Cabinet</button>
                @endeditplan
            </x-slot>

            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
                @forelse ($project->cabinets as $cabinet)
                    {{-- straight to the cabinet's full page (Back returns here, on this tab) --}}
                    <a href="{{ route('cabinets.show', $cabinet) }}"
                       class="flex flex-col bg-white rounded-xl border border-slate-200 p-5 hover:border-blue-300 hover:shadow-md transition">
                        <!-- Header -->
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <p class="text-base font-bold text-slate-900 leading-snug">{{ $cabinet->mo_no }}</p>
                                <p class="text-xs text-slate-500 mt-0.5">{{ $cabinet->cabinet_name }}</p>
                                <div class="flex items-center gap-2 mt-1.5 flex-wrap">
                                    <span class="text-[11px] whitespace-nowrap shrink-0 {{ $cabinet->due_date ? 'text-slate-400' : 'text-slate-300' }}">Due: {{ optional($cabinet->due_date)->thaiDate() ?? '-' }}</span>
                                    @if ($cabinet->days_remaining !== null && $cabinet->status !== 'completed')
                                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[11px] font-semibold whitespace-nowrap shrink-0
                                            {{ $cabinet->days_remaining < 0 ? 'bg-red-100 text-red-700' : ($cabinet->days_remaining <= 7 ? 'bg-amber-100 text-amber-700' : 'bg-slate-100 text-slate-500') }}">
                                            {{ $cabinet->days_remaining >= 0 ? 'เหลือ '.$cabinet->days_remaining.' วัน' : 'เลยกำหนด '.abs($cabinet->days_remaining).' วัน' }}
                                        </span>
                                    @endif
                                </div>
                            </div>
                            <x-status-badge :status="$cabinet->status" class="shrink-0" />
                        </div>

                        <div class="flex justify-end">
                            @editplan
                            <button type="button" x-data @click.stop.prevent="$dispatch('open-modal', 'copy-cabinet-{{ $cabinet->id }}')"
                                    class="text-[11px] font-medium text-slate-400 hover:text-blue-600 transition" title="คัดลอกตู้นี้">
                                คัดลอกตู้
                            </button>
                            @endeditplan
                        </div>

                        <!-- Overall Progress -->
                        <div class="mt-1">
                            <div class="flex items-baseline justify-between mb-1.5">
                                <span class="text-xs font-medium text-slate-500">Progress</span>
                                {{-- id lets the Cabinet Quick Detail Panel update this card live
                                     after a checklist toggle, without reloading this page. --}}
                                <span class="text-sm font-bold text-slate-800" id="cabinet-card-progress-{{ $cabinet->id }}">{{ $cabinet->progress }}%</span>
                            </div>
                            <x-progress-bar :value="$cabinet->progress" id="cabinet-{{ $cabinet->id }}" />
                        </div>

                        <!-- Steps -->
                        <div class="mt-4 grid grid-cols-1 sm:grid-cols-2 sm:gap-x-5">
                            @foreach ($cabinet->tasks as $task)
                                <div class="flex items-start justify-between gap-3 py-1.5 border-b border-slate-100 sm:border-0">
                                    <span class="text-xs leading-snug line-clamp-2 min-w-0 {{ $task->progress > 0 ? 'text-slate-600' : 'text-slate-400' }}">
                                        {{ $task->name }}
                                    </span>
                                    <span class="text-xs font-semibold shrink-0 tabular-nums leading-snug
                                        {{ $task->progress >= 100 ? 'text-emerald-600' : ($task->progress > 0 ? 'text-blue-600' : 'text-slate-300') }}">
                                        {{ $task->progress }}%
                                    </span>
                                </div>
                            @endforeach
                        </div>

                    </a>
                @empty
                    <p class="text-slate-400 text-sm col-span-full text-center py-6">ยังไม่มีตู้ไฟฟ้าในโครงการนี้</p>
                @endforelse
            </div>
        </x-card>

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
    @endpush
</x-app-layout>
