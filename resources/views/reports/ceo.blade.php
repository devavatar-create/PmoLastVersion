{{-- The CEO report on the company letterhead (public/images/report). Opens as a page with a print bar; print / Save as PDF
     gives A4 pages with the letterhead on every page: the bands are position: fixed in print (repeated per page) and the
     outer table's thead / tfoot spacers keep the content clear of them. Data from App\Services\ExecutiveReport ($r) and
     App\Services\ProductionReport ($p); ?by=project (default) / cabinet counts the summary and the lists per SO or per
     cabinet. The summary on top is written by App\Services\CeoSummary from the same numbers. --}}
@php
    $k = $r['kpis'];
    $value = $k['value'];
    $states = [
        'late' => ['ล่าช้า', 'bg-red-100 text-red-700', 'bg-red-600'],
        'watch' => ['เฝ้าระวัง', 'bg-orange-100 text-orange-700', 'bg-blue-600'],
        'ok' => ['ทันเวลา', 'bg-emerald-100 text-emerald-700', 'bg-blue-600'],
        'close' => ['รอปิดโครงการ', 'bg-blue-100 text-blue-700', 'bg-emerald-500'],
    ];
    $tone = ['red' => 'text-red-700', 'orange' => 'text-orange-700', 'blue' => 'text-ae-navy', 'gray' => 'text-slate-600', 'slate' => 'text-slate-600'];
    $maxMonth = max(1, collect($r['months'])->max('planned'));
    $maxLate = max(1, collect($r['departments'])->max('late'));
    $asOf = $r['asOf'];
    $thaiMonths = ['มกราคม', 'กุมภาพันธ์', 'มีนาคม', 'เมษายน', 'พฤษภาคม', 'มิถุนายน', 'กรกฎาคม', 'สิงหาคม', 'กันยายน', 'ตุลาคม', 'พฤศจิกายน', 'ธันวาคม'];
    $longDate = $asOf->day.' '.$thaiMonths[$asOf->month - 1].' '.($asOf->year + 543);
    $printed = $asOf->thaiDateTime();
    $section = 0;
    $byProject = $by === 'project';
    $unit = $byProject ? 'โครงการ' : 'ตู้';
    $summary = app(\App\Services\CeoSummary::class)->write($r, $p, $by, $value !== null);
    $lineTone = ['navy' => 'bg-ae-navy', 'red' => 'bg-red-600', 'orange' => 'bg-orange-500', 'green' => 'bg-emerald-500'];
    $pstate = [
        'late' => ['ล่าช้า', 'bg-red-100 text-red-700'], 'rush' => ['ต้องเร่ง', 'bg-orange-100 text-orange-700'],
        'risk' => ['เสี่ยง', 'bg-amber-100 text-amber-800'], 'ok' => ['ทัน', 'bg-emerald-100 text-emerald-700'],
        'ready' => ['พร้อมส่ง', 'bg-emerald-100 text-emerald-700'], 'done' => ['เสร็จ', 'bg-slate-100 text-slate-600'],
    ];
    $pc = $p['projectCount'];
    $watchCabinets = collect($p['late'])->concat($p['rush'])->concat($p['risk']);
    $watchProjects = collect($p['projects'])->whereIn('state', ['late', 'rush', 'risk'])->values();
    $dueCabinets = collect($p['due']);
    $dueProjects = collect($p['projects'])->filter(fn ($x) => $x['nextDays'] !== null && $x['nextDays'] <= 30)->sortBy('nextDays')->values();
    $readyProjects = collect($p['projects'])->where('ready', '>', 0)->sortByDesc('readyMaxWait')->values();
    $listLimit = 15;
    $show = fn (string $key) => in_array($key, $sections, true);   // "หัวข้อที่จะพิมพ์"
    $periodLabels = \App\Services\ReportFilter::PERIODS;
    $chosen = fn (array $ids, $id) => in_array((int) $id, $ids, true);
