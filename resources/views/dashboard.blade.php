<x-app-layout>
    <x-slot name="header">
        <h2 class="text-lg font-semibold text-slate-900">แดชบอร์ด</h2>
    </x-slot>

    <div class="max-w-7xl mx-auto space-y-6">

        <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
            <x-card>
                <p class="text-xs font-medium text-slate-500">โครงการที่กำลังดำเนินการ</p>
                <p class="text-2xl font-bold text-slate-900 mt-1">{{ $activeProjectsCount }}</p>
            </x-card>
            <x-card>
                <p class="text-xs font-medium text-slate-500">ใกล้ครบกำหนด (7 วัน)</p>
                <p class="text-2xl font-bold text-amber-600 mt-1">{{ $nearDueCount }}</p>
            </x-card>
            <x-card>
                <p class="text-xs font-medium text-slate-500">โครงการล่าช้า</p>
                <p class="text-2xl font-bold text-red-600 mt-1">{{ $delayedCount }}</p>
            </x-card>
            <x-card>
                <p class="text-xs font-medium text-slate-500">มีความเสี่ยง</p>
                <p class="text-2xl font-bold text-orange-600 mt-1">{{ $atRiskCount }}</p>
            </x-card>
        </div>

        <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
            <x-card>
                <p class="text-xs font-medium text-slate-500">ตู้ทั้งหมด</p>
                <p class="text-2xl font-bold text-slate-900 mt-1">{{ $totalCabinets }}</p>
            </x-card>
            <x-card>
                <p class="text-xs font-medium text-slate-500">ตู้ที่เสร็จสมบูรณ์</p>
                <p class="text-2xl font-bold text-emerald-600 mt-1">{{ $completedCabinets }}</p>
            </x-card>
            <x-card>
                <p class="text-xs font-medium text-slate-500">ตู้ที่อยู่ระหว่างผลิต</p>
                <p class="text-2xl font-bold text-blue-600 mt-1">{{ $inProductionCabinets }}</p>
            </x-card>
            <x-card>
                <p class="text-xs font-medium text-slate-500">ความคืบหน้าการผลิตเฉลี่ย</p>
                <p class="text-2xl font-bold text-slate-900 mt-1">{{ $avgProduction }}%</p>
            </x-card>
        </div>

        {{-- ===== งานตามแผนก (Sub Task level) - Avatar Planning style department section ===== --}}
        <section class="space-y-4" aria-label="งานตามแผนก">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h3 class="text-sm font-semibold text-ae-navy">งานตามแผนก</h3>

                @if ($canViewOthers)
                    {{-- Department filter pills (admin/PM only - everyone else is pinned to their own department server-side). --}}
                    <nav class="flex flex-wrap items-center gap-1.5 text-xs" aria-label="กรองแผนก">
                        <span class="text-slate-500">กรองแผนก:</span>
                        <a href="{{ route('dashboard') }}"
                           class="rounded-full px-3 py-1 font-medium border {{ $filterDepartmentId === null ? 'bg-blue-600 border-blue-600 text-white' : 'bg-white border-slate-300 text-slate-600 hover:bg-slate-50' }}">ทุกแผนก</a>
                        @foreach ($filterDepartments as $d)
                            <a href="{{ route('dashboard', ['department' => $d->id]) }}"
                               class="rounded-full px-3 py-1 font-medium border {{ $filterDepartmentId === $d->id ? 'bg-blue-600 border-blue-600 text-white' : 'bg-white border-slate-300 text-slate-600 hover:bg-slate-50' }}">{{ $d->name }}</a>
                        @endforeach
                    </nav>
                @endif
            </div>

            <div class="grid grid-cols-2 lg:grid-cols-5 gap-4">
                <x-card>
                    <p class="text-2xl font-bold text-slate-900">{{ $dept['totals']['total'] }}</p>
                    <p class="text-xs text-slate-500 mt-1">งานทั้งหมด</p>
                </x-card>
                <x-card>
                    <p class="text-2xl font-bold text-emerald-600">{{ $dept['totals']['completed'] }}</p>
                    <p class="text-xs text-slate-500 mt-1">เสร็จแล้ว</p>
                </x-card>
                <x-card>
                    <p class="text-2xl font-bold text-blue-600">{{ $dept['totals']['in_progress'] }}</p>
                    <p class="text-xs text-slate-500 mt-1">กำลังทำ</p>
                </x-card>
                <x-card>
                    <p class="text-2xl font-bold text-red-600">{{ $dept['totals']['overdue'] }}</p>
                    <p class="text-xs text-slate-500 mt-1">เลยกำหนด</p>
                </x-card>
                <x-card>
                    <p class="text-2xl font-bold text-slate-900">{{ $dept['totals']['checklist_pct'] }}%</p>
                    <p class="text-xs text-slate-500 mt-1">เช็คลิสต์รวม ({{ $dept['totals']['checklist_done'] }}/{{ $dept['totals']['checklist_total'] }})</p>
                </x-card>
            </div>

            {{-- Workload histogram: unfinished dated work by how soon it is due. One series, so no legend;
                 each bar carries its own number and a text label, and the overdue bar is also marked in words
                 (not just red). Native title = hover tooltip. --}}
            @php $maxBucket = max(1, collect($dept['buckets'])->max('count')); @endphp
            <x-card title="ภาระงานรายสัปดาห์" subtitle="งานที่ยังไม่เสร็จ ตามวันครบกำหนด">
                <div class="flex items-end gap-4 h-44" role="img" aria-label="จำนวนงานที่ยังไม่เสร็จแยกตามช่วงวันครบกำหนด">
                    @foreach ($dept['buckets'] as $b)
                        <div class="flex-1 flex flex-col items-center justify-end h-full" title="{{ $b['label'] }}: {{ $b['count'] }} งาน">
                            <span class="text-xs font-semibold tabular-nums {{ $b['overdue'] && $b['count'] > 0 ? 'text-red-700' : 'text-slate-700' }}">{{ $b['count'] }}</span>
                            <div class="w-full max-w-[56px] rounded-t {{ $b['overdue'] ? 'bg-red-600' : 'bg-blue-600' }} {{ $b['count'] === 0 ? 'opacity-30' : '' }}"
                                 style="height: {{ $b['count'] === 0 ? 3 : max(6, round($b['count'] / $maxBucket * 100)) }}%"></div>
                            <span class="mt-1.5 text-[11px] text-center {{ $b['overdue'] ? 'text-red-700 font-medium' : 'text-slate-500' }}">{{ $b['label'] }}</span>
                        </div>
                    @endforeach
                </div>
            </x-card>

            <x-card title="ความคืบหน้าแต่ละแผนก" subtitle="เช็คลิสต์ที่ติ๊กแล้ว / ทั้งหมด">
                @forelse ($dept['departments'] as $row)
                    <div class="grid grid-cols-[minmax(7rem,12rem)_1fr_auto] items-center gap-4 py-2.5 {{ ! $loop->last ? 'border-b border-slate-100' : '' }}">
                        <span class="text-sm font-medium text-slate-800 truncate">{{ $row['name'] }}</span>
                        <x-progress-bar :value="$row['checklist_pct']" size="sm" />
                        <span class="text-xs text-slate-500 whitespace-nowrap tabular-nums">
                            เสร็จ {{ $row['completed'] }}/{{ $row['total'] }}
                            @if ($row['overdue'] > 0)
                                · <span class="font-semibold text-red-600">เลย {{ $row['overdue'] }}</span>
                            @endif
                        </span>
                    </div>
                @empty
                    <p class="text-sm text-slate-400 py-4 text-center">ยังไม่มีข้อมูลแผนก</p>
                @endforelse
            </x-card>
        </section>

        <x-card title="โครงการล่าสุด" subtitle="โครงการล่าสุดและความคืบหน้า">
            <x-slot name="actions">
                <a href="{{ route('projects.index') }}" class="text-sm text-blue-600 font-medium hover:underline">ดูทั้งหมด</a>
            </x-slot>

            <div class="overflow-x-auto -mx-5">
                <table class="min-w-full text-sm">
                    <thead>
                        <tr class="text-left text-xs font-semibold text-slate-500 uppercase tracking-wide border-b border-slate-100">
                            <th class="px-5 py-2">โครงการ</th>
                            <th class="px-5 py-2">ลูกค้า</th>
                            <th class="px-5 py-2">กำหนดส่ง</th>
                            <th class="px-5 py-2">สถานะ</th>
                            <th class="px-5 py-2 w-48">การผลิต</th>
                            <th class="px-5 py-2">ความเสี่ยง</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @forelse ($recentProjects as $project)
                            <tr class="hover:bg-blue-50">
                                <td class="px-5 py-3">
                                    <a href="{{ route('projects.show', $project) }}" class="font-medium text-blue-600 hover:underline">{{ $project->project_no }}</a>
                                    <p class="text-xs text-slate-500">{{ $project->project_name }}</p>
                                </td>
                                <td class="px-5 py-3 text-slate-600">{{ $project->customer_name }}</td>
                                <td class="px-5 py-3 text-slate-600">{{ optional($project->due_date)->thaiDate() ?? '-' }}</td>
                                <td class="px-5 py-3"><x-status-badge :status="$project->status" /></td>
                                <td class="px-5 py-3"><x-progress-bar :value="$project->production_progress" size="sm" /></td>
                                <td class="px-5 py-3">
                                    @php $risk = $project->risk_level; @endphp
                                    @if ($risk === 'overdue')
                                        <span class="text-xs font-semibold text-red-600">ล่าช้า</span>
                                    @elseif ($risk === 'at_risk')
                                        <span class="text-xs font-semibold text-orange-500">มีความเสี่ยง</span>
                                    @elseif ($risk === 'on_track')
                                        <span class="text-xs font-semibold text-emerald-600">ตามแผน</span>
                                    @else
                                        <span class="text-xs text-slate-400">-</span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="px-5 py-6 text-center text-slate-400">ยังไม่มีโครงการ</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </x-card>
    </div>
</x-app-layout>
