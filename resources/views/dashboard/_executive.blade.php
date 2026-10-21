{{-- The dashboard's "ภาพรวมผู้บริหาร" view; data from App\Services\ExecutiveReport ($exec). reports/ceo prints the fuller report.
     Read on desktops, factory phones and a meeting-room projector (PRODUCT.md): Thai first, nothing that matters under 14px,
     and every count opens the list behind it. "Done" / "late" follow CabinetSubtask::isDone() like every other page. --}}
@php
    $s = $exec['statusCounts'];
    $list = fn (array $q = []) => route('projects.index', $q);
    // every visible project sits in exactly one of the five, so they add up to the first
    $cards = [
        ['ทั้งหมด', 'All projects', $s['total'], 'bg-[#F28C28]', 'ไม่นับที่ยกเลิก', $list()],
        ['ตามแผน', 'On track', $s['inProgress'], 'bg-[#1479D7]', 'กำลังทำ ยังไม่เลยวันส่ง', $list(['status' => 'in_progress', 'due' => 'ontrack'])],
        ['ล่าช้า', 'Delayed', $s['delayed'], 'bg-[#D93A31]', 'กำลังทำ แต่เลยวันส่งแล้ว', $list(['due' => 'late'])],
        ['รอเริ่ม', 'Not started', $s['notStarted'], 'bg-[#94A3B8]', 'Draft / Planning', $list(['status' => 'not_started'])],
        ['พักดำเนินการ', 'On hold', $s['onHold'], 'bg-[#475569]', 'หยุดไว้ชั่วคราว', $list(['status' => 'on_hold'])],
        ['เสร็จสิ้น', 'Completed', $s['completed'], 'bg-[#1F9D74]', 'ปิดโครงการแล้ว', $list(['status' => 'completed'])],
    ];

    // one row per department: its late work (if any) and how many running projects it still has work in
    $late = collect($exec['departments'])->keyBy(fn ($d) => (string) $d['id']);
    $open = collect($exec['deptProjects'])->keyBy(fn ($d) => (string) $d['id']);
    $departments = $late->keys()->merge($open->keys())->unique()->map(fn ($id) => [
        'id' => $id !== '' ? (int) $id : null,
        'name' => $late[$id]['name'] ?? $open[$id]['name'],
        'late' => $late[$id]['late'] ?? 0, 'cabinets' => $late[$id]['cabinets'] ?? 0, 'days' => $late[$id]['days'] ?? 0,
        'projects' => $open[$id]['projects'] ?? 0,
    ])->sortBy(fn ($d) => [-$d['late'], -$d['projects'], $d['name']])->values();
    $maxLate = max(1, $departments->max('late'));
    $openCount = $s['total'] - $s['completed'];
    $deptLink = fn ($id) => $id ? route('dashboard', ['view' => 'dept', 'department' => $id]) : null;

    // cabinets due per month (last month → four ahead): planned against finished
    $months = collect($exec['months']);
    $maxMonth = max(1, $months->max('planned'));

    // projects to follow, by the same rule as the cards: in progress and past due first, then at risk, then paused and past due
    $follow = collect($exec['projects'])->map(function ($p) {
        $pastDue = $p['days'] !== null && $p['days'] < 0;
        if ($pastDue) {
            $p['dueNote'] = 'เลย '.abs($p['days']).' วัน';   // also when production is done but the project is still open
        }
        $p['tag'] = match (true) {
            $p['status'] === 'in_progress' && $pastDue => 'late',
            $p['status'] === 'on_hold' && $pastDue => 'paused',
            $p['state'] === 'watch' => 'watch',
            default => null,
        };

        return $p;
    })->filter(fn ($p) => $p['tag'])->sortBy(fn ($p) => ['late' => 0, 'watch' => 1, 'paused' => 2][$p['tag']])->values();
    $shown = $follow->take(8);
    $tags = [
        'late' => ['ล่าช้า', 'text-[#C42B22]', 'bg-[#D93A31]'],
        'watch' => ['เฝ้าระวัง', 'text-[#B3400B]', 'bg-[#C2410C]'],
        'paused' => ['พักไว้ · เลยวันส่ง', 'text-slate-700', 'bg-[#475569]'],
    ];
    $hasPm = $shown->contains(fn ($p) => filled($p['pm']));
@endphp

