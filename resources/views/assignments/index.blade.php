<x-app-layout>
    <x-slot name="header">
        <h2 class="text-lg font-semibold text-slate-900">Dispatch</h2>
    </x-slot>

    <div class="max-w-7xl mx-auto space-y-4">
        <form method="GET" class="flex flex-wrap items-center gap-2">
            <input type="text" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="ค้นหา Project / Cabinet / Task / Sub Task"
                   class="rounded-lg border-slate-300 text-sm w-72" />
            <select name="status" class="rounded-lg border-slate-300 text-sm">
                <option value="">ทุกสถานะ</option>
                @foreach ($assignmentStatuses as $key => $label)
                    <option value="{{ $key }}" @selected(($filters['status'] ?? '') === $key)>{{ $label }}</option>
                @endforeach
            </select>
            <select name="department_id" class="rounded-lg border-slate-300 text-sm">
                <option value="">ทุกแผนก</option>
                @foreach ($departments as $department)
                    <option value="{{ $department->id }}" @selected(($filters['department_id'] ?? '') == $department->id)>{{ $department->name }}</option>
                @endforeach
            </select>
            <label class="flex items-center gap-1.5 text-sm text-slate-600 px-1">
                <input type="checkbox" name="urgent" value="1" @checked(($filters['urgent'] ?? false))
                       class="rounded border-slate-300 text-red-600 focus:ring-red-500">
                เฉพาะงานเร่งด่วน <span class="text-slate-400">(เลยกำหนด/ใกล้ครบกำหนด ≤3 วัน และยังไม่เสร็จ)</span>
            </label>
            <button class="px-3 py-2 rounded-lg bg-slate-100 text-sm font-medium text-slate-600 hover:bg-slate-200">ค้นหา</button>
            <p class="text-xs text-slate-400 ml-auto">เรียงงานที่ยังไม่จ่าย/รอรับก่อน งานที่รับแล้วอยู่ท้ายรายการไว้ดูเฉยๆ (แก้ไม่ได้อีก)</p>
        </form>

        <x-card class="!p-0">
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead>
                        <tr class="text-left text-xs font-semibold text-slate-500 uppercase tracking-wide border-b border-slate-100">
                            <th class="px-5 py-3">Project</th>
                            <th class="px-5 py-3">Cabinet</th>
                            <th class="px-5 py-3">Task / Sub Task</th>
                            <th class="px-5 py-3">Due Date</th>
                            <th class="px-5 py-3 w-96">Department &amp; Action</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @forelse ($subtasks as $subtask)
                            @php $cabinet = $subtask->cabinetTask->cabinet; @endphp
                            <tr class="hover:bg-blue-50">
                                <td class="px-5 py-3 text-slate-600 whitespace-nowrap">{{ $cabinet->project->project_no }}</td>
                                <td class="px-5 py-3 whitespace-nowrap">
                                    <a href="{{ route('cabinets.show', $cabinet) }}" class="font-medium text-blue-600 hover:underline">{{ $cabinet->mo_no }}</a>
                                </td>
                                <td class="px-5 py-3">
                                    <span class="text-slate-400">{{ $subtask->cabinetTask->name }} ›</span>
                                    <span class="font-medium text-slate-800">{{ $subtask->name }}</span>
                                </td>
                                <td class="px-5 py-3 text-slate-600 whitespace-nowrap">
                                    {{ optional($subtask->due_date)->thaiDate() ?? '-' }}
                                    @if ($subtask->due_date && $subtask->assignment_status !== \App\Models\CabinetSubtask::ASSIGNMENT_COMPLETED)
                                        @php $days = $subtask->days_remaining; @endphp
                                        <p class="mt-0.5">
                                            <span class="inline-block text-xs font-medium px-1.5 py-0.5 rounded whitespace-nowrap {{ $days < 0 ? 'bg-red-100 text-red-700' : ($days <= 3 ? 'bg-amber-100 text-amber-700' : 'bg-slate-100 text-slate-500') }}">
                                                @if ($days < 0)
                                                    เลยกำหนด {{ abs($days) }} วัน
                                                @elseif ($days === 0)
                                                    ครบกำหนดวันนี้
                                                @else
                                                    เหลือ {{ $days }} วัน
                                                @endif
                                            </span>
                                        </p>
                                    @endif
                                </td>
                                <td class="px-5 py-3">
                                    <div class="flex flex-wrap items-center gap-2" data-assignment="{{ $subtask->id }}">
                                        @include('cabinets.partials._assignment', ['subtask' => $subtask, 'departments' => $departments])
                                    </div>
                                    {{-- Who accepted the work - useful once real per-user login is in place (today everyone shares the auto-login demo account). Kept live via applyAssignment() in _assignment-scripts.blade.php. --}}
                                    <p class="text-xs text-slate-400 mt-1" data-accepted-by="{{ $subtask->id }}">
                                        @if ($subtask->accepted_by)
                                            รับงานโดย {{ $subtask->acceptedBy?->name ?? '-' }} · {{ $subtask->accepted_at->thaiDateTime() }}
                                        @endif
                                    </p>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="px-5 py-6 text-center text-slate-400">ไม่พบ Sub Task ตามเงื่อนไขที่เลือก</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </x-card>

        {{ $subtasks->links() }}
    </div>

    @push('scripts')
    @include('cabinets.partials._assignment-scripts')
    @endpush
</x-app-layout>
