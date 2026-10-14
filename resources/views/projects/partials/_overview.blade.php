{{-- The project page's "ภาพรวม" tab: every project fact on the left, delivery risk / departments / cabinets / activity on the right.
     Data comes from App\Services\ProjectOverview ($overview). --}}
@php
    $tone = [
        'red' => ['text' => 'text-red-700', 'dot' => 'bg-red-500'],
        'amber' => ['text' => 'text-amber-700', 'dot' => 'bg-amber-500'],
        'ok' => ['text' => 'text-slate-700', 'dot' => 'bg-[#7FA6C9]'],
        'idle' => ['text' => 'text-slate-500', 'dot' => 'bg-slate-300'],
        'done' => ['text' => 'text-slate-500', 'dot' => 'bg-[#0F4C81]'],
        'none' => ['text' => 'text-slate-700', 'dot' => 'bg-slate-400'],
    ];
    $health = $overview['health'];
    $healthShort = ['red' => 'เสี่ยงส่งไม่ทัน', 'amber' => 'ทันกำหนด · มีงานค้าง', 'ok' => 'ตามแผน', 'none' => ''][$health['tone']] ?? '';
    $canContact = auth()->user()->allows('data.contact');
    $canFinance = auth()->user()->allows('data.finance');
    $baht = fn ($v) => $v !== null ? number_format((float) $v, 2) : '-';
    $empty = '<span class="text-slate-400">ยังไม่ระบุ</span>';
@endphp

