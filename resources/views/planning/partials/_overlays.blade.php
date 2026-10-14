{{--
    Things that open over the page, as on the original: the "new project" window, the side drawers (stars / request inbox /
    trash), a photo / PDF viewer, and the quick status picker of a bar or a cabinet. All of it is sent through the same
    one-operation-at-a-time path as the rest of the page (PlanningSync decides).
--}}

{{-- new project (Admin / PM) --}}
<template x-if="newProject">
    <div class="fixed inset-0 z-[55] flex items-start justify-center p-4 sm:items-center" role="dialog" aria-modal="true" aria-label="สร้างโครงการใหม่">
        <div class="absolute inset-0 bg-slate-900/40" @click="newProject = null"></div>
        <form @submit.prevent="createProject()" class="relative w-full max-w-lg space-y-3 rounded-2xl border border-slate-200 bg-white p-5 shadow-2xl">
            <div class="flex items-center justify-between"><h3 class="text-base font-semibold text-slate-900">สร้างโครงการใหม่</h3>
                <button type="button" @click="newProject = null" class="text-2xl leading-none text-slate-400 hover:text-slate-600" aria-label="ปิด">&times;</button></div>
            <div class="grid gap-3 sm:grid-cols-[9rem_1fr]">
                <label class="text-xs font-semibold text-slate-600">เลขที่ (SO)<input x-ref="newSo" x-model="newProject.so" required maxlength="50" class="mt-1 w-full rounded-lg border-slate-300 text-sm"></label>
                <label class="text-xs font-semibold text-slate-600">ชื่อโครงการ<input x-model="newProject.title" required maxlength="255" class="mt-1 w-full rounded-lg border-slate-300 text-sm"></label>
            </div>
            <div class="grid gap-3 sm:grid-cols-[1fr_11rem]">
                <label class="text-xs font-semibold text-slate-600">ลูกค้า<input x-model="newProject.customer" required maxlength="255" class="mt-1 w-full rounded-lg border-slate-300 text-sm"></label>
                <label x-show="perms.contact" class="text-xs font-semibold text-slate-600">เบอร์ติดต่อ<input type="tel" x-model="newProject.customerPhone" maxlength="50" placeholder="081-234-5678" class="mt-1 w-full rounded-lg border-slate-300 text-sm"></label>
            </div>
            <div class="grid gap-3 sm:grid-cols-2">
                <label class="text-xs font-semibold text-slate-600">วันเริ่ม<input type="date" x-model="newProject.startDate" class="mt-1 w-full rounded-lg border-slate-300 text-sm"></label>
                <label class="text-xs font-semibold text-slate-600">วันครบกำหนด<input type="date" x-model="newProject.date" class="mt-1 w-full rounded-lg border-slate-300 text-sm"></label>
                <label class="text-xs font-semibold text-slate-600">ความสำคัญ
                    <select x-model="newProject.priority" class="mt-1 w-full rounded-lg border-slate-300 text-sm"><template x-for="(p, key) in priorities" :key="key"><option :value="key" x-text="p.label"></option></template></select></label>
                <label class="text-xs font-semibold text-slate-600">ผู้จัดการโครงการ (PM)
                    <select x-model="newProject.managerId" class="mt-1 w-full rounded-lg border-slate-300 text-sm"><option value="">—</option><template x-for="m in managers" :key="m.id"><option :value="m.id" x-text="m.name"></option></template></select></label>
                <label class="text-xs font-semibold text-slate-600">ประเภทงาน
                    <select x-model="newProject.jobTypeId" class="mt-1 w-full rounded-lg border-slate-300 text-sm"><option value="">—</option><template x-for="j in jobTypes" :key="'nj' + j.id"><option :value="j.id" x-text="j.name"></option></template></select></label>
                <label x-show="perms.finance" class="text-xs font-semibold text-slate-600">มูลค่างาน (บาท)<input inputmode="decimal" x-model="newProject.contractValue" maxlength="20" placeholder="1,250,000" class="mt-1 w-full rounded-lg border-slate-300 text-sm"></label>
                <label x-show="perms.finance" class="text-xs font-semibold text-slate-600">Margin (บาท)<input inputmode="decimal" x-model="newProject.margin" maxlength="20" placeholder="225,000" class="mt-1 w-full rounded-lg border-slate-300 text-sm">
                    <span x-show="marginPct(newProject)" class="text-[11px] font-normal text-slate-400" x-text="'คิดเป็น ' + marginPct(newProject)"></span></label>
                <label x-show="boards.length > 1" class="text-xs font-semibold text-slate-600">บอร์ด
                    <select x-model="newProject.boardId" class="mt-1 w-full rounded-lg border-slate-300 text-sm"><template x-for="b in boards" :key="b.id"><option :value="b.id === 'main' ? '' : b.id" x-text="b.name"></option></template></select></label>
            </div>
            <p class="text-[11px] text-slate-400">สร้างแล้วเพิ่มตู้และแผนกได้ในหน้าต่างโครงการ (ข้อมูลอื่น เช่น PO / เงื่อนไขชำระเงิน แก้ได้ที่หน้าโครงการ)</p>
            <div class="flex justify-end gap-2">
                <button type="button" @click="newProject = null" class="rounded-lg border border-slate-300 px-4 py-2 text-sm text-slate-700 hover:bg-slate-50">ยกเลิก</button>
                <button class="rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-700">สร้าง</button>
            </div>
        </form>
    </div>
