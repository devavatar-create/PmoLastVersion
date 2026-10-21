{{-- "รายงานการผลิต": App\Services\ProductionReport ($r). Five tabs on one page (?tab= keeps the one open, ?by=project
     counts projects instead of cabinets, ?projects[]= limits it to some SO, so a link shows the same); ภาพรวม answers
     the six questions in a sentence and six cards, each opening its tab. Thai first, nothing that matters under 14px,
     the unit always said ("ตู้เลยวันส่ง" vs "งานเลยกำหนด", PRODUCT.md: one number everywhere); value only with
     data.finance. Printing gives every tab in turn on the company letterhead; left on a screen it reloads every 5 min. --}}
@php
    $lateN = count($r['late']);
    $watchN = count($r['rush']) + count($r['risk']);
    $readyN = count($r['ready']);
    $mn = fn ($v) => number_format($v / 1_000_000, 1);
    $tag = [
        // label, tag, bar, card edge, text
        'late' => ['ล่าช้า', 'bg-red-50 text-red-700', 'bg-red-600', 'border-l-red-600', 'text-red-700'],
        'rush' => ['ต้องเร่ง', 'bg-orange-50 text-orange-700', 'bg-orange-500', 'border-l-orange-500', 'text-orange-700'],
        'risk' => ['เสี่ยง', 'bg-amber-50 text-amber-800', 'bg-amber-500', 'border-l-amber-500', 'text-amber-800'],
        'ok' => ['ทัน', 'bg-emerald-50 text-emerald-700', 'bg-emerald-600', 'border-l-emerald-600', 'text-emerald-700'],
        'ready' => ['พร้อมส่ง', 'bg-emerald-50 text-emerald-700', 'bg-emerald-600', 'border-l-emerald-600', 'text-emerald-700'],
        'done' => ['เสร็จ', 'bg-slate-100 text-slate-600', 'bg-slate-400', 'border-l-slate-400', 'text-slate-600'],
    ];
    $pc = $r['projectCount'];
    $prj = collect($r['projects']);
    // id => label, [cabinet count, project count], badge
    $tabs = [
        'overview' => ['ภาพรวม', null, ''],
        'late' => ['ล่าช้า / เสี่ยง', [$lateN + $watchN, $pc['late'] + $pc['watch']], 'bg-red-100 text-red-700'],
        'due' => ['ส่งมอบ', [$r['dueCount'][30], $pc['due'][30]], 'bg-amber-100 text-amber-800'],
        'stage' => ['ขั้นตอน / แผนก', [$r['mo'], $r['so']], 'bg-blue-100 text-blue-700'],
        'ready' => ['รอลูกค้ารับ', [$readyN, $pc['ready']], 'bg-emerald-100 text-emerald-700'],
    ];

    // the six answers: [question, big, unit, answer, edge colour, number colour, tab it opens | url, link text]
    $top = $r['topStage'];
    $dept = $r['topDept'];
    $valueCard = ! $canFinance ? null : ($r['value'] > 0
        ? ['มูลค่างานที่กำลังทำเท่าไร?', $mn($r['value']), 'ล้านบาท', 'ผลิตไปแล้วประมาณ '.$mn($r['produced']).' ล้าน ('.$r['producedPct'].'%)'.($r['valueMissing'] ? ' · ยังไม่กรอกมูลค่า '.$r['valueMissing'].' SO' : ''), 'border-t-blue-600', 'text-[#0B1F3A]', null, null]
        : ['มูลค่างานที่กำลังทำเท่าไร?', 'ยังไม่กรอก', '', 'ยังไม่มี SO ไหนกรอกมูลค่างาน ('.$r['valueMissing'].' SO)', 'border-t-slate-300', 'text-slate-500', route('projects.index'), 'กรอกได้ที่หน้าโครงการ']);
    $stageCard = ['งานกองอยู่ขั้นไหน แผนกไหนถือมากสุด?', $top ? $top['name'] : 'ยังไม่มีงาน', '',
        ($top ? $top['count'].' ตู้อยู่ขั้นนี้' : '').($dept ? ' · แผนก '.$dept['name'].' ถืองานมากสุด '.($dept['late'] + $dept['doing'] + $dept['wait']).' งาน'.($dept['late'] ? ' (เลยกำหนด '.$dept['late'].')' : '') : ''),
        'border-t-blue-600', 'text-[#0B1F3A]', 'stage', 'ดูขั้นตอนและแผนก'];
    $cards = array_values(array_filter([
        ['ตอนนี้มีงานสั่งผลิตเท่าไร?', $r['cabinets'], 'ตู้', $r['so'].' SO · '.$r['mo'].' MO', 'border-t-blue-600', 'text-[#0B1F3A]', 'stage', 'ดูตู้ตามขั้นตอน'],
        $valueCard,
        $stageCard,
        ['งานไหนล่าช้า หรือเสี่ยงส่งไม่ทัน?', $lateN, 'ตู้เลยวันส่ง', $watchN ? 'และอีก '.$watchN.' ตู้ต้องจับตา (ต้องเร่ง '.count($r['rush']).' · เสี่ยง '.count($r['risk']).')' : 'ไม่มีตู้ที่ต้องจับตา', 'border-t-red-600', 'text-red-700', 'late', 'ดูตู้และแผนกที่ต้องตาม'],
        ['ผลิตเสร็จแล้ว แต่ลูกค้ายังไม่รับกี่ตู้?', $readyN, 'ตู้', $readyN ? 'รอนานสุด '.$r['readyMaxWait'].' วัน' : 'ไม่มีตู้ที่รอ', 'border-t-emerald-600', 'text-emerald-700', 'ready', 'ดูตู้ที่รอรับ'],
        ['ต้องส่งอะไรเร็ว ๆ นี้?', $r['dueCount'][7], 'ตู้ใน 7 วัน', 'ใน 15 วัน '.$r['dueCount'][15].' ตู้ · ใน 30 วัน '.$r['dueCount'][30].' ตู้', 'border-t-amber-500', 'text-[#0B1F3A]', 'due', 'ดูปฏิทินส่งมอบ'],
    ]));
    $cardsProject = array_values(array_filter([
        ['ตอนนี้มีงานสั่งผลิตกี่โครงการ?', $r['so'], 'SO', $r['mo'].' MO · '.$r['cabinets'].' ตู้', 'border-t-blue-600', 'text-[#0B1F3A]', 'stage', 'ดูโครงการตามขั้นตอน'],
        $valueCard,
        $stageCard,
        ['โครงการไหนล่าช้า หรือเสี่ยงส่งไม่ทัน?', $pc['late'], 'โครงการมีตู้เลยวันส่ง', $pc['watch'] ? 'และอีก '.$pc['watch'].' โครงการต้องจับตา (ต้องเร่ง '.$pc['rush'].' · เสี่ยง '.$pc['risk'].')' : 'ไม่มีโครงการที่ต้องจับตา', 'border-t-red-600', 'text-red-700', 'late', 'ดูโครงการและแผนกที่ต้องตาม'],
        ['โครงการไหนมีตู้ผลิตเสร็จ รอลูกค้ารับ?', $pc['ready'], 'โครงการ', $pc['ready'] ? 'รวม '.$readyN.' ตู้ · รอนานสุด '.$r['readyMaxWait'].' วัน' : 'ไม่มีตู้ที่รอ', 'border-t-emerald-600', 'text-emerald-700', 'ready', 'ดูโครงการที่รอรับ'],
        ['โครงการไหนต้องส่งเร็ว ๆ นี้?', $pc['due'][7], 'โครงการใน 7 วัน', 'ใน 15 วัน '.$pc['due'][15].' · ใน 30 วัน '.$pc['due'][30].' โครงการ', 'border-t-amber-500', 'text-[#0B1F3A]', 'due', 'ดูกำหนดส่งตามโครงการ'],
    ]));

    $stages = $r['stages'];
    $firstStage = collect($stages)->search(fn ($s) => $s['late'] > 0);
    $deptMax = max(1, collect($r['departments'])->max(fn ($d) => $d['late'] + $d['doing'] + $d['wait']));
    $lateRows = collect($r['late'])->concat($r['rush'])->concat($r['risk']);
    $prjLate = $prj->whereIn('state', ['late', 'rush', 'risk'])->values();
    $prjDue = $prj->filter(fn ($p) => $p['nextDays'] !== null && $p['nextDays'] <= 30)->sortBy('nextDays')->values();
    $prjReady = $prj->where('ready', '>', 0)->sortByDesc('readyMaxWait')->values();
    $stageOrder = collect($stages)->pluck('name')->flip();
    $waitRed = \App\Services\ProductionReport::WAIT_RED_DAYS;
    $hint = 'ล่าช้า = ตู้เลยวันส่งแล้ว · ต้องเร่ง = ส่งใน 7 วันแต่ผลิตยังไม่ถึง 80% · เสี่ยง = มีงานย่อยเลยกำหนด หรือผลิตตามหลังเวลาที่ผ่านไปเกิน 10%';
