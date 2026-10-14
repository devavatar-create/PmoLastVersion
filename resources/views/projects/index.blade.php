<x-app-layout>
    <x-slot name="header">
        <h2 class="text-lg font-semibold text-slate-900">โครงการ</h2>
    </x-slot>

    @php
        // a filter link that keeps the other filters (search / status / priority) as they are
        $link = fn (array $change) => route('projects.index', array_filter(array_merge($filters, $change), fn ($v) => $v !== null && $v !== ''));
        $status = $filters['status'] ?? '';
        $priority = $filters['priority'] ?? '';
    @endphp

    <div class="max-w-screen-2xl mx-auto space-y-4">

        {{-- toolbar, in Planning's style: search, a segmented status switch, the ⚑ priority buttons --}}
        <div class="flex flex-wrap items-center gap-2 rounded-xl border border-slate-200 bg-white p-3 shadow-sm">
            <form method="GET" class="relative min-w-0 flex-1 basis-64 sm:max-w-xs">
                @foreach (['status' => $status, 'priority' => $priority] as $k => $v)
                    @if ($v)<input type="hidden" name="{{ $k }}" value="{{ $v }}">@endif
                @endforeach
                <label for="project-search" class="sr-only">ค้นหาโครงการ</label>
                <svg class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path stroke-linecap="round" d="m20 20-3.5-3.5"/></svg>
                <input id="project-search" type="search" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="ค้นหาเลขโครงการ / ชื่อ / ลูกค้า…"
                       class="w-full rounded-lg border-slate-300 pl-9 text-sm focus:border-blue-500 focus:ring-blue-500">
            </form>

            <nav aria-label="กรองตามสถานะ" class="flex overflow-x-auto rounded-lg border border-slate-200 p-0.5">
                @foreach (['' => 'ทั้งหมด'] + $statuses as $key => $label)
                    <a href="{{ $link(['status' => $key ?: null]) }}" @if ($status === $key) aria-current="true" @endif
                       class="flex items-center gap-1.5 whitespace-nowrap rounded-md px-3 py-1.5 text-sm font-semibold {{ $status === $key ? 'bg-blue-600 text-white shadow-sm' : 'text-slate-600 hover:bg-slate-100' }}">
                        @if ($key)<span class="h-1.5 w-1.5 rounded-full" style="background: {{ $status === $key ? '#fff' : \App\Services\PlanningBoard::statusColor($key) }}"></span>@endif
                        {{ $label }}
                    </a>
                @endforeach
            </nav>

            <div class="flex gap-1.5">
                @foreach (['urgent' => ['ด่วน', 'text-red-600', 'border-red-300 bg-red-50'], 'high' => ['สูง', 'text-orange-600', 'border-orange-300 bg-orange-50']] as $key => [$label, $text, $on])
                    <a href="{{ $link(['priority' => $priority === $key ? null : $key]) }}" @if ($priority === $key) aria-current="true" @endif
                       class="inline-flex items-center gap-1 rounded-lg border px-3 py-1.5 text-sm font-semibold {{ $text }} {{ $priority === $key ? $on : 'border-slate-200 bg-white hover:bg-slate-50' }}">
                        <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M5 3a1 1 0 0 1 1 1v.3c1.7-.9 3.6-.9 5.6 0 1.8.8 3.5.8 5.3-.1A1 1 0 0 1 18.3 5v8.2a1 1 0 0 1-.5.9c-2.4 1.3-4.9 1.4-7.2.4-1.6-.7-3.1-.7-4.6.1V20a1 1 0 1 1-2 0V4a1 1 0 0 1 1-1z"/></svg>{{ $label }}
                    </a>
                @endforeach
            </div>

            <div class="ml-auto flex items-center gap-2">
                @if (auth()->user()->allows('page.export'))
                    <a href="{{ route('projects.export', $filters) }}" class="rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-sm font-medium text-slate-700 hover:bg-slate-50">Export CSV</a>
                @endif
                @editplan
                    <a href="{{ route('projects.create') }}" class="inline-flex items-center gap-1.5 rounded-lg bg-blue-600 px-3.5 py-1.5 text-sm font-semibold text-white shadow-sm hover:bg-blue-700">
                        <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" d="M12 5v14M5 12h14"/></svg>โครงการใหม่
                    </a>
                @endeditplan
            </div>
        </div>

        <div class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
            <div class="flex items-baseline justify-between border-b border-slate-100 px-5 py-3">
                <h3 class="text-sm font-bold text-ae-navy">โครงการ <span class="font-medium text-slate-500">· {{ $projects->total() }}</span></h3>
                <span class="text-xs text-slate-400">เรียงตามวันส่งที่ใกล้ที่สุด</span>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead>
                        <tr class="border-b border-slate-100 bg-slate-50 text-left text-xs font-semibold text-slate-500">
                            <th class="px-5 py-2.5">โครงการ</th>
                            <th class="px-4 py-2.5">ลูกค้า</th>
                            <th class="px-4 py-2.5">กำหนดส่ง</th>
                            <th class="px-4 py-2.5">สถานะ</th>
                            <th class="px-4 py-2.5">แผนก</th>
                            <th class="w-44 px-4 py-2.5">การผลิต</th>
                            <th class="px-4 py-2.5 text-right">ตู้</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @forelse ($projects as $project)
                            @php
                                $color = \App\Services\PlanningBoard::statusColor($project->status);
                                $done = in_array($project->status, [\App\Models\Project::STATUS_COMPLETED, \App\Models\Project::STATUS_CANCELLED], true);
                                $depts = $projectDepartments[$project->id] ?? [];
                            @endphp
                            <tr class="cursor-pointer hover:bg-blue-50/40" onclick="window.location='{{ route('projects.show', $project) }}'">
                                <td class="px-5 py-3">
                                    <div class="flex items-start gap-3">
                                        {{-- the status ring Planning puts in front of every project --}}
                                        <span class="mt-0.5 flex h-4 w-4 shrink-0 items-center justify-center rounded-full border-2" style="border-color: {{ $color }}" aria-hidden="true">
                                            @if ($done)<span class="h-2 w-2 rounded-full" style="background: {{ $color }}"></span>@endif
                                        </span>
                                        <div class="min-w-0">
                                            <a href="{{ route('projects.show', $project) }}" class="flex items-center gap-2 font-bold text-ae-navy hover:underline">{{ $project->project_no }} <x-priority-flag :priority="$project->priority" /></a>
                                            <p class="max-w-xs truncate text-xs text-slate-500" title="{{ $project->project_name }}">{{ $project->project_name }}</p>
                                        </div>
                                    </div>
                                </td>
                                <td class="max-w-[14rem] px-4 py-3 text-slate-600"><span class="line-clamp-2">{{ $project->customer_name }}</span></td>
                                <td class="whitespace-nowrap px-4 py-3">
                                    <div class="tabular-nums text-slate-700">{{ optional($project->due_date)->thaiDate() ?? '-' }}</div>
                                    @if ($project->due_date && ! $done)
                                        @php $days = $project->days_remaining; @endphp
                                        <span data-countdown class="mt-0.5 inline-block rounded-full px-2 py-0.5 text-[11px] font-semibold {{ $days < 0 ? 'bg-red-100 text-red-700' : ($days <= 7 ? 'bg-amber-100 text-amber-700' : 'bg-slate-100 text-slate-500') }}">
                                            {{ $days < 0 ? 'เลยกำหนด '.abs($days).' วัน' : ($days === 0 ? 'ครบกำหนดวันนี้' : 'เหลือ '.$days.' วัน') }}
                                        </span>
                                    @endif
                                </td>
                                <td class="px-4 py-3"><x-project-status :status="$project->status" /></td>
                                <td class="px-4 py-3">
                                    @if ($depts)
                                        <div class="flex items-center" title="{{ collect($depts)->map(fn ($d) => $departmentNames[$d] ?? '')->implode(', ') }}">
                                            @foreach (array_slice($depts, 0, 6) as $deptId)
                                                <span class="-ml-1 h-3.5 w-3.5 rounded-full ring-2 ring-white first:ml-0" style="background: {{ $departmentColors[$deptId] ?? '#94a3b8' }}"></span>
                                            @endforeach
                                            @if (count($depts) > 6)<span class="ml-1.5 text-xs font-medium text-slate-500">+{{ count($depts) - 6 }}</span>@endif
                                        </div>
                                    @else
                                        <span class="text-xs text-slate-400">ยังไม่จ่ายงาน</span>
                                    @endif
                                </td>
                                <td class="px-4 py-3">
                                    <div class="flex items-center gap-2">
                                        <div class="h-1.5 flex-1 overflow-hidden rounded-full bg-slate-100">
                                            <div class="h-1.5 rounded-full {{ $project->production_progress >= 100 ? 'bg-emerald-500' : 'bg-blue-500' }}" style="width: {{ $project->production_progress }}%"></div>
                                        </div>
                                        <span class="w-9 text-right text-xs font-bold tabular-nums text-slate-700">{{ $project->production_progress }}%</span>
                                    </div>
                                    @if ($project->project_tasks_avg_progress !== null)
                                        <div class="mt-0.5 text-[11px] text-slate-400">งานบริหาร {{ $project->task_progress }}%</div>
                                    @endif
                                </td>
                                <td class="px-4 py-3 text-right">
                                    <span class="inline-flex items-center gap-1 rounded-full border border-slate-200 px-2 py-0.5 text-xs font-semibold tabular-nums text-slate-600">
                                        <svg class="h-3 w-3 text-slate-400" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><rect x="5" y="3" width="14" height="18" rx="2"/><path d="M9 8h6M9 12h6"/></svg>{{ $project->cabinets_count }}
                                    </span>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="7" class="px-5 py-10 text-center text-slate-400">ไม่พบโครงการ</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        {{ $projects->links() }}
    </div>
</x-app-layout>
