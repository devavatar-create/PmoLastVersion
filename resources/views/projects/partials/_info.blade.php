{{-- The project page's "ข้อมูลโครงการ" tab: project information + progress, the alert icons, the cabinet / MO table, and the
     project-management tasks. Data from App\Services\ProjectOverview ($overview). --}}
@php
    $s = $overview['summary'];
    $empty = '<span class="text-slate-400">-</span>';
    $baht = fn ($v) => $v !== null ? number_format((float) $v, 2) : '-';
    $tones = [
        'red' => ['btn' => 'text-red-600', 'ring' => 'ring-red-200', 'dot' => 'bg-red-500'],
        'orange' => ['btn' => 'text-orange-600', 'ring' => 'ring-orange-200', 'dot' => 'bg-orange-500'],
        'blue' => ['btn' => 'text-blue-600', 'ring' => 'ring-blue-200', 'dot' => 'bg-blue-500'],
        'gray' => ['btn' => 'text-slate-500', 'ring' => 'ring-slate-200', 'dot' => 'bg-slate-400'],
    ];
    $icons = [
        'cabinet' => '<rect x="5" y="3" width="14" height="18" rx="2"/><path d="M9 8h6M9 12h6"/>',
        'task' => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/><path d="M4 4l3 3"/>',
        'clock' => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
        'inbox' => '<path d="M4 13h4l2 3h4l2-3h4"/><path d="M5 5h14l1 8v6H4v-6z"/>',
        'dot' => '<circle cx="12" cy="12" r="8" stroke-dasharray="3 3"/>',
    ];
@endphp

