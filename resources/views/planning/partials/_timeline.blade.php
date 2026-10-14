{{-- ไทม์ไลน์: one row per project, the bar tinted by status, with a dot at the end; fixed-width day columns, scrolled to today. --}}
<div x-show="mode === 'timeline'" x-cloak>
    <div class="overflow-x-auto" x-ref="tlScroll">
        <div class="min-w-max relative">
            <div x-show="tlTodayLeft() !== null" class="absolute top-0 bottom-0 w-px bg-blue-400 z-[5] pointer-events-none" :style="'left:' + tlTodayLeft() + 'px'"></div>

            <div class="grid grid-cols-[220px_1fr] border-b border-slate-200 bg-white">
                <div class="sticky left-0 z-10 bg-white px-3 py-2 border-r border-slate-200 text-xs font-bold text-slate-500">โปรเจค</div>
                <div class="grid" :style="'grid-template-columns:' + tlTemplate()">
                    <template x-for="d in tlDays()" :key="d.date">
                        <div class="py-2 text-center text-xs font-medium border-l border-slate-100" :class="isWeekend(d.date) ? 'bg-slate-50/70' : ''">
                            <span :class="d.date === today ? 'bg-blue-600 text-white font-bold' : 'text-slate-500'" class="inline-flex items-center justify-center min-w-[1.4rem] h-[1.4rem] rounded-full" x-text="d.day"></span>
                        </div>
                    </template>
                </div>
            </div>

            <p x-show="tlRows().length === 0" class="text-sm text-slate-400 py-16 text-center">ไม่มีงานในเดือนนี้</p>

            <template x-for="r in tlRows()" :key="r.key">
                <div class="grid grid-cols-[220px_1fr] border-b border-slate-100 hover:bg-slate-50/40">
                    <div class="sticky left-0 z-10 bg-white px-3 py-3 border-r border-slate-200 text-xs text-slate-700 min-w-0 flex items-center gap-1.5">
                        <svg x-show="flag(r.t)" :style="'color:' + flag(r.t)" class="w-3.5 h-3.5 shrink-0" viewBox="0 0 24 24" fill="currentColor"><path d="M5 3v18h2v-7h11l-2-4 2-4H7V3H5z"/></svg>
                        <span class="truncate font-medium" x-text="(r.c ? (r.c.mo || r.c.text) + ' · ' : '') + r.t.so + ' ' + r.t.title" :title="r.t.so + ' ' + r.t.title + (r.c ? ' · ' + r.c.mo + ' ' + r.c.text : '')"></span>
                    </div>
                    <div class="grid items-center py-3" :style="'grid-template-columns:' + tlTemplate()">
                        <div @click="openModal(r.t.id)" :title="barTitle(r)"
                             :style="'grid-column:' + r.col + ' / span ' + r.span + ';' + chipStyle(statusColor(r.t), r.done)"
                             class="relative flex items-center gap-1.5 h-8 rounded-md border-l-[3px] px-2 text-xs cursor-pointer hover:brightness-95 hover:shadow-sm transition">
                            <span class="absolute -top-1.5 right-2 w-2.5 h-2.5 rounded-full ring-2 ring-white" :style="'background-color:' + statusColor(r.t)"></span>
                            <span x-show="r.before" class="shrink-0">◄</span>
                            <span class="truncate font-semibold" :class="r.done ? 'line-through text-slate-500' : 'text-slate-800'" x-text="r.t.so + ' ' + r.t.title"></span>
                            <span class="truncate text-[10px]" :class="r.late ? 'font-semibold text-red-600' : 'text-slate-500'" x-text="r.late ? '· เลยกำหนด ' + daysFromToday(r.end) * -1 + ' วัน' : (r.c ? '' : '· ' + r.t.subtasks.length + ' ตู้')"></span>
                            <span x-show="r.after" class="shrink-0 ml-auto">►</span>
                        </div>
                    </div>
                </div>
            </template>
        </div>
    </div>
</div>
