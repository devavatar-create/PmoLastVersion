<x-app-layout>
    <x-slot name="header">
        <h2 class="text-lg font-semibold text-slate-900">Cabinets</h2>
    </x-slot>

    <div class="max-w-7xl mx-auto space-y-4">
        <form method="GET" class="flex flex-wrap items-center gap-2">
            <input type="text" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="ค้นหา MO No. / ชื่อตู้"
                   class="rounded-lg border-slate-300 text-sm w-full sm:w-64" />
            <select name="status" class="rounded-lg border-slate-300 text-sm">
                <option value="">ทุกสถานะ</option>
                @foreach ($statuses as $key => $label)
                    <option value="{{ $key }}" @selected(($filters['status'] ?? '') === $key)>{{ $label }}</option>
                @endforeach
            </select>
            <button class="px-3 py-2 rounded-lg bg-slate-100 text-sm font-medium text-slate-600 hover:bg-slate-200">ค้นหา</button>
        </form>

        <x-card class="!p-0">
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead>
                        <tr class="text-left text-xs font-semibold text-slate-500 uppercase tracking-wide border-b border-slate-100">
                            <th class="px-5 py-3">MO No.</th>
                            <th class="px-5 py-3">Cabinet</th>
                            <th class="px-5 py-3">Project</th>
                            <th class="px-5 py-3">Due Date</th>
                            <th class="px-5 py-3">Status</th>
                            <th class="px-5 py-3 w-48">Progress</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @forelse ($cabinets as $cabinet)
                            <tr class="hover:bg-blue-50 cursor-pointer" onclick="window.location='{{ route('cabinets.show', $cabinet) }}'">
                                <td class="px-5 py-3 font-medium text-blue-600">{{ $cabinet->mo_no }}</td>
                                <td class="px-5 py-3">
                                    {{ $cabinet->cabinet_name }}
                                    @if ($cabinet->cabinet_type)<span class="text-xs text-slate-400">({{ $cabinet->cabinet_type }})</span>@endif
                                    @if ($cabinet->size)<span class="text-xs text-slate-400"> · {{ $cabinet->size }}</span>@endif
                                </td>
                                <td class="px-5 py-3 text-slate-600">{{ $cabinet->project->project_no }}</td>
                                <td class="px-5 py-3 text-slate-600 whitespace-nowrap">
                                    {{ optional($cabinet->due_date)->thaiDate() ?? '-' }}
                                    @if ($cabinet->due_date && $cabinet->status !== \App\Models\Cabinet::STATUS_COMPLETED)
                                        @php $days = (int) today()->diffInDays($cabinet->due_date, false); @endphp
                                        <p class="mt-0.5">
                                            <span class="inline-block text-xs font-medium px-1.5 py-0.5 rounded {{ $days < 0 ? 'bg-red-100 text-red-700' : ($days <= 7 ? 'bg-amber-100 text-amber-700' : 'bg-slate-100 text-slate-500') }}">
                                                @if ($days < 0)
                                                    เลยกำหนด {{ abs($days) }} วัน
                                                @elseif ($days === 0)
                                                    ครบกำหนดวันนี้
                                                @else
                                                    เหลือ {{ $days }} วัน
                                                @endif
                                            </span>
                                        </p>
                                        @if ($cabinet->expected_completion_date)
                                            <p class="mt-0.5 text-xs {{ $cabinet->expected_completion_date->gt($cabinet->due_date) ? 'text-red-600' : 'text-slate-400' }}">คาดเสร็จ {{ $cabinet->expected_completion_date->thaiDate() }}</p>
                                        @endif
                                    @endif
                                </td>
                                <td class="px-5 py-3"><x-status-badge :status="$cabinet->status" /></td>
                                <td class="px-5 py-3"><x-progress-bar :value="$cabinet->progress" size="sm" /></td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="px-5 py-6 text-center text-slate-400">ไม่พบตู้ไฟฟ้า</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </x-card>

        {{ $cabinets->links() }}
    </div>
</x-app-layout>
