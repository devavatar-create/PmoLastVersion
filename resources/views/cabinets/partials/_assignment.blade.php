@php
    $actions = app(\App\Services\SubtaskAssignmentService::class)->actionsFor($subtask, auth()->user());
    $locked = $subtask->is_department_locked;
    $canEditDepartment = auth()->user()?->canDispatchWork() ?? false;
    $badge = [
        'UNASSIGNED' => 'bg-slate-100 text-slate-600',
        'ASSIGNED' => 'bg-amber-100 text-amber-700',
        'ACCEPTED' => 'bg-indigo-100 text-indigo-700',
        'IN_PROGRESS' => 'bg-blue-100 text-blue-700',
        'COMPLETED' => 'bg-emerald-100 text-emerald-700',
    ][$subtask->assignment_status] ?? 'bg-slate-100 text-slate-600';
    $trail = collect([
        $subtask->accepted_at ? 'รับงานโดย '.($subtask->acceptedBy?->name ?? '-').' '.$subtask->accepted_at->thaiDateTime() : null,
        $subtask->started_at ? 'เริ่มงานโดย '.($subtask->startedBy?->name ?? '-').' '.$subtask->started_at->thaiDateTime() : null,
        $subtask->completed_at ? 'เสร็จงานโดย '.($subtask->completedBy?->name ?? '-').' '.$subtask->completed_at->thaiDateTime() : null,
    ])->filter()->implode(' · ');
@endphp
@php $step = ['UNASSIGNED' => 0, 'ASSIGNED' => 1, 'ACCEPTED' => 2, 'IN_PROGRESS' => 3, 'COMPLETED' => 4][$subtask->assignment_status] ?? 0; @endphp
{{-- จ่าย → รับ → เริ่ม → เสร็จ at a glance (the cabinet page leaves it out: 'stepBar' => false) --}}
@if ($stepBar ?? true)
<span class="inline-flex gap-0.5" data-step-bar role="img" aria-label="ขั้นการจ่ายงาน {{ $step }} จาก 4 (จ่าย → รับ → เริ่ม → เสร็จ)" title="จ่าย → รับ → เริ่ม → เสร็จ">
    @for ($i = 1; $i <= 4; $i++)
        <span class="h-1.5 w-3 rounded-full {{ $i <= $step ? ($step === 4 ? 'bg-emerald-500' : 'bg-[#0F4C81]') : 'bg-slate-200' }}"></span>
    @endfor
</span>
@endif
<span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium whitespace-nowrap {{ $badge }}" data-assignment-status
      @if ($trail) title="{{ $trail }}" @endif>{{ $subtask->assignment_status_label }}</span>

@php
    // the department's colour, as on Planning
    $dot = $subtask->department_id ? '<span class="h-2 w-2 shrink-0 rounded-full" style="background: '.e(\App\Services\PlanningBoard::departmentColors()[$subtask->department_id] ?? '#94a3b8').'" aria-hidden="true"></span>' : '';
@endphp
@if ($locked)
    <span class="inline-flex items-center gap-1 text-xs font-medium text-slate-600 cursor-not-allowed"
          title="ไม่สามารถเปลี่ยนแผนกได้ เนื่องจากแผนกรับงานแล้ว">
        {!! $dot !!}{{ $subtask->department?->name ?? '-' }} <span aria-hidden="true">🔒</span>
    </span>
@elseif (! $canEditDepartment)
    <span class="inline-flex items-center gap-1 text-xs font-medium text-slate-500"
          title="เฉพาะผู้ดูแลระบบ (Admin) หรือ Project Manager เท่านั้นที่มีสิทธิ์เปลี่ยนแผนก">
        {!! $dot !!}{{ $subtask->department?->name ?? '- ยังไม่จ่ายงาน -' }}
    </span>
@else
    <span class="inline-flex items-center gap-1.5">{!! $dot !!}
    <select class="assignment-department-select rounded-lg border-slate-300 text-xs py-1 pl-2 pr-7"
            data-url="{{ route('cabinet-subtasks.department', $subtask) }}"
            data-previous="{{ $subtask->department_id }}"
            aria-label="Department">
        <option value="">- ยังไม่จ่ายงาน -</option>
        @foreach ($departments as $department)
            <option value="{{ $department->id }}" @selected($subtask->department_id === $department->id)>{{ $department->name }}</option>
        @endforeach
    </select>
    </span>
@endif

@if ($actions['can_accept'])
    <button type="button" class="assignment-action px-2 py-1 rounded-lg bg-indigo-600 text-white text-xs font-semibold hover:bg-indigo-700"
            data-url="{{ route('cabinet-subtasks.accept', $subtask) }}" data-confirm="ยืนยันรับงาน? หลังรับงานแล้วจะไม่สามารถเปลี่ยนแผนกได้">รับงาน</button>
@endif
@if ($actions['can_start'])
    <button type="button" class="assignment-action px-2 py-1 rounded-lg bg-blue-600 text-white text-xs font-semibold hover:bg-blue-700"
            data-url="{{ route('cabinet-subtasks.start', $subtask) }}">เริ่มงาน</button>
@endif
@if ($actions['can_complete'])
    <button type="button" class="assignment-action px-2 py-1 rounded-lg bg-emerald-600 text-white text-xs font-semibold hover:bg-emerald-700"
            data-url="{{ route('cabinet-subtasks.complete', $subtask) }}">เสร็จงาน</button>
@endif