</template>

{{-- side drawers --}}
<template x-if="drawer">
    <div class="fixed inset-0 z-[45]" @click.self="drawer = null">
        <aside class="absolute right-0 top-0 flex h-full w-full max-w-sm flex-col border-l border-slate-200 bg-white shadow-2xl" role="dialog" :aria-label="{ stars: 'โครงการที่ติดดาว', trash: 'ถังขยะ' }[drawer]">
            <div class="flex items-center justify-between border-b border-slate-100 px-4 py-3">
                <h3 class="text-sm font-semibold text-slate-900" x-text="{ stars: '★ โครงการที่ติดดาว', trash: '🗑 ถังขยะ' }[drawer]"></h3>
                <button type="button" @click="drawer = null" class="text-2xl leading-none text-slate-400 hover:text-slate-600" aria-label="ปิด">&times;</button>
            </div>
            <div class="min-h-0 flex-1 overflow-y-auto p-3 space-y-2">
                <template x-if="drawer === 'stars'">
                    <div class="space-y-1">
                        <template x-for="t in starredTasks()" :key="t.id">
                            <button type="button" @click="drawer = null; openModal(t.id)" class="flex w-full items-center gap-2 rounded-lg px-2 py-2 text-left text-sm hover:bg-slate-50">
                                <span class="h-2.5 w-2.5 shrink-0 rounded-full" :style="'background-color:' + statusColor(t)"></span>
                                <span class="shrink-0 font-semibold" x-text="t.so"></span><span class="min-w-0 flex-1 truncate text-slate-600" x-text="t.title"></span>
                            </button>
                        </template>
                        <p x-show="!starredTasks().length" class="py-6 text-center text-xs text-slate-400">ยังไม่มีโครงการที่ติดดาว (กด ☆ ในหน้าต่างโครงการ)</p>
                    </div>
                </template>
                <template x-if="drawer === 'trash'">
                    <div class="space-y-1">
                        <template x-for="p in trash" :key="p.id">
                            <div class="flex items-center gap-2 rounded-lg px-2 py-2 text-sm hover:bg-slate-50">
                                <span class="shrink-0 font-semibold" x-text="p.so"></span><span class="min-w-0 flex-1 truncate text-slate-600" x-text="p.title"></span>
                                <span class="shrink-0 text-[11px] text-slate-400" x-text="fmtDateTime(p.at)"></span>
                                <button type="button" @click="restoreProject(p)" class="shrink-0 rounded-lg border border-slate-300 px-2 py-0.5 text-xs font-semibold text-blue-600 hover:bg-blue-50">กู้คืน</button>
                            </div>
                        </template>
                        <p x-show="!trash.length" class="py-6 text-center text-xs text-slate-400">ถังขยะว่าง</p>
                    </div>
                </template>
            </div>
        </aside>
    </div>