@endphp
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>รายงานสถานะโครงการ {{ $asOf->thaiDate() }}</title>
    <link rel="icon" type="image/png" href="{{ asset('images/logo.png') }}">
    <link rel="stylesheet" href="{{ asset('fonts/anuphan/anuphan.css') }}">
    @vite(['resources/css/app.css'])
    <style>
        @page { size: A4; margin: 0; }
        * { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        html, body { background: #e5e7eb; }
        .sheet { width: 210mm; margin: 0 auto 24px; background: #fff; box-shadow: 0 4px 24px rgba(15, 23, 42, .12); }
        .lh { display: block; width: 210mm; }
        .content { padding: 3mm 15mm 4mm; }
        .page-table, .page-table > tbody > tr > td { width: 100%; border: 0; padding: 0; border-collapse: collapse; }
        .page-table > thead, .page-table > tfoot { display: none; }
        .avoid { break-inside: avoid; }
        .printed { padding: 0 15mm 2mm; }
        /* on a wide screen the A4 sheet reads small: show it a little larger (print keeps the real size) */
        @media screen and (min-width: 1200px) { .sheet { zoom: 1.1; } }
        @media print {
            html, body { background: #fff; }
            .no-print { display: none !important; }
            .sheet { margin: 0; box-shadow: none; }
            .lh-head { position: fixed; top: 0; left: 0; }
            .lh-foot { position: fixed; bottom: 0; left: 0; }
            .printed { position: fixed; left: 0; right: 0; bottom: 36.5mm; }
            .page-table > thead { display: table-header-group; }
            .page-table > tfoot { display: table-footer-group; }
            .page-table > thead td > div { height: 36mm; }
            .page-table > tfoot td > div { height: 40mm; }
        }
    </style>
</head>
<body class="font-sans text-[12px] leading-relaxed text-slate-800 antialiased">

    {{-- the toolbar (not printed): แยกเป็น, then "ตัวเลือกรายงาน" (SO / departments / period / which parts) in one GET form --}}
    <form method="GET" action="{{ route('reports.ceo') }}" class="no-print sticky top-0 z-10 mb-4 border-b border-slate-200 bg-white/95 backdrop-blur">
        <input type="hidden" name="pick" value="1">
        <div class="mx-auto flex max-w-[210mm] flex-wrap items-center justify-between gap-x-3 gap-y-2 px-4 py-2 min-[1200px]:max-w-[231mm]">
            <a href="{{ route('dashboard', ['view' => 'exec']) }}" class="text-sm font-medium text-slate-600 hover:text-ae-navy">← กลับแดชบอร์ด</a>
            <span class="flex flex-wrap items-center gap-2">
                {{-- แยกเป็น: the summary and the lists per SO or per cabinet (sends the form, so the other choices stay) --}}
                <span class="inline-flex items-center rounded-lg bg-slate-200 p-[3px] text-sm font-semibold">
                    <span class="px-2 text-slate-500">แยกเป็น</span>
                    @foreach (['project' => 'โครงการ', 'cabinet' => 'ตู้'] as $id => $label)
                        <label class="cursor-pointer rounded-md px-3 py-1 has-[:checked]:bg-white has-[:checked]:text-ae-navy has-[:checked]:shadow-sm text-slate-500 hover:text-ae-navy">
                            <input type="radio" name="by" value="{{ $id }}" @checked($by === $id) onchange="this.form.submit()" class="sr-only">{{ $label }}
                        </label>
                    @endforeach
                </span>
                <details class="group relative">
                    <summary class="flex cursor-pointer list-none items-center gap-1.5 rounded-lg border px-3 py-1.5 text-sm font-semibold {{ $filter->isEmpty() && count($sections) === count($allSections) ? 'border-slate-300 text-slate-700' : 'border-blue-400 bg-blue-50 text-blue-700' }}">
                        ตัวเลือกรายงาน{{ $filter->isEmpty() && count($sections) === count($allSections) ? '' : ' (มีการเลือก)' }} <span class="transition group-open:rotate-180">▾</span>
                    </summary>
                    {{-- left: SO and their cabinets in one list (tick an SO = the whole project, or open it and tick some
                         cabinets; both add up); right: departments, the period, the parts to print. Small type: it is a tool. --}}
                    <div class="absolute right-0 top-full mt-1.5 w-[min(52rem,calc(100vw-2rem))] rounded-xl border border-slate-200 bg-white p-3.5 text-[13px] shadow-2xl">
                        <div class="grid gap-3.5 sm:grid-cols-[1.15fr_1fr]">
                            <fieldset class="flex min-w-0 flex-col">
                                <legend class="mb-1 text-[13px] font-bold text-ae-navy">โครงการ / ตู้ <span class="font-normal text-slate-500">· ติ๊ก SO = ทั้งโครงการ · กด ▸ เพื่อเลือกบางตู้ · ไม่ติ๊ก = ทั้งหมด</span></legend>
                                <div data-cab-picker class="flex min-h-0 flex-1 flex-col rounded-lg border border-slate-200">
                                    <input type="search" data-cab-search placeholder="ค้นหา SO / MO / ชื่อตู้ / ลูกค้า" aria-label="ค้นหาโครงการหรือตู้"
                                           class="w-full rounded-t-lg border-0 border-b border-slate-200 px-2.5 py-1.5 text-[13px] focus:ring-0">
                                    <div class="h-[22rem] overflow-y-auto p-1">
                                        @forelse ($cabinetOptions as $g)
                                            @php
                                                $whole = $chosen($filter->projects, $g['project']->id);
                                                $picked = $whole ? $g['cabinets']->count() : $g['cabinets']->filter(fn ($c) => $chosen($filter->cabinetIds, $c->id))->count();
                                                $soText = mb_strtolower($g['project']->project_no.' '.$g['project']->customer_name.' '.$g['project']->project_name);
                                            @endphp
                                            <details data-cab-group data-text="{{ $soText }}" @if ($picked && ! $whole) open @endif class="group/so">
                                                <summary class="flex cursor-pointer list-none items-center gap-2 rounded px-1.5 py-1 hover:bg-slate-50">
                                                    <span class="w-3 text-[11px] text-slate-400 transition group-open/so:rotate-90" aria-hidden="true">▸</span>
                                                    <input type="checkbox" name="projects[]" value="{{ $g['project']->id }}" data-cab-all @checked($whole) class="h-3.5 w-3.5 rounded border-slate-300 text-blue-600" aria-label="ทั้งโครงการ {{ $g['project']->project_no }}">
                                                    <span class="min-w-0 flex-1 truncate"><strong class="text-ae-navy">{{ $g['project']->project_no }}</strong>
                                                        <span class="text-xs text-slate-500">{{ $g['project']->customer_name ?: $g['project']->project_name }}</span></span>
                                                    <span data-cab-count class="shrink-0 text-xs tabular-nums text-slate-500">{{ $picked }}/{{ $g['cabinets']->count() }}</span>
                                                </summary>
                                                @foreach ($g['cabinets'] as $c)
                                                    <label data-cab-row data-text="{{ mb_strtolower($c->mo_no.' '.$c->cabinet_name) }}" class="ml-5 flex cursor-pointer items-start gap-2 rounded px-2 py-0.5 hover:bg-slate-50">
                                                        <input type="checkbox" name="cabinets[]" value="{{ $c->id }}" data-cab @checked($whole || $chosen($filter->cabinetIds, $c->id)) class="mt-0.5 h-3.5 w-3.5 rounded border-slate-300 text-blue-600">
                                                        <span class="min-w-0 leading-snug"><span class="font-semibold text-ae-navy">{{ $c->mo_no }}</span> <span class="block truncate text-xs text-slate-500">{{ $c->cabinet_name }}</span></span>
                                                    </label>
                                                @endforeach
                                            </details>
                                        @empty
                                            <p class="px-2 py-1 text-slate-400">ไม่มีโครงการที่ยังไม่ปิด</p>
                                        @endforelse
                                        <p data-cab-none hidden class="px-2 py-1 text-slate-400">ไม่พบโครงการหรือตู้ที่ค้นหา</p>
                                    </div>
                                </div>
                            </fieldset>

                            <div class="flex min-w-0 flex-col gap-3">
                                <fieldset class="min-w-0">
                                    <legend class="mb-1 text-[13px] font-bold text-ae-navy">แผนก <span class="font-normal text-slate-500">· ไม่ติ๊ก = ทุกแผนก</span></legend>
                                    <div class="grid max-h-36 grid-cols-2 gap-x-1 overflow-y-auto rounded-lg border border-slate-200 p-1">
                                        @foreach ($departmentOptions as $d)
                                            <label class="flex cursor-pointer items-start gap-1.5 rounded px-1.5 py-0.5 leading-snug hover:bg-slate-50">
                                                <input type="checkbox" name="departments[]" value="{{ $d->id }}" @checked($chosen($filter->departments, $d->id)) class="mt-0.5 h-3.5 w-3.5 shrink-0 rounded border-slate-300 text-blue-600">{{ $d->name }}
                                            </label>
                                        @endforeach
                                    </div>
                                </fieldset>
                                <fieldset class="min-w-0">
                                    <legend class="mb-1 text-[13px] font-bold text-ae-navy">ช่วงกำหนดส่งของตู้</legend>
                                    <div class="flex flex-wrap gap-1">
                                        @foreach ($periodLabels as $id => $label)
                                            <label class="cursor-pointer rounded-full border border-slate-300 px-2.5 py-0.5 has-[:checked]:border-blue-500 has-[:checked]:bg-blue-50 has-[:checked]:text-blue-700">
                                                <input type="radio" name="period" value="{{ $id }}" @checked($filter->period === $id) class="sr-only">{{ $label }}
                                            </label>
                                        @endforeach
                                    </div>
                                    <div class="mt-1.5 flex flex-wrap items-center gap-1.5 text-slate-600">
                                        <input type="date" name="from" value="{{ $filter->period === 'custom' ? $filter->from?->format('Y-m-d') : '' }}" class="rounded-md border-slate-300 px-2 py-0.5 text-[13px]" aria-label="ตั้งแต่วันที่">
                                        ถึง
                                        <input type="date" name="to" value="{{ $filter->period === 'custom' ? $filter->to?->format('Y-m-d') : '' }}" class="rounded-md border-slate-300 px-2 py-0.5 text-[13px]" aria-label="ถึงวันที่">
                                    </div>
                                    <p class="mt-0.5 text-xs text-slate-500">ใส่วันที่แล้วเลือก "กำหนดเอง"</p>
                                </fieldset>
                                <fieldset class="min-w-0">
                                    <legend class="mb-1 text-[13px] font-bold text-ae-navy">หัวข้อที่จะพิมพ์</legend>
                                    <div class="grid grid-cols-2 gap-x-1">
                                        @foreach ($allSections as $id => $label)
                                            <label class="flex cursor-pointer items-start gap-1.5 rounded px-1 py-0.5 leading-snug hover:bg-slate-50">
                                                <input type="checkbox" name="sections[]" value="{{ $id }}" @checked($show($id)) class="mt-0.5 h-3.5 w-3.5 shrink-0 rounded border-slate-300 text-blue-600">{{ $label }}
                                            </label>
                                        @endforeach
                                    </div>
                                </fieldset>
                            </div>
                        </div>
                        <div class="mt-3 flex items-center justify-between gap-2 border-t border-slate-100 pt-2.5">
                            <a href="{{ route('reports.ceo', ['by' => $by]) }}" class="rounded-md px-3 py-1.5 text-slate-600 hover:bg-slate-100">ล้างตัวเลือก</a>
                            <button type="submit" class="rounded-lg bg-blue-600 px-4 py-1.5 text-sm font-semibold text-white hover:bg-blue-700">ใช้ตัวเลือก</button>
                        </div>
                    </div>
                </details>
                <button type="button" onclick="window.print()" title="เลือกปลายทาง &quot;บันทึกเป็น PDF&quot; เพื่อได้ไฟล์ PDF"
                        class="rounded-lg bg-blue-600 px-4 py-1.5 text-sm font-semibold text-white hover:bg-blue-700">พิมพ์ / บันทึก PDF</button>
            </span>
        </div>
    </form>

    <div class="sheet">
        <img src="{{ asset('images/report/letterhead-header.png') }}" alt="Avatar Electric Company Limited" class="lh lh-head">

        <table class="page-table">
            <thead><tr><td><div></div></td></tr></thead>
            <tfoot><tr><td><div></div></td></tr></tfoot>
            <tbody><tr><td>
            <div class="content space-y-5">

                <div class="flex items-end justify-between gap-4 border-b-2 border-[#F28C28] pb-2.5">
                    <div>
                        <h1 class="text-[21px] font-bold leading-tight text-ae-navy">รายงานสถานะโครงการและการผลิต</h1>
                        <p class="text-slate-600">สำหรับผู้บริหาร · แยกเป็นราย{{ $unit }} · ข้อมูล ณ วันที่ {{ $longDate }} เวลา {{ $asOf->format('H:i') }} น.</p>
                        <p class="text-[11px] text-slate-500">ขอบเขตรายงาน: <span class="font-semibold text-slate-700">{{ $filter->describe() }}</span></p>
                    </div>
                    <div class="text-right text-[11px] leading-snug text-slate-500">
                        <div>เลขที่รายงาน RPT-{{ $asOf->year + 543 }}-{{ $asOf->format('m-d') }}</div>
                        <div>จัดทำโดย ฝ่ายบริหารโครงการ (PMO)</div>
                    </div>
                </div>

                @if ($show('summary'))
                <section class="avoid">
                    <h2 class="mb-1.5 text-[14px] font-bold text-ae-navy">{{ ++$section }}. บทสรุปผู้บริหาร</h2>
                    <div class="grid grid-cols-[1.45fr_1fr] gap-3">
                        <ul class="space-y-1.5 rounded-md border border-slate-200 bg-slate-50 px-3 py-2.5 text-[12.5px] leading-snug text-slate-800">
                            @foreach ($summary['lines'] as $line)
                                <li class="flex gap-2"><span class="mt-[5px] h-2 w-2 shrink-0 rounded-full {{ $lineTone[$line['tone']] }}"></span><span>{{ $line['text'] }}</span></li>
                            @endforeach
                        </ul>
                        <div class="rounded-md border border-[#F28C28]/50 bg-orange-50/60 px-3 py-2.5">
                            <p class="mb-1 text-[11.5px] font-bold text-ae-navy">ข้อเสนอให้ดำเนินการ</p>
                            @if ($summary['actions'])
                                <ol class="list-decimal space-y-1 pl-4 text-[11.5px] leading-snug text-slate-700">
                                    @foreach ($summary['actions'] as $a)<li>{{ $a }}</li>@endforeach
                                </ol>
                            @else
                                <p class="text-[11.5px] text-slate-600">ไม่มีเรื่องเร่งด่วน งานเดินตามแผน</p>
                            @endif
                        </div>
                    </div>
                </section>
                @endif

                @if ($show('highlights') && $r['highlights'])
                    <section class="avoid">
                        <h2 class="mb-1.5 text-[14px] font-bold text-ae-navy">{{ ++$section }}. ประเด็นสำคัญรายโครงการ</h2>
                        <ol class="list-decimal space-y-1 pl-5 text-slate-700">
                            @foreach ($r['highlights'] as $h)
                                <li><strong class="{{ $tone[$h['tone']] }}">{{ $h['title'] }}</strong> — {{ $h['text'] }}</li>
                            @endforeach
                        </ol>
                    </section>
                @endif

                @if ($show('kpis'))
                <section class="avoid">
                    <h2 class="mb-1.5 text-[14px] font-bold text-ae-navy">{{ ++$section }}. ตัวเลขหลัก</h2>
                    @php
                        $cards = array_filter([
                            ['โครงการที่กำลังทำ', $k['projects'], 'โครงการ', 'ด่วน '.$k['urgent'].' · ล่าช้า '.$k['lateProjects'], 'text-ae-navy', 'border-t-blue-600'],
                            ['ตู้ที่ยังผลิตอยู่', $k['cabinetsOpen'], 'ตู้', 'เสร็จแล้ว '.$k['cabinetsDone'].' จาก '.$k['cabinetsTotal'].' ตู้', 'text-ae-navy', 'border-t-emerald-500'],
                            ['ส่งมอบเดือน '.$k['monthLabel'], $k['monthDone'].'/'.$k['monthPlanned'], 'ตู้', $k['monthOverdue'] ? 'เลยกำหนด '.$k['monthOverdue'].' ตู้' : 'ไม่มีตู้เลยกำหนด', $k['monthOverdue'] ? 'text-orange-700' : 'text-ae-navy', 'border-t-[#F28C28]'],
                            ['งานย่อยเลยกำหนด', $k['lateSubtasks'], 'งาน', $k['lateSubtasks'] ? 'ใน '.$k['lateCabinets'].' ตู้ · '.$k['lateInProjects'].' โครงการ' : 'ไม่มีงานค้าง', $k['lateSubtasks'] ? 'text-red-700' : 'text-ae-navy', 'border-t-red-600'],
                            $value !== null && $value['projects'] ? ['มูลค่างานในมือ', number_format($value['sum'] / 1e6, 2), 'ล้านบาท', 'ก่อน VAT · '.$value['projects'].' โครงการ', 'text-ae-navy', 'border-t-slate-500'] : null,
                        ]);
                    @endphp
                    <div class="grid gap-2 {{ count($cards) > 4 ? 'grid-cols-5' : 'grid-cols-4' }}">
                        @foreach ($cards as [$label, $num, $unit, $sub, $color, $accent])
                            <div class="rounded-md border border-t-[3px] border-slate-200 {{ $accent }} px-2.5 py-2">
                                <div class="text-[11px] text-slate-500">{{ $label }}</div>
                                <div class="text-[22px] font-bold leading-tight tabular-nums {{ $color }}">{{ $num }} <span class="text-[12px] font-medium text-slate-500">{{ $unit }}</span></div>
                                <div class="text-[10.5px] text-slate-500">{{ $sub }}</div>
                            </div>
                        @endforeach
                    </div>
                    @php
                        $prod = $byProject ? [
                            ['มีตู้เลยวันส่ง', $pc['late'], 'โครงการ', 'ต้องจับตาอีก '.$pc['watch'].' โครงการ', $pc['late'] ? 'text-red-700' : 'text-ae-navy', 'border-t-red-600'],
                            ['ต้องส่งใน 7 วัน', $pc['due'][7], 'โครงการ', 'ใน 30 วัน '.$pc['due'][30].' โครงการ', 'text-ae-navy', 'border-t-[#F28C28]'],
                            ['มีตู้รอลูกค้ารับ', $pc['ready'], 'โครงการ', $pc['ready'] ? 'รวม '.count($p['ready']).' ตู้ · นานสุด '.$p['readyMaxWait'].' วัน' : 'ไม่มีตู้ที่รอ', 'text-emerald-700', 'border-t-emerald-500'],
                            ['ผลิตแล้วเฉลี่ย', $p['avgProgress'].'%', '', 'ของตู้ที่ยังไม่เสร็จ '.collect($p['projects'])->sum(fn ($x) => $x['cabinets'] - $x['done']).' ตู้', 'text-ae-navy', 'border-t-blue-600'],
                        ] : [
                            ['ตู้เลยวันส่ง', count($p['late']), 'ตู้', 'ต้องเร่ง '.count($p['rush']).' · เสี่ยง '.count($p['risk']), count($p['late']) ? 'text-red-700' : 'text-ae-navy', 'border-t-red-600'],
                            ['ต้องส่งใน 7 วัน', $p['dueCount'][7], 'ตู้', 'ใน 15 วัน '.$p['dueCount'][15].' · 30 วัน '.$p['dueCount'][30], 'text-ae-navy', 'border-t-[#F28C28]'],
                            ['รอลูกค้ารับ', count($p['ready']), 'ตู้', count($p['ready']) ? 'นานสุด '.$p['readyMaxWait'].' วัน' : 'ไม่มีตู้ที่รอ', 'text-emerald-700', 'border-t-emerald-500'],
                            ['ผลิตแล้วเฉลี่ย', $p['avgProgress'].'%', '', 'ของตู้ที่ยังไม่เสร็จ', 'text-ae-navy', 'border-t-blue-600'],
                        ];
                    @endphp
                    <div class="mt-2 grid grid-cols-4 gap-2">
                        @foreach ($prod as [$label, $num, $unitLabel, $sub, $color, $accent])
                            <div class="rounded-md border border-t-[3px] border-slate-200 {{ $accent }} px-2.5 py-2">
                                <div class="text-[11px] text-slate-500">{{ $label }}</div>
                                <div class="text-[22px] font-bold leading-tight tabular-nums {{ $num === 0 ? 'text-slate-400' : $color }}">{{ $num }} <span class="text-[12px] font-medium text-slate-500">{{ $unitLabel }}</span></div>
                                <div class="text-[10.5px] text-slate-500">{{ $sub }}</div>
                            </div>
                        @endforeach
                    </div>
                </section>
                @endif

                @if ($show('status') && ! $byProject)
                    {{-- by cabinet: the cabinets late or at risk, worst first --}}
                    <section>
                        <h2 class="mb-1.5 text-[14px] font-bold text-ae-navy">{{ ++$section }}. ตู้ที่ล่าช้าหรือเสี่ยงส่งไม่ทัน ({{ $watchCabinets->count() }} ตู้)</h2>
                        @if ($watchCabinets->isNotEmpty())
                            <table class="w-full table-fixed rounded-md border border-slate-200">
                                <colgroup><col class="w-[33%]"><col class="w-[11%]"><col class="w-[7%]"><col><col class="w-[9%]"></colgroup>
                                <thead>
                                    <tr class="bg-slate-100 text-left text-[10.5px] font-bold text-slate-600">
                                        <th class="px-2.5 py-1.5">ตู้</th><th class="px-1.5 py-1.5">กำหนดส่ง</th><th class="px-1.5 py-1.5 text-right">ผลิต</th><th class="px-1.5 py-1.5">เหตุผล · ค้างที่แผนก</th><th class="px-2.5 py-1.5">สถานะ</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($watchCabinets->take($listLimit)->groupBy('so') as $so => $list)
                                        <tr class="avoid border-t border-slate-200 bg-slate-50">
                                            <td colspan="5" class="px-2.5 py-1 text-[11px]"><strong class="text-ae-navy">{{ $so }}</strong> <span class="text-slate-500">· {{ $list->first()['customer'] }} · {{ $list->count() }} ตู้</span></td>
                                        </tr>
                                    @foreach ($list as $c)
                                        <tr class="avoid border-t border-slate-100 align-top">
                                            <td class="px-2.5 py-1 pl-4 font-semibold leading-snug text-ae-navy">{{ $c['mo'] }}</td>
                                            <td class="whitespace-nowrap px-1.5 py-1 tabular-nums">{{ $c['dueText'] }}</td>
                                            <td class="px-1.5 py-1 text-right font-bold tabular-nums">{{ $c['progress'] }}%</td>
                                            @php
                                                // the late work per department, the 2 holding most, then "อื่น ๆ" (one short line); without late work: who has its current step
                                                $byDept = collect($c['lateByDept']);
                                                $who = $byDept->isNotEmpty()
                                                    ? $byDept->take(2)->map(fn ($n, $d) => $d.' '.$n)->values()->all() + ($byDept->count() > 2 ? [2 => 'อื่น ๆ '.$byDept->slice(2)->sum()] : [])
                                                    : ['ตามที่ '.implode(', ', array_slice($c['holders'], 0, 3))];
                                            @endphp
                                            <td class="px-1.5 py-1 text-[11px] leading-snug"><span class="font-semibold text-slate-800">{{ $c['brief'] }}</span>
                                                @if ($byDept->isNotEmpty() || $c['holders'])<div class="text-slate-500">@foreach ($who as $part)<span class="whitespace-nowrap">{{ $part }}</span>@unless ($loop->last) · @endunless @endforeach</div>@endif</td>
                                            <td class="whitespace-nowrap px-2.5 py-1"><span class="inline-block rounded-full px-2 py-px text-[10.5px] font-bold {{ $pstate[$c['state']][1] }}">{{ $pstate[$c['state']][0] }}</span></td>
                                        </tr>
                                    @endforeach
                                    @endforeach
                                </tbody>
                            </table>
                            @if ($watchCabinets->count() > $listLimit)<p class="mt-1 text-[10px] text-slate-400">และอีก {{ $watchCabinets->count() - $listLimit }} ตู้ · ดูทั้งหมดที่ รายงานการผลิต › ล่าช้า / เสี่ยง</p>@endif
                        @else
                            <p class="text-slate-500">ไม่มีตู้ที่ล่าช้าหรือเสี่ยง</p>
                        @endif
                    </section>
                @endif

                @if ($show('status') && $byProject)
                <section>
                    <h2 class="mb-1.5 text-[14px] font-bold text-ae-navy">{{ ++$section }}. สถานะโครงการที่กำลังดำเนินการ</h2>
                    <table class="w-full overflow-hidden rounded-md border border-slate-200">
                        <thead>
                            <tr class="bg-slate-100 text-left text-[10.5px] font-bold text-slate-600">
                                <th class="px-2.5 py-1.5">โครงการ / ลูกค้า</th>
                                <th class="px-1.5 py-1.5">กำหนดส่ง</th>
                                <th class="w-[34mm] px-1.5 py-1.5">ผลิตแล้ว</th>
                                <th class="px-1.5 py-1.5 text-right">ตู้</th>
                                <th class="px-2.5 py-1.5">สถานะ</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($r['projects'] as $row)
                                @php [$label, $pill, $bar] = $states[$row['state']]; @endphp
                                <tr class="avoid border-t border-slate-100 align-top">
                                    <td class="px-2.5 py-1.5">
                                        <div class="font-bold text-ae-navy">{{ $row['name'] }}</div>
                                        <div class="text-[10.5px] text-slate-500">{{ $row['no'] }}@if ($row['customer']) · {{ $row['customer'] }}@endif</div>
                                        <div class="text-[10.5px] text-slate-600">ประเด็น: {{ $row['issue'] }}</div>
                                    </td>
                                    <td class="whitespace-nowrap px-1.5 py-1.5 text-[11px] leading-snug">{{ $row['due'] }}<br><span class="font-semibold {{ $row['state'] === 'late' ? 'text-red-700' : 'text-slate-500' }}">{{ $row['dueNote'] }}</span></td>
                                    <td class="px-1.5 py-2">
                                        <div class="flex items-center gap-1.5">
                                            <div class="relative h-[7px] flex-1 rounded-full bg-slate-200">
                                                <div class="h-[7px] rounded-full {{ $bar }}" style="width: {{ $row['progress'] }}%"></div>
                                                @if ($row['timeElapsed'] !== null)<div class="absolute -top-[3px] h-[13px] w-[1.5px] bg-slate-700" style="left: {{ $row['timeElapsed'] }}%"></div>@endif
                                            </div>
                                            <strong class="w-8 text-right tabular-nums text-ae-navy">{{ $row['progress'] }}%</strong>
                                        </div>
                                    </td>
                                    <td class="px-1.5 py-1.5 text-right tabular-nums">{{ $row['cabinets'] }}</td>
                                    <td class="whitespace-nowrap px-2.5 py-1.5"><span class="inline-block rounded-full px-2 py-px text-[10.5px] font-bold {{ $pill }}">{{ $label }}</span></td>
                                </tr>
                            @empty
                                <tr><td colspan="5" class="px-2.5 py-4 text-center text-slate-400">ไม่มีโครงการที่กำลังดำเนินการ</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                    <p class="mt-1 text-[10px] text-slate-400">เส้น │ บนแถบ = เวลาที่ผ่านไป (วันเริ่ม → วันส่ง)@if ($r['lastClosed']) · ปิดโครงการล่าสุด: {{ $r['lastClosed'] }}@endif</p>
                </section>
                @endif

                {{-- what must be handed over in the next 30 days --}}
                @if ($show('due'))
                <section class="avoid">
                    <h2 class="mb-1.5 text-[14px] font-bold text-ae-navy">{{ ++$section }}. กำหนดส่งมอบใน 30 วัน ({{ $byProject ? $dueProjects->count().' โครงการ' : $dueCabinets->count().' ตู้' }})</h2>
                    @if ($byProject ? $dueProjects->isNotEmpty() : $dueCabinets->isNotEmpty())
                        <table class="w-full rounded-md border border-slate-200">
                            <thead>
                                <tr class="bg-slate-100 text-left text-[10.5px] font-bold text-slate-600">
                                    <th class="px-2.5 py-1.5">วันส่ง</th><th class="px-1.5 py-1.5">{{ $byProject ? 'โครงการ' : 'ตู้ / โครงการ' }}</th><th class="px-1.5 py-1.5">{{ $byProject ? 'ตู้ที่ต้องส่ง (7 / 15 / 30 วัน)' : 'ขั้นตอนตอนนี้' }}</th><th class="px-1.5 py-1.5 text-right">ผลิต</th><th class="px-2.5 py-1.5">สถานะ</th>
                                </tr>
                            </thead>
                            <tbody>
                                @if ($byProject)
                                    @foreach ($dueProjects->take($listLimit) as $x)
                                        <tr class="avoid border-t border-slate-100 align-top">
                                            <td class="whitespace-nowrap px-2.5 py-1"><strong>{{ $x['nextText'] }}</strong><div class="text-[10.5px] text-slate-500">อีก {{ $x['nextDays'] }} วัน</div></td>
                                            <td class="px-1.5 py-1"><div class="font-bold text-ae-navy">{{ $x['so'] }}</div><div class="text-[10.5px] text-slate-500">{{ $x['customer'] }}</div></td>
                                            <td class="px-1.5 py-1 tabular-nums">{{ $x['dueCabinets'][7] }} / {{ $x['dueCabinets'][15] }} / {{ $x['dueCabinets'][30] }} ตู้</td>
                                            <td class="px-1.5 py-1 text-right font-bold tabular-nums">{{ $x['progress'] }}%</td>
                                            <td class="whitespace-nowrap px-2.5 py-1"><span class="inline-block rounded-full px-2 py-px text-[10.5px] font-bold {{ $pstate[$x['state']][1] }}">{{ $pstate[$x['state']][0] }}</span></td>
                                        </tr>
                                    @endforeach
                                @else
                                    @foreach ($dueCabinets->take($listLimit) as $c)
                                        <tr class="avoid border-t border-slate-100 align-top">
                                            <td class="whitespace-nowrap px-2.5 py-1"><strong>{{ $c['dueText'] }}</strong><div class="text-[10.5px] text-slate-500">อีก {{ $c['days'] }} วัน</div></td>
                                            <td class="px-1.5 py-1"><div class="font-bold text-ae-navy">{{ $c['mo'] }}</div><div class="text-[10.5px] text-slate-500">{{ $c['so'] }} · {{ $c['customer'] }}</div></td>
                                            <td class="px-1.5 py-1 text-[11px]">{{ $c['stage'] }}</td>
                                            <td class="px-1.5 py-1 text-right font-bold tabular-nums">{{ $c['progress'] }}%</td>
                                            <td class="whitespace-nowrap px-2.5 py-1"><span class="inline-block rounded-full px-2 py-px text-[10.5px] font-bold {{ $pstate[$c['state']][1] }}">{{ $pstate[$c['state']][0] }}</span></td>
                                        </tr>
                                    @endforeach
                                @endif
                            </tbody>
                        </table>
                    @else
                        <p class="text-slate-500">ไม่มีกำหนดส่งมอบใน 30 วันข้างหน้า</p>
                    @endif
                </section>
                @endif

                {{-- produced, waiting for the customer to take it --}}
                @if ($show('ready'))
                <section class="avoid">
                    <h2 class="mb-1.5 text-[14px] font-bold text-ae-navy">{{ ++$section }}. ผลิตเสร็จแล้ว รอลูกค้ารับ ({{ $byProject ? $readyProjects->count().' โครงการ · ' : '' }}{{ count($p['ready']) }} ตู้)</h2>
                    @if ($p['ready'])
                        <table class="w-full rounded-md border border-slate-200">
                            <thead>
                                <tr class="bg-slate-100 text-left text-[10.5px] font-bold text-slate-600">
                                    <th class="px-2.5 py-1.5">{{ $byProject ? 'โครงการ' : 'ตู้ / โครงการ' }}</th><th class="px-1.5 py-1.5">{{ $byProject ? 'ตู้ที่รอรับ' : 'ผลิตเสร็จ' }}</th><th class="px-2.5 py-1.5 text-right">รอมาแล้ว</th>
                                </tr>
                            </thead>
                            <tbody>
                                @if ($byProject)
                                    @foreach ($readyProjects as $x)
                                        <tr class="border-t border-slate-100">
                                            <td class="px-2.5 py-1"><span class="font-bold text-ae-navy">{{ $x['so'] }}</span> <span class="text-[10.5px] text-slate-500">· {{ $x['customer'] }}</span></td>
                                            <td class="px-1.5 py-1">{{ $x['ready'] }} จาก {{ $x['cabinets'] }} ตู้</td>
                                            <td class="px-2.5 py-1 text-right font-bold {{ $x['readyMaxWait'] >= \App\Services\ProductionReport::WAIT_RED_DAYS ? 'text-red-700' : '' }}">นานสุด {{ $x['readyMaxWait'] }} วัน</td>
                                        </tr>
                                    @endforeach
                                @else
                                    @foreach (collect($p['ready'])->take($listLimit) as $c)
                                        <tr class="border-t border-slate-100">
                                            <td class="px-2.5 py-1"><span class="font-bold text-ae-navy">{{ $c['mo'] }}</span> <span class="text-[10.5px] text-slate-500">· {{ $c['so'] }} · {{ $c['customer'] }}</span></td>
                                            <td class="px-1.5 py-1">{{ $c['producedText'] }}</td>
                                            <td class="px-2.5 py-1 text-right font-bold {{ $c['waitDays'] >= \App\Services\ProductionReport::WAIT_RED_DAYS ? 'text-red-700' : '' }}">{{ $c['waitDays'] }} วัน</td>
                                        </tr>
                                    @endforeach
                                @endif
                            </tbody>
                        </table>
                    @else
                        <p class="text-slate-500">ไม่มีตู้ที่ผลิตเสร็จแล้วรอลูกค้ารับ</p>
                    @endif
                </section>
                @endif

                @if ($show('months'))
                <section class="avoid">
                    <h2 class="mb-1.5 text-[14px] font-bold text-ae-navy">{{ ++$section }}. แผนส่งมอบตู้รายเดือน ({{ $r['months'][0]['label'] }} {{ $r['months'][0]['year'] }} – {{ last($r['months'])['label'] }} {{ last($r['months'])['year'] }})</h2>
                    <div class="rounded-md border border-slate-200 px-3.5 py-3">
                        <div class="flex h-[36mm] items-end gap-6 border-b border-slate-300 px-3" role="img"
                             aria-label="{{ collect($r['months'])->map(fn ($m) => $m['label'].' '.$m['done'].'/'.$m['planned'])->implode(', ') }}">
                            @foreach ($r['months'] as $m)
                                <div class="flex h-full flex-1 flex-col items-center justify-end">
                                    <span class="text-[11.5px] font-bold tabular-nums text-ae-navy">{{ $m['done'] }}/{{ $m['planned'] }}</span>
                                    <div class="relative w-full max-w-[11mm] overflow-hidden rounded-t bg-slate-200 {{ $m['current'] ? 'outline outline-2 outline-blue-500' : '' }}"
                                         style="height: {{ $m['planned'] ? max(4, $m['planned'] / $maxMonth * 85) : 2 }}%">
                                        <div class="absolute inset-x-0 bottom-0 bg-emerald-500" style="height: {{ $m['planned'] ? $m['done'] / $m['planned'] * 100 : 0 }}%"></div>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                        <div class="flex gap-6 px-3 pt-1 text-center text-[11px] text-slate-500">
                            @foreach ($r['months'] as $m)
                                <span class="flex-1 {{ $m['current'] ? 'font-bold text-blue-700' : '' }}">{{ $m['label'] }} {{ $m['year'] }}</span>
                            @endforeach
                        </div>
                        <div class="mt-2 flex flex-wrap justify-between gap-1.5 text-[10.5px] text-slate-500">
                            <span><span class="mr-1 inline-block h-2 w-2 rounded-sm bg-emerald-500 align-[-1px]"></span>ส่งแล้ว <span class="ml-2.5 mr-1 inline-block h-2 w-2 rounded-sm bg-slate-200 align-[-1px]"></span>ตามแผน (ตามวันครบกำหนดของตู้)</span>
                            <span>ตัวเลขบนแท่ง = ส่งแล้ว / ตามแผน</span>
                        </div>
                    </div>
                </section>
                @endif

                @if ($show('bottleneck'))
                <section class="avoid">
                    <h2 class="mb-1.5 text-[14px] font-bold text-ae-navy">{{ ++$section }}. คอขวดตามแผนก (งานย่อยที่เลยกำหนด)</h2>
                    @if ($r['departments'])
                        <table class="w-full rounded-md border border-slate-200">
                            <thead>
                                <tr class="bg-slate-100 text-left text-[10.5px] font-bold text-slate-600">
                                    <th class="px-2.5 py-1.5">แผนก</th><th class="px-1.5 py-1.5">งานเลยกำหนด</th><th class="px-1.5 py-1.5 text-right">ตู้</th><th class="px-2.5 py-1.5 text-right">ค้างนานสุด</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($r['departments'] as $d)
                                    <tr class="border-t border-slate-100">
                                        <td class="px-2.5 py-1 font-semibold">{{ $d['name'] }}</td>
                                        <td class="w-[60mm] px-1.5 py-1"><span class="flex items-center gap-1.5"><span class="h-2.5 rounded-sm bg-red-400" style="width: {{ $d['late'] / $maxLate * 80 }}%"></span><strong class="tabular-nums text-red-700">{{ $d['late'] }}</strong></span></td>
                                        <td class="px-1.5 py-1 text-right tabular-nums">{{ $d['cabinets'] }}</td>
                                        <td class="px-2.5 py-1 text-right tabular-nums">{{ $d['days'] }} วัน</td>
                                    </tr>
                                @endforeach
                                <tr class="border-t border-slate-200 bg-slate-50 font-bold">
                                    <td class="px-2.5 py-1">รวม</td><td class="px-1.5 py-1 text-red-700">{{ $k['lateSubtasks'] }} งาน</td><td class="px-1.5 py-1 text-right tabular-nums">{{ $k['lateCabinets'] }}</td><td></td>
                                </tr>
                            </tbody>
                        </table>
                    @else
                        <p class="text-slate-500">ไม่มีงานย่อยเลยกำหนด</p>
                    @endif
                </section>
                @endif

                @if ($show('follow') && $byProject)
                <section class="avoid">
                    <h2 class="mb-1.5 text-[14px] font-bold text-ae-navy">{{ ++$section }}. ตู้ที่ต้องติดตาม (เลยกำหนด หรือครบกำหนดใน 30 วัน)</h2>
                    @if ($r['follow'])
                        <table class="w-full rounded-md border border-slate-200">
                            <thead>
                                <tr class="bg-slate-100 text-left text-[10.5px] font-bold text-slate-600">
                                    <th class="px-2.5 py-1.5">ตู้</th><th class="px-1.5 py-1.5">โครงการ</th><th class="px-1.5 py-1.5">กำหนดส่ง</th><th class="px-1.5 py-1.5 text-right">ผลิต</th><th class="px-2.5 py-1.5">เหตุผล</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($r['follow'] as $c)
                                    <tr class="avoid border-t border-slate-100 align-top">
                                        <td class="px-2.5 py-1"><div class="font-bold text-ae-navy">{{ $c['name'] }}</div><div class="text-[10.5px] text-slate-500">{{ $c['mo'] }}</div></td>
                                        <td class="px-1.5 py-1">{{ $c['project'] }}</td>
                                        <td class="whitespace-nowrap px-1.5 py-1 tabular-nums">{{ $c['due'] }}</td>
                                        <td class="px-1.5 py-1 text-right font-bold tabular-nums">{{ $c['progress'] }}%</td>
                                        <td class="whitespace-nowrap px-2.5 py-1 text-[11px] font-semibold {{ $tone[$c['tone']] }}">{{ $c['why'] }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    @else
                        <p class="text-slate-500">ไม่มีตู้ที่เลยกำหนดหรือครบกำหนดใน 30 วัน</p>
                    @endif
                </section>
                @endif

                @if ($show('sign'))
                <section class="avoid grid grid-cols-2 gap-10 px-5 pt-2 text-center text-[11.5px] text-slate-700">
                    <div><div class="h-7 border-b border-dotted border-slate-500"></div><div class="mt-1">ผู้จัดทำรายงาน</div><div class="text-slate-400">วันที่ ____/____/______</div></div>
                    <div><div class="h-7 border-b border-dotted border-slate-500"></div><div class="mt-1">ผู้อนุมัติ</div><div class="text-slate-400">วันที่ ____/____/______</div></div>
                </section>
                @endif
            </div>
            </td></tr></tbody>
        </table>

        <div class="printed text-[9.5px] text-slate-400">เอกสารภายใน · พิมพ์จากระบบ PMO {{ $printed }} น.</div>
        <img src="{{ asset('images/report/letterhead-footer.png') }}" alt="www.avatar-electric.com" class="lh lh-foot">
    </div>
    <script>
        // "โครงการ / ตู้" in ตัวเลือกรายงาน (no Alpine on this page): an SO box is the whole project and ticks its cabinets;
        // unticking one of them leaves the others as single cabinets; counts x/y; search over SO, MO, names, customers
        (() => {
            const picker = document.querySelector('[data-cab-picker]');
            if (! picker) return;
            const groups = [...picker.querySelectorAll('[data-cab-group]')];
            const search = picker.querySelector('[data-cab-search]');
            const none = picker.querySelector('[data-cab-none]');
            const sync = (g) => {
                const boxes = [...g.querySelectorAll('[data-cab]')], on = boxes.filter((b) => b.checked).length, all = g.querySelector('[data-cab-all]');
                g.querySelector('[data-cab-count]').textContent = on + '/' + boxes.length;
                all.checked = boxes.length > 0 && on === boxes.length;
                all.indeterminate = on > 0 && on < boxes.length;
            };
            const show = () => {
                const q = search.value.trim().toLowerCase();
                let any = false;
                groups.forEach((g) => {
                    const soHit = ! q || g.dataset.text.includes(q);
                    let hits = 0;
                    g.querySelectorAll('[data-cab-row]').forEach((row) => {
                        const hit = soHit || row.dataset.text.includes(q);
                        row.hidden = ! hit;
                        hits += hit ? 1 : 0;
                    });
                    g.hidden = ! soHit && ! hits;
                    if (q && ! soHit && hits) g.open = true;
                    any = any || ! g.hidden;
                });
                none.hidden = any;
            };
            groups.forEach((g) => {
                const all = g.querySelector('[data-cab-all]');
                all.addEventListener('click', (e) => e.stopPropagation());   // ticking it does not fold the group
                all.addEventListener('change', () => { g.querySelectorAll('[data-cab]').forEach((b) => { b.checked = all.checked; }); sync(g); });
                g.querySelectorAll('[data-cab]').forEach((b) => b.addEventListener('change', () => sync(g)));
                sync(g);
            });
            search.addEventListener('input', show);
            search.addEventListener('keydown', (e) => { if (e.key === 'Enter') e.preventDefault(); });   // Enter searches, it does not send the form
            show();
        })();
    </script>
</body>
</html>
