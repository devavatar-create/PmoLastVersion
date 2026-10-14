{{--
    Left panel "งานที่ต้องเสร็จ", laid out like the original page: title + day stepper (the word "วันนี้ / พรุ่งนี้ / เมื่อวาน…" and
    the date), "ความคืบหน้า x/y ตู้", then CABINETS grouped by project - "เลยกำหนด" (red, only when looking at today), "ครบกำหนด…" -
    and (ours) "งานในวันที่ …" for what is merely running that day. Click a calendar day to look at it here.
--}}
<aside class="lg:w-[300px] lg:shrink-0 border-b lg:border-b-0 lg:border-r border-slate-200">
    <div class="px-4 pb-4 pt-2 lg:sticky lg:top-16 lg:max-h-[calc(100vh-4rem)] lg:overflow-y-auto">
        {{-- รอรับงาน: every project where work handed to my department (or, for Admin / PM allowed to act for departments, any
             department) still waits to be accepted - accept it here without opening anything --}}
        <section id="waiting-box" x-show="waitingList().length" x-cloak class="mb-2 mt-2 rounded-lg border border-amber-300 bg-amber-50/70 p-2" aria-label="งานรอรับ">
            <div class="mb-1.5 flex items-center gap-2">
                <span class="text-[13px] font-bold text-amber-900" x-text="'📥 รอรับงาน (' + waitingCount() + ')'"></span>
                <button type="button" x-show="waitingList().length > 1" @click="acceptAll()" class="ml-auto rounded-md bg-amber-600 px-2 py-0.5 text-[11px] font-semibold text-white hover:bg-amber-700">รับทั้งหมด</button>
            </div>
            <ul class="max-h-64 space-y-1 overflow-y-auto">
                <template x-for="w in waitingList()" :key="w.t.id + w.s.deptId">
                    <li class="flex items-center gap-2 rounded-md bg-white px-2 py-1.5 shadow-sm">
                        <button type="button" @click="openModal(w.t.id); $nextTick(() => setTimeout(() => openStageFromLink(w.t, w.s.deptId, false), 150))" class="min-w-0 flex-1 text-left">
                            <span class="block truncate text-xs font-semibold text-slate-800" x-text="w.t.so + ' ' + w.t.title"></span>
                            <span class="block truncate text-[11px] text-slate-500"
                                  x-text="(myDept === w.s.deptId ? '' : deptOf(w.s.deptId).name + ' · ') + w.s.pmo.waiting + ' งาน' + (w.s.end ? ' · ส่ง ' + fmtBE(w.s.end) : '') + (w.days > 0 ? ' · รอ ' + w.days + ' วัน' : '')"></span>
                        </button>
                        <button type="button" @click="step(w.t, w.s.deptId, 'accept')" class="shrink-0 rounded-md bg-blue-600 px-2.5 py-1 text-[11px] font-semibold text-white hover:bg-blue-700">รับงาน</button>
                    </li>
                </template>
            </ul>
        </section>

        <div class="flex flex-wrap items-center gap-x-2 gap-y-1.5 px-0.5 pb-1.5 pt-2">
            <div class="whitespace-nowrap text-[13.5px] font-bold text-slate-900">งานที่ต้องเสร็จ</div>
            <div class="flex h-7 min-w-0 flex-[1_1_200px] items-stretch gap-1">
                <button type="button" @click="shiftFocus(-1)" class="inline-flex w-[26px] shrink-0 items-center justify-center rounded-[7px] border border-slate-300 bg-white text-[17px] leading-none text-slate-500 hover:bg-slate-100 hover:text-slate-900" title="วันก่อนหน้า" aria-label="วันก่อนหน้า">&lsaquo;</button>
                <button type="button" @click="focusDate = today; goToday()" class="flex min-w-0 flex-1 items-baseline justify-center gap-1.5 overflow-hidden whitespace-nowrap rounded-[7px] border border-slate-300 bg-white px-2 hover:border-blue-500 hover:bg-slate-50" title="กลับมาวันนี้">
                    <span class="text-[12.5px] font-bold leading-[26px]" :class="focusDate === today ? 'text-blue-600' : 'text-slate-900'" x-text="focusWord()"></span>
                    <span class="text-[11px] leading-[26px] text-slate-500" x-text="fmtBE(focusDate)"></span>
                </button>
                <button type="button" @click="shiftFocus(1)" class="inline-flex w-[26px] shrink-0 items-center justify-center rounded-[7px] border border-slate-300 bg-white text-[17px] leading-none text-slate-500 hover:bg-slate-100 hover:text-slate-900" title="วันถัดไป" aria-label="วันถัดไป">&rsaquo;</button>
                <button type="button" @click="$dispatch('open-status-report')" class="inline-flex w-[26px] shrink-0 items-center justify-center rounded-[7px] border border-slate-300 bg-white text-slate-500 hover:bg-slate-100 hover:text-slate-900" title="รายงานสถานะงาน" aria-label="รายงานสถานะงาน">
                    <svg class="h-[15px] w-[15px]" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4"/></svg>
                </button>
                <button type="button" @click="printWorkOrder()" class="inline-flex w-[26px] shrink-0 items-center justify-center rounded-[7px] border border-slate-300 bg-white text-slate-500 hover:bg-slate-100 hover:text-slate-900" title="พิมพ์ใบสั่งงาน — แผนกที่ดู + วันที่ดู" aria-label="พิมพ์">
                    <svg class="h-[15px] w-[15px]" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M6 9V3h12v6M6 18H4a1 1 0 01-1-1v-6a2 2 0 012-2h14a2 2 0 012 2v6a1 1 0 01-1 1h-2M7 14h10v7H7z"/></svg>
                </button>
            </div>
        </div>

        <div x-show="focusCounts().total > 0" class="px-0.5 pb-1">
            <div class="mb-[3px] flex justify-between text-[11.5px] text-slate-500"><span>ความคืบหน้า</span><span class="tabular-nums" x-text="focusCounts().done + '/' + focusCounts().total + ' ตู้'"></span></div>
            <div class="h-[5px] overflow-hidden rounded bg-slate-100"><div class="h-full rounded bg-blue-600 transition-all" :style="'width:' + focusCounts().pct + '%'"></div></div>
        </div>

        <div class="pb-2 pt-1">
            <template x-for="sec in focusSections()" :key="sec.key">
                <div x-show="sec.groups.length > 0">
                    <div class="flex items-center justify-between px-1.5 pb-1.5 pt-3 text-[11px] font-bold uppercase tracking-[.4px]" :class="sec.danger ? 'text-red-600' : 'text-slate-400'">
                        <span x-text="sec.label"></span>
                        <span class="rounded-lg px-[7px] py-px text-[11px] normal-case tracking-normal" :class="sec.danger ? 'bg-red-50 text-red-600' : 'bg-slate-100 text-slate-500'" x-text="sec.count"></span>
                    </div>
                    <template x-for="g in sec.groups" :key="sec.key + g.t.id">
                        <div class="mb-2 overflow-hidden rounded-[9px] border border-slate-200 bg-white">
                            <div @click="openModal(g.t.id)" :title="(g.t.so ? g.t.so + ' ' : '') + g.t.title" class="flex cursor-pointer items-center gap-2 border-b border-slate-200 bg-slate-50 px-2.5 py-[9px] hover:bg-slate-100">
                                <span class="h-[18px] w-[18px] shrink-0 rounded-full border-2 border-white" :style="'background-color:' + statusColor(g.t) + ';box-shadow:0 0 0 1.5px ' + statusColor(g.t)" :title="'สถานะโปรเจค: ' + statusOf(g.t).name"></span>
                                <span class="flex min-w-0 flex-1 items-center gap-1 truncate text-[13px] font-semibold text-slate-900">
                                    <svg x-show="flag(g.t)" :style="'color:' + flag(g.t)" class="h-3.5 w-3.5 shrink-0" viewBox="0 0 24 24" fill="currentColor"><path d="M5 3v18h2v-7h11l-2-4 2-4H7V3H5z"/></svg>
                                    <span class="truncate" x-text="g.t.title"></span>
                                </span>
                                <span x-show="g.t.so" class="shrink-0 rounded bg-slate-100 px-1.5 text-[10.5px] font-semibold leading-[18px] text-slate-500" x-text="g.t.so"></span>
                                <span class="shrink-0 rounded-lg bg-slate-100 px-[7px] py-px text-[11px] font-semibold text-slate-500 tabular-nums" x-text="taskCabs(g.t).done + '/' + taskCabs(g.t).total"></span>
                            </div>
                            <div class="relative mb-1.5 ml-2.5 mr-2 flex flex-col pl-4 pt-0.5">
                                <span class="absolute bottom-3 left-1 top-[-2px] w-[1.5px] bg-slate-200"></span>
                                <template x-for="u in g.cabs" :key="u.key || (u.c ? u.c.id : 'whole')">
                                    <div @click="openModal(g.t.id)" :title="rowName(u.c, g.t, u) + ' — ' + g.t.title" class="relative flex cursor-pointer items-center gap-[7px] rounded-md px-1.5 py-[3px] hover:bg-slate-50">
                                        <span class="absolute -left-3 top-1/2 h-[1.5px] w-2.5 bg-slate-200"></span>
                                        <span class="h-3.5 w-3.5 shrink-0 rounded-full border-2 border-white" :style="'background-color:' + rowColor(g.t, u.c) + ';box-shadow:0 0 0 1.2px ' + rowColor(g.t, u.c)"></span>
                                        <span class="min-w-0 flex-1 truncate text-xs" :class="u.done ? 'text-slate-400 line-through' : 'text-slate-900'" x-text="rowName(u.c, g.t, u)"></span>
                                        <button type="button" x-show="u.c && cabProgress(u.c, dept || null).total > 0" @click.stop="openChecklist(g.t.id, u.c.id, $event)"
                                                class="inline-flex shrink-0 items-center gap-[3px] rounded-[10px] border bg-white px-[5px] py-px text-[9px] font-bold hover:border-blue-500 hover:text-blue-600"
                                                :class="u.c && cabProgress(u.c, dept || null).total > 0 && cabProgress(u.c, dept || null).done === cabProgress(u.c, dept || null).total ? 'border-emerald-500 text-emerald-600' : 'border-slate-300 text-slate-500'" title="เปิด checklist">
                                            <svg class="h-2.5 w-2.5" fill="none" stroke="currentColor" stroke-width="2.4" viewBox="0 0 24 24"><rect x="3" y="3" width="18" height="18" rx="3"/><path stroke-linecap="round" stroke-linejoin="round" d="M8 12l3 3 5-6"/></svg>
                                            <span class="tabular-nums" x-text="u.c ? cabProgress(u.c, dept || null).done + '/' + cabProgress(u.c, dept || null).total : ''"></span>
                                        </button>
                                        <span class="shrink-0 whitespace-nowrap text-[11px] tabular-nums" :class="daysFromToday(u.ed) < 0 && !u.done ? 'font-semibold text-red-600' : 'text-slate-400'" x-text="rowDate(u.ed)"></span>
                                    </div>
                                </template>
                            </div>
                        </div>
                    </template>
                </div>
            </template>

            <div x-show="focusEmpty()" class="px-4 py-[50px] text-center text-sm leading-[1.8] text-slate-400">
                <svg class="mx-auto mb-1 h-[30px] w-[30px] text-emerald-500" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path stroke-linecap="round" stroke-linejoin="round" d="M8 12l3 3 5-6"/></svg>
                <span>ไม่มีตู้ที่ต้องเสร็จ<span x-text="focusWhen()"></span></span>
            </div>
        </div>
    </div>
</aside>
