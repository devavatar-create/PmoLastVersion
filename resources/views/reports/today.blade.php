{{-- "สรุปงาน": App\Services\DailyWork ($data), one Alpine component. Made to be read at a glance, also from a screen on
     the wall (เต็มจอ): per department, how much came in and how much got done on the day (or over ?from=&to=), with
     what it still holds. While what it shows ends today it asks reports/today/data every $refreshSeconds seconds (and
     when the tab comes back), so the numbers stay live; showing just today, past midnight it moves on to the new day.
     Nothing that matters under 14px; status is a word as well as a colour. Blue = งานเข้า, emerald = เสร็จ, everywhere here. --}}
<x-app-layout>
    <x-slot name="header">
        <h2 class="text-lg font-semibold text-slate-900">สรุปงาน</h2>
    </x-slot>

    <style>
        .work-summary:fullscreen { background: #f8fafc; padding: 24px; overflow-y: auto; max-width: none; }
        .work-summary:fullscreen .hide-in-fullscreen { display: none !important; }
        /* this page's range calendar: weekday names and month arrows readable on the dark header */
        .work-summary-calendar span.flatpickr-weekday { color: #cbd5e1; }
        .work-summary-calendar .flatpickr-months .flatpickr-prev-month svg, .work-summary-calendar .flatpickr-months .flatpickr-next-month svg { fill: #fff; }
        .work-summary-calendar .flatpickr-months .flatpickr-prev-month:hover svg, .work-summary-calendar .flatpickr-months .flatpickr-next-month:hover svg { fill: #93c5fd; }
    </style>

    @if ($noDepartment)
        <div class="mx-auto max-w-2xl rounded-xl border border-amber-200 bg-amber-50 p-6 text-base text-amber-900">
            บัญชีนี้ยังไม่ได้อยู่ในแผนกใด จึงยังไม่มีงานให้แสดง — ติดต่อผู้ดูแลระบบเพื่อกำหนดแผนก
        </div>
    @else
    <div x-data="workSummary(@js(['summary' => $data, 'dataUrl' => route('reports.today.data'), 'pageUrl' => route('reports.today'), 'acknowledgeUrl' => route('reports.today.acknowledge'), 'refreshSeconds' => $refreshSeconds]))"
         class="work-summary mx-auto flex w-full max-w-[1600px] flex-col gap-4 text-sm text-slate-800">

        {{-- the days shown (one, or a range), live state, and how to look at it --}}
        <div class="flex flex-wrap items-center gap-x-4 gap-y-3">
            <div class="flex items-center gap-1.5">
                <button type="button" @click="showRange(summary.range.previous.from, summary.range.previous.to)" class="flex h-11 w-11 shrink-0 items-center justify-center rounded-lg border border-slate-300 bg-white text-slate-700 hover:bg-slate-50" :aria-label="summary.range.dayCount > 1 ? 'ช่วงก่อนหน้า' : 'วันก่อนหน้า'">
                    <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/></svg>
                </button>
                {{-- the label is the picker: pick one day, or a first and a last day --}}
                <button type="button" x-ref="datePickerButton" @click="openDatePicker()" title="เลือกวัน หรือช่วงวันที่"
                        class="flex h-11 min-w-0 items-center gap-2 rounded-lg border border-slate-300 bg-white px-3 text-left hover:bg-slate-50">
                    <svg class="h-5 w-5 shrink-0 text-slate-500" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                    <span class="truncate text-lg font-semibold text-slate-900" x-text="summary.range.label">{{ $data['range']['label'] }}</span>
                    <span x-show="summary.range.dayCount > 1" x-cloak class="shrink-0 rounded-full bg-slate-100 px-2 py-0.5 text-sm font-medium text-slate-600" x-text="summary.range.dayCount + ' วัน'"></span>
                    <svg class="h-4 w-4 shrink-0 text-slate-400" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/></svg>
                </button>
                <input type="text" x-ref="datePickerInput" class="sr-only" tabindex="-1" aria-hidden="true">
                <button type="button" @click="summary.range.next && showRange(summary.range.next.from, summary.range.next.to)" :disabled="!summary.range.next" class="flex h-11 w-11 shrink-0 items-center justify-center rounded-lg border border-slate-300 bg-white text-slate-700 hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-40" :aria-label="summary.range.dayCount > 1 ? 'ช่วงถัดไป' : 'วันถัดไป'">
                    <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>
                </button>
            </div>

            {{-- one tap to the usual looks --}}
            <div class="flex flex-wrap items-center gap-1 rounded-lg border border-slate-300 bg-white p-1" role="group" aria-label="ช่วงที่ใช้บ่อย">
                <template x-for="option in quickRanges()" :key="option.key">
                    <button type="button" @click="showRange(option.from, option.to)" class="h-9 rounded-md px-3 font-medium"
                            :class="isShowing(option) ? 'bg-blue-600 text-white' : 'text-slate-600 hover:bg-slate-100'"
                            :aria-pressed="isShowing(option)" x-text="option.label"></button>
                </template>
            </div>

            <div class="ml-auto flex flex-wrap items-center gap-2">
                {{-- live state: a word as well as a colour --}}
                <span class="inline-flex h-9 items-center gap-2 rounded-full px-3 font-medium" :class="liveBadgeClass()">
                    <span class="relative flex h-2.5 w-2.5" x-show="connection === 'ok' && summary.range.isLive">
                        <span class="absolute inline-flex h-full w-full rounded-full bg-emerald-500 opacity-75 motion-safe:animate-ping"></span>
                        <span class="relative inline-flex h-2.5 w-2.5 rounded-full bg-emerald-600"></span>
                    </span>
                    <span x-text="liveBadgeText()"></span>
                </span>
                <div class="hide-in-fullscreen inline-flex rounded-lg border border-slate-300 bg-white p-0.5" role="group" aria-label="รูปแบบการแสดง">
                    <button type="button" @click="setViewMode('cards')" class="h-9 rounded-md px-3 font-medium" :class="viewMode === 'cards' ? 'bg-slate-900 text-white' : 'text-slate-600 hover:bg-slate-100'" :aria-pressed="viewMode === 'cards'">การ์ด</button>
                    <button type="button" @click="setViewMode('table')" class="h-9 rounded-md px-3 font-medium" :class="viewMode === 'table' ? 'bg-slate-900 text-white' : 'text-slate-600 hover:bg-slate-100'" :aria-pressed="viewMode === 'table'">ตาราง</button>
                </div>
                <button type="button" @click="toggleFullscreen()" class="inline-flex h-10 items-center gap-2 rounded-lg border border-slate-300 bg-white px-3 font-medium text-slate-700 hover:bg-slate-50">
                    <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M4 8V4h4M20 8V4h-4M4 16v4h4M20 16v4h-4"/></svg>
                    <span x-text="isFullscreen ? 'ออกจากเต็มจอ' : 'เต็มจอ'">เต็มจอ</span>
                </button>
            </div>
        </div>

        {{-- the four numbers of the period --}}
        <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
            <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                <p class="flex items-center gap-2 text-base font-medium text-slate-600"><span class="h-3 w-3 rounded-sm bg-blue-600"></span><span x-text="'งานเข้า' + periodWord()"></span></p>
                <p class="mt-1 text-5xl font-semibold text-slate-900 lg:text-6xl" x-text="summary.totals.received">{{ $data['totals']['received'] }}</p>
                <p class="mt-1 text-sm text-slate-500" x-text="summary.totals.received ? summary.totals.receivedCabinets + ' ตู้' : 'ยังไม่มีงานเข้า'"></p>
            </div>
            <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                <p class="flex items-center gap-2 text-base font-medium text-slate-600"><span class="h-3 w-3 rounded-sm bg-emerald-600"></span><span x-text="'เสร็จ' + periodWord()"></span></p>
                <p class="mt-1 text-5xl font-semibold text-slate-900 lg:text-6xl" x-text="summary.totals.completed">{{ $data['totals']['completed'] }}</p>
                <p class="mt-1 text-sm text-slate-500" x-text="summary.totals.completed ? summary.totals.completedCabinets + ' ตู้ · ' + summary.totals.activeDepartmentCount + ' แผนกมีงานเคลื่อนไหว' : 'ยังไม่มีงานเสร็จ'"></p>
            </div>
            <button type="button" @click="toggleFeedFilter(NEW_CABINET)" :aria-pressed="feedFilter === NEW_CABINET" title="กดเพื่อดูรายการตู้ที่เข้ามา"
                    class="flex flex-col justify-start rounded-xl border bg-white p-4 text-left shadow-sm transition focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-600"
                    :class="feedFilter === NEW_CABINET ? 'border-blue-600 ring-2 ring-blue-600' : 'border-slate-200 hover:border-slate-300'">
                <p class="flex items-center gap-2 text-base font-medium text-slate-600">
                    <svg class="h-4 w-4 text-violet-600" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/></svg>
                    <span x-text="'ตู้/อุปกรณ์เข้าใหม่' + periodWord()"></span>
                </p>
                <p class="mt-1 text-5xl font-semibold text-slate-900 lg:text-6xl" x-text="summary.totals.newCabinetQuantity">{{ $data['totals']['newCabinetQuantity'] }}</p>
                <p class="mt-1 text-sm text-slate-500" x-text="summary.totals.newCabinetQuantity ? 'ตู้ · ' + summary.totals.newCabinetCount + ' รายการ จาก ' + summary.totals.newCabinetProjectCount + ' โครงการ' : 'ยังไม่มีของเข้า'"></p>
            </button>
            <div class="rounded-xl border bg-white p-4 shadow-sm" :class="summary.totals.overdue ? 'border-red-200' : 'border-slate-200'">
                <p class="text-base font-medium text-slate-600">ค้างอยู่ตอนนี้</p>
                <p class="mt-1 text-5xl font-semibold text-slate-900 lg:text-6xl" x-text="summary.totals.pending">{{ $data['totals']['pending'] }}</p>
                <p class="mt-1 text-sm text-slate-500"><span x-text="'รอรับ ' + summary.totals.waiting + ' · กำลังทำ ' + summary.totals.inProgress"></span></p>
                <p class="mt-0.5 text-sm font-semibold text-red-700" x-show="summary.totals.overdue" x-cloak>⚠ เลยกำหนด <span x-text="summary.totals.overdue"></span> งาน</p>
            </div>
        </div>

        <div class="grid gap-4 xl:grid-cols-[minmax(0,1fr)_380px]">
            {{-- per department --}}
            <section class="min-w-0">
                <div class="mb-2 flex flex-wrap items-baseline justify-between gap-2">
                    <h3 class="text-base font-semibold text-slate-900">แยกตามแผนก</h3>
                    <p class="flex flex-wrap items-center gap-x-4 gap-y-1 text-sm text-slate-600" x-show="viewMode === 'cards'">
                        <span class="inline-flex items-center gap-1.5"><span class="h-3 w-3 rounded-sm bg-blue-600"></span>งานเข้า</span>
                        <span class="inline-flex items-center gap-1.5"><span class="h-3 w-3 rounded-sm bg-emerald-600"></span>เสร็จ</span>
                        <span class="text-slate-500"><span x-text="'กราฟเล็ก = ' + summary.range.chartLabel">กราฟเล็ก = {{ $data['range']['chartLabel'] }}</span> · กดการ์ดเพื่อดูความเคลื่อนไหวเฉพาะแผนก</span>
                    </p>
                </div>

                <div x-show="viewMode === 'cards'" class="grid gap-3 sm:grid-cols-2 2xl:grid-cols-3">
                    {{-- every active department gets a card; one with nothing in the period and nothing held is faded (they come last) --}}
                    <template x-for="department in summary.departments" :key="department.id">
                        <article @click="toggleFeedFilter(department.id)" @keydown.enter="toggleFeedFilter(department.id)" tabindex="0" role="button" :aria-pressed="feedFilter === department.id"
                                 class="cursor-pointer rounded-xl border bg-white p-4 shadow-sm transition duration-300 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-600"
                                 :class="[feedFilter === department.id ? 'border-blue-600 ring-2 ring-blue-600' : (department.unacknowledged ? 'border-blue-200 hover:border-blue-300' : 'border-slate-200 hover:border-slate-300'), highlightedDepartments[department.id] ? 'bg-blue-50' : '', department.isQuiet && feedFilter !== department.id ? 'opacity-60' : '']">
                            <header class="flex items-start gap-2">
                                <h4 class="min-w-0 flex-1 text-base font-semibold leading-snug text-slate-900"><span x-show="department.icon" x-text="department.icon + ' '"></span><span x-text="department.name"></span></h4>
                                <div class="flex shrink-0 items-center gap-2">
                                    <span x-show="department.overdue" class="rounded-full bg-red-50 px-2.5 py-0.5 text-sm font-semibold text-red-700">⚠ เลยกำหนด <span x-text="department.overdue"></span></span>
                                    {{-- งานเข้าใหม่: Sub Tasks that came in and nobody has opened from this page yet (is_active = 0). Blue like
                                         every "งานเข้า" here, so it never reads as the red "เลยกำหนด" beside it; the count grows to the
                                         right, off the bell; opens the list --}}
                                    <button type="button" x-show="department.unacknowledged" @click.stop="openNewWork(department.id)" @keydown.enter.stop
                                            :title="'งานเข้าใหม่ ' + department.unacknowledged + ' งาน — กดเพื่อดูรายการ'" :aria-label="'งานเข้าใหม่ ' + department.unacknowledged + ' งาน'"
                                            class="relative mr-1 -mt-1 flex h-10 w-10 items-center justify-center rounded-full bg-blue-50 text-blue-700 ring-1 ring-blue-200 transition hover:bg-blue-100 hover:text-blue-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-600">
                                        <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M15 17h5l-1.4-1.4A2 2 0 0118 14.2V11a6 6 0 00-4-5.7V5a2 2 0 10-4 0v.3C7.7 6.2 6 8.4 6 11v3.2c0 .5-.2 1-.6 1.4L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/></svg>
                                        <span class="absolute -top-1.5 left-[22px] flex h-6 min-w-[1.5rem] items-center justify-center rounded-full bg-blue-600 px-1.5 text-sm font-bold tabular-nums text-white ring-2 ring-white" x-text="department.unacknowledged > 99 ? '99+' : department.unacknowledged"></span>
                                    </button>
                                </div>
                            </header>

                            <div class="mt-2 grid grid-cols-2 gap-3">
                                <div>
                                    <p class="flex items-center gap-1.5 text-sm text-slate-600"><span class="h-2.5 w-2.5 rounded-sm bg-blue-600"></span>งานเข้า</p>
                                    <p class="text-5xl font-semibold leading-tight text-slate-900" x-text="department.received"></p>
                                    <p class="text-sm text-slate-500" x-text="department.received ? department.receivedCabinets + ' ตู้' : '—'"></p>
                                </div>
                                <div>
                                    <p class="flex items-center gap-1.5 text-sm text-slate-600"><span class="h-2.5 w-2.5 rounded-sm bg-emerald-600"></span>เสร็จ</p>
                                    <p class="text-5xl font-semibold leading-tight text-slate-900" x-text="department.completed"></p>
                                    <p class="text-sm text-slate-500" x-text="department.completed ? department.completedCabinets + ' ตู้' : '—'"></p>
                                </div>
                            </div>

                            {{-- งานเข้า | เสร็จ per bar, one scale for every department. One day: its week, that day in full colour;
                                 a range: the range by day / week / month (DailyWork::chartBars) --}}
                            <div class="mt-3" role="img" :aria-label="chartDescription(department)">
                                <div class="flex h-10 items-end border-b border-slate-200" :class="department.chart.length > 10 ? 'gap-[2px]' : 'gap-1.5'">
                                    <template x-for="bar in department.chart" :key="bar.date">
                                        <div class="group relative flex h-full flex-1 items-end justify-center gap-[2px]">
                                            <div class="w-1/2 max-w-[10px] rounded-t-[4px] bg-blue-600" :class="bar.isHighlighted ? '' : 'opacity-40'" :style="barHeight(bar.received)"></div>
                                            <div class="w-1/2 max-w-[10px] rounded-t-[4px] bg-emerald-600" :class="bar.isHighlighted ? '' : 'opacity-40'" :style="barHeight(bar.completed)"></div>
                                            <div class="pointer-events-none absolute bottom-full left-1/2 z-20 mb-1 hidden -translate-x-1/2 whitespace-nowrap rounded-md bg-slate-900 px-2.5 py-1.5 text-sm text-white shadow-lg group-hover:block">
                                                <span x-text="bar.label"></span> · เข้า <b x-text="bar.received"></b> · เสร็จ <b x-text="bar.completed"></b>
                                            </div>
                                        </div>
                                    </template>
                                </div>
                                <div x-show="department.chart.length <= 8" class="mt-1 flex gap-1.5">
                                    <template x-for="bar in department.chart" :key="bar.date">
                                        <span class="flex-1 text-center text-xs" :class="bar.isHighlighted && summary.range.dayCount === 1 ? 'font-semibold text-slate-900' : 'text-slate-500'" x-text="bar.shortLabel"></span>
                                    </template>
                                </div>
                                <div x-show="department.chart.length > 8" x-cloak class="mt-1 flex justify-between text-xs text-slate-500">
                                    <span x-text="department.chart[0]?.label"></span><span x-text="department.chart[department.chart.length - 1]?.label"></span>
                                </div>
                            </div>

                            <footer class="mt-3 flex flex-wrap items-center justify-between gap-x-3 gap-y-1 border-t border-slate-100 pt-2 text-sm text-slate-600">
                                <span x-show="department.pending"><span class="font-semibold text-slate-900" x-text="'ค้าง ' + department.pending"></span><span x-text="' · รอรับ ' + department.waiting + ' · กำลังทำ ' + department.inProgress"></span></span>
                                <span x-show="!department.pending" class="text-slate-500">ไม่มีงานค้าง</span>
                            </footer>
                        </article>
                    </template>
                </div>


                <div x-show="viewMode === 'table'" x-cloak class="overflow-x-auto rounded-xl border border-slate-200 bg-white shadow-sm">
                    <table class="w-full min-w-[720px] text-left text-sm tabular-nums">
                        <thead class="bg-slate-50 text-slate-600">
                            <tr>
                                <th class="px-4 py-3 font-semibold">แผนก</th>
                                <th class="px-3 py-3 text-center font-semibold">งานเข้าใหม่</th>
                                <th class="px-3 py-3 text-right font-semibold">งานเข้า</th>
                                <th class="px-3 py-3 text-right font-semibold">ตู้</th>
                                <th class="px-3 py-3 text-right font-semibold">เสร็จ</th>
                                <th class="px-3 py-3 text-right font-semibold">ตู้</th>
                                <th class="px-3 py-3 text-right font-semibold">รอรับ</th>
                                <th class="px-3 py-3 text-right font-semibold">กำลังทำ</th>
                                <th class="px-4 py-3 text-right font-semibold">เลยกำหนด</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <template x-for="department in summary.departments" :key="department.id">
                                <tr :class="[department.isQuiet ? 'text-slate-400' : '', highlightedDepartments[department.id] ? 'bg-blue-50' : '']">
                                    <td class="px-4 py-2.5 font-medium" :class="department.isQuiet ? '' : 'text-slate-900'"><span x-show="department.icon" x-text="department.icon + ' '"></span><span x-text="department.name"></span></td>
                                    <td class="px-3 py-2.5 text-center">
                                        <button type="button" x-show="department.unacknowledged" @click="openNewWork(department.id)" title="กดเพื่อดูรายการ"
                                                class="min-w-[2.5rem] rounded-full bg-blue-600 px-2.5 py-0.5 text-base font-bold text-white transition hover:bg-blue-700" x-text="department.unacknowledged"></button>
                                        <span x-show="!department.unacknowledged">0</span>
                                    </td>
                                    <td class="px-3 py-2.5 text-right text-base font-semibold" x-text="department.received"></td>
                                    <td class="px-3 py-2.5 text-right" x-text="department.receivedCabinets"></td>
                                    <td class="px-3 py-2.5 text-right text-base font-semibold" x-text="department.completed"></td>
                                    <td class="px-3 py-2.5 text-right" x-text="department.completedCabinets"></td>
                                    <td class="px-3 py-2.5 text-right" x-text="department.waiting"></td>
                                    <td class="px-3 py-2.5 text-right" x-text="department.inProgress"></td>
                                    <td class="px-4 py-2.5 text-right" :class="department.overdue ? 'font-semibold text-red-700' : ''" x-text="department.overdue ? '⚠ ' + department.overdue : '0'"></td>
                                </tr>
                            </template>
                        </tbody>
                        <tfoot class="border-t-2 border-slate-200 bg-slate-50 font-semibold text-slate-900">
                            <tr>
                                <td class="px-4 py-3">รวม</td>
                                <td class="px-3 py-3 text-center" :class="summary.totals.unacknowledged ? 'text-blue-700' : ''" x-text="summary.totals.unacknowledged"></td>
                                <td class="px-3 py-3 text-right text-base" x-text="summary.totals.received"></td>
                                <td class="px-3 py-3 text-right" x-text="summary.totals.receivedCabinets"></td>
                                <td class="px-3 py-3 text-right text-base" x-text="summary.totals.completed"></td>
                                <td class="px-3 py-3 text-right" x-text="summary.totals.completedCabinets"></td>
                                <td class="px-3 py-3 text-right" x-text="summary.totals.waiting"></td>
                                <td class="px-3 py-3 text-right" x-text="summary.totals.inProgress"></td>
                                <td class="px-4 py-3 text-right" :class="summary.totals.overdue ? 'text-red-700' : ''" x-text="summary.totals.overdue"></td>
                            </tr>
                        </tfoot>
                    </table>
                    <p class="border-t border-slate-100 px-4 py-2 text-sm text-slate-500">งานเข้าใหม่ = งานที่ส่งเข้าแผนกแล้วยังไม่มีใครเปิดดูจากหน้านี้ · ตู้ = จำนวนตู้ของงานนั้น (นับตู้ละครั้ง) · รอรับ / กำลังทำ / เลยกำหนด = งานที่ค้างอยู่ตอนนี้</p>
                </div>

                <p x-show="!summary.departments.length" x-cloak class="rounded-xl border border-slate-200 bg-white p-6 text-center text-base text-slate-500">ยังไม่มีแผนกที่เปิดใช้งาน</p>
            </section>

            {{-- what happened, newest first --}}
            <section class="flex min-w-0 flex-col rounded-xl border border-slate-200 bg-white shadow-sm xl:max-h-[calc(100vh-9rem)]">
                <header class="flex flex-wrap items-center justify-between gap-2 border-b border-slate-100 px-4 py-3">
                    <h3 class="text-base font-semibold text-slate-900" x-text="'ความเคลื่อนไหว' + periodWord()"></h3>
                    <button type="button" x-show="feedFilter" x-cloak @click="feedFilter = null" class="inline-flex items-center gap-1 rounded-full bg-blue-50 px-3 py-1 text-sm font-medium text-blue-700 hover:bg-blue-100">
                        <span x-text="'เฉพาะ ' + feedFilterName()"></span><span aria-hidden="true">✕</span><span class="sr-only">แสดงทั้งหมด</span>
                    </button>
                </header>
                <ol class="flex-1 divide-y divide-slate-100 overflow-y-auto">
                    {{-- one heading per run of the same project, its events under it --}}
                    <template x-for="projectGroup in feedByProject()" :key="projectGroup.key">
                        <li class="py-2">
                            <div class="z-10 flex xl:sticky xl:top-0 items-baseline gap-2 bg-white/95 px-4 pb-1 pt-1 backdrop-blur">
                                <h4 class="line-clamp-2 min-w-0 flex-1 text-base font-semibold leading-snug text-slate-900" :title="projectGroup.projectName" x-text="projectGroup.projectName || projectGroup.projectNo || 'ไม่ระบุโครงการ'"></h4>
                                <span x-show="projectGroup.projectName && projectGroup.projectNo" class="shrink-0 rounded bg-slate-100 px-1.5 py-0.5 text-xs font-medium tabular-nums text-slate-600" x-text="projectGroup.projectNo"></span>
                            </div>
                            <ul>
                                <template x-for="event in projectGroup.events" :key="event.key">
                                    <li class="transition-colors duration-700" :class="highlightedEvents[event.key] ? 'bg-blue-50' : ''">
                                        <a :href="event.url" @click="acknowledge(event)" @auxclick="acknowledge(event)" class="flex gap-3 px-4 py-1.5 hover:bg-slate-50 focus-visible:bg-slate-50 focus-visible:outline-none">
                                            <span class="shrink-0 pt-0.5 text-sm tabular-nums text-slate-500" :class="event.date ? 'w-14' : 'w-11'" :title="event.time ? '' : 'ไม่ทราบเวลา'">
                                                <span x-show="event.date" class="block text-xs font-medium text-slate-600" x-text="event.date"></span>
                                                <span x-text="event.time || '—'"></span>
                                            </span>
                                            <div class="min-w-0 flex-1 text-sm">
                                                <p class="flex flex-wrap items-center gap-x-2">
                                                    <span class="rounded px-1.5 py-0.5 font-semibold" :class="eventTagClass(event.type)" x-text="event.typeLabel"></span>
                                                    <span x-show="event.isNew && event.type === 'received'" class="rounded-full bg-blue-600 px-2 py-0.5 text-xs font-semibold text-white" title="ยังไม่มีใครเปิดดูงานนี้">ใหม่</span>
                                                    <span x-show="event.departmentId" class="font-medium text-slate-700" x-text="departmentName(event.departmentId)"></span>
                                                </p>
                                                <p class="mt-0.5 truncate text-slate-500" :title="eventDetail(event)" x-text="eventDetail(event)"></p>
                                            </div>
                                        </a>
                                    </li>
                                </template>
                            </ul>
                        </li>
                    </template>
                    <li x-show="!feedFilter && summary.workEventCount > listedWorkEventCount()" x-cloak class="px-4 py-3 text-center text-sm text-slate-500"
                        x-text="'แสดงงาน ' + listedWorkEventCount() + ' รายการล่าสุด จากทั้งหมด ' + summary.workEventCount + ' รายการ' + (summary.range.dayCount > 1 ? ' — เลือกช่วงสั้นลงเพื่อดูละเอียดขึ้น' : '')"></li>
                    <li x-show="!filteredFeed().length" class="px-4 py-10 text-center text-base text-slate-500" x-text="emptyFeedText()"></li>
                </ol>
            </section>
        </div>

        {{-- "งานเข้าใหม่": slides in from the right, from a department's bell --}}
        <div class="fixed inset-0 z-50" :class="newWorkOpen ? '' : 'pointer-events-none'" @keydown.escape.window="newWorkOpen = false">
            <div x-show="newWorkOpen" x-cloak x-transition.opacity.duration.200ms class="absolute inset-0 bg-slate-900/40 backdrop-blur-[1px]" @click="newWorkOpen = false"></div>
            <aside x-show="newWorkOpen" x-cloak role="dialog" aria-modal="true" aria-labelledby="new-work-title"
                   x-transition:enter="transform transition ease-out duration-200" x-transition:enter-start="translate-x-full" x-transition:enter-end="translate-x-0"
                   x-transition:leave="transform transition ease-in duration-150" x-transition:leave-start="translate-x-0" x-transition:leave-end="translate-x-full"
                   class="absolute inset-y-0 right-0 flex w-full max-w-md flex-col bg-slate-50 shadow-2xl">
                <header class="bg-gradient-to-br from-blue-600 to-blue-700 px-5 pb-4 pt-5 text-white">
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <p class="text-sm text-blue-100">ยังไม่มีใครเปิดดู · กดที่งานเพื่อรับทราบ</p>
                            <h3 id="new-work-title" class="mt-0.5 text-2xl font-semibold">งานเข้าใหม่ <span class="tabular-nums" x-text="summary.totals.unacknowledged"></span> งาน</h3>
                        </div>
                        <button type="button" x-ref="newWorkClose" @click="newWorkOpen = false" class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full text-blue-100 transition hover:bg-white/15 hover:text-white focus:outline-none focus-visible:ring-2 focus-visible:ring-white" aria-label="ปิด">
                            <svg class="h-6 w-6" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                        </button>
                    </div>
                    {{-- every department, or one --}}
                    <div class="-mx-1 mt-4 flex gap-1.5 overflow-x-auto px-1 pb-1" role="group" aria-label="แผนก">
                        <button type="button" @click="newWorkDepartment = null" :aria-pressed="newWorkDepartment === null"
                                class="flex shrink-0 items-center gap-1.5 rounded-full px-3 py-1.5 text-sm font-semibold transition"
                                :class="newWorkDepartment === null ? 'bg-white text-blue-700 shadow' : 'bg-white/15 text-white hover:bg-white/25'">
                            ทั้งหมด <span class="tabular-nums" x-text="summary.totals.unacknowledged"></span>
                        </button>
                        <template x-for="department in newWorkDepartments()" :key="department.id">
                            <button type="button" @click="newWorkDepartment = department.id" :aria-pressed="newWorkDepartment === department.id"
                                    class="flex shrink-0 items-center gap-1.5 rounded-full px-3 py-1.5 text-sm font-semibold transition"
                                    :class="newWorkDepartment === department.id ? 'bg-white text-blue-700 shadow' : 'bg-white/15 text-white hover:bg-white/25'">
                                <span class="max-w-[10rem] truncate" x-text="department.name"></span><span class="tabular-nums" x-text="department.unacknowledged"></span>
                            </button>
                        </template>
                    </div>
                </header>

                <div class="flex-1 overflow-y-auto">
                    <template x-for="group in newWorkGroups()" :key="group.key">
                        <section>
                            <h4 class="sticky top-0 z-10 flex items-center justify-between gap-2 border-b border-slate-200 bg-slate-50/95 px-5 py-2.5 backdrop-blur">
                                <span class="truncate text-sm font-semibold text-slate-800" x-text="group.title"></span>
                                <span class="shrink-0 rounded-full bg-blue-600 px-2.5 py-0.5 text-xs font-bold tabular-nums text-white" x-text="group.total + ' งาน'"></span>
                            </h4>
                            <ul class="space-y-2 p-3">
                                <template x-for="event in group.events" :key="event.key">
                                    <li>
                                        <a :href="event.url" @click="acknowledge(event)" @auxclick="acknowledge(event)"
                                           class="group flex gap-3 rounded-xl border border-slate-200 bg-white p-3 shadow-sm transition hover:-translate-y-px hover:border-blue-300 hover:shadow-md focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-600">
                                            <span class="mt-1.5 h-2.5 w-2.5 shrink-0 rounded-full transition" :class="event.isNew ? 'bg-blue-600' : 'bg-slate-300'"></span>
                                            <div class="min-w-0 flex-1">
                                                <p class="truncate text-base font-semibold text-slate-900" :title="event.title" x-text="event.title"></p>
                                                <p class="mt-0.5 truncate text-sm text-slate-600" :title="event.projectName" x-text="[event.projectNo, event.projectName].filter(Boolean).join(' · ') || 'ไม่ระบุโครงการ'"></p>
                                                <p class="mt-1.5 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-slate-500">
                                                    <span x-show="event.detail" class="rounded bg-slate-100 px-1.5 py-0.5 font-medium text-slate-600" x-text="event.detail"></span>
                                                    <span class="inline-flex items-center gap-1">
                                                        <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                                        <span x-text="'เข้า ' + [event.date, event.time].filter(Boolean).join(' ')"></span>
                                                    </span>
                                                </p>
                                            </div>
                                            <svg class="mt-1 h-5 w-5 shrink-0 text-slate-300 transition group-hover:translate-x-0.5 group-hover:text-blue-600" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>
                                        </a>
                                    </li>
                                </template>
                            </ul>
                            <p x-show="group.total > group.events.length" class="px-5 pb-4 text-center text-xs text-slate-500" x-text="'แสดง ' + group.events.length + ' รายการล่าสุด จาก ' + group.total + ' งาน'"></p>
                        </section>
                    </template>
                    <div x-show="!newWorkGroups().length" class="flex flex-col items-center px-6 py-16 text-center">
                        <span class="flex h-14 w-14 items-center justify-center rounded-full bg-emerald-50 text-emerald-600">
                            <svg class="h-7 w-7" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                        </span>
                        <p class="mt-3 text-base font-semibold text-slate-800">เปิดดูงานเข้าครบแล้ว</p>
                        <p class="mt-1 text-sm text-slate-500">งานที่ส่งเข้าแผนกใหม่จะขึ้นที่นี่</p>
                    </div>
                </div>
            </aside>
        </div>
    </div>
    @endif

    @push('scripts')
    <script>
        window.workSummary = (config) => ({
            NEW_CABINET: 'new_cabinet',   // DailyWork::EVENT_NEW_CABINET: the feed type, and the filter of the "ตู้/อุปกรณ์เข้าใหม่" tile
            config,
            summary: config.summary,
            viewMode: 'cards',            // cards | table
            feedFilter: null,             // null (everything) | a department id | NEW_CABINET
            newWorkOpen: false,           // the "งานเข้าใหม่" panel
            newWorkDepartment: null,      // in it: null (every department) | one department id
            connection: 'ok',             // ok | error | expired
            datePicker: null,
            highlightedDepartments: {},
            highlightedEvents: {},
            isFullscreen: false,
            seenEventKeys: new Set(),

            init() {
                try { if (localStorage.getItem('pmo_today_view') === 'table') this.viewMode = 'table'; } catch (error) { /* blocked storage: cards */ }
                this.seenEventKeys = new Set(this.summary.feed.map((event) => event.key));
                setInterval(() => this.refreshIfLive(), config.refreshSeconds * 1000);
                document.addEventListener('visibilitychange', () => { if (!document.hidden) this.refreshIfLive(); });
                document.addEventListener('fullscreenchange', () => { this.isFullscreen = document.fullscreenElement === this.$root; });
            },
            // a range ending today stays live; just today also moves on to the new day after midnight
            refreshIfLive() {
                if (!this.summary.range.isLive || document.hidden) return;
                const query = this.summary.range.isToday ? '' : this.rangeQuery(this.summary.range.from, this.summary.range.to);
                this.fetchSummary(query, true);
            },
            rangeQuery(from, to) {
                return from === to && from === this.summary.today ? '' : '?from=' + from + '&to=' + to;
            },
            async fetchSummary(query, isRefresh = false) {
                try {
                    const response = await fetch(config.dataUrl + query, {
                        headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, cache: 'no-store',
                    });
                    if (response.status === 401 || response.status === 419) { this.connection = 'expired'; return; }
                    if (!response.ok) { this.connection = 'error'; return; }
                    const latest = await response.json();
                    const sameRange = latest.range.from === this.summary.range.from && latest.range.to === this.summary.range.to;
                    if (isRefresh && sameRange) this.highlightChanges(latest);
                    else this.seenEventKeys = new Set(latest.feed.map((event) => event.key));
                    this.summary = latest;
                    this.connection = 'ok';
                } catch (error) {
                    this.connection = 'error';   // offline for a moment: keep the numbers we have, try again next time
                }
            },
            /** briefly light up what moved since the last look */
            highlightChanges(latest) {
                const movedBefore = Object.fromEntries(this.summary.departments.map((department) => [department.id, department.received + department.completed]));
                latest.departments.forEach((department) => {
                    const before = movedBefore[department.id];
                    if (before !== undefined && department.received + department.completed > before) this.highlightBriefly(this.highlightedDepartments, department.id, 2500);
                });
                latest.feed.forEach((event) => {
                    if (this.seenEventKeys.has(event.key)) return;
                    this.seenEventKeys.add(event.key);
                    this.highlightBriefly(this.highlightedEvents, event.key, 6000);
                });
            },
            highlightBriefly(highlights, key, milliseconds) {
                highlights[key] = true;
                setTimeout(() => { delete highlights[key]; }, milliseconds);
            },
            showRange(from, to) {
                if (!from) return;
                to = to || from;
                if (from > to) [from, to] = [to, from];
                const query = this.rangeQuery(from, to);
                history.replaceState(null, '', config.pageUrl + query);
                this.fetchSummary(query);
            },
            isShowing(range) {
                return range.from === this.summary.range.from && range.to === this.summary.range.to;
            },
            toIsoDate(date) {
                return date.getFullYear() + '-' + String(date.getMonth() + 1).padStart(2, '0') + '-' + String(date.getDate()).padStart(2, '0');
            },
            quickRanges() {
                const today = this.summary.today;
                const [year, month, day] = today.split('-').map(Number);
                const daysAgo = (count) => this.toIsoDate(new Date(year, month - 1, day - count));
                return [
                    { key: 'today', label: 'วันนี้', from: today, to: today },
                    { key: 'yesterday', label: 'เมื่อวาน', from: daysAgo(1), to: daysAgo(1) },
                    { key: 'last7', label: '7 วัน', from: daysAgo(6), to: today },
                    { key: 'last30', label: '30 วัน', from: daysAgo(29), to: today },
                    { key: 'thisMonth', label: 'เดือนนี้', from: this.toIsoDate(new Date(year, month - 1, 1)), to: today },
                ];
            },
            /** one calendar for one day or a range (first click = from, second = to); Thai names, ค.ศ. values */
            openDatePicker() {
                if (!window.flatpickr) return;
                if (!this.datePicker) {
                    this.datePicker = window.flatpickr(this.$refs.datePickerInput, {
                        mode: 'range', dateFormat: 'Y-m-d', positionElement: this.$refs.datePickerButton,
                        onReady: (selectedDates, dateText, picker) => picker.calendarContainer.classList.add('work-summary-calendar'),
                        locale: {
                            firstDayOfWeek: 1, rangeSeparator: ' ถึง ',
                            weekdays: { shorthand: ['อา', 'จ', 'อ', 'พ', 'พฤ', 'ศ', 'ส'], longhand: ['อาทิตย์', 'จันทร์', 'อังคาร', 'พุธ', 'พฤหัสบดี', 'ศุกร์', 'เสาร์'] },
                            months: {
                                shorthand: ['ม.ค.', 'ก.พ.', 'มี.ค.', 'เม.ย.', 'พ.ค.', 'มิ.ย.', 'ก.ค.', 'ส.ค.', 'ก.ย.', 'ต.ค.', 'พ.ย.', 'ธ.ค.'],
                                longhand: ['มกราคม', 'กุมภาพันธ์', 'มีนาคม', 'เมษายน', 'พฤษภาคม', 'มิถุนายน', 'กรกฎาคม', 'สิงหาคม', 'กันยายน', 'ตุลาคม', 'พฤศจิกายน', 'ธันวาคม'],
                            },
                        },
                        onClose: (selectedDates) => {
                            if (!selectedDates.length) return;
                            const range = { from: this.toIsoDate(selectedDates[0]), to: this.toIsoDate(selectedDates[selectedDates.length - 1]) };
                            if (!this.isShowing(range)) this.showRange(range.from, range.to);
                        },
                    });
                }
                this.datePicker.set('maxDate', this.summary.today);
                this.datePicker.setDate([this.summary.range.from, this.summary.range.to], false);
                this.datePicker.open();
            },
            setViewMode(mode) {
                this.viewMode = mode;
                try { localStorage.setItem('pmo_today_view', mode); } catch (error) { /* not remembered */ }
            },
            /** "งานเข้าใหม่": the panel, with every department's work (null) or one department's */
            openNewWork(departmentId) {
                this.newWorkDepartment = departmentId;
                this.newWorkOpen = true;
                this.$nextTick(() => this.$refs.newWorkClose?.focus());
            },
            /** the departments with work not opened yet, most first */
            newWorkDepartments() {
                return this.summary.departments.filter((department) => department.unacknowledged)
                    .sort((a, b) => b.unacknowledged - a.unacknowledged || a.name.localeCompare(b.name, 'th'));
            },
            newWorkOf(departmentId) {
                return this.summary.newWork.filter((event) => event.departmentId === departmentId);
            },
            /** the panel's list: a heading per department, its work newest first (DailyWork lists at most NEW_WORK_LIMIT each) */
            newWorkGroups() {
                return this.newWorkDepartments()
                    .filter((department) => this.newWorkDepartment === null || department.id === this.newWorkDepartment)
                    .map((department) => ({
                        key: 'department-' + department.id, title: (department.icon ? department.icon + ' ' : '') + department.name, total: department.unacknowledged,
                        events: this.newWorkOf(department.id),
                    }))
                    .filter((group) => group.events.length);
            },
            toggleFeedFilter(filter) {
                this.feedFilter = this.feedFilter === filter ? null : filter;
            },
            toggleFullscreen() {
                if (document.fullscreenElement) document.exitFullscreen();
                else if (this.$root.requestFullscreen) this.$root.requestFullscreen();
            },
            /** the word after a heading: "งานเข้าวันนี้", "งานเข้าวันนั้น", "งานเข้าช่วงนี้" */
            periodWord() {
                if (this.summary.range.isToday) return 'วันนี้';
                return this.summary.range.dayCount === 1 ? 'วันนั้น' : 'ช่วงนี้';
            },
            filteredFeed() {
                if (this.feedFilter === this.NEW_CABINET) return this.summary.feed.filter((event) => event.type === this.NEW_CABINET);
                if (this.feedFilter) return this.summary.feed.filter((event) => event.departmentId === this.feedFilter);
                return this.summary.feed;
            },
            listedWorkEventCount() {
                return this.summary.feed.filter((event) => event.type !== this.NEW_CABINET).length;
            },
            // newest first, so a project only gets a new heading when other work came in between
            feedByProject() {
                const groups = [];
                this.filteredFeed().forEach((event) => {
                    const lastGroup = groups[groups.length - 1];
                    if (lastGroup && lastGroup.projectNo === event.projectNo) lastGroup.events.push(event);
                    else groups.push({ key: 'project-' + event.key, projectNo: event.projectNo, projectName: event.projectName, events: [event] });
                });
                return groups;
            },
            /**
             * Opening work that came in marks it seen (is_active = 1) for everyone. keepalive: the link leaves the page
             * straight away; a new tab stays here, so the badges are taken off now and the counts fetched again.
             */
            acknowledge(event) {
                if (!event.subtaskId || !event.isNew) return;
                const markSeen = (list) => list.forEach((item) => { if (item.subtaskId === event.subtaskId) item.isNew = false; });
                markSeen(this.summary.feed);
                markSeen(this.summary.newWork);
                fetch(config.acknowledgeUrl, {
                    method: 'POST', keepalive: true,
                    headers: { 'Accept': 'application/json', 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '' },
                    body: JSON.stringify({ subtask_id: event.subtaskId }),
                }).then(() => this.fetchSummary(this.rangeQuery(this.summary.range.from, this.summary.range.to), true)).catch(() => { /* seen next time */ });
            },
            eventDetail(event) {
                return [event.title, event.detail].filter(Boolean).join(' · ');
            },
            emptyFeedText() {
                if (this.feedFilter === this.NEW_CABINET) return 'ยังไม่มีตู้เข้าใหม่' + this.periodWord();
                if (this.feedFilter) return 'แผนกนี้ยังไม่มีความเคลื่อนไหว' + this.periodWord();
                return 'ยังไม่มีความเคลื่อนไหว' + this.periodWord();
            },
            departmentName(id) {
                const department = this.summary.departments.find((candidate) => candidate.id === id);
                return department ? department.name : '';
            },
            feedFilterName() {
                return this.feedFilter === this.NEW_CABINET ? 'ตู้/อุปกรณ์เข้าใหม่' : this.departmentName(this.feedFilter);
            },
            barHeight(value) {
                return 'height:' + (value ? Math.max(10, Math.round(value / this.summary.chartMax * 100)) : 0) + '%';
            },
            chartDescription(department) {
                return this.summary.range.chartLabel + ': ' + department.chart.map((bar) => bar.label + ' เข้า ' + bar.received + ' เสร็จ ' + bar.completed).join(', ');
            },
            eventTagClass(type) {
                return {
                    received: 'bg-blue-50 text-blue-700',
                    accepted: 'bg-slate-100 text-slate-700',
                    started: 'bg-amber-50 text-amber-800',
                    completed: 'bg-emerald-50 text-emerald-700',
                    new_cabinet: 'bg-violet-50 text-violet-700',
                }[type] || 'bg-slate-100 text-slate-700';
            },
            liveBadgeClass() {
                if (this.connection === 'expired') return 'bg-red-50 text-red-700';
                if (this.connection === 'error') return 'bg-amber-50 text-amber-800';
                return this.summary.range.isLive ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-600';
            },
            liveBadgeText() {
                if (this.connection === 'expired') return 'หมดเวลาเข้าสู่ระบบ — โหลดหน้าใหม่';
                if (this.connection === 'error') return 'เชื่อมต่อไม่ได้ · จะลองใหม่เอง';
                return this.summary.range.isLive ? 'สด · อัปเดต ' + this.summary.updatedAt : 'ดูย้อนหลัง';
            },
        });
    </script>
    @endpush
</x-app-layout>
