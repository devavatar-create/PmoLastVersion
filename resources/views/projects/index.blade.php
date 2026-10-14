<x-app-layout>
    <x-slot name="header">
        <h2 class="text-lg font-semibold text-slate-900">Projects</h2>
    </x-slot>

    <div class="max-w-7xl mx-auto space-y-4">
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
            <form method="GET" class="flex flex-wrap items-center gap-2">
                <input type="text" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="ค้นหา Project No. / ชื่อ / ลูกค้า"
                       class="rounded-lg border-slate-300 text-sm w-full sm:w-64" />
                <select name="status" class="rounded-lg border-slate-300 text-sm">
                    <option value="">ทุกสถานะ</option>
                    @foreach ($statuses as $key => $label)
                        <option value="{{ $key }}" @selected(($filters['status'] ?? '') === $key)>{{ $label }}</option>
                    @endforeach
                </select>
                <button class="px-3 py-2 rounded-lg bg-slate-100 text-sm font-medium text-slate-600 hover:bg-slate-200">ค้นหา</button>
            </form>

            <div class="flex items-center gap-2">
@if (auth()->user()->allows('page.export'))
                <a href="{{ route('projects.export', $filters) }}" class="inline-flex items-center gap-2 px-3 py-2 rounded-lg border border-slate-300 bg-white text-sm font-medium text-slate-600 hover:bg-slate-50">
                    Export CSV
                </a>
                @endif
                @editplan
                <a href="{{ route('projects.create') }}" class="inline-flex items-center gap-2 px-4 py-2 rounded-lg bg-blue-600 text-white text-sm font-semibold hover:bg-blue-700">
                @endeditplan
                    + New Project
                </a>
            </div>
        </div>

        <x-card class="!p-0">
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead>
                        <tr class="text-left text-xs font-semibold text-slate-500 uppercase tracking-wide border-b border-slate-100">
                            <th class="px-5 py-3">Project</th>
                            <th class="px-5 py-3">Customer</th>
                            <th class="px-5 py-3">Due Date</th>
                            <th class="px-5 py-3">Status</th>
                            <th class="px-5 py-3 w-40">Task Progress</th>
                            <th class="px-5 py-3 w-40">Production</th>
                            <th class="px-5 py-3">Cabinets</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @forelse ($projects as $project)
                            <tr class="hover:bg-blue-50 cursor-pointer" onclick="window.location='{{ route('projects.show', $project) }}'">
                                <td class="px-5 py-3">
                                    <p class="font-medium text-blue-600">{{ $project->project_no }}</p>
                                    <p class="text-xs text-slate-500">{{ $project->project_name }}</p>
                                </td>
                                <td class="px-5 py-3 text-slate-600">{{ $project->customer_name }}</td>
                                <td class="px-5 py-3 text-slate-600 whitespace-nowrap">
                                    {{ optional($project->due_date)->thaiDate() ?? '-' }}
                                    @if ($project->due_date && ! in_array($project->status, [\App\Models\Project::STATUS_COMPLETED, \App\Models\Project::STATUS_CANCELLED], true))
                                        @php $days = $project->days_remaining; @endphp
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
                                    @endif
                                </td>
                                <td class="px-5 py-3"><x-status-badge :status="$project->status" /></td>
                                <td class="px-5 py-3"><x-progress-bar :value="$project->task_progress" size="sm" /></td>
                                <td class="px-5 py-3"><x-progress-bar :value="$project->production_progress" size="sm" /></td>
                                <td class="px-5 py-3 text-slate-600">{{ $project->cabinets_count }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="7" class="px-5 py-6 text-center text-slate-400">ไม่พบโครงการ</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </x-card>

        {{ $projects->links() }}
    </div>
</x-app-layout>