<div class="space-y-4" x-data="pmoProjectInfo(@js($overview['rows']))" @keydown.escape.window="alert = null">

    {{-- ===== 1. project information + progress ===== --}}
    <section class="grid gap-4 lg:grid-cols-[minmax(0,1fr)_20rem]">
        <div class="rounded-xl border border-slate-200 bg-white p-4" aria-label="ข้อมูลโครงการ">
            <dl class="grid grid-cols-1 gap-x-6 gap-y-2.5 text-sm sm:grid-cols-2 xl:grid-cols-3">
                <div><dt class="text-xs text-slate-500">เลขที่โครงการ</dt><dd class="font-semibold text-slate-800">{{ $project->project_no }}</dd></div>
                <div class="sm:col-span-1 xl:col-span-2"><dt class="text-xs text-slate-500">ชื่อโครงการ</dt><dd class="font-semibold text-slate-800">{{ $project->project_name }}</dd></div>
                <div><dt class="text-xs text-slate-500">ลูกค้า</dt><dd class="text-slate-800">{{ $project->customer_name ?: '-' }}</dd></div>
                <div><dt class="text-xs text-slate-500">สถานะ</dt><dd class="flex flex-wrap items-center gap-2"><x-project-status :status="$project->status" /><x-priority-flag :priority="$project->priority" :always="true" /></dd></div>
                <div><dt class="text-xs text-slate-500">ประเภทงาน</dt><dd class="text-slate-800">{!! $project->jobType ? e($project->jobType->name) : $empty !!}</dd></div>
                <div><dt class="text-xs text-slate-500">วันเริ่ม</dt><dd class="tabular-nums text-slate-800">{{ optional($project->start_date)->thaiDate() ?? '-' }}</dd></div>
                <div><dt class="text-xs text-slate-500">วันครบกำหนด</dt>
                    <dd class="tabular-nums text-slate-800">{{ optional($project->due_date)->thaiDate() ?? '-' }}
                        @if ($project->days_remaining !== null && ! $project->isLocked())
                            <span class="ml-1 rounded-full px-2 py-0.5 text-[11px] font-semibold {{ $project->days_remaining < 0 ? 'bg-red-100 text-red-700' : ($project->days_remaining <= 7 ? 'bg-orange-100 text-orange-700' : 'bg-slate-100 text-slate-600') }}">
                                {{ $project->days_remaining < 0 ? 'เลยกำหนด '.abs($project->days_remaining).' วัน' : 'เหลือ '.$project->days_remaining.' วัน' }}
                            </span>
                        @endif
                    </dd></div>
                <div><dt class="text-xs text-slate-500">วันทำงานคงเหลือ</dt><dd class="text-slate-800">{{ $s['workingDaysLeft'] !== null ? $s['workingDaysLeft'].' วัน (จ.–ศ.)' : '-' }}</dd></div>
                <div><dt class="text-xs text-slate-500">เงื่อนไขการชำระ</dt><dd class="text-slate-800">{!! $project->payment_terms ? e($project->payment_terms) : $empty !!}</dd></div>
                <div><dt class="text-xs text-slate-500">ผู้จัดการโครงการ (PM)</dt><dd class="text-slate-800">{!! $project->projectManager ? e($project->projectManager->name) : $empty !!}</dd></div>
                <div><dt class="text-xs text-slate-500">พนักงานขาย</dt><dd class="text-slate-800">{!! $project->sales_person ? e($project->sales_person) : $empty !!}</dd></div>
                <div><dt class="text-xs text-slate-500">เลขที่ใบสั่งซื้อ (PO)</dt><dd class="break-words text-slate-800">{!! $project->po_no ? e($project->po_no) : $empty !!}</dd></div>
                @if (auth()->user()->allows('data.contact'))   {{-- Settings > สิทธิ์การใช้งาน --}}
                    <div><dt class="text-xs text-slate-500">เบอร์ติดต่อลูกค้า</dt><dd>@if ($project->customer_phone)<a href="tel:{{ preg_replace('/[^0-9+]/', '', $project->customer_phone) }}" class="text-blue-600 hover:underline">{{ $project->customer_phone }}</a>@else{!! $empty !!}@endif</dd></div>
                @endif
                {{-- no longer asked for on the form: shown only for an older project that still has a value --}}
                @if ($project->sales_order_no)
                    <div><dt class="text-xs text-slate-500">ใบสั่งขาย / อ้างอิง</dt><dd class="break-words text-slate-800">{{ $project->sales_order_no }}</dd></div>
                @endif
                @if ($project->project_owner)
                    <div><dt class="text-xs text-slate-500">เจ้าของโครงการ</dt><dd class="text-slate-800">{{ $project->project_owner }}</dd></div>
                @endif
                @if (auth()->user()->allows('data.finance') && ($project->contract_value !== null || $project->margin !== null))
                    <div><dt class="text-xs text-slate-500">มูลค่างาน (ก่อน VAT)</dt><dd class="tabular-nums text-slate-800">{{ $baht($project->contract_value) }}</dd></div>
                    <div><dt class="text-xs text-slate-500">VAT 7%</dt><dd class="tabular-nums text-slate-800">{{ $baht($project->vatAmount()) }}</dd></div>
                    <div><dt class="text-xs text-slate-500">มูลค่างานรวม VAT 7%</dt><dd class="font-semibold tabular-nums text-ae-navy">{{ $baht($project->contractValueWithVat()) }}</dd></div>
                    <div><dt class="text-xs text-slate-500">Margin</dt><dd class="tabular-nums text-slate-800">{{ $baht($project->margin) }}@if ($project->marginPercent() !== null) · {{ $project->marginPercent() }} %@endif</dd></div>
                @endif
                @if ($project->description)
                    <div class="sm:col-span-2 xl:col-span-3"><dt class="text-xs text-slate-500">คำอธิบาย</dt><dd class="text-slate-700">{{ $project->description }}</dd></div>
                @endif
            </dl>
        </div>

        <div class="rounded-xl border border-slate-200 bg-white p-4" aria-label="ความคืบหน้าโครงการ">
            <div class="flex items-baseline justify-between">
                <span class="text-sm font-semibold text-slate-600">ความคืบหน้าโครงการ</span>
                <span class="text-2xl font-bold tabular-nums text-ae-navy">{{ $s['progress'] }}%</span>
            </div>
            <div class="mt-2 h-2 overflow-hidden rounded-full bg-slate-100" role="img" aria-label="ความคืบหน้า {{ $s['progress'] }}%">
                <div class="h-2 rounded-full {{ $s['progress'] >= 100 ? 'bg-emerald-500' : 'bg-blue-600' }}" style="width: {{ $s['progress'] }}%"></div>
            </div>
            @if ($s['taskProgress'] !== null)
                <p class="mt-1 text-xs text-slate-500">งานบริหารโครงการ {{ $s['taskProgress'] }}% (แยกจากงานผลิต)</p>
            @endif
            <dl class="mt-3 grid grid-cols-2 gap-x-4 gap-y-1.5 text-sm">
                <dt class="text-slate-500">ตู้ทั้งหมด</dt><dd class="text-right font-semibold tabular-nums text-slate-800">{{ $s['total'] }}</dd>
                <dt class="flex items-center gap-1.5 text-slate-500"><span class="h-2 w-2 rounded-full bg-emerald-500"></span>เสร็จแล้ว</dt><dd class="text-right font-semibold tabular-nums text-slate-800">{{ $s['completed'] }}</dd>
                <dt class="flex items-center gap-1.5 text-slate-500"><span class="h-2 w-2 rounded-full bg-blue-600"></span>กำลังผลิต</dt><dd class="text-right font-semibold tabular-nums text-slate-800">{{ $s['inProgress'] }}</dd>
                <dt class="flex items-center gap-1.5 text-slate-500"><span class="h-2 w-2 rounded-full bg-slate-300"></span>ยังไม่เริ่ม</dt><dd class="text-right font-semibold tabular-nums text-slate-800">{{ $s['notStarted'] }}</dd>
                @if ($s['onHold'])
                    <dt class="flex items-center gap-1.5 text-slate-500"><span class="h-2 w-2 rounded-full bg-amber-400"></span>พักไว้</dt><dd class="text-right font-semibold tabular-nums text-slate-800">{{ $s['onHold'] }}</dd>
                @endif
            </dl>
            {{-- a risk count, not a fourth status: a delayed cabinet is also counted above --}}
            <div class="mt-2 flex items-center justify-between border-t border-slate-100 pt-2 text-sm">
                <span class="flex items-center gap-1.5 {{ $s['delayed'] ? 'font-semibold text-red-600' : 'text-slate-500' }}"><span class="h-2 w-2 rounded-full {{ $s['delayed'] ? 'bg-red-500' : 'bg-slate-300' }}"></span>ล่าช้ากว่ากำหนด</span>
                <span class="font-bold tabular-nums {{ $s['delayed'] ? 'text-red-600' : 'text-slate-800' }}">{{ $s['delayed'] }}</span>
            </div>
        </div>
    </section>

    {{-- ===== 2. compact alert icons, each opening a short list ===== --}}
    <section aria-label="การแจ้งเตือน" class="flex flex-wrap items-center gap-2" @click.outside="alert = null">
        <span class="mr-1 text-xs font-semibold text-slate-500">แจ้งเตือน</span>
        @foreach ($overview['alerts'] as $a)
            @php $t = $tones[$a['tone']]; @endphp
            <div class="relative">
                <button type="button" @click="alert = alert === '{{ $a['key'] }}' ? null : '{{ $a['key'] }}'"
                        :aria-expanded="alert === '{{ $a['key'] }}'" aria-controls="alert-{{ $a['key'] }}" aria-label="{{ $a['title'] }} {{ $a['count'] }}" title="{{ $a['title'] }}"
                        @disabled(! $a['count'])
                        class="inline-flex items-center gap-1.5 rounded-full border border-slate-200 bg-white py-1 pl-2 pr-2.5 text-sm font-bold tabular-nums shadow-sm hover:bg-slate-50 disabled:cursor-default disabled:opacity-50 disabled:shadow-none {{ $a['count'] ? $t['btn'] : 'text-slate-400' }}"
                        :class="alert === '{{ $a['key'] }}' && 'ring-2 {{ $t['ring'] }}'">
                    <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24" aria-hidden="true">{!! $icons[$a['icon']] !!}</svg>
                    {{ $a['count'] }}
                    @if ($a['count'])<svg class="h-3 w-3 opacity-60" fill="none" stroke="currentColor" stroke-width="3" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M6 9l6 6 6-6"/></svg>@endif
                </button>
                @if ($a['count'])
                    <div id="alert-{{ $a['key'] }}" x-show="alert === '{{ $a['key'] }}'" x-cloak x-transition.opacity
                         class="absolute left-0 z-30 mt-2 w-[min(22rem,calc(100vw-2rem))] overflow-hidden rounded-xl border border-slate-200 bg-white text-sm shadow-xl">
                        <div class="flex items-center gap-2 border-b border-slate-100 px-4 py-2.5 font-semibold text-slate-800"><span class="h-2 w-2 rounded-full {{ $t['dot'] }}"></span>{{ $a['title'] }} ({{ $a['count'] }})</div>
                        <ul class="max-h-72 divide-y divide-slate-50 overflow-y-auto">
                            @foreach ($a['items'] as $item)
                                <li>
                                    <a href="{{ $item['url'] }}" class="flex items-start justify-between gap-3 px-4 py-2 hover:bg-slate-50 focus:bg-slate-50 focus:outline-none">
                                        <span class="min-w-0">
                                            <span class="flex items-center gap-1.5 font-medium text-slate-800">@if ($item['color'])<span class="h-2 w-2 shrink-0 rounded-full" style="background: {{ $item['color'] }}"></span>@endif<span class="truncate">{{ $item['label'] }}</span></span>
                                            <span class="block truncate text-xs text-slate-500">{{ $item['sub'] }}</span>
                                        </span>
                                        @if ($item['note'])<span class="shrink-0 whitespace-nowrap text-xs font-semibold {{ $t['btn'] }}">{{ $item['note'] }}</span>@endif
                                    </a>
                                </li>
                            @endforeach
                            @foreach ($a['departments'] ?? [] as $d)
                                <li class="flex items-center justify-between gap-3 px-4 py-2">
                                    <span class="min-w-0">
                                        <span class="flex items-center gap-1.5 font-medium text-slate-800"><span class="h-2 w-2 shrink-0 rounded-full" style="background: {{ $d['color'] }}"></span><span class="truncate">{{ $d['name'] }}</span></span>
                                        <span class="block text-xs text-slate-500">รอรับ {{ $d['count'] }} งาน · นานสุด {{ $d['days'] }} วัน</span>
                                    </span>
                                    @if ($overview['canDispatch'])
                                        <button type="button" onclick="pmoRemind('{{ route('cabinet-subtasks.remind') }}', @js($d['ids']), this)"
                                                class="shrink-0 rounded-md border border-blue-300 px-2 py-1 text-xs font-semibold text-blue-700 hover:bg-blue-50 disabled:opacity-50">ทวงถาม</button>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                        @if ($a['filter'] || $a['count'] > count($a['items']) && empty($a['departments']))
                            <div class="border-t border-slate-100 px-4 py-2 text-right">
                                @if ($a['filter'])
                                    <button type="button" @click="risk = '{{ $a['filter'] }}'; page = 1; alert = null; $nextTick(() => $refs.table.scrollIntoView({ behavior: 'smooth', block: 'start' }))"
                                            class="text-xs font-semibold text-blue-600 hover:underline">ดูทั้งหมดในตาราง →</button>
                                @else
                                    <span class="text-xs text-slate-500">แสดง {{ count($a['items']) }} จาก {{ $a['count'] }} รายการ</span>
                                @endif
                            </div>
                        @endif
                    </div>
                @endif
            </div>
        @endforeach
    </section>

    {{-- ===== 3. cabinet / MO table ===== --}}
    <section x-ref="table" id="cabinets-section" aria-label="ตู้ / ใบสั่งผลิต" class="scroll-mt-20 rounded-xl border border-slate-200 bg-white">
        <div class="flex flex-wrap items-center gap-2 border-b border-slate-100 px-4 py-3">
            <h3 class="mr-2 text-sm font-bold text-ae-navy">ตู้ / ใบสั่งผลิต (MO) <span class="font-medium text-slate-500">· {{ $overview['cabinetCount'] }}</span></h3>
            <div class="relative min-w-0 flex-1 basis-48 sm:max-w-xs">
                <label for="cab-search" class="sr-only">ค้นหาตู้</label>
                <svg class="pointer-events-none absolute left-2.5 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path stroke-linecap="round" d="m20 20-3.5-3.5"/></svg>
                <input id="cab-search" type="search" x-model="q" @input="page = 1" placeholder="ค้นหา MO / ชื่อตู้" class="w-full rounded-lg border-slate-300 py-1.5 pl-8 text-sm">
            </div>
            <label class="sr-only" for="cab-status">สถานะ</label>
            <select id="cab-status" x-model="status" @change="page = 1" class="rounded-lg border-slate-300 py-1.5 text-sm">
                <option value="">ทุกสถานะ</option>
                <option value="not_started">ยังไม่เริ่ม</option>
                <option value="in_progress">กำลังผลิต</option>
                <option value="completed">เสร็จแล้ว</option>
                <option value="on_hold">พักไว้</option>
            </select>
            @if (count($overview['types']) > 1)
                <label class="sr-only" for="cab-type">ประเภท</label>
                <select id="cab-type" x-model="type" @change="page = 1" class="rounded-lg border-slate-300 py-1.5 text-sm">
                    <option value="">ทุกประเภท</option>
                    @foreach ($overview['types'] as $type)<option value="{{ $type }}">{{ $type }}</option>@endforeach
                </select>
            @endif
            <label class="sr-only" for="cab-risk">ความเสี่ยง</label>
            <select id="cab-risk" x-model="risk" @change="page = 1" class="rounded-lg border-slate-300 py-1.5 text-sm">
                <option value="">ทุกความเสี่ยง</option>
                <option value="high">สูง</option>
                <option value="watch">เฝ้าระวัง</option>
                <option value="ok">ปกติ</option>
                <option value="delayed">ตู้ล่าช้า</option>
                <option value="late-tasks">มีงานเลยกำหนด</option>
            </select>
            <div class="ml-auto flex flex-wrap items-center gap-2">
                @if ($overview['canDispatch'] && $overview['groups'])
                    @php
                        // every step a group offers, named with its template when the project mixes templates
                        $steps = collect($overview['groups'])->flatMap(fn ($g) => collect($g['steps'])->map(fn ($st) => ['label' => (count($overview['groups']) > 1 ? ($g['templateName'] ?? 'Template').' · ' : '').$st['label'], 'parts' => $st['parts']]))->values();
                    @endphp
                    <div x-data="pmoStepDispatch(@js($steps))">
                        <button type="button" @click="open(0)" class="rounded-lg border border-slate-300 px-3 py-1.5 text-sm font-medium text-slate-700 hover:bg-slate-50">จ่ายงานทั้งขั้น</button>
                        @include('projects.partials._step-dispatch')
                    </div>
                @endif
                @editplan
                <button type="button" x-data @click="$dispatch('open-modal', 'checklist-bulk')" class="rounded-lg px-2 py-1.5 text-sm font-medium text-blue-600 hover:bg-blue-50">แก้ Checklist ทั้งโครงการ</button>
                <button type="button" x-data @click="$dispatch('open-modal', 'add-cabinet')" class="rounded-lg bg-blue-600 px-3 py-1.5 text-sm font-semibold text-white hover:bg-blue-700">+ เพิ่มตู้</button>
                @endeditplan
            </div>
        </div>

        <div class="relative overflow-x-auto">   {{-- relative: keeps the sr-only header label inside the scroll box --}}
            <table class="min-w-full text-sm">
                <thead>
                    <tr class="border-b border-slate-100 bg-slate-50 text-left text-xs font-semibold text-slate-500">
                        <th class="px-4 py-2">เลขตู้ / MO</th>
                        <th class="hidden px-2 py-2 2xl:table-cell">ประเภท</th>
                        <th class="px-2 py-2">ความคืบหน้า</th>
                        <th class="hidden px-2 py-2 md:table-cell">ขั้นตอนปัจจุบัน</th>
                        <th class="hidden px-2 py-2 sm:table-cell">กำหนดเสร็จ</th>
                        <th class="hidden px-2 py-2 lg:table-cell">เหลือ</th>
                        <th class="px-2 py-2">สถานะ</th>
                        <th class="hidden whitespace-nowrap px-2 py-2 sm:table-cell">ความเสี่ยง</th>
                        <th class="hidden px-2 py-2 xl:table-cell">งานค้าง</th>
                        <th class="hidden px-2 py-2 xl:table-cell">ผู้รับผิดชอบ</th>
                        <th class="px-2 py-2 text-right"><span class="sr-only">ดำเนินการ</span></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <template x-for="r in paged()" :key="r.id">
                        <tr class="hover:bg-slate-50">
                            <td class="max-w-[13rem] px-4 py-2.5">
                                <a :href="r.url" class="line-clamp-2 break-words font-semibold leading-snug text-ae-navy hover:underline" x-text="r.mo" :title="r.mo"></a>
                                <div class="truncate text-xs text-slate-500" x-text="r.model"></div>
                            </td>
                            <td class="hidden max-w-[7rem] truncate px-2 py-2.5 text-slate-600 2xl:table-cell" x-text="r.type || '-'" :title="r.type"></td>
                            <td class="px-2 py-2.5">
                                <div class="flex min-w-[5.5rem] items-center gap-1.5">
                                    <div class="h-1.5 flex-1 overflow-hidden rounded-full bg-slate-100"><div class="h-1.5 rounded-full" :class="r.progress >= 100 ? 'bg-emerald-500' : 'bg-blue-600'" :style="'width:' + r.progress + '%'"></div></div>
                                    <span class="w-9 text-right text-xs font-bold tabular-nums text-slate-700" x-text="r.progress + '%'"></span>
                                </div>
                            </td>
                            <td class="hidden max-w-[11rem] px-2 py-2.5 md:table-cell">
                                <span class="flex items-center gap-1.5 text-slate-700"><span class="h-2 w-2 shrink-0 rounded-full" :style="'background:' + r.currentColor"></span><span class="truncate" x-text="r.current" :title="r.current"></span></span>
                            </td>
                            <td class="hidden whitespace-nowrap px-2 py-2.5 tabular-nums text-slate-600 sm:table-cell" x-text="r.due || '-'"></td>
                            <td class="hidden whitespace-nowrap px-2 py-2.5 text-xs font-semibold lg:table-cell"
                                :class="r.daysLeft === null ? 'text-slate-400' : (r.daysLeft < 0 ? 'text-red-600' : (r.daysLeft <= 7 ? 'text-orange-600' : 'text-slate-600'))"
                                x-text="r.daysLeft === null ? '-' : (r.daysLeft < 0 ? 'เลย ' + (-r.daysLeft) + ' วัน' : r.daysLeft + ' วัน')"></td>
                            <td class="px-2 py-2.5"><span class="whitespace-nowrap rounded-full px-2 py-0.5 text-xs font-semibold" :class="statusClass(r.status)" x-text="statusLabel(r.status)"></span></td>
                            <td class="hidden px-2 py-2.5 sm:table-cell"><span class="whitespace-nowrap text-xs font-semibold" :class="riskClass(r.risk)" x-text="riskLabel(r.risk)"></span></td>
                            <td class="hidden whitespace-nowrap px-2 py-2.5 text-xs xl:table-cell">
                                <span class="font-semibold text-slate-700" x-text="r.pending + ' งาน'"></span>
                                <span x-show="r.lateTasks" class="text-red-600" x-text="' · เลย ' + r.lateTasks"></span>
                            </td>
                            <td class="hidden max-w-[8rem] truncate px-2 py-2.5 text-slate-600 xl:table-cell" x-text="r.responsible" :title="r.responsible"></td>
                            <td class="whitespace-nowrap px-2 py-2.5 text-right">
                                <a :href="r.url" class="rounded-md px-2 py-1 text-xs font-semibold text-blue-600 hover:bg-blue-50">เปิด</a>
                                @editplan
                                <button type="button" @click="$dispatch('open-modal', 'copy-cabinet-' + r.id)" class="rounded-md p-1 text-slate-400 hover:bg-slate-100 hover:text-blue-600" title="คัดลอกตู้" :aria-label="'คัดลอกตู้ ' + r.mo">
                                    <svg class="inline h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><rect x="9" y="9" width="12" height="12" rx="2"/><path d="M5 15H4a1 1 0 0 1-1-1V4a1 1 0 0 1 1-1h10a1 1 0 0 1 1 1v1"/></svg>
                                </button>
                                @endeditplan
                            </td>
                        </tr>
                    </template>
                    <tr x-show="! filtered().length">
                        <td colspan="11" class="px-4 py-8 text-center text-sm text-slate-400">{{ $overview['cabinetCount'] ? 'ไม่มีตู้ที่ตรงกับตัวกรอง' : 'ยังไม่มีตู้ไฟฟ้าในโครงการนี้' }}</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div class="flex flex-wrap items-center justify-between gap-2 border-t border-slate-100 px-4 py-2.5 text-xs text-slate-500" x-show="filtered().length">
            <span x-text="'แสดง ' + ((page - 1) * per + 1) + '–' + Math.min(page * per, filtered().length) + ' จาก ' + filtered().length + ' ตู้'"></span>
            <span class="flex items-center gap-1" x-show="pages() > 1">
                <button type="button" @click="page--" :disabled="page <= 1" class="rounded-md border border-slate-200 px-2 py-1 font-medium hover:bg-slate-50 disabled:opacity-40" aria-label="หน้าก่อน">‹</button>
                <span class="px-1 tabular-nums" x-text="page + ' / ' + pages()"></span>
                <button type="button" @click="page++" :disabled="page >= pages()" class="rounded-md border border-slate-200 px-2 py-1 font-medium hover:bg-slate-50 disabled:opacity-40" aria-label="หน้าถัดไป">›</button>
            </span>
        </div>
    </section>

    {{-- project-management tasks (separate from cabinet production) --}}
    <x-card title="งานบริหารโครงการ" subtitle="แยกจากงานผลิตตู้">
        <x-slot name="actions">
            @editplan
            <button type="button" x-data @click="$dispatch('open-modal', 'add-project-task')" class="text-sm font-medium text-blue-600 hover:underline">+ เพิ่มงาน</button>
            @endeditplan
        </x-slot>
        <div class="overflow-x-auto -mx-5 -mb-5">
            <table class="min-w-full text-sm">
                <thead>
                    <tr class="text-left text-xs font-semibold text-slate-500 border-b border-slate-100">
                        <th class="px-5 py-2">งาน</th>
                        <th class="px-5 py-2">ผู้รับผิดชอบ</th>
                        <th class="px-5 py-2">กำหนด</th>
                        <th class="px-5 py-2">สถานะ</th>
                        <th class="px-5 py-2 w-40">ความคืบหน้า</th>
                        <th class="px-5 py-2"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($project->projectTasks as $task)
                        <tr class="hover:bg-slate-50 {{ $task->is_overdue ? 'bg-red-50' : '' }}">
                            <td class="px-5 py-3 font-medium text-slate-800">{{ $task->name }}</td>
                            <td class="px-5 py-3 text-slate-600">{{ $task->owner?->name ?? '-' }}</td>
                            <td class="px-5 py-3 text-slate-600">
                                {{ optional($task->due_date)->thaiDate() ?? '-' }}
                                @if ($task->is_overdue)<span class="text-xs text-red-600 font-semibold ml-1">Late</span>@endif
                            </td>
                            <td class="px-5 py-3"><x-status-badge :status="$task->status" /></td>
                            <td class="px-5 py-3"><x-progress-bar :value="$task->progress" size="sm" /></td>
                            <td class="px-5 py-3 text-right whitespace-nowrap">
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
                        <tr><td colspan="6" class="px-5 py-5 text-center text-slate-400">ยังไม่มีงานบริหารโครงการ</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-card>
</div>
