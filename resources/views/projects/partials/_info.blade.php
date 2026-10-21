{{-- The project page's "ข้อมูลโครงการ" tab: project details (every field, grouped) + production progress, the alerts
     that need following up, and the cabinet / MO table. Data from App\Services\ProjectOverview ($overview). --}}
@php
    $s = $overview['summary'];
    $baht = fn ($v) => $v !== null ? number_format((float) $v, 2) : null;
    $tones = [
        'red' => ['btn' => 'text-red-600', 'ring' => 'ring-red-200', 'dot' => 'bg-red-500'],
        'orange' => ['btn' => 'text-orange-600', 'ring' => 'ring-orange-200', 'dot' => 'bg-orange-500'],
        'blue' => ['btn' => 'text-blue-600', 'ring' => 'ring-blue-200', 'dot' => 'bg-blue-500'],
        'gray' => ['btn' => 'text-slate-600', 'ring' => 'ring-slate-200', 'dot' => 'bg-slate-400'],
    ];
    $canContact = auth()->user()->allows('data.contact');   // Settings > สิทธิ์การใช้งาน
    $canFinance = auth()->user()->allows('data.finance');
    $daysLeft = $project->isLocked() ? null : $project->days_remaining;

    // [label, value (null = not filled in yet), extra dd classes]; the form's fields, grouped. Legacy fields the form no
    // longer asks for (ใบสั่งขาย, เจ้าของโครงการ) appear only when an older project still has a value.
    $groups = [
        'โครงการ' => [
            ['เลขที่โครงการ', e($project->project_no), 'font-semibold'],
            ['ชื่อโครงการ', e($project->project_name), 'font-semibold'],
            ['ลูกค้า', $project->customer_name ? e($project->customer_name) : null, ''],
            ['สถานะ', 'status', 'badge'],   // drawn with the status / priority components below
            ['ประเภทงาน', $project->jobType ? e($project->jobType->name) : null, ''],
            ['คำอธิบาย', $project->description ? nl2br(e($project->description)) : null, 'whitespace-normal'],
        ],
        'กำหนดการ' => [
            ['วันเริ่ม', $project->start_date?->thaiDate(), 'tabular-nums'],
            ['วันครบกำหนด', $project->due_date ? $project->due_date->thaiDate().($daysLeft !== null
                ? ' <span class="ml-1 whitespace-nowrap rounded-full px-2 py-0.5 text-xs font-semibold '.($daysLeft < 0 ? 'bg-red-100 text-red-700' : ($daysLeft <= 7 ? 'bg-orange-100 text-orange-700' : 'bg-slate-100 text-slate-600')).'">'
                    .($daysLeft < 0 ? 'เลยกำหนด '.abs($daysLeft).' วัน' : ($daysLeft === 0 ? 'ครบกำหนดวันนี้' : 'เหลือ '.$daysLeft.' วัน')).'</span>' : '') : null, 'tabular-nums'],
            ['วันทำงานคงเหลือ', $s['workingDaysLeft'] !== null ? $s['workingDaysLeft'].' วัน (จ.–ศ.)' : '-', ''],   // worked out from the due date, never typed in
        ],
        'ทีมและการติดต่อ' => array_values(array_filter([
            ['ผู้จัดการโครงการ (PM)', $project->projectManager ? e($project->projectManager->name) : null, ''],
            ['พนักงานขาย', $project->sales_person ? e($project->sales_person) : null, ''],
            $project->project_owner ? ['เจ้าของโครงการ', e($project->project_owner), ''] : null,
            $canContact ? ['เบอร์ติดต่อลูกค้า', $project->customer_phone
                ? '<a href="tel:'.e(preg_replace('/[^0-9+]/', '', $project->customer_phone)).'" class="text-blue-600 hover:underline">'.e($project->customer_phone).'</a>' : null, ''] : null,
        ])),
        'สัญญาและการเงิน' => array_values(array_filter([
            ['เลขที่ใบสั่งซื้อ (PO)', $project->po_no ? e($project->po_no) : null, ''],
            $project->sales_order_no ? ['ใบสั่งขาย / อ้างอิง', e($project->sales_order_no), ''] : null,
            ['เงื่อนไขการชำระ', $project->payment_terms ? e($project->payment_terms) : null, ''],
            $canFinance ? ['มูลค่างาน (ก่อน VAT)', $baht($project->contract_value), 'tabular-nums'] : null,
            $canFinance && $project->contract_value !== null ? ['VAT 7%', $baht($project->vatAmount()), 'tabular-nums'] : null,
            $canFinance && $project->contract_value !== null ? ['มูลค่างานรวม VAT 7%', $baht($project->contractValueWithVat()), 'font-semibold tabular-nums text-ae-navy'] : null,
            $canFinance ? ['Margin', $project->margin !== null ? $baht($project->margin).($project->marginPercent() !== null ? ' · '.$project->marginPercent().' %' : '') : null, 'tabular-nums'] : null,
        ])),
    ];
    // what the folded card still shows: the facts people look up most
    $keyFacts = collect($groups)->flatten(1)->keyBy(0)->only(['ลูกค้า', 'วันครบกำหนด', 'ผู้จัดการโครงการ (PM)', 'เลขที่ใบสั่งซื้อ (PO)'])->values()->all();

    $pace = ['on_track' => ['ทันตามเวลา', 'text-emerald-700'], 'watch' => ['เฝ้าระวัง', 'text-orange-600'], 'behind' => ['ช้ากว่าเวลา', 'text-red-600']][$s['pace']] ?? null;
    $statusBar = array_filter([
        ['เสร็จแล้ว', $s['completed'], 'bg-emerald-500'],
        ['กำลังผลิต', $s['inProgress'], 'bg-blue-400'],
        ['พักไว้', $s['onHold'], 'bg-amber-400'],
        ['ยังไม่เริ่ม', $s['notStarted'], 'bg-slate-300'],
    ], fn ($b) => $b[0] !== 'พักไว้' || $b[1]);

    $alerts = collect($overview['alerts']);
    $quiet = $alerts->where('count', 0)->pluck('title');