@endphp

<x-app-layout>
    <x-slot name="header">
        <h2 class="text-lg font-semibold text-slate-900">รายงานการผลิต</h2>
    </x-slot>

    <style>
        /* print: every tab in turn (of the view chosen), no app menus or links, on the company letterhead */
        @media print {
            @page { size: A4; margin: 10mm 12mm 12mm; }
            * { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            html, body { background: #fff !important; }
            #app-sidebar, #app-content > header { display: none !important; }
            #app-content { padding-left: 0 !important; }
            #app-content main, #app-content > div { padding: 0 !important; }
            .rp [data-panel] { display: block !important; margin-top: 14px; }
            .rp[data-view="cabinet"] [data-by="project"], .rp[data-view="project"] [data-by="cabinet"] { display: none !important; }
            .rp a { text-decoration: none; }
            .rp .row { break-inside: avoid; }
        }
    </style>

    <div class="rp mx-auto flex max-w-7xl flex-col gap-4 text-sm" :data-view="by" data-view="{{ $by }}"
         x-data="{ tab: @js($tab), by: @js($by), pick: {{ $firstStage === false ? 0 : $firstStage }}, lf: 'all', pickOpen: false,
                   url(k, v, off) { const u = new URL(location.href); v === off ? u.searchParams.delete(k) : u.searchParams.set(k, v); history.replaceState(null, '', u); },
                   go(t) { this.tab = t; this.url('tab', t, 'overview'); window.scrollTo({ top: 0 }); this.showTab(); },
                   showTab() { this.$nextTick(() => { const b = this.$root.querySelector('[data-tab=' + this.tab + ']'); if (b) b.scrollIntoView({ inline: 'center', block: 'nearest' }); }); },
                   setBy(b) { this.by = b; this.url('by', b, 'cabinet'); } }"
         x-init="showTab(); setInterval(() => { if (! document.hidden && ! pickOpen) location.reload() }, 300000)">   {{-- left open on a meeting-room screen: fresh every 5 minutes --}}

        {{-- print only: the letterhead and what this is --}}
        <div class="hidden print:block">
            <img src="{{ asset('images/report/letterhead-header.png') }}" alt="Avatar Electric Company Limited" class="mb-3 w-full">
            <h1 class="text-2xl font-bold text-[#0B1F3A]">รายงานการผลิต <span class="text-base font-semibold text-slate-500" x-text="by === 'project' ? '· ดูเป็นโครงการ' : '· ดูเป็นตู้'"></span></h1>
        </div>

        <div class="flex flex-wrap items-center justify-between gap-x-3 gap-y-2">
            <p class="text-sm text-slate-500">งานสั่งผลิตที่ยังไม่ปิด{{ $r['selected'] ? ' · เฉพาะ '.count($r['selected']).' โครงการที่เลือก' : '' }} · ข้อมูล ณ {{ $r['asOf']->thaiDate() }} · {{ $r['asOf']->format('H:i') }} น.</p>
            <div class="flex w-full items-center gap-2 sm:w-auto print:hidden">
                {{-- ดูเป็น: every tab counts cabinets or projects --}}
                <div role="group" aria-label="ดูเป็น" class="inline-flex shrink-0 rounded-lg bg-slate-200 p-[3px] text-sm font-semibold">
                    <span class="self-center px-2 text-slate-500 max-sm:hidden">ดูเป็น</span>
                    <button type="button" @click="setBy('cabinet')" :aria-pressed="by === 'cabinet'" class="min-h-[38px] rounded-md px-3.5" :class="by === 'cabinet' ? 'bg-white text-[#0B1F3A] shadow-sm' : 'text-slate-500'">ตู้</button>
                    <button type="button" @click="setBy('project')" :aria-pressed="by === 'project'" class="min-h-[38px] rounded-md px-3.5" :class="by === 'project' ? 'bg-white text-[#0B1F3A] shadow-sm' : 'text-slate-500'">โครงการ</button>
                </div>
                {{-- เลือกโครงการ: the whole report for these SO only --}}
                <form method="GET" class="relative min-w-0 flex-1 sm:flex-none" @click.outside="pickOpen = false" @keydown.escape="pickOpen = false">
                    <input type="hidden" name="tab" :value="tab"><input type="hidden" name="by" :value="by">
                    <button type="button" @click="pickOpen = !pickOpen" :aria-expanded="pickOpen"
                            class="inline-flex min-h-11 w-full items-center justify-between gap-1.5 rounded-lg border bg-white px-3.5 text-sm font-semibold {{ $r['selected'] ? 'border-blue-400 text-blue-700' : 'border-slate-300 text-slate-800' }}">
                        <span class="truncate">โครงการ: {{ $r['selected'] ? count($r['selected']).' ที่เลือก' : 'ทั้งหมด' }}</span><span aria-hidden="true">▾</span>
                    </button>
                    <div x-show="pickOpen" x-cloak class="absolute right-0 top-full z-30 mt-1 flex max-h-[70vh] w-80 max-w-[calc(100vw-2rem)] flex-col rounded-xl border border-slate-200 bg-white p-2 shadow-xl">
                        <div class="min-h-0 flex-1 overflow-y-auto">
                            @foreach ($r['projectOptions'] as $o)
                                <label class="flex cursor-pointer items-start gap-2.5 rounded-md px-2 py-2 hover:bg-slate-50">
                                    <input type="checkbox" name="projects[]" value="{{ $o['id'] }}" @checked(in_array($o['id'], $r['selected'])) class="mt-1 h-4 w-4 rounded border-slate-300 text-blue-600">
                                    <span class="min-w-0 leading-snug"><span class="block font-semibold text-[#0B1F3A]">{{ $o['no'] }}</span><span class="block truncate text-sm text-slate-500">{{ $o['name'] }}</span></span>
                                </label>
                            @endforeach
                        </div>
                        <div class="mt-1.5 flex shrink-0 justify-between gap-2 border-t border-slate-100 pt-2">
                            <a :href="'?' + new URLSearchParams({ tab, by }).toString()" class="rounded-md px-3 py-2 text-sm text-slate-600 hover:bg-slate-100">ดูทั้งหมด</a>
                            <button type="submit" class="rounded-md bg-blue-600 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-700">ดูรายงาน</button>
                        </div>
                    </div>
                </form>
                <button type="button" onclick="window.print()" class="hidden min-h-11 items-center rounded-lg border border-slate-300 bg-white px-4 text-sm font-semibold text-slate-800 hover:bg-slate-50 sm:inline-flex">พิมพ์ / PDF</button>
            </div>
        </div>

        {{-- tabs: the count says how much is inside; on a phone they scroll (the fade says there is more) --}}
        <nav role="tablist" aria-label="หัวข้อรายงาน"
             class="flex gap-1 overflow-x-auto border-b border-slate-200 [scrollbar-width:none] max-sm:[mask-image:linear-gradient(to_right,#000_80%,transparent)] print:hidden">
            @foreach ($tabs as $id => [$label, $count, $badge])
                <button type="button" role="tab" data-tab="{{ $id }}" @click="go(@js($id))" :aria-selected="tab === @js($id)"
                        class="-mb-px inline-flex min-h-11 shrink-0 items-center gap-2 border-b-[3px] px-3.5 text-sm font-semibold"
                        :class="tab === @js($id) ? 'border-blue-600 text-[#0B1F3A]' : 'border-transparent text-slate-500 hover:text-[#0B1F3A]'">
                    {{ $label }}
                    @if ($count !== null)<span class="rounded-full px-2 text-xs font-bold {{ $badge }}" x-text="by === 'project' ? {{ $count[1] }} : {{ $count[0] }}">{{ $count[0] }}</span>@endif
                </button>
            @endforeach
        </nav>

        {{-- ภาพรวม --}}
        <section data-panel x-show="tab === 'overview'" class="flex flex-col gap-3 sm:gap-4">
            <p data-by="project" x-show="by === 'project'" class="rounded-2xl bg-[#0B1F3A] px-4 py-3.5 text-base leading-relaxed text-white sm:px-5 sm:py-3.5 sm:text-lg">
                ตอนนี้มีงานผลิต <strong>{{ $r['so'] }} โครงการ</strong> ({{ $r['cabinets'] }} ตู้)
                @if ($canFinance && $r['value'] > 0) มูลค่า <strong>{{ $mn($r['value']) }} ล้านบาท</strong> @endif
                · <span class="font-bold text-red-300">มีตู้เลยวันส่ง {{ $pc['late'] }} โครงการ</span>
                · <span class="font-bold text-amber-300">ต้องจับตา {{ $pc['watch'] }} โครงการ</span>
                · <span class="font-bold text-emerald-300">มีตู้รอลูกค้ารับ {{ $pc['ready'] }} โครงการ</span>
            </p>
            <p data-by="cabinet" x-show="by === 'cabinet'" class="rounded-2xl bg-[#0B1F3A] px-4 py-3.5 text-base leading-relaxed text-white sm:px-5 sm:py-3.5 sm:text-lg">
                ตอนนี้กำลังผลิต <strong>{{ $r['cabinets'] }} ตู้</strong>
                @if ($canFinance && $r['value'] > 0) มูลค่า <strong>{{ $mn($r['value']) }} ล้านบาท</strong> @endif
                · <span class="font-bold text-red-300">เลยวันส่ง {{ $lateN }} ตู้</span>
                · <span class="font-bold text-amber-300">ต้องจับตา {{ $watchN }} ตู้</span>
                · <span class="font-bold text-emerald-300">รอลูกค้ารับ {{ $readyN }} ตู้</span>
            </p>
            @foreach (['cabinet' => $cards, 'project' => $cardsProject] as $mode => $list)
            <div data-by="{{ $mode }}" x-show="by === @js($mode)" class="grid grid-cols-2 gap-2.5 sm:gap-3.5 xl:grid-cols-3 print:grid-cols-3">
                @foreach ($list as [$q, $big, $unit, $answer, $accent, $color, $to, $link])
                    @php
                        $zero = $big === 0;   // nothing there: grey, so the cards with trouble stand out
                        $isUrl = $to && str_starts_with($to, 'http');
                    @endphp
                    <{{ $isUrl ? 'a href='.$to : 'div' }} @if ($to && ! $isUrl) @click="go(@js($to))" role="button" tabindex="0" @keydown.enter="go(@js($to))" @endif
                         class="flex min-w-0 flex-col gap-1 rounded-2xl border border-t-[5px] border-slate-200 bg-white px-3.5 py-3 sm:px-4 sm:py-3.5 {{ $zero ? 'border-t-slate-300' : $accent }} {{ $to ? 'cursor-pointer transition hover:border-blue-300 hover:shadow-sm' : '' }}">
                        <p class="text-[13px] font-semibold leading-snug text-slate-500 sm:text-sm">{{ $q }}</p>
                        <p class="flex flex-wrap items-baseline gap-x-2">
                            <span class="font-bold leading-tight {{ is_int($big) ? 'text-[28px] sm:text-[32px]' : 'text-lg sm:text-[22px]' }} {{ $zero ? 'text-slate-400' : $color }}">{{ $big }}</span>
                            @if ($unit !== '')<span class="text-sm font-semibold sm:text-[15px] {{ $zero ? 'text-slate-400' : 'text-slate-700' }}">{{ $unit }}</span>@endif
                        </p>
                        <p class="text-[13px] leading-snug sm:text-sm {{ $zero ? 'text-slate-500' : 'text-slate-700' }}">{{ $answer }}</p>
                        @if ($link)<span class="mt-auto pt-1 text-sm font-semibold text-blue-600 print:hidden">{{ $link }} →</span>@endif
                    </{{ $isUrl ? 'a' : 'div' }}>
                @endforeach
            </div>
            @endforeach
        </section>

        {{-- ล่าช้า / เสี่ยง: per project --}}
        <section data-panel data-by="project" x-show="tab === 'late' && by === 'project'" x-cloak class="overflow-hidden rounded-2xl border border-slate-200 bg-white">
            <div class="border-b border-slate-100 px-4 py-4 sm:px-5">
                <h3 class="text-base font-bold text-[#0B1F3A]">โครงการที่ล่าช้า หรือเสี่ยงส่งไม่ทัน</h3>
                <p class="text-sm text-slate-500">สถานะโครงการ = ตู้ที่แย่ที่สุดในโครงการ · {{ $hint }}</p>
            </div>
            @forelse ($prjLate as $p)
                <a href="{{ $p['url'] }}" class="row grid grid-cols-[6px_1fr_auto] items-center gap-x-4 gap-y-1 border-t border-slate-100 py-3 pr-4 hover:bg-slate-50 sm:pr-5 md:grid-cols-[6px_minmax(190px,1.3fr)_minmax(220px,2fr)_minmax(150px,1.2fr)_110px]">
                    <span class="self-stretch {{ $tag[$p['state']][2] }} max-md:row-span-3"></span>
                    <span class="min-w-0"><strong class="text-[#0B1F3A]">{{ $p['so'] }}</strong><span class="block truncate text-sm text-slate-500">{{ $p['customer'] }}</span></span>
                    <span class="text-slate-700 max-md:col-start-2">{{ $p['why'] }} <span class="text-sm text-slate-500">· ผลิต {{ $p['progress'] }}%</span></span>
                    <span class="text-sm text-slate-700 max-md:col-start-2">ตามที่ <strong>{{ $p['holders'] ? implode(', ', $p['holders']) : '-' }}</strong></span>
                    <span class="max-md:col-start-3 max-md:row-start-1"><span class="whitespace-nowrap rounded-full px-3 py-0.5 text-sm font-bold {{ $tag[$p['state']][1] }}">{{ $tag[$p['state']][0] }}</span></span>
                </a>
            @empty
                <p class="px-5 py-6 text-slate-500">ไม่มีโครงการที่ล่าช้าหรือเสี่ยง</p>
            @endforelse
        </section>

        {{-- ล่าช้า / เสี่ยง --}}
        <section data-panel data-by="cabinet" x-show="tab === 'late' && by === 'cabinet'" x-cloak class="overflow-hidden rounded-2xl border border-slate-200 bg-white">
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-100 px-4 py-4 sm:px-5">
                <div class="min-w-0">
                    <h3 class="text-base font-bold text-[#0B1F3A]">ตู้ที่ล่าช้า หรือเสี่ยงส่งไม่ทัน</h3>
                    <p class="text-sm text-slate-500">{{ $hint }}</p>
                </div>
                <div class="inline-flex max-w-full overflow-x-auto rounded-lg bg-slate-200 p-[3px] text-sm font-semibold print:hidden">
                    @foreach (['all' => 'ทั้งหมด', 'late' => 'ล่าช้า '.$lateN, 'rush' => 'ต้องเร่ง '.count($r['rush']), 'risk' => 'เสี่ยง '.count($r['risk'])] as $id => $label)
                        <button type="button" @click="lf = @js($id)" class="min-h-[34px] shrink-0 rounded-md px-3" :class="lf === @js($id) ? 'bg-white text-[#0B1F3A] shadow-sm' : 'text-slate-500'">{{ $label }}</button>
                    @endforeach
                </div>
            </div>
            @forelse ($lateRows as $c)
                <a href="{{ $c['url'] }}" x-show="lf === 'all' || lf === @js($c['state'])"
                   class="row grid grid-cols-[6px_1fr_auto] items-center gap-x-4 gap-y-1 border-t border-slate-100 py-3 pr-4 hover:bg-slate-50 sm:pr-5 md:grid-cols-[6px_minmax(170px,1.2fr)_minmax(220px,2fr)_minmax(150px,1.2fr)_110px]">
                    <span class="self-stretch {{ $tag[$c['state']][2] }} max-md:row-span-3"></span>
                    <span class="min-w-0"><strong class="text-[#0B1F3A]">{{ $c['mo'] }}</strong><span class="block truncate text-sm text-slate-500">{{ $c['so'] }} · {{ $c['customer'] }}</span></span>
                    <span class="text-slate-700 max-md:col-start-2">{{ $c['why'] }} <span class="text-sm text-slate-500">· ส่ง {{ $c['dueText'] }}</span></span>
                    <span class="text-sm text-slate-700 max-md:col-start-2">ตามที่ <strong>{{ $c['holders'] ? implode(', ', $c['holders']) : $c['stage'] }}</strong></span>
                    <span class="max-md:col-start-3 max-md:row-start-1"><span class="whitespace-nowrap rounded-full px-3 py-0.5 text-sm font-bold {{ $tag[$c['state']][1] }}">{{ $tag[$c['state']][0] }}</span></span>
                </a>
            @empty
                <p class="px-5 py-6 text-slate-500">ไม่มีตู้ที่ล่าช้าหรือเสี่ยง</p>
            @endforelse
        </section>

        {{-- ส่งมอบ: per project --}}
        <section data-panel data-by="project" x-show="tab === 'due' && by === 'project'" x-cloak class="overflow-hidden rounded-2xl border border-slate-200 bg-white">
            <div class="border-b border-slate-100 p-4 sm:p-5">
                <h3 class="mb-3 text-base font-bold text-[#0B1F3A]">กำหนดส่งตามโครงการ</h3>
                <div class="grid grid-cols-3 gap-2 sm:flex sm:flex-wrap sm:gap-2.5">
                    @foreach ([7, 15, 30] as $n)
                        <span class="rounded-xl border px-3 py-2 sm:px-4 {{ $n === 7 ? 'border-amber-200 bg-amber-50' : 'border-slate-200 bg-slate-50' }}"><strong class="text-xl text-[#0B1F3A]">{{ $pc['due'][$n] }}</strong> <span class="text-sm text-slate-600">โครงการ ใน {{ $n }} วัน</span></span>
                    @endforeach
                </div>
            </div>
            @forelse ($prjDue as $p)
                @php $t = $tag[$p['state']] ?? $tag['ok']; @endphp
                <a href="{{ $p['url'] }}" class="row grid grid-cols-[1fr_auto] items-center gap-x-4 gap-y-1 border-t border-slate-100 px-4 py-3 hover:bg-slate-50 sm:px-5 md:grid-cols-[120px_minmax(190px,1.3fr)_minmax(200px,1.5fr)_150px_100px]">
                    <span><strong class="text-[#0B1F3A]">{{ $p['nextText'] }}</strong><span class="block text-sm text-slate-500">อีก {{ $p['nextDays'] }} วัน · {{ $p['nextCabinets'] }} ตู้</span></span>
                    <span class="max-md:col-start-2 max-md:row-start-1"><span class="whitespace-nowrap rounded-full px-3 py-0.5 text-sm font-bold {{ $t[1] }}">{{ $t[0] }}</span></span>
                    <span class="min-w-0 md:col-start-2 md:row-start-1"><strong class="text-[#0B1F3A]">{{ $p['so'] }}</strong><span class="block truncate text-sm text-slate-500">{{ $p['customer'] }}</span></span>
                    <span class="text-sm text-slate-700 max-md:col-span-2 md:col-start-3 md:row-start-1">ตู้ที่ต้องส่ง: 7 วัน <strong>{{ $p['dueCabinets'][7] }}</strong> · 15 วัน <strong>{{ $p['dueCabinets'][15] }}</strong> · 30 วัน <strong>{{ $p['dueCabinets'][30] }}</strong></span>
                    <span class="flex items-center gap-2 max-md:col-span-2 md:col-start-4 md:row-start-1"><span class="h-1.5 flex-1 overflow-hidden rounded-full bg-slate-200"><span class="block h-full {{ $t[2] }}" style="width: {{ $p['progress'] }}%"></span></span><span class="text-sm font-semibold">ผลิต {{ $p['progress'] }}%</span></span>
                </a>
            @empty
                <p class="px-5 py-6 text-slate-500">ไม่มีโครงการที่ต้องส่งใน 30 วัน</p>
            @endforelse
        </section>

        {{-- ส่งมอบ --}}
        <section data-panel data-by="cabinet" x-show="tab === 'due' && by === 'cabinet'" x-cloak class="rounded-2xl border border-slate-200 bg-white p-4 sm:p-5">
            <h3 class="mb-3 text-base font-bold text-[#0B1F3A]">งานที่ต้องส่งมอบ</h3>
            <div class="mb-4 grid grid-cols-3 gap-2 sm:flex sm:flex-wrap sm:gap-2.5">
                @foreach ([7, 15, 30] as $n)
                    <span class="rounded-xl border px-3 py-2 sm:px-4 {{ $n === 7 ? 'border-amber-200 bg-amber-50' : 'border-slate-200 bg-slate-50' }}"><strong class="text-xl text-[#0B1F3A]">{{ $r['dueCount'][$n] }}</strong> <span class="text-sm text-slate-600">ตู้ ใน {{ $n }} วัน</span></span>
                @endforeach
            </div>
            <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4 print:grid-cols-4">
                @foreach ($r['weeks'] as $i => $w)
                    <div class="row rounded-xl border border-slate-200 p-3 {{ $i === 0 ? 'bg-amber-50/60' : 'bg-white' }}">
                        <p class="font-bold text-[#0B1F3A]">{{ $w['label'] }} <span class="text-sm font-normal text-slate-500">· {{ count($w['items']) }} ตู้</span></p>
                        <p class="mb-2 text-sm text-slate-500">{{ $w['range'] }}</p>
                        <div class="flex flex-col gap-1.5">
                            @forelse ($w['items'] as $c)
                                @php $t = $tag[$c['state']] ?? $tag['ok']; @endphp
                                <a href="{{ $c['url'] }}" class="block rounded-lg border border-l-4 border-slate-100 bg-white px-2.5 py-1.5 hover:border-slate-300 {{ $t[3] }}">
                                    <span class="flex justify-between gap-2 text-sm"><strong class="text-[#0B1F3A]">{{ $c['dueText'] }}</strong><span class="font-semibold {{ $t[4] }}">{{ $t[0] }}</span></span>
                                    <span class="block truncate text-sm text-slate-600">{{ $c['mo'] }} · {{ $c['customer'] }}</span>
                                    <span class="mt-1 flex items-center gap-1.5"><span class="h-[5px] flex-1 overflow-hidden rounded-full bg-slate-200"><span class="block h-full {{ $t[2] }}" style="width: {{ $c['progress'] }}%"></span></span><span class="text-sm text-slate-500">ผลิต {{ $c['progress'] }}%</span></span>
                                </a>
                            @empty
                                <span class="text-sm text-slate-400">ไม่มีงานส่ง</span>
                            @endforelse
                        </div>
                    </div>
                @endforeach
            </div>
        </section>

        {{-- ขั้นตอน / แผนก --}}
        <div data-panel x-show="tab === 'stage'" x-cloak class="flex flex-col gap-4">
            {{-- per project: how its cabinets spread over the steps --}}
            <section data-by="project" x-show="by === 'project'" class="overflow-hidden rounded-2xl border border-slate-200 bg-white">
                <div class="border-b border-slate-100 px-4 py-4 sm:px-5">
                    <h3 class="text-base font-bold text-[#0B1F3A]">แต่ละโครงการ ตู้อยู่ขั้นไหน</h3>
                    <p class="text-sm text-slate-500">ตัวเลข = จำนวนตู้ในขั้นนั้น · เรียงจากโครงการที่มีปัญหามากสุด</p>
                </div>
                @forelse ($prj as $p)
                    @php $t = $tag[$p['state']] ?? $tag['ok']; @endphp
                    <a href="{{ $p['url'] }}" class="row grid items-center gap-x-4 gap-y-1.5 border-t border-slate-100 px-4 py-3 hover:bg-slate-50 sm:px-5 md:grid-cols-[minmax(190px,1.2fr)_minmax(260px,2.4fr)_150px]">
                        <span class="min-w-0"><strong class="text-[#0B1F3A]">{{ $p['so'] }}</strong> <span class="text-sm text-slate-500">· เสร็จ {{ $p['done'] }}/{{ $p['cabinets'] }} ตู้</span><span class="block truncate text-sm text-slate-500">{{ $p['customer'] }}</span></span>
                        <span class="flex flex-wrap gap-1.5">
                            @forelse (collect($p['stages'])->sortBy(fn ($n, $name) => $stageOrder[$name] ?? 999) as $name => $n)
                                <span class="rounded-full px-2.5 py-0.5 text-sm {{ $name === 'รอลูกค้ารับ' ? 'bg-emerald-50 text-emerald-800' : 'bg-blue-50 text-blue-800' }}">{{ $name }} <strong>{{ $n }}</strong></span>
                            @empty
                                <span class="text-sm text-slate-400">{{ $p['cabinets'] ? 'เสร็จครบทุกตู้' : 'ยังไม่มีตู้' }}</span>
                            @endforelse
                        </span>
                        <span class="flex items-center gap-2"><span class="h-1.5 flex-1 overflow-hidden rounded-full bg-slate-200"><span class="block h-full {{ $t[2] }}" style="width: {{ $p['progress'] }}%"></span></span><span class="text-sm font-semibold">ผลิต {{ $p['progress'] }}%</span></span>
                    </a>
                @empty
                    <p class="px-5 py-6 text-slate-500">ไม่มีโครงการ</p>
                @endforelse
            </section>

            <section data-by="cabinet" x-show="by === 'cabinet'" class="rounded-2xl border border-slate-200 bg-white p-4 sm:p-5">
                <h3 class="text-base font-bold text-[#0B1F3A]">ตู้อยู่ขั้นไหน</h3>
                <p class="mb-3 text-sm text-slate-500">ขั้นแรกที่ยังไม่เสร็จของแต่ละตู้ · <span class="print:hidden">กดวงกลมเพื่อดูตู้ · </span>สีแดง = มีตู้เลยวันส่ง · สีเหลือง = มีตู้ต้องจับตา</p>
                @if ($stages)
                    <div class="-mx-1 overflow-x-auto px-1">
                        <div class="grid items-start" style="grid-template-columns: repeat({{ count($stages) }}, minmax(104px, 1fr)); min-width: {{ count($stages) * 108 }}px">
                            @foreach ($stages as $i => $s)
                                @php $fill = $s['last'] ? 'bg-emerald-600' : ($s['late'] ? 'bg-red-600' : ($s['watch'] ? 'bg-amber-500' : 'bg-blue-600')); @endphp
                                <button type="button" @click="pick = {{ $i }}" class="relative flex flex-col items-center gap-1.5 rounded-2xl px-1 pb-2.5 pt-2" :class="pick === {{ $i }} ? 'bg-blue-50' : ''" :aria-pressed="pick === {{ $i }}">
                                    @unless ($loop->last)<span class="absolute left-1/2 right-[-50%] top-9 h-[3px] bg-slate-300"></span>@endunless
                                    <span class="relative flex h-14 w-14 items-center justify-center rounded-full text-[22px] font-bold text-white {{ $fill }}" :class="pick === {{ $i }} ? 'ring-4 ring-blue-200' : ''">{{ $s['count'] }}</span>
                                    <span class="text-center text-sm font-semibold leading-snug text-[#0B1F3A]">{{ $s['name'] }}</span>
                                    <span class="text-sm font-semibold {{ $s['late'] ? 'text-red-700' : ($s['watch'] ? 'text-amber-800' : 'text-slate-500') }}">
                                        {{ $s['last'] ? 'นานสุด '.$r['readyMaxWait'].' วัน' : ($s['late'] ? 'เลยวันส่ง '.$s['late'] : ($s['watch'] ? 'จับตา '.$s['watch'] : 'ตามแผน')) }}</span>
                                </button>
                            @endforeach
                        </div>
                    </div>
                    @foreach ($stages as $i => $s)
                        <div x-show="pick === {{ $i }}" class="mt-2.5 rounded-xl bg-slate-50 p-3 sm:p-3.5">
                            <p class="mb-2 font-bold text-[#0B1F3A]">ขั้น {{ $s['name'] }} · {{ $s['count'] }} ตู้</p>
                            <div class="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                                @foreach ($s['cabinets'] as $c)
                                    @php $t = $tag[$c['state']] ?? $tag['ok']; @endphp
                                    <a href="{{ $c['url'] }}" class="block rounded-lg border border-l-4 border-slate-200 bg-white px-3 py-2 hover:border-slate-300 {{ $t[3] }}">
                                        <span class="flex justify-between gap-2"><strong class="truncate text-[#0B1F3A]">{{ $c['mo'] }}</strong><span class="shrink-0 text-sm font-semibold {{ $t[4] }}">{{ $c['ready'] ? 'รอ '.$c['waitDays'].' วัน' : $t[0] }}</span></span>
                                        <span class="block truncate text-sm text-slate-500">{{ $c['so'] }} · {{ $c['customer'] }}</span>
                                        @if ($c['holders'])<span class="block text-sm text-slate-700">ตามที่ <strong>{{ implode(', ', $c['holders']) }}</strong></span>@endif
                                    </a>
                                @endforeach
                            </div>
                        </div>
                    @endforeach
                @else
                    <p class="text-slate-500">ยังไม่มีตู้ที่กำลังผลิต</p>
                @endif
            </section>

            <section class="overflow-hidden rounded-2xl border border-slate-200 bg-white">
                <div class="border-b border-slate-100 px-4 py-4 sm:px-5">
                    <h3 class="text-base font-bold text-[#0B1F3A]">แต่ละแผนกถืองานกี่งาน</h3>
                    <p class="text-sm text-slate-500">นับเป็นงาน (Sub Task) ที่ยังไม่เสร็จ ไม่ใช่ตู้ · เรียงจากงานเลยกำหนดมากสุด</p>
                </div>
                @forelse ($r['departments'] as $d)
                    <a href="{{ route('dashboard', ['view' => 'dept', 'department' => $d['id']]) }}"
                       class="row grid items-center gap-x-4 gap-y-1 border-t border-slate-100 px-4 py-2.5 hover:bg-slate-50 sm:px-5 md:grid-cols-[minmax(170px,1.3fr)_minmax(200px,2fr)_260px]">
                        <strong class="truncate text-[#0B1F3A]">{{ $d['name'] }}</strong>
                        <span class="flex h-3 overflow-hidden rounded-full bg-slate-100">
                            <span class="bg-red-600" style="width: {{ $d['late'] / $deptMax * 100 }}%"></span><span class="bg-blue-500" style="width: {{ $d['doing'] / $deptMax * 100 }}%"></span><span class="bg-slate-300" style="width: {{ $d['wait'] / $deptMax * 100 }}%"></span>
                        </span>
                        <span class="text-sm text-slate-700"><strong class="{{ $d['late'] ? 'text-red-700' : 'text-slate-400' }}">เลยกำหนด {{ $d['late'] }}</strong> · กำลังทำ {{ $d['doing'] }} · รอรับ {{ $d['wait'] }} <span class="text-slate-500">งาน</span></span>
                    </a>
                @empty
                    <p class="px-5 py-6 text-slate-500">ไม่มีงานค้าง</p>
                @endforelse
                <p class="flex flex-wrap gap-x-4 gap-y-1 border-t border-slate-100 px-4 py-2.5 text-sm text-slate-500 sm:px-5">
                    <span><span class="mr-1 inline-block h-2.5 w-2.5 rounded-sm bg-red-600"></span>งานเลยกำหนด</span>
                    <span><span class="mr-1 inline-block h-2.5 w-2.5 rounded-sm bg-blue-500"></span>กำลังทำ</span>
                    <span><span class="mr-1 inline-block h-2.5 w-2.5 rounded-sm bg-slate-300"></span>รอรับ / ยังไม่จ่าย</span>
                </p>
            </section>
        </div>

        {{-- รอลูกค้ารับ: per project --}}
        <section data-panel data-by="project" x-show="tab === 'ready' && by === 'project'" x-cloak class="overflow-hidden rounded-2xl border border-slate-200 bg-white">
            <div class="border-b border-slate-100 px-4 py-4 sm:px-5">
                <h3 class="text-base font-bold text-[#0B1F3A]">โครงการที่มีตู้ผลิตเสร็จ รอลูกค้ารับ · {{ $pc['ready'] }} โครงการ</h3>
                <p class="text-sm text-slate-500">รอ {{ $waitRed }} วันขึ้นไปเป็นสีแดง</p>
            </div>
            @forelse ($prjReady as $p)
                <a href="{{ $p['url'] }}" class="row grid grid-cols-[1fr_auto] items-center gap-x-4 gap-y-0.5 border-t border-slate-100 px-4 py-3 hover:bg-slate-50 sm:px-5 md:grid-cols-[minmax(190px,1.3fr)_minmax(180px,1.5fr)_140px]">
                    <span class="min-w-0"><strong class="text-[#0B1F3A]">{{ $p['so'] }}</strong><span class="block truncate text-sm text-slate-500">{{ $p['customer'] }}</span></span>
                    <span class="text-slate-700 max-md:col-start-1">รอลูกค้ารับ <strong>{{ $p['ready'] }}</strong> จาก {{ $p['cabinets'] }} ตู้</span>
                    <span class="whitespace-nowrap text-[15px] font-bold max-md:col-start-2 max-md:row-start-1 {{ $p['readyMaxWait'] >= $waitRed ? 'text-red-700' : 'text-slate-700' }}">นานสุด {{ $p['readyMaxWait'] }} วัน</span>
                </a>
            @empty
                <p class="px-5 py-6 text-slate-500">ไม่มีโครงการที่มีตู้รอลูกค้ารับ</p>
            @endforelse
        </section>

        {{-- รอลูกค้ารับ --}}
        <section data-panel data-by="cabinet" x-show="tab === 'ready' && by === 'cabinet'" x-cloak class="overflow-hidden rounded-2xl border border-slate-200 bg-white">
            <div class="border-b border-slate-100 px-4 py-4 sm:px-5">
                <h3 class="text-base font-bold text-[#0B1F3A]">ผลิตเสร็จแล้ว รอลูกค้ารับ · {{ $readyN }} ตู้</h3>
                <p class="text-sm text-slate-500">ทุกขั้นเสร็จแล้ว เหลือ Task สุดท้ายของเทมเพลต (เช่น ส่งมอบ / ติดตั้ง) · รอ {{ $waitRed }} วันขึ้นไปเป็นสีแดง</p>
            </div>
            @forelse ($r['ready'] as $c)
                <a href="{{ $c['url'] }}" class="row grid grid-cols-[1fr_auto] items-center gap-x-4 gap-y-0.5 border-t border-slate-100 px-4 py-3 hover:bg-slate-50 sm:px-5 md:grid-cols-[minmax(170px,1.2fr)_minmax(180px,1.5fr)_minmax(150px,1fr)_120px]">
                    <span class="min-w-0"><strong class="text-[#0B1F3A]">{{ $c['mo'] }}</strong><span class="block text-sm text-slate-500">{{ $c['so'] }}</span></span>
                    <span class="truncate text-slate-700 max-md:col-start-1">{{ $c['customer'] }}</span>
                    <span class="text-sm text-slate-500 max-md:col-start-1">ผลิตเสร็จ {{ $c['producedText'] }} · รอ {{ $c['stage'] }}</span>
                    <span class="whitespace-nowrap text-[15px] font-bold max-md:col-start-2 max-md:row-start-1 {{ $c['waitDays'] >= $waitRed ? 'text-red-700' : 'text-slate-700' }}">รอ {{ $c['waitDays'] }} วัน</span>
                </a>
            @empty
                <p class="px-5 py-6 text-slate-500">ไม่มีตู้ที่ผลิตเสร็จแล้วรอลูกค้ารับ</p>
            @endforelse
        </section>

        <p class="hidden text-sm text-slate-400 print:block">เอกสารภายใน · พิมพ์จากระบบ PMO {{ $r['asOf']->thaiDate() }} {{ $r['asOf']->format('H:i') }} น.</p>
    </div>
</x-app-layout>