<div class="flex flex-wrap items-start gap-5">

    {{-- ===== project facts ===== --}}
    <aside aria-label="ข้อมูลโครงการ" class="w-full rounded-xl border border-slate-200 bg-white shadow-sm lg:w-80 lg:shrink-0">
        <div class="space-y-3 border-b border-slate-100 p-4">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <div class="flex items-center gap-1.5">
                    <x-status-badge :status="$project->status" />
                    <span class="text-xs font-medium text-slate-500">· {{ \App\Models\Project::$priorities[$project->priority] ?? $project->priority }}</span>
                </div>
                @if ($healthShort)
                    <span class="inline-flex items-center gap-1.5 text-xs font-semibold {{ $tone[$health['tone']]['text'] }}">
                        <span class="h-1.5 w-1.5 rounded-full {{ $tone[$health['tone']]['dot'] }}"></span>{{ $healthShort }}
                    </span>
                @endif
            </div>
            <div class="flex items-end justify-between">
                <div>
                    <div class="text-xs text-slate-500">ส่งมอบ</div>
                    <div class="text-lg font-bold text-ae-navy">{{ optional($project->due_date)->thaiDate() ?? '-' }}</div>
                </div>
                @if ($project->days_remaining !== null && ! $project->isLocked())
                    <div class="text-right">
                        <div class="text-xs text-slate-500">{{ $project->days_remaining >= 0 ? 'เหลือ' : 'เลยกำหนด' }}</div>
                        <div class="text-lg font-bold {{ $project->days_remaining < 0 ? 'text-red-700' : 'text-ae-navy' }}">{{ abs($project->days_remaining) }} วัน</div>
                    </div>
                @endif
            </div>
            @if ($overview['timeline'])
                <div>
                    <div class="relative h-1.5 rounded-full bg-slate-100" role="img" aria-label="เวลาผ่านไป {{ $overview['timeline']['elapsed'] }}% ผลิตได้ {{ $project->production_progress }}%">
                        <div class="h-1.5 rounded-full bg-slate-400" style="width: {{ $overview['timeline']['elapsed'] }}%"></div>
                        <div class="absolute -top-1 h-3.5 w-0.5 bg-[#0F4C81]" style="left: {{ min(99, $project->production_progress) }}%"></div>
                    </div>
                    <div class="mt-1 flex justify-between text-[11px] text-slate-500">
                        <span>เริ่ม {{ $overview['timeline']['start'] }}</span>
                        <span>เวลาผ่านไป {{ $overview['timeline']['elapsed'] }}% · ผลิตได้ {{ $project->production_progress }}%</span>
                    </div>
                </div>
            @endif
            <div class="grid grid-cols-2 gap-2 pt-1">
                <div class="rounded-lg bg-slate-50 px-3 py-2">
                    <div class="text-[11px] text-slate-500">การผลิต · {{ $overview['cabinetCount'] }} ตู้</div>
                    <div class="text-lg font-bold text-ae-navy">{{ $project->production_progress }}%</div>
                </div>
                <div class="rounded-lg bg-slate-50 px-3 py-2">
                    <div class="text-[11px] text-slate-500">งานบริหาร</div>
                    @if ($project->projectTasks->isNotEmpty())
                        <div class="text-lg font-bold text-ae-navy">{{ $project->task_progress }}%</div>
                    @else
                        <div class="pt-1 text-xs font-medium text-slate-400">ยังไม่มีงาน</div>
                    @endif
                </div>
            </div>
        </div>

        <div class="border-b border-slate-100 p-4">
            <h3 class="mb-2 text-xs font-bold text-slate-500">ลูกค้า</h3>
            <div class="font-bold text-ae-navy">{{ $project->customer_name ?: '-' }}</div>
            <dl class="mt-1.5 grid grid-cols-[6.5rem_minmax(0,1fr)] gap-x-2 gap-y-1 text-sm">
                @if ($canContact)
                    <dt class="text-slate-500">เบอร์ติดต่อ</dt>
                    <dd>@if ($project->customer_phone)<a href="tel:{{ preg_replace('/[^0-9+]/', '', $project->customer_phone) }}" class="text-[#0F4C81] hover:underline">{{ $project->customer_phone }}</a>@else{!! $empty !!}@endif</dd>
                @endif
                <dt class="text-slate-500">เลขที่ PO</dt><dd class="break-words font-medium text-slate-800">{!! $project->po_no ? e($project->po_no) : $empty !!}</dd>
                {{-- no longer asked for on the form: shown only for an older project that still has a value --}}
                @if ($project->sales_order_no)
                    <dt class="text-slate-500">ใบสั่งขาย / อ้างอิง</dt><dd class="break-words text-slate-800">{{ $project->sales_order_no }}</dd>
                @endif
                <dt class="text-slate-500">การชำระเงิน</dt><dd class="text-slate-800">{!! $project->payment_terms ? e($project->payment_terms) : $empty !!}</dd>
            </dl>
        </div>

        <div class="border-b border-slate-100 p-4">
            <h3 class="mb-2 text-xs font-bold text-slate-500">ทีม</h3>
            <dl class="grid grid-cols-[6.5rem_minmax(0,1fr)] gap-x-2 gap-y-1 text-sm">
                <dt class="text-slate-500">PM</dt><dd class="font-medium text-slate-800">{!! $project->projectManager ? e($project->projectManager->name) : $empty !!}</dd>
                <dt class="text-slate-500">พนักงานขาย</dt><dd class="font-medium text-slate-800">{!! $project->sales_person ? e($project->sales_person) : $empty !!}</dd>
                @if ($project->project_owner)
                    <dt class="text-slate-500">เจ้าของโครงการ</dt><dd class="text-slate-800">{{ $project->project_owner }}</dd>
                @endif
                <dt class="text-slate-500">ประเภทงาน</dt><dd class="text-slate-800">{!! $project->jobType ? e($project->jobType->name) : $empty !!}</dd>
            </dl>
        </div>

        @if ($canFinance)   {{-- Settings > สิทธิ์การใช้งาน --}}
            <div class="border-b border-slate-100 p-4">
                <h3 class="mb-1.5 text-xs font-bold text-slate-500">มูลค่างาน</h3>
                @if ($project->contract_value !== null || $project->margin !== null)
                    <div class="text-lg font-bold tabular-nums text-ae-navy">{{ $baht($project->contractValueWithVat()) }} <span class="text-xs font-medium text-slate-500">บาท รวม VAT 7%</span></div>
                    <dl class="mt-1 grid grid-cols-[6.5rem_minmax(0,1fr)] gap-x-2 gap-y-0.5 text-sm tabular-nums">
                        <dt class="text-slate-500">ก่อน VAT</dt><dd class="text-slate-800">{{ $baht($project->contract_value) }}</dd>
                        <dt class="text-slate-500">VAT 7%</dt><dd class="text-slate-800">{{ $baht($project->vatAmount()) }}</dd>
                        <dt class="text-slate-500">Margin</dt><dd class="text-slate-800">{{ $baht($project->margin) }}@if ($project->marginPercent() !== null) · {{ $project->marginPercent() }} %@endif</dd>
                    </dl>
                @else
                    <div class="text-sm text-slate-400">ยังไม่ระบุมูลค่า</div>
                @endif
            </div>
        @endif

        @if ($project->description)
            <div class="border-b border-slate-100 p-4">
                <h3 class="mb-1.5 text-xs font-bold text-slate-500">คำอธิบาย</h3>
                <p class="text-sm text-slate-700">{{ $project->description }}</p>
            </div>
        @endif

        @if ($overview['upcoming'])
            <div class="border-b border-slate-100 p-4">
                <h3 class="mb-2 text-xs font-bold text-slate-500">ครบกำหนดใน 7 วัน</h3>
                <ul class="space-y-1.5 text-sm">
                    @foreach ($overview['upcoming'] as $u)
                        <li class="flex justify-between gap-2"><span class="min-w-0 text-slate-800">{{ $u['name'] }}</span><span class="shrink-0 whitespace-nowrap text-slate-500">{{ $u['due'] }} · {{ $u['cabinets'] }} ตู้</span></li>
                    @endforeach
                </ul>
            </div>
        @endif

        @if ($latestFiles)
            <div class="p-4">
                <h3 class="mb-2 text-xs font-bold text-slate-500">ไฟล์ล่าสุด</h3>
                <ul class="space-y-1.5 text-sm">
                    @foreach ($latestFiles as $f)
                        <li class="truncate"><a href="{{ $f['url'] }}" target="_blank" rel="noopener" class="text-[#0F4C81] hover:underline" title="{{ $f['name'] }}">{{ $f['name'] }}</a></li>
                    @endforeach
                    <li><button type="button" @click="tab = 'attachments'" class="text-slate-500 hover:text-slate-700">ดูไฟล์ทั้งหมด</button></li>
                </ul>
            </div>
        @endif
    </aside>

    {{-- ===== work ===== --}}
    <div class="min-w-0 flex-1 basis-[36rem] space-y-4">

        {{-- will it be delivered on time, and why not --}}
        <section aria-label="ผลกระทบต่อวันส่งมอบ" class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
            <div class="flex flex-wrap items-baseline gap-x-3 gap-y-1">
                <span class="inline-flex items-center gap-2 font-bold {{ $tone[$health['tone']]['text'] }}">
                    <span class="h-2 w-2 rounded-full {{ $tone[$health['tone']]['dot'] }}"></span>{{ $health['title'] }}
                </span>
                @if ($health['note'])<span class="text-sm text-slate-500">{{ $health['note'] }}</span>@endif
            </div>
            @if ($health['items'])
                <div class="mt-2 grid gap-x-5 gap-y-2 text-sm text-slate-600 sm:grid-cols-2 xl:grid-cols-3">
                    @foreach ($health['items'] as $item)
                        <div><div class="font-semibold text-slate-800">{{ $item['label'] }}</div>{{ $item['detail'] }}</div>
                    @endforeach
                </div>
                @if (($health['more'] ?? 0) > 0)
                    <p class="mt-2 text-xs text-slate-500">และอีก {{ $health['more'] }} รายการ — ดูในตารางด้านล่าง</p>
                @endif
            @endif
        </section>

        {{-- who is holding what --}}
        @if ($overview['departments'])
            <section aria-label="งานตามแผนก" class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                <div class="mb-3 flex flex-wrap items-baseline justify-between gap-2">
                    <h3 class="text-sm font-bold text-ae-navy">งานตามแผนก</h3>
                    <span class="text-xs text-slate-500">
                        @if ($overview['unassigned'])ยังไม่จ่ายงาน {{ $overview['unassigned'] }} งาน @else จ่ายครบทุกงานแล้ว @endif
                        @if ($overview['startedNotAccepted']) · {{ $overview['startedNotAccepted'] }} งานมี Checklist คืบหน้าแล้ว แต่แผนกยังไม่กดรับงาน @endif
                    </span>
                </div>
                <div class="grid grid-cols-2 gap-2 sm:grid-cols-3 xl:grid-cols-4">
                    @foreach ($overview['departments'] as $d)
                        <div class="rounded-lg border border-slate-100 px-3 py-2">
                            <div class="text-sm font-semibold leading-snug text-ae-navy">{{ $d['name'] }}</div>
                            <div class="mt-1 flex gap-3 text-xs text-slate-600">
                                <span>ค้าง <strong class="text-sm text-slate-800">{{ $d['open'] }}</strong></span>
                                <span>เลยกำหนด <strong class="text-sm {{ $d['late'] ? 'text-amber-700' : 'text-slate-800' }}">{{ $d['late'] }}</strong></span>
                            </div>
                            @if ($d['waitingIds'])
                                <div class="mt-1.5 flex items-center justify-between gap-2 text-xs text-slate-500">
                                    <span>รอรับ {{ count($d['waitingIds']) }} · นานสุด {{ $d['waitingDays'] }} วัน</span>
                                    @if ($overview['canDispatch'])
                                        <button type="button" onclick="pmoRemind('{{ route('cabinet-subtasks.remind') }}', @js($d['waitingIds']), this)"
                                                class="rounded-md border border-amber-500 px-1.5 py-0.5 font-semibold text-amber-700 hover:bg-amber-50 disabled:opacity-50">ทวงถาม</button>
                                    @endif
                                </div>
                            @endif
                        </div>
                    @endforeach
                </div>
            </section>
        @endif

        {{-- every cabinet: one row, one cell per step --}}
        <section id="cabinets-section" aria-label="ตู้ / ใบสั่งผลิต" class="rounded-xl border border-slate-200 bg-white shadow-sm" x-data="{ legend: false }">
            <div class="flex flex-wrap items-center justify-between gap-3 px-4 py-3">
                <h3 class="text-base font-bold text-ae-navy">ตู้ / ใบสั่งผลิต (MO) <span class="font-medium text-slate-500">· {{ $overview['cabinetCount'] }} ตู้</span></h3>
                <div class="flex flex-wrap items-center gap-2">
                    @if ($overview['cabinetCount'])
                        <button type="button" @click="legend = ! legend" :aria-expanded="legend" class="rounded-lg border border-slate-200 px-3 py-1.5 text-sm font-medium text-[#0F4C81] hover:bg-slate-50"
                                x-text="legend ? 'ซ่อนชื่อขั้นตอน' : 'แสดงชื่อขั้นตอน'">แสดงชื่อขั้นตอน</button>
                    @endif
                    @editplan
                    <button type="button" x-data @click="$dispatch('open-modal', 'checklist-bulk')" class="rounded-lg px-3 py-1.5 text-sm font-medium text-[#0F4C81] hover:bg-slate-50">แก้ Checklist ทั้งโครงการ</button>
                    <button type="button" x-data @click="$dispatch('open-modal', 'add-cabinet')" class="rounded-lg bg-[#0F4C81] px-3 py-1.5 text-sm font-semibold text-white hover:bg-ae-navy">+ เพิ่มตู้</button>
                    @endeditplan
                </div>
            </div>

            @forelse ($overview['groups'] as $group)
                @php $cols = 'grid-template-columns: repeat('.max(1, count($group['steps'])).', minmax(0, 1fr))'; @endphp
                <div class="border-t border-slate-100" @if ($overview['canDispatch']) x-data="pmoStepDispatch(@js(collect($group['steps'])->map(fn ($s) => ['label' => $s['label'], 'parts' => $s['parts']])))" @endif>
                    @if (count($overview['groups']) > 1)
                        <div class="px-4 pb-1 pt-3 text-sm font-bold text-ae-navy">{{ $group['templateName'] ?? 'Template อื่น' }} <span class="font-medium text-slate-500">· {{ count($group['steps']) }} ขั้นตอน · {{ $group['count'] }} ตู้</span></div>
                    @endif
                    <ol x-show="legend" x-cloak class="mx-4 mb-3 mt-2 grid gap-x-5 gap-y-1 rounded-lg bg-slate-50 px-3 py-2 text-xs text-slate-700 sm:grid-cols-2 xl:grid-cols-3">
                        @foreach ($group['steps'] as $s)
                            <li class="truncate" title="{{ $s['label'] }}">{{ $s['label'] }}</li>
                        @endforeach
                    </ol>

                    <div class="pmo-cab-table">
                        <div role="table" aria-label="ความคืบหน้าของแต่ละตู้ แยกตามขั้นตอน">
                            <div role="row" class="pmo-cab-grid pmo-cab-head border-y border-slate-100 bg-slate-50 px-4 py-1.5 text-xs font-semibold text-slate-500">
                                <span role="columnheader" class="a-name">ตู้ / MO</span>
                                <span role="columnheader" class="a-strip grid gap-[3px] text-center" style="{{ $cols }}">
                                    @foreach ($group['steps'] as $si => $s)
                                        @if ($overview['canDispatch'])
                                            <button type="button" @click="open({{ $si }})" title="{{ $s['label'] }} — กดเพื่อจ่ายงานขั้นนี้ทุกตู้" aria-label="จ่ายงานขั้น {{ $s['label'] }} ทุกตู้"
                                                    class="rounded text-[11px] font-bold tabular-nums text-[#0F4C81] hover:bg-[#EAF4FF]">{{ $s['no'] }}</button>
                                        @else
                                            <span title="{{ $s['label'] }}" class="text-[11px] tabular-nums">{{ $s['no'] }}</span>
                                        @endif
                                    @endforeach
                                </span>
                                <span role="columnheader" class="a-now">ขั้นปัจจุบัน / สถานะ</span>
                                <span role="columnheader" class="a-pct">รวม</span>
                                <span role="columnheader" class="a-act"><span class="sr-only">ตัวเลือก</span></span>
                            </div>

                            @foreach ($group['batches'] as $batch)
                                @if ($group['showBatches'])
                                    <div role="row" class="flex flex-wrap justify-between gap-2 border-b border-slate-100 px-4 pb-1.5 pt-3">
                                        <span role="cell" class="text-sm font-bold text-ae-navy">
                                            {{ $batch['due'] ? 'งวดส่ง '.$batch['due'] : 'ยังไม่กำหนดวันเสร็จ' }}
                                            <span class="font-medium text-slate-500">· {{ $batch['count'] }} ตู้@if ($batch['daysLeft'] !== null) · {{ $batch['daysLeft'] >= 0 ? 'เหลือ '.$batch['daysLeft'].' วัน' : 'เลยกำหนด '.abs($batch['daysLeft']).' วัน' }}@endif</span>
                                        </span>
                                        <span role="cell" class="text-xs text-slate-500">เฉลี่ย {{ $batch['avg'] }}%</span>
                                    </div>
                                @endif

                                @foreach ($batch['rows'] as $r)
                                    @php $c = $r['cabinet']; @endphp
                                    <div x-data="{ open: false }" class="border-b border-slate-100">
                                        <div role="row" class="pmo-cab-grid px-4 py-2 hover:bg-slate-50">
                                            <div role="cell" class="a-name min-w-0" title="{{ $c->mo_no }}">
                                                {{-- straight to the cabinet's full page (Back returns here) --}}
                                                <a href="{{ route('cabinets.show', $c) }}" class="block truncate font-bold text-ae-navy hover:underline">{{ $r['short'] }}</a>
                                                <div class="truncate text-xs text-slate-500">{{ $r['model'] }}</div>
                                            </div>
                                            <div role="cell" class="a-strip">
                                                <div role="img" aria-label="{{ $r['stripLabel'] }}" class="grid gap-[3px]" style="{{ $cols }}">
                                                    @foreach ($r['steps'] as $s)
                                                        <span title="{{ $s['tip'] }}" class="relative h-[18px] overflow-hidden rounded {{ $s['pct'] >= 100 ? 'bg-[#0F4C81]' : 'bg-slate-200/70' }} {{ $s['lateCount'] ? 'border-2 border-amber-600' : '' }}">
                                                            @if ($s['pct'] > 0 && $s['pct'] < 100)<span class="absolute inset-y-0 left-0 bg-[#7FA6C9]" style="width: {{ $s['pct'] }}%"></span>@endif
                                                        </span>
                                                    @endforeach
                                                </div>
                                            </div>
                                            <div role="cell" class="a-now min-w-0" title="{{ $r['currentMeta'] }}">
                                                <div class="truncate text-sm font-semibold text-ae-navy">{{ $r['current'] }}</div>
                                                <div class="flex items-center gap-1.5 text-xs font-semibold {{ $tone[$r['tone']]['text'] }}">
                                                    <span class="h-1.5 w-1.5 shrink-0 rounded-full {{ $tone[$r['tone']]['dot'] }}"></span><span class="truncate">{{ $r['status'] }}</span>
                                                </div>
                                            </div>
                                            <div role="cell" class="a-pct font-bold tabular-nums text-ae-navy" id="cabinet-card-progress-{{ $c->id }}">{{ $r['progress'] }}%</div>
                                            <div role="cell" class="a-act flex items-center justify-end gap-0.5">
                                                @editplan
                                                <button type="button" @click="$dispatch('open-modal', 'copy-cabinet-{{ $c->id }}')" class="rounded-lg p-1.5 text-slate-400 hover:bg-slate-100 hover:text-[#0F4C81]" title="คัดลอกตู้นี้" aria-label="คัดลอกตู้ {{ $c->mo_no }}">
                                                    <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><rect x="9" y="9" width="12" height="12" rx="2"/><path d="M5 15H4a1 1 0 0 1-1-1V4a1 1 0 0 1 1-1h10a1 1 0 0 1 1 1v1"/></svg>
                                                </button>
                                                @endeditplan
                                                <button type="button" @click="open = ! open" :aria-expanded="open" class="rounded-lg p-1.5 text-slate-500 hover:bg-slate-100" aria-label="แสดงทุกขั้นตอนของ {{ $c->mo_no }}">
                                                    <svg class="h-4 w-4 transition-transform" :class="open && 'rotate-180'" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M6 9l6 6 6-6"/></svg>
                                                </button>
                                            </div>
                                        </div>
                                        <div x-show="open" x-cloak class="bg-slate-50/60 px-4 pb-3 pt-1">
                                            <div class="mb-1.5 text-xs text-slate-500">{{ $c->mo_no }}@if ($r['due']) · กำหนดเสร็จ {{ $r['due'] }}@endif</div>
                                            <div class="grid overflow-hidden rounded-lg border border-slate-100 bg-white sm:grid-cols-2 xl:grid-cols-3">
                                                @foreach ($r['steps'] as $s)
                                                    <div class="grid grid-cols-[minmax(0,1fr)_2.75rem_4.5rem] items-center gap-2 border-b border-slate-100 px-3 py-1.5 text-xs">
                                                        <span class="truncate {{ $s['pct'] >= 100 ? 'text-slate-500' : 'text-ae-navy' }}" title="{{ $s['label'] }} · {{ $s['department'] }}">{{ $s['label'] }} <span class="text-slate-500">· {{ $s['department'] }}</span></span>
                                                        <span class="font-semibold tabular-nums">{{ $s['pct'] }}%</span>
                                                        <span class="font-semibold {{ $s['lateDays'] !== null ? 'text-amber-700' : 'text-slate-500' }}">{{ $s['lateDays'] !== null ? 'ช้า '.$s['lateDays'].' วัน' : ($s['pct'] >= 100 ? 'เสร็จ' : '') }}</span>
                                                    </div>
                                                @endforeach
                                            </div>
                                            <div class="pt-2 text-right"><a href="{{ route('cabinets.show', $c) }}" class="text-sm font-semibold text-[#0F4C81] hover:underline">เปิดหน้าตู้</a></div>
                                        </div>
                                    </div>
                                @endforeach
                            @endforeach
                        </div>
                    </div>
                    @if ($overview['canDispatch'])
                        @include('projects.partials._step-dispatch')
                    @endif
                </div>
            @empty
                <p class="border-t border-slate-100 px-4 py-8 text-center text-sm text-slate-400">ยังไม่มีตู้ไฟฟ้าในโครงการนี้</p>
            @endforelse

            @if ($overview['cabinetCount'])
                <div class="flex flex-wrap gap-x-4 gap-y-1 border-t border-slate-100 px-4 py-2.5 text-xs text-slate-600">
                    <span class="inline-flex items-center gap-1.5"><span class="h-2.5 w-3.5 rounded-sm bg-[#0F4C81]"></span>เสร็จ</span>
                    <span class="inline-flex items-center gap-1.5"><span class="h-2.5 w-3.5 rounded-sm bg-[linear-gradient(90deg,#7FA6C9_50%,#E2E8F0_50%)]"></span>กำลังทำ (เติมตาม %)</span>
                    <span class="inline-flex items-center gap-1.5"><span class="h-2.5 w-3.5 rounded-sm bg-slate-200"></span>ยังไม่เริ่ม</span>
                    <span class="inline-flex items-center gap-1.5"><span class="h-2.5 w-3.5 rounded-sm border-2 border-amber-600"></span>มีงานย่อยเลยกำหนด</span>
                    <span class="text-slate-500">· ชี้ที่ช่องเพื่อดูชื่อขั้นตอน · กดลูกศรเพื่อดูทุกขั้น</span>
                </div>
            @endif
        </section>

        {{-- project-management tasks (separate from cabinet production) --}}
        <x-card title="งานบริหารโครงการ" subtitle="แยกจากงานผลิตตู้">
            <x-slot name="actions">
                @editplan
                <button type="button" x-data @click="$dispatch('open-modal', 'add-project-task')" class="text-sm font-medium text-[#0F4C81] hover:underline">+ เพิ่มงาน</button>
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
                            <tr><td colspan="6" class="px-5 py-6 text-center text-slate-400">ยังไม่มีงานบริหารโครงการ</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </x-card>

        {{-- latest activity + a quick comment --}}
        @if (auth()->user()->allows('data.comments'))
            <section aria-label="ความเคลื่อนไหวล่าสุด" class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                <div class="mb-2 flex items-baseline justify-between">
                    <h3 class="text-sm font-bold text-ae-navy">ความเคลื่อนไหวล่าสุด</h3>
                    <button type="button" @click="tab = 'activity'" class="text-sm font-medium text-[#0F4C81] hover:underline">ดูทั้งหมด</button>
                </div>
                @if ($overview['feed'])
                    <ul class="divide-y divide-slate-100">
                        @foreach ($overview['feed'] as $a)
                            <li class="grid grid-cols-[5.5rem_minmax(0,1fr)] gap-3 py-2 text-sm">
                                <span class="text-slate-500 tabular-nums">{{ $a['at'] }}</span>
                                <span class="min-w-0 break-words text-slate-700"><strong class="text-slate-800">{{ $a['who'] }}</strong> {{ $a['note'] ? 'ความคิดเห็น:' : '' }} {{ \Illuminate\Support\Str::limit($a['text'], 180) }}</span>
                            </li>
                        @endforeach
                    </ul>
                @else
                    <p class="py-2 text-sm text-slate-400">ยังไม่มีความเคลื่อนไหว</p>
                @endif
                <form method="POST" action="{{ route('projects.notes.store', $project) }}" class="mt-3 flex gap-2">
                    @csrf
                    <label for="overview-note" class="sr-only">เขียนความคิดเห็น</label>
                    <input id="overview-note" name="note" type="text" required maxlength="2000" placeholder="เขียนความคิดเห็นถึงทีม…"
                           class="min-w-0 flex-1 rounded-lg border-slate-300 text-sm">
                    <button class="rounded-lg border border-slate-200 px-4 text-sm font-semibold text-[#0F4C81] hover:bg-slate-50">ส่ง</button>
                </form>
            </section>
        @endif
    </div>
</div>