</template>

{{-- photo / PDF viewer --}}
<template x-if="viewer">
    <div class="fixed inset-0 z-[70] flex flex-col bg-slate-900/90" role="dialog" aria-modal="true" :aria-label="viewer.name">
        <div class="flex items-center gap-3 px-4 py-2 text-sm text-white">
            <span class="min-w-0 flex-1 truncate" x-text="viewer.name"></span>
            <a :href="viewer.url" target="_blank" rel="noopener" class="rounded border border-white/40 px-2 py-0.5 text-xs hover:bg-white/10">เปิดแท็บใหม่ / ดาวน์โหลด</a>
            <button type="button" @click="viewer = null" class="text-2xl leading-none text-white/70 hover:text-white" aria-label="ปิด">&times;</button>
        </div>
        <div class="flex min-h-0 flex-1 items-center justify-center p-3" @click.self="viewer = null">
            <img x-show="viewer.kind === 'image'" :src="viewer.url" alt="" class="max-h-full max-w-full rounded object-contain">
            <iframe x-show="viewer.kind === 'pdf'" :src="viewer.kind === 'pdf' ? viewer.url : 'about:blank'" class="h-full w-full max-w-5xl rounded bg-white" title="PDF"></iframe>
        </div>
    </div>
</template>

{{-- quick status picker (a project bar, or a cabinet's dot) --}}
<template x-if="statusPop">
    <div class="fixed inset-0 z-[65]" @click="statusPop = null">
        <div class="absolute w-52 rounded-xl border border-slate-200 bg-white p-1.5 shadow-xl" :style="'top:' + statusPop.top + 'px;left:' + statusPop.left + 'px'" @click.stop role="listbox" aria-label="เลือกสถานะ">
            <p class="px-2 pb-1 pt-0.5 text-[10.5px] font-bold text-slate-400" x-text="statusPop.cabId ? 'สถานะตู้' : 'สถานะโครงการ'"></p>
            <button type="button" @click="pickStatus('')" class="flex w-full items-center gap-2 rounded-lg px-2 py-1.5 text-left text-xs text-slate-600 hover:bg-slate-50" x-text="statusPop.cabId ? '⟳ อัตโนมัติ (จากงานในตู้)' : '⟳ ตามสถานะ PMO'"></button>
            <template x-for="s in statuses" :key="s.id">
                <button type="button" @click="pickStatus(s.id)" class="flex w-full items-center gap-2 rounded-lg px-2 py-1.5 text-left text-xs hover:bg-slate-50">
                    <span class="h-2.5 w-2.5 shrink-0 rounded-full" :style="'background-color:' + s.color"></span><span x-text="s.name"></span>
                </button>
            </template>
        </div>
    </div>
</template>

{{-- new cabinet: MO + name, the template it starts from, and which department gets each of the template's sub tasks --}}
<template x-if="cabDialog">
    <div class="fixed inset-0 z-[55] flex items-start justify-center p-4 sm:items-center" role="dialog" aria-modal="true" aria-label="เพิ่มตู้">
        <div class="absolute inset-0 bg-slate-900/40" @click="cabDialog = null"></div>
        <form @submit.prevent="addCabinet()" class="relative flex max-h-[calc(100vh-2rem)] w-full max-w-4xl flex-col rounded-2xl border border-slate-200 bg-white shadow-2xl">
            <div class="flex items-center justify-between border-b border-slate-100 px-5 py-3">
                <h3 class="text-base font-semibold text-slate-900">เพิ่มตู้</h3>
                <button type="button" @click="cabDialog = null" class="text-2xl leading-none text-slate-400 hover:text-slate-600" aria-label="ปิด">&times;</button>
            </div>
            <div class="min-h-0 flex-1 space-y-3 overflow-y-auto px-5 py-4">
                <div class="grid gap-3 sm:grid-cols-[10rem_1fr]">
                    <label class="text-xs font-semibold text-slate-600">เลข MO<input x-model="cabDialog.mo" required maxlength="100" class="mt-1 w-full rounded-lg border-slate-300 text-sm"></label>
                    <label class="text-xs font-semibold text-slate-600">ชื่อตู้<input x-model="cabDialog.text" required maxlength="255" class="mt-1 w-full rounded-lg border-slate-300 text-sm"></label>
                </div>
                <label class="block text-xs font-semibold text-slate-600">เทมเพลต (งาน งานย่อย และ checklist)
                    <select x-model="cabDialog.templateId" @change="pickTemplate()" class="mt-1 w-full rounded-lg border-slate-300 text-sm">
                        <template x-for="tpl in templates" :key="tpl.id"><option :value="String(tpl.id)" x-text="tpl.name + (tpl.default ? ' (ค่าเริ่มต้น)' : '') + ' · ' + tpl.subtasks.length + ' งานย่อย'"></option></template>
                        <option value="none">ไม่ใช้เทมเพลต (ตู้เปล่า)</option>
                    </select>
                </label>
                <div class="grid gap-3 sm:grid-cols-2">
                    <label class="text-xs font-semibold text-slate-600">วันเริ่มของตู้<input type="date" x-model="cabDialog.cabStart" class="mt-1 w-full rounded-lg border-slate-300 text-sm"></label>
                    <label class="text-xs font-semibold text-slate-600">วันครบกำหนดของตู้<input type="date" x-model="cabDialog.cabEnd" class="mt-1 w-full rounded-lg border-slate-300 text-sm"></label>
                </div>
                <template x-if="templateOf(cabDialog.templateId)">
                    <div class="space-y-1.5 rounded-lg bg-slate-50 px-3 py-2 text-xs text-slate-600">
                        <p class="font-semibold">ช่วงวันของแต่ละแผนก <span class="font-normal text-slate-400">— งานทั้งหมดของแผนกในโครงการนี้ใช้เริ่ม–ส่งเดียวกัน แผนกจัดลำดับงานเอง</span></p>
                        <template x-for="dp in chosenDepts(cabDialog)" :key="'cabDialog' + dp">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="inline-flex w-44 items-center gap-1.5 truncate font-semibold text-slate-700"><span class="h-2 w-2 shrink-0 rounded-full" :style="'background-color:' + deptOf(dp).color"></span><span class="truncate" x-text="deptOf(dp).name"></span></span>
                                <span>เริ่ม</span><input type="date" x-model="deptRange(cabDialog, dp).start" class="h-8 rounded-lg border-slate-300 py-0 text-xs">
                                <span>ส่ง</span><input type="date" x-model="deptRange(cabDialog, dp).due" class="h-8 rounded-lg border-slate-300 py-0 text-xs">
                                <span x-show="stageOf(cur, dp) && stageOf(cur, dp).end" class="text-[11px] text-slate-400">(ช่วงเดิมของแผนกนี้)</span>
                            </div>
                        </template>
                        <p x-show="!chosenDepts(cabDialog).length" class="text-slate-400">เลือกแผนกให้งานด้านล่างก่อน</p>
                    </div>
                </template>
                <template x-if="templateOf(cabDialog.templateId)">
                    <div class="rounded-lg border border-slate-200">
                        <div class="flex flex-wrap items-center gap-2 border-b border-slate-100 bg-slate-50 px-3 py-2 text-xs">
                            <span class="font-semibold text-slate-600">จ่ายงานให้แผนก</span>
                            <span class="text-slate-400">(เว้นว่าง = ยังไม่จ่าย ไปจ่ายทีหลังได้)</span>
                            <select class="ml-auto h-7 w-44 rounded-lg border-slate-300 py-0 text-xs" @change="setAllCabDepts($event.target.value); $event.target.value = '-'">
                                <option value="-">ทุกงานย่อยเป็น…</option><option value="">— ไม่จ่าย —</option>
                                <template x-for="d in departments" :key="'all' + d.id"><option :value="d.id" x-text="d.name"></option></template>
                            </select>
                        </div>
                        <div class="divide-y divide-slate-50">
                            <template x-for="s in templateOf(cabDialog.templateId).subtasks" :key="s.id">
                                <div class="flex items-center gap-2 px-3 py-1.5 text-xs">
                                    <span class="min-w-0 flex-1 truncate"><span class="text-slate-400" x-text="s.task + ' › '"></span><span class="font-medium text-slate-800" x-text="s.name"></span></span>
                                    <span class="shrink-0 text-[11px] text-slate-400" x-text="'☑ ' + s.checklists"></span>
                                    <select x-model="cabDialog.assign[s.id]" class="h-7 w-40 shrink-0 rounded-lg border-slate-300 py-0 text-xs">
                                        <option value="">— ยังไม่จ่าย —</option>
                                        <template x-for="d in departments" :key="s.id + d.id"><option :value="d.id" x-text="d.name" :selected="cabDialog.assign[s.id] === d.id"></option></template>
                                    </select>
                                </div>
                            </template>
                        </div>
                    </div>
                </template>
            </div>
            <div class="flex items-center justify-end gap-2 border-t border-slate-100 px-5 py-3">
                <span class="mr-auto text-xs text-slate-400" x-text="Object.values(cabDialog.assign).filter((v) => v).length + ' งานย่อยจะถูกจ่ายให้แผนก (แจ้งเตือนแผนกทันที)'"></span>
                <button type="button" @click="cabDialog = null" class="rounded-lg border border-slate-300 px-4 py-2 text-sm text-slate-700 hover:bg-slate-50">ยกเลิก</button>
                <button class="rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-700">เพิ่มตู้</button>
            </div>
        </form>
    </div>
</template>

{{-- hand out sub tasks that have no department yet (a cabinet, or the whole project; "+ เพิ่มแผนก" opens it with that department ready) --}}
<template x-if="dispatch">
    <div class="fixed inset-0 z-[55] flex items-start justify-center p-4 sm:items-center" role="dialog" aria-modal="true" aria-label="จ่ายงาน">
        <div class="absolute inset-0 bg-slate-900/40" @click="dispatch = null"></div>
        <div class="relative flex max-h-[calc(100vh-2rem)] w-full max-w-4xl flex-col rounded-2xl border border-slate-200 bg-white shadow-2xl">
            <div class="flex items-center justify-between border-b border-slate-100 px-5 py-3">
                <h3 x-show="!dispatch.preset" class="text-base font-semibold text-slate-900">จ่ายงานที่ยังไม่มีแผนก</h3>
                <div x-show="dispatch.preset" class="flex items-center gap-2">
                    <h3 class="text-base font-semibold text-slate-900">เพิ่มแผนก</h3>
                    <select class="h-8 w-48 rounded-lg border-slate-300 py-0 text-sm font-semibold" @change="setPreset($event.target.value)" aria-label="แผนก">
                        <template x-for="dp in departments" :key="'pre' + dp.id"><option :value="dp.id" x-text="dp.name" :selected="dp.id === dispatch.preset"></option></template>
                    </select>
                </div>
                <button type="button" @click="dispatch = null" class="text-2xl leading-none text-slate-400 hover:text-slate-600" aria-label="ปิด">&times;</button>
            </div>
            <div class="min-h-0 flex-1 space-y-3 overflow-y-auto px-5 py-4">
                <div class="space-y-1.5 rounded-lg bg-slate-50 px-3 py-2 text-xs text-slate-600">
                        <p class="font-semibold">ช่วงวันของแต่ละแผนก <span class="font-normal text-slate-400">— งานทั้งหมดของแผนกในโครงการนี้ใช้เริ่ม–ส่งเดียวกัน แผนกจัดลำดับงานเอง</span></p>
                        <template x-for="dp in chosenDepts(dispatch)" :key="'dispatch' + dp">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="inline-flex w-44 items-center gap-1.5 truncate font-semibold text-slate-700"><span class="h-2 w-2 shrink-0 rounded-full" :style="'background-color:' + deptOf(dp).color"></span><span class="truncate" x-text="deptOf(dp).name"></span></span>
                                <span>เริ่ม</span><input type="date" x-model="deptRange(dispatch, dp).start" class="h-8 rounded-lg border-slate-300 py-0 text-xs">
                                <span>ส่ง</span><input type="date" x-model="deptRange(dispatch, dp).due" class="h-8 rounded-lg border-slate-300 py-0 text-xs">
                                <span x-show="stageOf(cur, dp) && stageOf(cur, dp).end" class="text-[11px] text-slate-400">(ช่วงเดิมของแผนกนี้)</span>
                            </div>
                        </template>
                        <p x-show="!chosenDepts(dispatch).length" class="text-slate-400">เลือกแผนกให้งานด้านล่างก่อน</p>
                    </div>
                {{-- simple: tick the pieces of work for this department; each row covers every cabinet that has it --}}
                <template x-if="dispatch.preset && !dispatch.perCabinet && dispatchGroups().length">
                    <div class="rounded-lg border border-slate-200">
                        <div class="flex flex-wrap items-center gap-3 border-b border-slate-100 bg-slate-50 px-3 py-2 text-xs">
                            <span class="font-semibold text-slate-600">ติ๊กงานของแผนกนี้ — จ่ายให้ทุกตู้ที่มีงานนั้น</span>
                            <button type="button" @click="pickAllGroups(true)" class="ml-auto text-blue-600 hover:underline">เลือกทั้งหมด</button>
                            <button type="button" @click="pickAllGroups(false)" class="text-blue-600 hover:underline">ล้าง</button>
                        </div>
                        <div class="divide-y divide-slate-50">
                            <template x-for="g in dispatchGroups()" :key="g.key">
                                <label class="flex cursor-pointer items-center gap-2.5 px-3 py-2 text-sm hover:bg-slate-50" :class="dispatch.picked[g.key] ? 'bg-blue-50/60' : ''">
                                    <input type="checkbox" class="h-4 w-4 rounded border-slate-300 text-blue-600" :checked="!!dispatch.picked[g.key]" @change="dispatch.picked = { ...dispatch.picked, [g.key]: $event.target.checked }">
                                    <span class="min-w-0 flex-1 truncate"><span class="text-xs text-slate-400" x-text="g.task + ' › '"></span><span class="font-medium text-slate-800" x-text="g.name"></span></span>
                                    <span x-show="g.usual" class="shrink-0 rounded-full px-2 text-[11px]" :class="g.usual === dispatch.preset ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-100 text-slate-500'" x-text="'ปกติ: ' + (g.usual ? deptOf(g.usual).name : '')" title="แผนกที่งานนี้มักได้รับในตู้อื่น"></span>
                                    <span class="shrink-0 text-xs text-slate-500" x-text="g.ids.length + ' ตู้ · ☑ ' + g.checklists"></span>
                                </label>
                            </template>
                        </div>
                    </div>
                </template>
                <button type="button" x-show="dispatch.preset && dispatchGroups().length" @click="dispatch.perCabinet = !dispatch.perCabinet" class="text-xs text-blue-600 hover:underline"
                        x-text="dispatch.perCabinet ? '← กลับไปแบบง่าย (ติ๊กทีเดียวทุกตู้)' : 'ต้องการเลือกแผนกแยกทีละตู้ / ทีละงาน →'"></button>
                <template x-for="c in (!dispatch.preset || dispatch.perCabinet ? dispatchCabinets() : [])" :key="'dc' + c.id">
                    <div class="rounded-lg border border-slate-200">
                        <div class="flex flex-wrap items-center gap-2 border-b border-slate-100 bg-slate-50 px-3 py-2 text-xs">
                            <span class="font-semibold text-slate-800" x-text="c.mo + ' · ' + c.text"></span>
                            <select class="ml-auto h-7 w-44 rounded-lg border-slate-300 py-0 text-xs" @change="setCabDispatch(c, $event.target.value); $event.target.value = '-'">
                                <option value="-">ทั้งตู้เป็น…</option><option value="">— ไม่จ่าย —</option>
                                <template x-for="d in departments" :key="c.id + 'all' + d.id"><option :value="d.id" x-text="d.name"></option></template>
                            </select>
                        </div>
                        <template x-for="s in c.free" :key="s.id">
                            <div class="flex items-center gap-2 px-3 py-1.5 text-xs">
                                <span class="min-w-0 flex-1 truncate"><span class="text-slate-400" x-text="s.task + ' › '"></span><span class="font-medium text-slate-800" x-text="s.name"></span></span>
                                <span class="shrink-0 text-[11px] text-slate-400" x-text="'☑ ' + s.checklists"></span>
                                <select x-model="dispatch.assign[s.id]" class="h-7 w-40 shrink-0 rounded-lg border-slate-300 py-0 text-xs">
                                    <option value="">— ยังไม่จ่าย —</option>
                                    <template x-for="d in departments" :key="s.id + d.id"><option :value="d.id" x-text="d.name" :selected="dispatch.assign[s.id] === d.id"></option></template>
                                </select>
                            </div>
                        </template>
                    </div>
                </template>
                <div x-show="!dispatchCabinets().length" class="rounded-lg bg-slate-50 px-3 py-4 text-center text-sm text-slate-500">ไม่มีงานย่อยที่ยังไม่ได้จ่าย</div>
                <div x-show="dispatch.preset" class="rounded-lg border border-dashed border-slate-300 px-3 py-2 text-xs text-slate-600">
                    ไม่มีงานที่ตรงกับแผนกนี้?
                    <button type="button" @click="newWorkForDept()" class="ml-1 font-semibold text-blue-600 hover:underline" x-text="'สร้างงานใหม่ “งาน ' + deptOf(dispatch.preset).name + '” ในทุกตู้ (ไม่มี checklist)'"></button>
                </div>
            </div>
            <div class="flex items-center justify-end gap-2 border-t border-slate-100 px-5 py-3">
                <span class="mr-auto text-xs text-slate-400" x-text="(dispatch.preset && !dispatch.perCabinet ? pickedCount() + ' งานย่อยจะถูกจ่ายให้ ' + deptOf(dispatch.preset).name : Object.values(dispatch.assign).filter((v) => v).length + ' งานย่อยจะถูกจ่าย') + ' (แจ้งเตือนแผนกทันที)'"></span>
                <button type="button" @click="dispatch = null" class="rounded-lg border border-slate-300 px-4 py-2 text-sm text-slate-700 hover:bg-slate-50">ยกเลิก</button>
                <button type="button" @click="sendDispatch()" :disabled="dispatch.preset && !dispatch.perCabinet ? !pickedCount() : !Object.values(dispatch.assign).some((v) => v)" class="rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-700 disabled:opacity-50">จ่ายงาน</button>
            </div>
        </div>
    </div>
</template>

{{-- a project's files (the same file manager as the project page) --}}
<template x-if="filesFor && taskById(filesFor)">
    <div class="fixed inset-0 z-[60] flex items-start justify-center bg-slate-900/40 p-2 sm:p-6" @click.self="closeFiles()" role="dialog" aria-modal="true" aria-label="ไฟล์โครงการ">
        <div class="flex max-h-full w-full max-w-6xl flex-col overflow-hidden rounded-2xl bg-white shadow-2xl">
            <div class="flex items-center gap-3 border-b border-slate-100 px-4 py-2.5">
                <h3 class="min-w-0 flex-1 truncate text-base font-semibold text-slate-900" x-text="'📎 ไฟล์ · ' + taskById(filesFor).so + ' ' + taskById(filesFor).title"></h3>
                <a :href="taskById(filesFor).pmoUrl + '?tab=attachments'" class="text-xs text-slate-500 hover:text-blue-600" title="เปิดที่หน้าโครงการ">เปิดที่หน้าโครงการ ↗</a>
                <button type="button" @click="closeFiles()" class="text-2xl leading-none text-slate-400 hover:text-slate-600" aria-label="ปิด">&times;</button>
            </div>
            <div class="min-h-0 flex-1 overflow-y-auto p-3">
                <x-file-manager expr="fileManager(filesCfg())" />
            </div>
        </div>
    </div>
</template>