{{-- order: phones put the projects to follow right after the numbers; wider screens keep the charts first --}}
<div class="flex flex-col gap-5"
     x-data x-init="setInterval(() => { if (! document.hidden) location.reload() }, 300000)">   {{-- left open on a meeting-room screen: fresh every 5 minutes --}}
    {{-- brand bar: name, page, as-of and the report in one slim row --}}
    <div class="overflow-hidden rounded-xl bg-[#1C1D21] shadow-sm">
        <div class="flex flex-wrap items-center justify-between gap-x-6 gap-y-3 px-5 py-3 sm:px-6">
            <div class="min-w-0">
                <p class="flex flex-wrap items-baseline gap-x-3 gap-y-0.5">
                    <span class="text-lg font-bold tracking-wide text-white">AVATAR ELECTRIC</span>
                    <span class="text-lg font-bold text-[#F6A04D]">ภาพรวมการบริหารโครงการ</span>
                    <span class="text-xs font-semibold uppercase tracking-wider text-slate-400">Project overview</span>
                </p>
                <p class="mt-0.5 text-sm text-slate-300">ข้อมูล ณ {{ $exec['asOf']->thaiDateTime() }} น. · อัปเดตเองทุก 5 นาที</p>
            </div>
            <a href="{{ route('reports.ceo') }}" target="_blank" rel="noopener"
               class="inline-flex min-h-11 items-center gap-2 rounded-lg bg-[#F28C28] px-4 text-sm font-semibold text-[#1C1D21] hover:bg-[#F6A04D] focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#F28C28]">
                <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3v12m0 0l-4.5-4.5M12 15l4.5-4.5M4 17v2a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-2"/></svg>
                ส่งออกรายงาน CEO
            </a>
        </div>
        <div class="h-1 bg-[#F28C28]"></div>
    </div>

    {{-- key numbers; each opens the matching project list --}}
    <section aria-label="จำนวนโครงการตามสถานะ" class="grid grid-cols-2 gap-3 sm:grid-cols-3 xl:grid-cols-6">
        @foreach ($cards as [$label, $en, $n, $bar, $caption, $href])
            <a href="{{ $href }}"
               class="group flex flex-col overflow-hidden rounded-xl bg-white shadow-sm ring-1 ring-slate-200 hover:ring-2 hover:ring-[#F28C28] focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#F28C28]">
                <span class="block h-1.5 {{ $bar }}"></span>
                <span class="flex flex-1 flex-col px-4 pb-3.5 pt-3">
                    <span class="flex items-baseline justify-between gap-2">
                        <span class="text-[15px] font-semibold text-slate-800">{{ $label }}</span>
                        <span class="text-slate-400 group-hover:text-[#C2410C]" aria-hidden="true">→</span>
                    </span>
                    <span class="text-xs font-medium uppercase tracking-wide text-slate-500">{{ $en }}</span>
                    <span class="mt-1.5 text-4xl font-bold tabular-nums {{ $label === 'ล่าช้า' && $n ? 'text-[#C42B22]' : 'text-[#1C1D21]' }}">{{ $n }}</span>
                    <span class="mt-1 text-sm text-slate-600">{{ $caption }}</span>
                </span>
            </a>
        @endforeach
    </section>

    {{-- projects to follow --}}
    <section aria-labelledby="ov-follow" class="order-none rounded-xl bg-white px-4 py-5 shadow-sm ring-1 ring-slate-200 sm:px-6 lg:order-last">
        <div class="mb-4 flex flex-wrap items-baseline justify-between gap-2 px-1 sm:px-0">
            <div>
                <h3 id="ov-follow" class="text-lg font-bold text-[#1C1D21]">โครงการที่ควรติดตาม</h3>
                <p class="text-sm text-slate-600"><span class="text-xs font-semibold uppercase tracking-wider text-slate-500">Priority projects</span>
                    · <strong class="font-semibold text-[#C42B22]">ล่าช้า</strong> = เลยวันส่งแล้ว
                    · <strong class="font-semibold text-[#B3400B]">เฝ้าระวัง</strong> = มีงานย่อยเลยกำหนด หรือผลิตช้ากว่าเวลาที่ผ่านไปเกิน 10%</p>
            </div>
            @if ($follow->count() > $shown->count())
                <p class="text-sm text-slate-600">แสดง {{ $shown->count() }} จาก {{ $follow->count() }} โครงการ · <a href="{{ $list(['due' => 'late']) }}" class="font-semibold text-[#1479D7] hover:underline">ดูโครงการล่าช้าทั้งหมด</a></p>
            @endif
        </div>

        @if ($shown->isNotEmpty())
            {{-- phones: one card per project, the status and the reason first --}}
            <ul class="space-y-3 sm:hidden">
                @foreach ($shown as $p)
                    @php [$th, $text, $dot] = $tags[$p['tag']]; @endphp
                    <li>
                        <a href="{{ $p['url'] }}" class="block rounded-xl border border-slate-200 px-4 py-3 active:bg-slate-50">
                            <span class="flex items-center justify-between gap-3">
                                <span class="font-bold text-[#1C1D21]">{{ $p['no'] }}</span>
                                <span class="inline-flex items-center gap-1.5 text-[15px] font-bold {{ $text }}"><span class="h-2 w-2 rounded-full {{ $dot }}"></span>{{ $th }}</span>
                            </span>
                            <span class="mt-1 block text-[15px] font-medium text-slate-800">{{ $p['name'] }}</span>
                            <span class="mt-1 block text-sm font-medium {{ $text }}">{{ $p['issue'] }}</span>
                            <span class="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-sm text-slate-600">
                                <span>ส่ง {{ $p['due'] }} <span class="{{ $p['tag'] === 'late' ? 'font-semibold text-[#C42B22]' : '' }}">({{ $p['dueNote'] }})</span></span>
                                <span>ผลิตแล้ว <strong class="tabular-nums text-slate-800">{{ $p['progress'] }}%</strong>@if ($p['timeElapsed'] !== null) · เวลาผ่านไป {{ $p['timeElapsed'] }}%@endif</span>
                                @if ($p['pm'])<span>PM {{ $p['pm'] }}</span>@endif
                            </span>
                        </a>
                    </li>
                @endforeach
            </ul>

            {{-- tablet and up: the table --}}
            <div class="hidden overflow-x-auto sm:block">
                <table class="w-full text-[15px]">
                    <thead>
                        <tr class="bg-[#26282E] text-left text-white">
                            @foreach (array_filter([['เลขที่ SO', 'SO'], ['โครงการ / ลูกค้า', 'Project'], $hasPm ? ['ผู้จัดการโครงการ', 'PM'] : null, ['กำหนดส่ง', 'Due date'], ['ผลิตแล้ว เทียบเวลา', 'Progress vs time'], ['สถานะ', 'Status']]) as [$th, $en])
                                <th scope="col" class="whitespace-nowrap px-4 py-2.5 align-bottom font-semibold {{ $loop->first ? 'rounded-l-lg' : '' }} {{ $loop->last ? 'rounded-r-lg' : '' }}">
                                    {{ $th }}<span class="block text-[11px] font-medium uppercase tracking-wider text-slate-400">{{ $en }}</span>
                                </th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($shown as $p)
                            @php [$th, $text, $dot] = $tags[$p['tag']]; @endphp
                            <tr class="border-b border-slate-100 align-top last:border-0">
                                <td class="whitespace-nowrap px-4 py-3 font-bold text-[#1C1D21]"><a href="{{ $p['url'] }}" class="hover:text-[#B3400B] hover:underline">{{ $p['no'] }}</a></td>
                                <td class="px-4 py-3">
                                    <a href="{{ $p['url'] }}" class="font-medium text-slate-800 hover:underline">{{ $p['name'] }}</a>
                                    @if ($p['customer'])<span class="block text-sm text-slate-600">{{ $p['customer'] }}</span>@endif
                                </td>
                                @if ($hasPm)<td class="px-4 py-3 text-slate-700">{{ $p['pm'] ?: 'ยังไม่ระบุ' }}</td>@endif
                                <td class="whitespace-nowrap px-4 py-3 tabular-nums text-slate-800">{{ $p['due'] }}<span class="block text-sm {{ $p['tag'] === 'late' ? 'font-semibold text-[#C42B22]' : 'text-slate-600' }}">{{ $p['dueNote'] }}</span></td>
                                <td class="w-44 px-4 py-3">
                                    {{-- production against the share of the start → due span already used (the dark tick) --}}
                                    <span class="font-semibold tabular-nums text-slate-800">{{ $p['progress'] }}%</span>
                                    <span class="relative mt-1.5 block h-2 rounded-full bg-slate-200" role="img"
                                          aria-label="ผลิตแล้ว {{ $p['progress'] }}%{{ $p['timeElapsed'] !== null ? ' เวลาผ่านไป '.$p['timeElapsed'].'%' : '' }}">
                                        <span class="block h-full rounded-full {{ $p['timeElapsed'] !== null && $p['progress'] < $p['timeElapsed'] ? 'bg-[#C2410C]' : 'bg-[#1479D7]' }}" style="width: {{ min(100, $p['progress']) }}%"></span>
                                        @if ($p['timeElapsed'] !== null)
                                            <span class="absolute -top-1 h-4 w-0.5 rounded bg-[#1C1D21]" style="left: calc({{ $p['timeElapsed'] }}% - 1px)"></span>
                                        @endif
                                    </span>
                                    @if ($p['timeElapsed'] !== null)<span class="mt-1 block text-sm text-slate-600">เวลาผ่านไป {{ $p['timeElapsed'] }}%</span>@endif
                                </td>
                                <td class="px-4 py-3">
                                    <span class="inline-flex items-center gap-1.5 font-bold {{ $text }}"><span class="h-2 w-2 rounded-full {{ $dot }}"></span>{{ $th }}</span>
                                    <span class="block text-sm text-slate-700">{{ $p['issue'] }}</span>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @else
            <p class="rounded-lg bg-emerald-50 px-4 py-6 text-center text-[15px] font-medium text-emerald-800">ไม่มีโครงการที่ล่าช้าหรือมีความเสี่ยง</p>
        @endif
    </section>

    <div class="grid gap-5 lg:grid-cols-[3fr_2fr]">
        {{-- departments: where the late work sits, and how many running projects each still has work in --}}
        <section aria-labelledby="ov-dept" class="rounded-xl bg-white px-4 py-5 shadow-sm ring-1 ring-slate-200 sm:px-6">
            <h3 id="ov-dept" class="text-lg font-bold text-[#1C1D21]">งานค้างตามแผนก</h3>
            <p class="mb-3 text-sm text-slate-600"><span class="text-xs font-semibold uppercase tracking-wider text-slate-500">Departments</span> · งานย่อยที่เลยกำหนด และจำนวนโครงการที่แผนกยังมีงานค้าง จาก {{ $openCount }} โครงการที่ยังไม่ปิด</p>
            @if ($departments->isNotEmpty())
                <div class="hidden grid-cols-[minmax(0,9.5rem)_minmax(0,1fr)_6.5rem] gap-4 border-b border-slate-100 px-2 pb-2 text-xs font-semibold uppercase tracking-wide text-slate-500 sm:grid">
                    <span>แผนก</span><span>งานเลยกำหนด</span><span class="text-right">มีงานค้างใน</span>
                </div>
                <ul class="divide-y divide-slate-100">
                    @foreach ($departments as $d)
                        @php $href = $deptLink($d['id']); @endphp
                        <li>
                            <{{ $href ? 'a' : 'div' }} @if ($href) href="{{ $href }}" @endif
                                class="grid grid-cols-[minmax(0,1fr)_auto] items-center gap-x-4 gap-y-1.5 px-2 py-2.5 sm:grid-cols-[minmax(0,9.5rem)_minmax(0,1fr)_6.5rem] {{ $href ? 'hover:bg-slate-50' : '' }}">
                                <span class="text-[15px] font-semibold leading-snug text-slate-800">{{ $d['name'] }}</span>
                                <span class="col-span-2 row-start-2 min-w-0 sm:col-span-1 sm:row-start-auto">
                                    @if ($d['late'])
                                        <span class="block h-3 rounded-full bg-slate-100" role="img" aria-label="{{ $d['name'] }} เลยกำหนด {{ $d['late'] }} งาน">
                                            <span class="block h-full rounded-full bg-[#D93A31]" style="width: {{ max(4, round($d['late'] / $maxLate * 100)) }}%"></span>
                                        </span>
                                        <span class="mt-1 block text-sm text-slate-700"><strong class="tabular-nums text-[#C42B22]">{{ $d['late'] }} งาน</strong> · {{ $d['cabinets'] }} ตู้ · ช้าสุด {{ $d['days'] }} วัน</span>
                                    @else
                                        <span class="text-sm font-medium text-emerald-800">ไม่มีงานเลยกำหนด</span>
                                    @endif
                                </span>
                                <span class="whitespace-nowrap text-right text-[15px] font-bold tabular-nums text-[#1C1D21]">{{ $d['projects'] }} <span class="text-sm font-normal text-slate-600">โครงการ</span></span>
                            </{{ $href ? 'a' : 'div' }}>
                        </li>
                    @endforeach
                </ul>
            @else
                <p class="rounded-lg bg-emerald-50 px-4 py-6 text-center text-[15px] font-medium text-emerald-800">ไม่มีงานค้างในแผนกใด</p>
            @endif
        </section>

        {{-- cabinets due per month: what the factory actually ships --}}
        <section aria-labelledby="ov-months" class="rounded-xl bg-white px-4 py-5 shadow-sm ring-1 ring-slate-200 sm:px-6">
            <h3 id="ov-months" class="text-lg font-bold text-[#1C1D21]">ส่งมอบตู้รายเดือน</h3>
            <p class="mb-1 text-sm text-slate-600"><span class="text-xs font-semibold uppercase tracking-wider text-slate-500">Cabinet deliveries</span> · ตู้ที่มีกำหนดส่งในเดือนนั้น เสร็จแล้วเทียบกับตามแผน</p>
            <p class="mb-4 flex flex-wrap gap-x-4 gap-y-1 text-sm text-slate-600">
                <span class="inline-flex items-center gap-1.5"><span class="h-3 w-3 rounded-sm bg-[#1F9D74]"></span>เสร็จแล้ว</span>
                <span class="inline-flex items-center gap-1.5"><span class="h-3 w-3 rounded-sm border border-slate-400 bg-slate-200"></span>ตามแผน</span>
            </p>
            <ol class="grid grid-cols-6 items-end gap-2" aria-label="ตู้ที่ส่งมอบรายเดือน">
                @foreach ($months as $m)
                    @php
                        $past = ! $m['current'] && $m['key'] < $exec['asOf']->format('Y-m');
                        $short = $past ? $m['planned'] - $m['done'] : 0;
                    @endphp
                    <li class="flex flex-col items-center text-center">
                        <span class="text-sm font-bold tabular-nums text-[#1C1D21]">{{ $m['done'] }}/{{ $m['planned'] }}</span>
                        {{-- the outlined bar is the plan, the green part inside it what is finished --}}
                        <span class="mt-1 flex h-32 w-full max-w-12 items-end border-b-2 border-slate-400" role="img"
                              aria-label="{{ $m['label'] }} เสร็จ {{ $m['done'] }} จาก {{ $m['planned'] }} ตู้">
                            @if ($m['planned'])
                                <span class="flex w-full items-end overflow-hidden rounded-t-md border border-b-0 border-slate-400 bg-slate-200" style="height: {{ round($m['planned'] / $maxMonth * 100) }}%">
                                    <span class="block w-full bg-[#1F9D74]" style="height: {{ round($m['done'] / $m['planned'] * 100) }}%"></span>
                                </span>
                            @endif
                        </span>
                        <span class="mt-1.5 text-sm {{ $m['current'] ? 'rounded bg-[#1C1D21] px-1.5 font-bold text-white' : 'text-slate-700' }}">{{ $m['label'] }}</span>
                        @if ($short > 0)<span class="mt-0.5 text-sm font-semibold text-[#C42B22]">ค้าง {{ $short }}</span>@endif
                    </li>
                @endforeach
            </ol>
            @php $k = $exec['kpis']; @endphp
            <dl class="mt-5 grid grid-cols-3 gap-3 border-t border-slate-100 pt-4 text-center">
                <div><dt class="text-sm text-slate-600">เดือนนี้ต้องส่ง</dt><dd class="text-2xl font-bold tabular-nums text-[#1C1D21]">{{ $k['monthPlanned'] }} <span class="text-sm font-normal text-slate-600">ตู้</span></dd></div>
                <div><dt class="text-sm text-slate-600">เสร็จแล้ว</dt><dd class="text-2xl font-bold tabular-nums text-[#17805E]">{{ $k['monthDone'] }} <span class="text-sm font-normal text-slate-600">ตู้</span></dd></div>
                <div><dt class="text-sm text-slate-600">เลยกำหนดแล้ว</dt><dd class="text-2xl font-bold tabular-nums {{ $k['monthOverdue'] ? 'text-[#C42B22]' : 'text-[#1C1D21]' }}">{{ $k['monthOverdue'] }} <span class="text-sm font-normal text-slate-600">ตู้</span></dd></div>
            </dl>
            <p class="mt-3 text-sm text-slate-600">ทั้งหมด {{ $k['cabinetsTotal'] }} ตู้ในโครงการที่กำลังทำ · เสร็จแล้ว {{ $k['cabinetsDone'] }} ตู้</p>
        </section>
    </div>
</div>
