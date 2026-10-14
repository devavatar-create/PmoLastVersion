{{-- แดชบอร์ด: tiles, unfinished work by due date, progress per department (checklist), and the project table. Follows the department filter. --}}
<div x-show="mode === 'dashboard'" x-cloak class="p-4 sm:p-5 space-y-5">
    <div class="flex flex-wrap items-center gap-2 text-xs">
        <label class="inline-flex items-center gap-2"><span class="text-slate-500">โครงการ:</span>
            <select x-model="dashProject" class="h-8 max-w-[22rem] rounded-lg border-slate-300 py-0 text-xs font-medium" aria-label="ดูแดชบอร์ดของโครงการ">
                <option value="">ทุกโครงการ (ภาพรวม)</option>
                <template x-for="t in tasks.slice().sort((a, b) => a.so.localeCompare(b.so))" :key="'dp' + t.id"><option :value="t.id" x-text="t.so + ' · ' + t.title" :selected="t.id === dashProject"></option></template>
            </select></label>
        <button type="button" x-show="dashProject" @click="dashProject = ''" class="rounded-full border border-slate-300 bg-white px-3 py-1 font-medium text-slate-600 hover:bg-slate-50">← ภาพรวมทุกโครงการ</button>
        <span class="ml-2 text-slate-500">กรองแผนก:</span>
        <button type="button" @click="dept = ''" :class="dept === '' ? 'bg-blue-600 border-blue-600 text-white' : 'bg-white border-slate-300 text-slate-600 hover:bg-slate-50'" class="rounded-full border px-3 py-1 font-medium">ทุกแผนก</button>
        <template x-for="d in departments" :key="d.id">
            <button type="button" @click="dept = d.id" :class="dept === d.id ? 'bg-blue-600 border-blue-600 text-white' : 'bg-white border-slate-300 text-slate-600 hover:bg-slate-50'" class="rounded-full border px-3 py-1 font-medium" x-text="d.name"></button>
        </template>
    </div>

    {{-- `d` = the computed figures (one-element list = a local alias, recomputed whenever the data or a filter changes) --}}
    {{-- ONE project --}}
    <template x-for="p in (mode === 'dashboard' && dashProject ? [projectDash()].filter(Boolean) : [])" :key="'pd' + dashProject">
        <div class="space-y-5">
            <div class="flex flex-wrap items-start gap-3 rounded-xl border border-slate-200 p-4">
                <div class="min-w-0 flex-1">
                    <p class="text-lg font-bold text-slate-900"><span x-text="p.t.so"></span> <span class="font-semibold text-slate-600" x-text="p.t.title"></span></p>
                    <p class="mt-0.5 text-xs text-slate-500" x-text="(p.t.info.customer || '-') + ' · ครบกำหนด ' + fmtBE(p.t.date) + (overdueFor(p.t) ? ' · เลย ' + Math.abs(daysFromToday(p.t.date)) + ' วัน' : '')"></p>
                    <p x-show="perms.finance && p.t.info.contractValue" class="mt-1 text-xs text-slate-600" x-text="'มูลค่างาน ' + fmtMoney(p.t.info.contractValue) + ' · รวม VAT 7% ' + fmtMoney(money(p.t.info.contractValue) * 1.07) + (p.t.info.margin != null ? ' · Margin ' + fmtMoney(p.t.info.margin) + (marginPct(p.t.info) ? ' (' + marginPct(p.t.info) + ')' : '') : '')"></p>
                </div>
                <span class="rounded-full px-2.5 py-1 text-xs font-semibold" :style="'background-color:' + statusColor(p.t) + '26;color:' + statusColor(p.t)" x-text="statusOf(p.t).name"></span>
                <button type="button" @click="openModal(p.t.id)" class="rounded-lg border border-slate-300 px-3 py-1.5 text-xs font-medium text-slate-700 hover:bg-slate-50">เปิดหน้าต่างโครงการ</button>
            </div>
            <div class="grid grid-cols-2 gap-3 lg:grid-cols-5">
                <div class="rounded-xl border border-slate-200 p-4"><p class="text-2xl font-bold text-slate-900" x-text="p.cp.pct + '%'"></p><p class="mt-1 text-xs text-slate-500" x-text="'เช็คลิสต์ (' + p.cp.done + '/' + p.cp.total + ')'"></p></div>
                <div class="rounded-xl border border-slate-200 p-4"><p class="text-2xl font-bold text-emerald-600" x-text="p.cabsDone + '/' + p.cabs.length"></p><p class="mt-1 text-xs text-slate-500">ตู้ที่เสร็จ</p></div>
                <div class="rounded-xl border border-slate-200 p-4"><p class="text-2xl font-bold text-blue-600" x-text="p.stagesDone + '/' + p.stages.length"></p><p class="mt-1 text-xs text-slate-500">แผนกที่เสร็จ</p></div>
                <div class="rounded-xl border border-slate-200 p-4"><p class="text-2xl font-bold" :class="p.late ? 'text-red-600' : 'text-slate-900'" x-text="p.late"></p><p class="mt-1 text-xs text-slate-500">แผนกที่เกินกำหนด</p></div>
                <div class="rounded-xl border border-slate-200 p-4"><p class="text-2xl font-bold" :class="p.waiting ? 'text-amber-600' : 'text-slate-900'" x-text="p.waiting"></p><p class="mt-1 text-xs text-slate-500">งานรอรับ</p></div>
            </div>

            <div>
                <h4 class="text-sm font-bold text-slate-900">ความคืบหน้าแต่ละแผนก</h4>
                <div class="mt-2 divide-y divide-slate-100 rounded-xl border border-slate-200">
                    <template x-for="r in p.stages" :key="r.s.deptId">
                        <div class="grid items-center gap-x-4 gap-y-1 px-4 py-2.5 sm:grid-cols-[minmax(8rem,13rem)_1fr_auto]">
                            <div class="flex min-w-0 items-center gap-2">
                                <span class="inline-flex min-w-0 items-center gap-1.5 truncate rounded-full border px-2.5 py-0.5 text-xs font-semibold text-slate-800" :style="deptTag(r.s.deptId)">
                                    <span class="h-2 w-2 shrink-0 rounded-full" :style="'background-color:' + r.dp.color"></span><span class="truncate" x-text="r.dp.name"></span></span>
                            </div>
                            <div>
                                <div class="h-2 overflow-hidden rounded-full bg-slate-100"><div class="h-2 rounded-full" :class="r.prog.pct === 100 ? 'bg-emerald-500' : 'bg-blue-600'" :style="'width:' + r.prog.pct + '%'"></div></div>
                                <p class="mt-1 text-[11px] text-slate-500" x-text="(r.s.start || r.s.end ? fmtBE(r.s.start || r.s.end) + ' – ' + fmtBE(r.s.end) : 'ยังไม่มีวันส่ง') + ' · รอรับ ' + r.s.pmo.waiting + ' · รับแล้ว ' + r.s.pmo.accepted + ' · กำลังทำ ' + r.s.pmo.working + ' · เสร็จ ' + r.s.pmo.done"></p>
                            </div>
                            <div class="flex items-center gap-2 whitespace-nowrap text-xs tabular-nums">
                                <span class="text-slate-500" x-text="r.prog.done + '/' + r.prog.total + ' (' + r.prog.pct + '%)'"></span>
                                <span class="rounded-full px-2 py-0.5 font-semibold" :style="'background-color:' + r.status.color + '26;color:' + r.status.color" x-text="r.status.name"></span>
                                <span x-show="r.late" class="font-semibold text-red-600" x-text="'เลย ' + r.lateDays + ' วัน'"></span>
                            </div>
                        </div>
                    </template>
                    <p x-show="!p.stages.length" class="px-4 py-6 text-center text-sm text-slate-400">ยังไม่ได้จ่ายงานให้แผนกไหน</p>
                </div>
            </div>

            <div>
                <h4 class="text-sm font-bold text-slate-900" x-text="'ตู้ / ใบสั่งผลิต (' + p.cabs.length + ')'"></h4>
                <div class="mt-2 overflow-x-auto rounded-xl border border-slate-200">
                    <table class="min-w-full text-sm">
                        <thead class="bg-slate-50 text-xs text-slate-500"><tr>
                            <th class="px-4 py-2 text-left font-semibold">ตู้</th><th class="px-4 py-2 text-left font-semibold">ครบกำหนด</th><th class="px-4 py-2 text-left font-semibold">สถานะ</th>
                            <th class="px-4 py-2 text-left font-semibold">เช็คลิสต์</th><th class="px-4 py-2 text-left font-semibold">แผนก</th>
                        </tr></thead>
                        <tbody class="divide-y divide-slate-100">
                            <template x-for="x in p.cabs" :key="x.c.id">
                                <tr class="align-top">
                                    <td class="px-4 py-2.5"><a :href="cabinetPage(x.c)" class="font-semibold text-blue-600 hover:underline" x-text="x.c.mo || '-'"></a><p class="text-xs text-slate-500" x-text="x.c.text"></p></td>
                                    <td class="whitespace-nowrap px-4 py-2.5 text-xs" x-text="x.due ? fmtBE(x.due) : '-'"></td>
                                    <td class="px-4 py-2.5"><span class="rounded-full px-2 py-0.5 text-xs font-semibold" :style="'background-color:' + x.status.color + '26;color:' + x.status.color" x-text="x.status.name"></span></td>
                                    <td class="whitespace-nowrap px-4 py-2.5"><div class="flex w-36 items-center gap-2"><div class="h-1.5 flex-1 overflow-hidden rounded-full bg-slate-100"><div class="h-1.5 rounded-full" :class="x.prog.pct === 100 ? 'bg-emerald-500' : 'bg-blue-600'" :style="'width:' + x.prog.pct + '%'"></div></div><span class="text-xs tabular-nums text-slate-500" x-text="x.prog.done + '/' + x.prog.total"></span></div></td>
                                    <td class="px-4 py-2.5"><div class="flex flex-wrap gap-1">
                                        <template x-for="dd in x.depts" :key="dd.id">
                                            <span class="inline-flex items-center gap-1 rounded-full border px-2 py-0.5 text-[11px] font-medium text-slate-700" :style="deptTag(dd.id)" :title="deptOf(dd.id).name + ' ' + dd.prog.done + '/' + dd.prog.total">
                                                <span x-text="deptOf(dd.id).name"></span><span class="tabular-nums" :class="dd.done ? 'text-emerald-700' : 'text-slate-500'" x-text="dd.done ? '✓' : dd.prog.pct + '%'"></span></span>
                                        </template></div></td>
                                </tr>
                            </template>
                            <tr x-show="!p.cabs.length"><td colspan="5" class="px-4 py-8 text-center text-slate-400">ยังไม่มีตู้ที่แสดงได้</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </template>

    {{-- every project in view --}}
    <template x-for="d in (mode === 'dashboard' && !dashProject ? [dash()] : [])" :key="'dash'">
        <div class="space-y-5">
            <div class="grid grid-cols-2 lg:grid-cols-5 gap-3">
                <div class="rounded-xl border border-slate-200 p-4"><p class="text-2xl font-bold text-slate-900" x-text="d.total"></p><p class="text-xs text-slate-500 mt-1">โปรเจคทั้งหมด</p></div>
                <div class="rounded-xl border border-slate-200 p-4"><p class="text-2xl font-bold text-emerald-600" x-text="d.completed"></p><p class="text-xs text-slate-500 mt-1">เสร็จแล้ว</p></div>
                <div class="rounded-xl border border-slate-200 p-4"><p class="text-2xl font-bold text-blue-600" x-text="d.doing"></p><p class="text-xs text-slate-500 mt-1">กำลังทำ</p></div>
                <div class="rounded-xl border border-slate-200 p-4"><p class="text-2xl font-bold text-red-600" x-text="d.late"></p><p class="text-xs text-slate-500 mt-1">เลยกำหนด</p></div>
                <div class="rounded-xl border border-slate-200 p-4"><p class="text-2xl font-bold text-slate-900" x-text="d.checkPct + '%'"></p><p class="text-xs text-slate-500 mt-1" x-text="'เช็คลิสต์รวม (' + d.checkDone + '/' + d.checkTotal + ')'"></p></div>
            </div>

            <div>
                <h4 class="text-sm font-bold text-slate-900">ภาระงานรายสัปดาห์ <span class="font-normal text-xs text-slate-400">(งานที่ยังไม่เสร็จ ตามวันครบกำหนด)</span></h4>
                <div class="mt-2 rounded-xl border border-slate-200 p-4">
                    <div class="flex items-end gap-4 h-44" role="img" aria-label="จำนวนงานที่ยังไม่เสร็จแยกตามช่วงวันครบกำหนด">
                        <template x-for="b in d.bucket" :key="b.label">
                            <div class="flex-1 flex flex-col items-center justify-end h-full" :title="b.label + ': ' + b.n + ' งาน'">
                                <span class="text-xs font-semibold tabular-nums" :class="b.late && b.n > 0 ? 'text-red-700' : 'text-slate-700'" x-text="b.n"></span>
                                <div class="w-full max-w-[56px] rounded-t" :class="[b.late ? 'bg-red-600' : 'bg-blue-600', b.n === 0 ? 'opacity-30' : '']" :style="'height:' + (b.n === 0 ? 3 : Math.max(6, Math.round(b.n / d.bucketMax * 100))) + '%'"></div>
                                <span class="mt-1.5 text-[11px] text-center" :class="b.late ? 'text-red-700 font-medium' : 'text-slate-500'" x-text="b.label"></span>
                            </div>
                        </template>
                    </div>
                </div>
            </div>

            <div>
                <h4 class="text-sm font-bold text-slate-900">ความคืบหน้าแต่ละแผนก</h4>
                <div class="mt-2 rounded-xl border border-slate-200 divide-y divide-slate-100">
                    <template x-for="r in d.depts" :key="r.dp.id">
                        <div class="grid grid-cols-[minmax(7rem,12rem)_1fr_auto] items-center gap-4 px-4 py-2.5">
                            <span class="inline-flex min-w-0 items-center gap-1.5 justify-self-start truncate rounded-full border px-2.5 py-0.5 text-xs font-semibold text-slate-800" :style="deptTag(r.dp.id)">
                                <span class="h-2 w-2 shrink-0 rounded-full" :style="'background-color:' + r.dp.color"></span><span class="truncate" x-text="r.dp.name"></span></span>
                            <div class="h-2 rounded-full bg-slate-100 overflow-hidden"><div class="h-2 rounded-full" :class="r.pct === 100 ? 'bg-emerald-500' : 'bg-blue-600'" :style="'width:' + r.pct + '%'"></div></div>
                            <span class="text-xs text-slate-500 whitespace-nowrap tabular-nums"><span x-text="r.done + '/' + r.total + ' (' + r.pct + '%)'"></span> · เสร็จ <span x-text="r.stagesDone + '/' + r.stages"></span><template x-if="r.late > 0"><span> · <b class="text-red-600" x-text="'เลย ' + r.late"></b></span></template></span>
                        </div>
                    </template>
                    <p x-show="d.depts.length === 0" class="px-4 py-6 text-center text-sm text-slate-400">ยังไม่มีข้อมูลแผนก</p>
                </div>
            </div>

            <div>
                <h4 class="text-sm font-bold text-slate-900" x-text="'โปรเจคทั้งหมด (' + d.rows.length + ')'"></h4>
                <div class="mt-2 overflow-x-auto rounded-xl border border-slate-200">
                    <table class="min-w-full text-sm">
                        <thead class="bg-slate-50 text-xs text-slate-500"><tr>
                            <th class="px-4 py-2 text-left font-semibold">โปรเจค</th><th class="px-4 py-2 text-left font-semibold">วันครบกำหนด</th><th class="px-4 py-2 text-left font-semibold">สถานะ</th>
                            <th class="px-4 py-2 text-left font-semibold">แผนก</th><th class="px-4 py-2 text-left font-semibold">เช็คลิสต์</th><th class="px-4 py-2 text-right font-semibold">ตู้</th>
                        </tr></thead>
                        <tbody class="divide-y divide-slate-100">
                            <template x-for="t in d.rows" :key="t.id">
                                <tr class="hover:bg-slate-50 cursor-pointer" @click="openModal(t.id)">
                                    <td class="px-4 py-2.5"><span class="font-semibold text-blue-600" x-text="t.so"></span>
                                        <button type="button" @click.stop="dashProject = t.id; window.scrollTo({ top: 0, behavior: 'smooth' })" class="ml-1.5 rounded border border-slate-200 px-1.5 text-[11px] text-slate-500 hover:border-blue-400 hover:text-blue-600" title="แดชบอร์ดของโครงการนี้">📊</button>
                                        <p class="text-xs text-slate-500" x-text="t.title"></p></td>
                                    <td class="px-4 py-2.5 whitespace-nowrap"><span x-text="fmtBE(dueFor(t))"></span><p x-show="overdueFor(t)" class="text-[11px] font-semibold text-red-600" x-text="'เลย ' + Math.abs(daysFromToday(dueFor(t))) + ' วัน'"></p></td>
                                    <td class="px-4 py-2.5"><span class="rounded-full px-2.5 py-0.5 text-xs font-semibold" :style="'background-color:' + statusColor(t) + '26;color:' + statusColor(t)" x-text="statusOf(t).name"></span></td>
                                    <td class="px-4 py-2.5 text-xs text-slate-600"><template x-for="s in t.deptStages" :key="s.deptId"><span class="inline-flex items-center gap-1 mr-2"><span class="w-2 h-2 rounded-full" :style="'background-color:' + deptOf(s.deptId).color"></span><span :class="s.done ? 'line-through text-slate-400' : ''" x-text="deptOf(s.deptId).name"></span></span></template></td>
                                    <td class="px-4 py-2.5 whitespace-nowrap"><div class="flex items-center gap-2 w-32"><div class="h-1.5 flex-1 rounded-full bg-slate-100 overflow-hidden"><div class="h-1.5 rounded-full bg-emerald-500" :style="'width:' + progress(t, dept || null).pct + '%'"></div></div><span class="text-xs text-slate-500 tabular-nums" x-text="progress(t, dept || null).pct + '%'"></span></div></td>
                                    <td class="px-4 py-2.5 text-right font-semibold tabular-nums" x-text="t.subtasks.length"></td>
                                </tr>
                            </template>
                            <tr x-show="d.rows.length === 0"><td colspan="6" class="px-4 py-8 text-center text-slate-400">ยังไม่มีโปรเจคตามเงื่อนไขที่เลือก</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </template>
</div>