@endphp

<div class="space-y-4" x-data="pmoProjectInfo(@js($overview['rows']))" @keydown.escape.window="alert = null">

    {{-- ===== 1. project details + production progress ===== --}}
    <section class="flex flex-wrap gap-4">
        {{-- folded by default (a few key facts stay visible); the choice is remembered on this browser --}}
        <div class="min-w-0 flex-[999_1_34rem] rounded-xl border border-slate-200 bg-white p-5" aria-label="รายละเอียดโครงการ"
             x-data="{ more: (() => { try { return localStorage.getItem('pmo.projectDetails') === 'open'; } catch (e) { return false; } })() }"
             x-init="$watch('more', (v) => { try { localStorage.setItem('pmo.projectDetails', v ? 'open' : 'closed'); } catch (e) {} })">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h3 class="text-base font-bold text-ae-navy">รายละเอียดโครงการ</h3>
                <button type="button" @click="more = ! more" :aria-expanded="more" aria-controls="project-details"
                        class="inline-flex items-center gap-1 rounded-lg px-2 py-1 text-sm font-semibold text-blue-600 hover:bg-blue-50">
                    <span x-text="more ? 'ย่อ' : 'ดูทั้งหมด'">ดูทั้งหมด</span>
                    <svg class="h-3.5 w-3.5 transition-transform" :class="more && 'rotate-180'" fill="none" stroke="currentColor" stroke-width="3" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M6 9l6 6 6-6"/></svg>
                </button>
            </div>
            <dl x-show="! more" class="mt-3 grid gap-x-8 gap-y-3 text-sm sm:grid-cols-2">
                @foreach ($keyFacts as [$label, $value, $class])
                    <div class="min-w-0">
                        <dt class="text-xs text-slate-500">{{ $label }}</dt>
                        <dd class="break-words {{ $value === null ? 'text-slate-400' : 'text-slate-800 '.$class }}">{!! $value ?? 'ยังไม่ระบุ' !!}</dd>
                    </div>
                @endforeach
            </dl>
            <div id="project-details" x-show="more" x-cloak class="mt-4 grid gap-x-8 gap-y-5 md:grid-cols-2">
                @foreach ($groups as $title => $fields)
                    @continue(! $fields)
                    <div>
                        <h4 class="mb-2 border-b border-slate-100 pb-1.5 text-sm font-bold text-ae-navy">{{ $title }}</h4>
                        <dl class="grid grid-cols-[8.5rem_minmax(0,1fr)] gap-x-3 gap-y-1.5 text-sm">
                            @foreach ($fields as [$label, $value, $class])
                                <dt class="text-slate-500">{{ $label }}</dt>
                                @if ($class === 'badge')
                                    <dd class="flex flex-wrap items-center gap-2"><x-project-status :status="$project->status" /><x-priority-flag :priority="$project->priority" :always="true" /></dd>
                                @else
                                    <dd class="break-words {{ $value === null ? 'text-slate-400' : 'text-slate-800 '.$class }}">{!! $value ?? 'ยังไม่ระบุ' !!}</dd>
                                @endif
                            @endforeach
                        </dl>
                    </div>
                @endforeach
            </div>
        </div>

        <div class="flex-[1_1_18rem] rounded-xl border border-slate-200 bg-white p-5" aria-label="ความคืบหน้าโครงการ">
            <div class="flex items-baseline justify-between">
                <h3 class="text-sm font-bold text-slate-600">ความคืบหน้าการผลิต</h3>
                <span class="text-3xl font-bold tabular-nums text-ae-navy">{{ $s['progress'] }}%</span>
            </div>
            <div class="relative mt-2 h-2 rounded-full bg-slate-100" role="img"
                 aria-label="ผลิตแล้ว {{ $s['progress'] }}%{{ $s['timeElapsed'] !== null ? ' เวลาผ่านไป '.$s['timeElapsed'].'%' : '' }}">
                <div class="h-2 rounded-full {{ $s['progress'] >= 100 ? 'bg-emerald-500' : 'bg-blue-600' }}" style="width: {{ $s['progress'] }}%"></div>
                @if ($s['timeElapsed'] !== null)
                    {{-- where production "should" be if work moved at an even pace from the start date to the due date --}}
                    <div class="absolute -top-1 h-4 w-0.5 -translate-x-1/2 rounded bg-slate-700" style="left: {{ $s['timeElapsed'] }}%"></div>
                @endif
            </div>
            @if ($s['timeElapsed'] !== null)
                <div class="mt-1.5 flex items-center justify-between text-xs">
                    <span class="text-slate-500" title="นับจากวันเริ่มถึงวันครบกำหนดของโครงการ">เวลาผ่านไป {{ $s['timeElapsed'] }}%</span>
                    <span class="font-bold {{ $pace[1] }}">{{ $pace[0] }}</span>
                </div>
            @endif

            @if ($s['total'])
                <div class="mt-5 flex h-2.5 overflow-hidden rounded-full bg-slate-100" role="img"
                     aria-label="ตู้ {{ $s['total'] }}: {{ collect($statusBar)->map(fn ($b) => $b[0].' '.$b[1])->implode(', ') }}">
                    @foreach ($statusBar as [$label, $n, $color])
                        @if ($n)<div class="{{ $color }}" style="width: {{ $n / $s['total'] * 100 }}%"></div>@endif
                    @endforeach
                </div>
            @endif
            <div class="mt-2 flex flex-wrap gap-x-3.5 gap-y-1 text-sm text-slate-600">
                <span><strong class="text-ae-navy">{{ $s['total'] }}</strong> ตู้</span>
                @foreach ($statusBar as [$label, $n, $color])
                    <span class="inline-flex items-center gap-1.5 {{ $n ? '' : 'text-slate-400' }}"><span class="h-2 w-2 rounded-full {{ $color }}"></span>{{ $label }} {{ $n }}</span>
                @endforeach
            </div>
        </div>
    </section>

    {{-- ===== 2. what needs following up: worded chips, each opening a short list; the empty ones are listed quietly ===== --}}
    <section aria-label="เรื่องที่ต้องตาม" class="flex flex-wrap items-center gap-2" @click.outside="alert = null">
        <span class="mr-1 text-sm font-bold text-slate-600">ต้องตาม</span>
        @foreach ($alerts->where('count', '>', 0) as $a)
            @php $t = $tones[$a['tone']]; @endphp
            <div class="relative">
                <button type="button" @click="alert = alert === '{{ $a['key'] }}' ? null : '{{ $a['key'] }}'"
                        :aria-expanded="alert === '{{ $a['key'] }}'" aria-controls="alert-{{ $a['key'] }}"
                        class="inline-flex min-h-9 items-center gap-2 rounded-full border border-slate-200 bg-white py-1 pl-3 pr-2.5 text-sm text-slate-700 shadow-sm hover:border-slate-400"
                        :class="alert === '{{ $a['key'] }}' && 'ring-2 {{ $t['ring'] }}'">
                    <span class="h-2 w-2 rounded-full {{ $t['dot'] }}"></span>
                    <strong class="tabular-nums {{ $t['btn'] }}">{{ $a['count'] }}</strong>{{ $a['title'] }}
                    <svg class="h-3 w-3 opacity-60" fill="none" stroke="currentColor" stroke-width="3" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M6 9l6 6 6-6"/></svg>
                </button>
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
                                <button type="button" @click="risk = '{{ $a['filter'] }}'; filters = true; page = 1; alert = null; $nextTick(() => $refs.table.scrollIntoView({ behavior: 'smooth', block: 'start' }))"
                                        class="text-xs font-semibold text-blue-600 hover:underline">ดูทั้งหมดในตาราง →</button>
                            @else
                                <span class="text-xs text-slate-500">แสดง {{ count($a['items']) }} จาก {{ $a['count'] }} รายการ</span>
                            @endif
                        </div>
                    @endif
                </div>
            </div>
        @endforeach
        @if ($quiet->isNotEmpty())
            <span class="text-xs text-slate-400">ไม่มี: {{ $quiet->implode(' · ') }}</span>
        @endif
    </section>

    {{-- ===== 3. cabinet / MO table ===== --}}
    <section x-ref="table" id="cabinets-section" aria-label="ตู้ / ใบสั่งผลิต" class="scroll-mt-20 rounded-xl border border-slate-200 bg-white">
        <div class="flex flex-wrap items-center gap-2 px-4 py-3">
            <h3 class="mr-2 text-base font-bold text-ae-navy">ตู้ / ใบสั่งผลิต <span class="font-medium text-slate-500">· {{ $overview['cabinetCount'] }}</span></h3>
            <div class="relative min-w-0 flex-1 basis-48 sm:max-w-xs">
                <label for="cab-search" class="sr-only">ค้นหาตู้</label>
                <svg class="pointer-events-none absolute left-2.5 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path stroke-linecap="round" d="m20 20-3.5-3.5"/></svg>
                <input id="cab-search" type="search" x-model="q" @input="page = 1" placeholder="ค้นหา MO / ชื่อตู้" class="w-full rounded-lg border-slate-300 py-1.5 pl-8 text-sm">
            </div>
            <button type="button" @click="filters = ! filters" :aria-expanded="filters" aria-controls="cab-filters"
                    class="inline-flex items-center gap-1.5 rounded-lg border border-slate-200 px-3 py-1.5 text-sm font-semibold text-slate-700 hover:bg-slate-50">
                <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" viewBox="0 0 24 24" aria-hidden="true"><path d="M4 6h16M7 12h10M10 18h4"/></svg>ตัวกรอง
                <span x-show="activeFilters()" x-cloak class="rounded-full bg-blue-600 px-1.5 text-xs font-bold text-white" x-text="activeFilters()"></span>
            </button>

            <div class="ml-auto flex flex-wrap items-center gap-2">
                @php
                    $canStep = $overview['canDispatch'] && $overview['groups'];
                    $canEdit = auth()->user()->canDispatchWork();   // the same check as @editplan
                    // every step a group offers, named with its template when the project mixes templates
                    $steps = $canStep ? collect($overview['groups'])->flatMap(fn ($g) => collect($g['steps'])->map(fn ($st) => ['label' => (count($overview['groups']) > 1 ? ($g['templateName'] ?? 'Template').' · ' : '').$st['label'], 'parts' => $st['parts']]))->values() : [];
                @endphp
                @if ($canStep || $canEdit)
                    <div class="relative" x-data="pmoStepDispatch(@js($steps))">
                        <div x-data="{ menu: false }" @click.outside="menu = false" @keydown.escape="menu = false">
                            <button type="button" @click="menu = ! menu" :aria-expanded="menu" aria-haspopup="true"
                                    class="inline-flex items-center gap-1.5 rounded-lg border border-slate-200 px-3 py-1.5 text-sm font-semibold text-slate-700 hover:bg-slate-50">
                                จ่ายงาน / Checklist
                                <svg class="h-3 w-3" fill="none" stroke="currentColor" stroke-width="3" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M6 9l6 6 6-6"/></svg>
                            </button>
                            <div x-show="menu" x-cloak x-transition.opacity class="absolute right-0 z-30 mt-1 w-56 overflow-hidden rounded-xl border border-slate-200 bg-white py-1 text-sm shadow-xl">
                                @if ($canStep)
                                    <button type="button" @click="menu = false; open(0)" class="block w-full px-4 py-2 text-left text-slate-700 hover:bg-slate-50">จ่ายงานทั้งขั้น</button>
                                @endif
                                @if ($canEdit)
                                    <button type="button" @click="menu = false; $dispatch('open-modal', 'checklist-bulk')" class="block w-full px-4 py-2 text-left text-slate-700 hover:bg-slate-50">แก้ Checklist ทั้งโครงการ</button>
                                @endif
                            </div>
                        </div>
                        @if ($canStep)
                            @include('projects.partials._step-dispatch')
                        @endif
                    </div>
                @endif
                @editplan
                <button type="button" x-data @click="$dispatch('open-modal', 'add-cabinet')" class="rounded-lg bg-blue-600 px-3 py-1.5 text-sm font-semibold text-white hover:bg-blue-700">+ เพิ่มตู้</button>
                @endeditplan
            </div>

            <div id="cab-filters" x-show="filters" x-cloak class="flex w-full flex-wrap items-center gap-2 pt-1">
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
                <button type="button" x-show="activeFilters()" @click="status = ''; type = ''; risk = ''; page = 1" class="px-2 py-1.5 text-sm font-medium text-blue-600 hover:underline">ล้างตัวกรอง</button>
            </div>
        </div>

        <div class="relative overflow-x-auto">   {{-- relative: keeps the sr-only header label inside the scroll box --}}
            <table class="min-w-full text-sm">
                <thead>
                    <tr class="border-y border-slate-100 bg-slate-50 text-left text-xs font-semibold text-slate-500">
                        <th class="w-10 py-2 pl-4 pr-0 text-right"><span aria-hidden="true">#</span><span class="sr-only">ลำดับ</span></th>
                        <th class="px-4 py-2">ตู้ @if ($overview['moPrefix'])<span class="font-normal text-slate-400" title="ทุกตู้ขึ้นต้นด้วย {{ $overview['moPrefix'] }}">({{ $overview['moPrefix'] }} …)</span>@endif</th>
                        <th class="px-2 py-2">ความคืบหน้า</th>
                        <th class="hidden px-2 py-2 md:table-cell">ตอนนี้อยู่ขั้น</th>
                        <th class="hidden px-2 py-2 sm:table-cell">ส่ง</th>
                        <th class="hidden whitespace-nowrap px-2 py-2 text-right sm:table-cell">งานเลยกำหนด</th>
                        <th class="px-2 py-2"><span class="sr-only">ดำเนินการ</span></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <template x-for="r in paged()" :key="r.id">
                        <tr class="cursor-pointer hover:bg-slate-50" @click="if (! $event.target.closest('a, button')) window.location = r.url">
                            <td class="py-2.5 pl-4 pr-0 text-right align-top text-sm font-semibold tabular-nums text-slate-400" x-text="r.no"></td>
                            <td class="max-w-[16rem] px-4 py-2.5" :title="r.mo">
                                <span class="flex items-center gap-2">
                                    <a :href="r.url" class="line-clamp-2 break-words font-semibold leading-snug text-ae-navy hover:underline" x-text="r.site"></a>
                                    <span x-show="r.status === 'on_hold'" class="shrink-0 rounded-full bg-amber-50 px-2 py-0.5 text-xs font-semibold text-amber-700">พักไว้</span>
                                </span>
                                <div class="truncate text-xs text-slate-500" x-text="r.model"></div>
                            </td>
                            <td class="px-2 py-2.5">
                                <div class="flex min-w-[6rem] items-center gap-1.5">
                                    <div class="h-1.5 flex-1 overflow-hidden rounded-full bg-slate-100"><div class="h-1.5 rounded-full" :class="r.progress >= 100 ? 'bg-emerald-500' : 'bg-blue-600'" :style="'width:' + r.progress + '%'"></div></div>
                                    <span class="w-9 text-right text-xs font-bold tabular-nums text-slate-700" x-text="r.progress + '%'"></span>
                                </div>
                            </td>
                            <td class="hidden max-w-[16rem] px-2 py-2.5 md:table-cell">
                                <span class="flex items-center gap-2">
                                    <span class="h-2 w-2 shrink-0 rounded-full" :style="'background:' + r.currentColor"></span>
                                    <span class="min-w-0">
                                        <span class="block truncate text-slate-800" x-text="r.current" :title="r.current"></span>
                                        <span class="block truncate text-xs text-slate-500" x-show="r.currentDept" x-text="r.currentDept"></span>
                                    </span>
                                </span>
                            </td>
                            <td class="hidden whitespace-nowrap px-2 py-2.5 sm:table-cell">
                                <span class="tabular-nums text-slate-600" x-text="r.due || '-'"></span>
                                <span x-show="r.daysLeft !== null" class="text-xs font-semibold" :class="dueClass(r.daysLeft)" x-text="dueLabel(r.daysLeft)"></span>
                            </td>
                            <td class="hidden whitespace-nowrap px-2 py-2.5 text-right text-sm font-bold text-red-600 sm:table-cell" x-text="r.lateTasks ? r.lateTasks + ' งาน' : ''"></td>
                            <td class="whitespace-nowrap px-2 py-2.5 text-right">
                                @editplan
                                <button type="button" @click="$dispatch('open-modal', 'copy-cabinet-' + r.id)" class="rounded-md p-1 text-slate-400 hover:bg-slate-100 hover:text-blue-600" title="คัดลอกตู้" :aria-label="'คัดลอกตู้ ' + r.mo">
                                    <svg class="inline h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><rect x="9" y="9" width="12" height="12" rx="2"/><path d="M5 15H4a1 1 0 0 1-1-1V4a1 1 0 0 1 1-1h10a1 1 0 0 1 1 1v1"/></svg>
                                </button>
                                @endeditplan
                            </td>
                        </tr>
                    </template>
                    <tr x-show="! filtered().length">
                        <td colspan="7" class="px-4 py-8 text-center text-sm text-slate-400">{{ $overview['cabinetCount'] ? 'ไม่มีตู้ที่ตรงกับตัวกรอง' : 'ยังไม่มีตู้ไฟฟ้าในโครงการนี้' }}</td>
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
</div>
