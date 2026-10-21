{{--
    Wide project modal. Left: the project (name, status, priority, dates), "ส่งต่อแผนก" chips (click one: its due date,
    its workflow buttons), the cabinets with their checklists you can tick right here. Right: Activity + compose.
    Everything the user changes is sent at once as one operation (see _script); a refused edit bounces back with the reason.
--}}
<template x-if="cur">
    <div class="fixed inset-0 z-40 flex items-start sm:items-center justify-center" :class="modalSize === 'full' ? 'p-1' : 'p-2 sm:p-6'" role="dialog" aria-modal="true">
        <div class="absolute inset-0 bg-slate-900/40" @click="closeModal()"></div>

        {{-- size: normal 1000px / wide 1240px / full = (almost) the whole screen; the activity column grows with it --}}
        <div class="relative flex w-full flex-col rounded-2xl bg-white shadow-2xl border border-slate-200"
             :class="{ 'max-w-[1000px] max-h-[calc(100vh-1rem)] sm:max-h-[calc(100vh-3rem)]': modalSize === 'normal',
                       'max-w-[1240px] max-h-[calc(100vh-1rem)] sm:max-h-[calc(100vh-2rem)]': modalSize === 'wide',
                       'max-w-none h-[calc(100vh-0.5rem)]': modalSize === 'full' }" @click.stop="stageDept = null; closeChecklist()">
            <div class="flex items-center justify-between gap-3 border-b border-slate-100 px-5 py-3.5 shrink-0">
                <h3 class="text-base font-semibold text-slate-900">โครงการ</h3>
                <div class="flex items-center gap-2">
                    <div class="hidden sm:inline-flex overflow-hidden rounded-lg border border-slate-300 text-xs font-medium" role="group" aria-label="ขนาดหน้าต่าง">
                        <button type="button" @click="setModalSize('normal')" :class="modalSize === 'normal' ? 'bg-slate-800 text-white' : 'bg-white text-slate-600 hover:bg-slate-50'" class="px-2.5 py-1.5" title="ขนาดปกติ">ปกติ</button>
                        <button type="button" @click="setModalSize('wide')" :class="modalSize === 'wide' ? 'bg-slate-800 text-white' : 'bg-white text-slate-600 hover:bg-slate-50'" class="px-2.5 py-1.5 border-l border-slate-300" title="กว้าง">กว้าง</button>
                        <button type="button" @click="setModalSize('full')" :class="modalSize === 'full' ? 'bg-slate-800 text-white' : 'bg-white text-slate-600 hover:bg-slate-50'" class="px-2.5 py-1.5 border-l border-slate-300" title="เต็มจอ">เต็มจอ</button>
                    </div>
                    <button type="button" @click="toggleStar(cur)" :aria-pressed="isStarred(cur)" :title="isStarred(cur) ? 'เอาดาวออก' : 'ติดดาว (เก็บไว้ในเบราว์เซอร์นี้)'" class="rounded-lg p-1.5 hover:bg-slate-100" :class="isStarred(cur) ? 'text-amber-500' : 'text-slate-400 hover:text-slate-600'">
                        <svg class="w-[18px] h-[18px]" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round" :fill="isStarred(cur) ? 'currentColor' : 'none'"><path d="M12 3l2.7 5.6 6.1.9-4.4 4.3 1 6.1L12 17l-5.4 2.9 1-6.1L3.2 9.5l6.1-.9z"/></svg>
                    </button>
                    <button type="button" x-show="isPmo" @click="shiftProject(cur)" class="rounded-lg px-2.5 py-1.5 text-sm text-slate-600 hover:bg-slate-100" title="เลื่อนวันของโครงการ ตู้ และงานที่ยังไม่เสร็จพร้อมกัน">⇆ เลื่อนทั้งโครงการ</button>
                    <button type="button" x-show="isPmo" @click="duplicateProject(cur)" class="rounded-lg px-2.5 py-1.5 text-sm text-slate-600 hover:bg-slate-100" title="คัดลอกโครงการนี้ (พร้อมตู้ งาน checklist)">⧉ คัดลอก</button>
                    <button type="button" @click="openProjectDash(cur.id)" class="inline-flex items-center gap-1.5 rounded-lg px-2.5 py-1.5 text-sm text-slate-600 hover:bg-slate-100" title="แดชบอร์ดของโครงการนี้">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M4 20V10m6 10V4m6 16v-7m4 7H2"/></svg>แดชบอร์ด
                    </button>
                    <a :href="cur.pmoUrl" class="rounded-lg px-2.5 py-1.5 text-sm text-slate-600 hover:bg-slate-100" title="เปิดหน้าโครงการใน PMO">หน้าโครงการ ↗</a>
                    <button type="button" @click="closeModal()" class="text-2xl leading-none text-slate-400 hover:text-slate-600" aria-label="ปิด">&times;</button>
                </div>
            </div>

            <div class="flex min-h-0 flex-1 flex-col md:flex-row">
                {{-- ============ left: the project ============ --}}
                <div class="min-h-0 flex-1 overflow-y-auto p-5 space-y-4">
                    <div class="flex gap-2.5">
                        <div class="w-[126px] shrink-0 rounded-lg border border-slate-300 bg-white px-3 py-2 text-lg font-semibold text-slate-900" x-text="cur.so" title="เลขที่โครงการ"></div>
                        <template x-if="canEdit">
                            <input type="text" x-model="cur.title" @input.debounce.700ms="cur.title.trim() && editProject(cur, { title: cur.title.trim() })" maxlength="255" class="min-w-0 flex-1 rounded-lg border-slate-300 py-2 text-lg font-semibold text-slate-900" aria-label="ชื่อโครงการ">
                        </template>
                        <div x-show="!canEdit" class="min-w-0 flex-1 truncate rounded-lg border border-slate-300 px-3 py-2 text-lg font-semibold text-slate-900" x-text="cur.title"></div>
                    </div>

                    {{-- same box as the original page: status (wider) | priority, then the dates; light grey, hairline dividers --}}
                    <div class="grid grid-cols-1 overflow-hidden rounded-[10px] border border-slate-200 bg-slate-50 sm:grid-cols-[minmax(0,1.6fr)_minmax(0,1fr)]">
                        <div class="flex min-w-0 flex-col gap-1 px-3 py-2">
                            <span class="text-[10.5px] font-bold tracking-wide text-slate-400">สถานะโครงการ</span>
                            <span class="flex flex-wrap items-center gap-2">
                                <span class="inline-flex w-fit items-center rounded-[7px] px-3.5 py-[7px] text-[13px] font-semibold text-white" :style="'background-color:' + statusColor(cur)" x-text="statusOf(cur).name"></span>
                                <span x-show="!cur.manualStatus" class="text-[11px] text-slate-400">(สถานะ PMO)</span>
                                <select x-show="canEdit" class="h-8 w-44 rounded-[7px] border-slate-300 bg-white px-2 py-0 text-xs" :value="cur.manualStatus ? cur.statusId : ''" @change="setStatus(cur, $event.target.value)" title="สถานะของโครงการ (Admin / PM) — ไม่ได้คิดจากงานของแผนก; งานของแต่ละแผนกมีสถานะของตัวเองใน ส่งต่อแผนก">
                                    <option value="" :selected="!cur.manualStatus">ตามสถานะ PMO</option>
                                    <template x-for="s in statuses" :key="s.id"><option :value="s.id" x-text="s.name" :selected="cur.manualStatus && cur.statusId === s.id"></option></template>
                                </select>
                            </span>
                        </div>
                        <div class="flex min-w-0 flex-col gap-1 border-t border-slate-200 px-3 py-2 sm:border-l sm:border-t-0">
                            <span class="text-[10.5px] font-bold tracking-wide text-slate-400">ความสำคัญ</span>
                            <select x-model="cur.priority" @change="editProject(cur, { priority: cur.priority })" :disabled="!canEdit" :style="'color:' + priorities[cur.priority].color"
                                    class="h-8 w-full rounded-[7px] border-slate-300 bg-white px-2 py-1 text-[13px] font-semibold disabled:bg-slate-50">
                                <template x-for="(p, key) in priorities" :key="key"><option :value="key" :style="'color:' + p.color" x-text="'⚑ ' + p.label" :selected="key === cur.priority"></option></template>
                            </select>
                        </div>
                        <div class="flex min-w-0 flex-col gap-1 border-t border-slate-200 px-3 py-2">
                            <span class="text-[10.5px] font-bold tracking-wide text-slate-400">กำหนดเวลา</span>
                            <div class="flex flex-wrap items-center gap-2 text-xs">
                                <span class="font-bold text-slate-600">เริ่ม</span>
                                <span class="relative"><input type="text" placeholder="dd/mm/yyyy" x-init="dateField($el, () => cur && cur.startDate, (v) => editProject(cur, { startDate: v }), canEdit, 'w-[8.75rem]')"><svg class="pointer-events-none absolute right-2 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-400" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><rect x="3" y="5" width="18" height="16" rx="2.5"/><path stroke-linecap="round" d="M3 10h18M8 3v4m8-4v4"/></svg></span>
                                <span class="text-slate-400">→</span><span class="font-bold text-slate-600">จบ</span>
                                <span class="relative"><input type="text" placeholder="dd/mm/yyyy" x-init="dateField($el, () => cur && cur.date, (v) => v && editProject(cur, { date: v }), canEdit, 'w-[8.75rem]')"><svg class="pointer-events-none absolute right-2 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-400" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><rect x="3" y="5" width="18" height="16" rx="2.5"/><path stroke-linecap="round" d="M3 10h18M8 3v4m8-4v4"/></svg></span>
                            </div>
                        </div>
                        <div class="hidden border-slate-200 sm:block sm:border-l sm:border-t"></div>
                    </div>

                    {{-- tags + the people in charge (= the departments that hold work on the project) + which board --}}
                    <div class="space-y-2">
                        <div class="flex flex-wrap items-center gap-1.5">
                            <span class="w-24 shrink-0 text-xs font-semibold text-slate-600">แท็ก</span>
                            <template x-for="id in cur.labels" :key="id">
                                <span class="inline-flex items-center gap-1 rounded-full px-2.5 py-0.5 text-xs font-semibold text-white" :style="'background-color:' + labelOf(id).color">
                                    <span x-text="labelOf(id).name"></span>
                                    <button type="button" x-show="canEdit" @click="toggleLabel(cur, id)" class="leading-none opacity-80 hover:opacity-100" aria-label="เอาแท็กออก">×</button>
                                </span>
                            </template>
                            <select x-show="canEdit" class="h-7 w-28 rounded-full border-slate-300 py-0 text-xs" @change="$event.target.value === '+new' ? createLabel(cur) : toggleLabel(cur, $event.target.value); $event.target.value = ''">
                                <option value="">+ แท็ก</option>
                                <template x-for="l in labels.filter((x) => !cur.labels.includes(x.id))" :key="l.id"><option :value="l.id" x-text="l.name"></option></template>
                                <option x-show="isPmo" value="+new">+ สร้างแท็กใหม่…</option>
                            </select>
                        </div>
                        <div class="flex flex-wrap items-center gap-1.5">
                            <span class="w-24 shrink-0 text-xs font-semibold text-slate-600">ผู้รับผิดชอบ</span>
                            <template x-for="id in cur.assignees" :key="id">
                                <span class="inline-flex items-center gap-1.5 rounded-full border border-slate-200 bg-white px-2.5 py-0.5 text-xs font-semibold text-slate-700"><span class="h-2 w-2 rounded-full" :style="'background-color:' + deptOf(id).color"></span><span x-text="deptOf(id).name"></span></span>
                            </template>
                            <span x-show="cur.assignees.length === 0" class="text-xs text-slate-400">ยังไม่มีแผนก</span>
                        </div>
                        <div class="flex flex-wrap items-center gap-1.5">
                            <span class="w-24 shrink-0 text-xs font-semibold text-slate-600">ผู้รับผิดชอบรายคน</span>
                            <template x-for="id in cur.owners" :key="id">
                                <span class="inline-flex items-center gap-1 rounded-full bg-slate-100 py-0.5 pl-0.5 pr-2 text-xs font-semibold text-slate-700">
                                    <span class="flex h-5 w-5 items-center justify-center rounded-full text-[10px] text-white" :style="'background-color:' + avatarColor(personOf(id).name)" x-text="initial(personOf(id).name)"></span>
                                    <span x-text="personOf(id).name"></span>
                                    <button type="button" x-show="canEdit" @click="toggleOwner(cur, id)" class="text-slate-400 hover:text-red-600" aria-label="เอาออก">×</button>
                                </span>
                            </template>
                            <select x-show="canEdit" class="h-7 w-32 rounded-full border-slate-300 py-0 text-xs" @change="$event.target.value && toggleOwner(cur, $event.target.value); $event.target.value = ''">
                                <option value="">+ เพิ่มคน</option>
                                <template x-for="p in team().filter((x) => !cur.owners.includes(x.id))" :key="p.id"><option :value="p.id" x-text="p.name + (p.dept ? ' · ' + p.dept : '')"></option></template>
                            </select>
                        </div>
                        <div x-show="isPmo && boards.length > 1" class="flex flex-wrap items-center gap-1.5">
                            <span class="w-24 shrink-0 text-xs font-semibold text-slate-600">บอร์ด</span>
                            <select class="h-7 w-40 rounded-lg border-slate-300 py-0 text-xs" @change="setBoard(cur, $event.target.value)">
                                <template x-for="b in boards" :key="b.id"><option :value="b.id" x-text="b.name" :selected="b.id === (boardNo ? 'b' + boardNo : 'main')"></option></template>
                            </select>
                        </div>
                    </div>

                    {{-- what is still missing (Admin / PM): click to go and fill it in --}}
                    <div x-show="isPmo && warningList(cur).length" class="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-900">
                        <p class="mb-1 font-semibold">⚠ ข้อมูลที่ยังขาด</p>
                        <ul class="space-y-0.5">
                            <template x-for="w in warningList(cur)" :key="w.text">
                                <li class="flex items-center gap-2"><span>•</span><span x-text="w.text"></span>
                                    <button type="button" x-show="w.act === 'dispatch'" @click="openDispatch(cur, null, '')" class="font-semibold text-blue-700 hover:underline">จ่ายงาน</button>
                                    <button type="button" x-show="w.act === 'info'" @click="$refs.infoBox.open = true; $refs.infoBox.scrollIntoView({ block: 'center' })" class="font-semibold text-blue-700 hover:underline">กรอก</button>
                                </li>
                            </template>
                        </ul>
                    </div>

                    {{-- the project's own record (as on the project form) - Admin / PM edit it, everyone else reads it --}}
                    <details x-ref="infoBox" class="rounded-lg border border-slate-200 bg-white px-3 py-2">
                        <summary class="cursor-pointer text-xs font-semibold text-slate-600">ข้อมูลโครงการ <span class="font-normal text-slate-400" x-text="'· ' + cur.info.customer + (cur.info.poNo ? ' · PO ' + cur.info.poNo : '')"></span></summary>
                        <div class="mt-2 grid gap-2 text-xs sm:grid-cols-2">
                            <template x-for="f in [['customer', 'ลูกค้า'], ['customerPhone', 'เบอร์ติดต่อ'], ['poNo', 'เลขที่ใบสั่งซื้อ (PO)'], ['salesPerson', 'พนักงานขาย'], ['paymentTerms', 'เงื่อนไขการชำระเงิน']].filter((f) => f[0] !== 'customerPhone' || perms.contact)" :key="f[0]">
                                <label class="text-slate-500"><span x-text="f[1]"></span>
                                    <input type="text" :value="cur.info[f[0]]" :disabled="!isPmo" maxlength="255" @change="editInfo(cur, f[0], $event.target.value.trim())" class="mt-0.5 w-full rounded-lg border-slate-300 px-2 py-1 text-xs disabled:bg-slate-50"></label>
                            </template>
                            <template x-for="f in (perms.finance ? [['contractValue', 'มูลค่างาน (บาท)'], ['margin', 'Margin (บาท)']] : [])" :key="f[0]">
                                <label class="text-slate-500"><span x-text="f[1]"></span>
                                    <input type="text" inputmode="decimal" :value="fmtMoney(cur.info[f[0]])" :disabled="!isPmo" maxlength="20" placeholder="เช่น 1,250,000"
                                           @change="editMoney(cur, f[0], $event.target)" class="mt-0.5 w-full rounded-lg border-slate-300 px-2 py-1 text-xs disabled:bg-slate-50">
                                    <span x-show="f[0] === 'margin' && marginPct(cur.info)" class="text-[11px] text-slate-400" x-text="'คิดเป็น ' + marginPct(cur.info) + ' ของมูลค่างาน'"></span></label>
                            </template>
                            <label class="text-slate-500">ผู้จัดการโครงการ (PM)
                                <select :disabled="!isPmo" @change="editInfo(cur, 'managerId', $event.target.value)" class="mt-0.5 w-full rounded-lg border-slate-300 py-1 text-xs disabled:bg-slate-50">
                                    <option value="">—</option>
                                    <template x-for="m in staff.filter((p) => p.pmo)" :key="'pm' + m.id"><option :value="m.id" x-text="m.name" :selected="String(m.id) === String(cur.info.managerId)"></option></template>
                                </select></label>
                            <label class="text-slate-500">ประเภทงาน
                                <select :disabled="!isPmo" @change="editInfo(cur, 'jobTypeId', $event.target.value)" class="mt-0.5 w-full rounded-lg border-slate-300 py-1 text-xs disabled:bg-slate-50">
                                    <option value="">—</option>
                                    <template x-for="j in jobTypes" :key="'jt' + j.id"><option :value="j.id" x-text="j.name" :selected="String(j.id) === String(cur.info.jobTypeId)"></option></template>
                                </select></label>
                            <label class="text-slate-500">สถานะโครงการ (PMO)
                                <select :disabled="!isPmo" @change="editInfo(cur, 'pmoStatus', $event.target.value)" class="mt-0.5 w-full rounded-lg border-slate-300 py-1 text-xs disabled:bg-slate-50">
                                    <template x-for="(label, key) in pmoStatuses" :key="'ps' + key"><option :value="key" x-text="label" :selected="key === cur.info.pmoStatus"></option></template>
                                </select></label>
                        </div>
                    </details>

                    {{-- ภาพรวมของโครงการ (the original's project dashboard): checklist progress per department and overall --}}
                    <details class="rounded-lg border border-slate-200 bg-white px-3 py-2" x-show="cur.deptStages.length">
                        <summary class="cursor-pointer text-xs font-semibold text-slate-600">ภาพรวมโครงการ <span class="font-normal text-slate-400" x-text="'· checklist ' + progress(cur, null).done + '/' + progress(cur, null).total + ' (' + progress(cur, null).pct + '%)'"></span></summary>
                        <div class="mt-2 space-y-1.5">
                            <template x-for="s in cur.deptStages" :key="'ov' + s.deptId">
                                <div class="flex items-center gap-2 text-xs">
                                    <span class="inline-flex w-36 shrink-0 items-center gap-1.5 truncate rounded-full border px-2 py-0.5 font-semibold text-slate-800" :style="deptTag(s.deptId)">
                                        <span class="h-2 w-2 shrink-0 rounded-full" :style="'background-color:' + deptOf(s.deptId).color"></span><span class="truncate" x-text="deptOf(s.deptId).name"></span></span>
                                    <div class="h-2 flex-1 overflow-hidden rounded bg-slate-100"><div class="h-full rounded" :class="progress(cur, s.deptId).pct === 100 ? 'bg-emerald-500' : 'bg-blue-600'" :style="'width:' + progress(cur, s.deptId).pct + '%'"></div></div>
                                    <span class="w-24 shrink-0 text-right tabular-nums text-slate-500" x-text="progress(cur, s.deptId).done + '/' + progress(cur, s.deptId).total + ' · ' + stageInfo(s).label"></span>
                                    <span class="w-20 shrink-0 truncate rounded-full px-2 py-px text-center text-[11px] font-semibold" :style="'background-color:' + stageStatus(s).color + '26;color:' + stageStatus(s).color" x-text="stageStatus(s).name"></span>
                                </div>
                            </template>
                        </div>
                    </details>

                    {{-- ส่งต่อแผนก --}}
                    <div>
                        <p class="mb-1.5 text-xs font-semibold text-slate-600">ส่งต่อแผนก</p>
                        <div class="flex flex-wrap items-center gap-x-1 gap-y-1.5">
                            <template x-for="(s, i) in cur.deptStages" :key="s.deptId">
                                <span class="inline-flex items-center gap-1">
                                    <span x-show="i > 0" class="px-px text-[15px] leading-none text-slate-400">&rsaquo;</span>
                                    <button type="button" @click.stop="openStage(s.deptId, $event)" :data-stage-chip="s.deptId" :aria-expanded="stageDept === s.deptId"
                                            class="inline-flex items-center gap-1.5 whitespace-nowrap rounded-lg border px-2.5 py-[5px] text-[12.5px] hover:border-blue-500 hover:bg-blue-50/40"
                                            :class="[s.done ? 'border-emerald-200 bg-emerald-50' : 'border-slate-300 bg-white', stageDept === s.deptId ? 'border-blue-500 ring-1 ring-blue-500' : '']">
                                        <span class="h-2.5 w-2.5 shrink-0 rounded-full" :style="'background-color:' + deptOf(s.deptId).color"></span>
                                        <span x-show="deptOf(s.deptId).icon" x-text="deptOf(s.deptId).icon"></span>
                                        <span class="font-semibold" :class="s.done ? 'text-emerald-700' : 'text-slate-800'" x-text="deptOf(s.deptId).name"></span>
                                        <span :class="stageInfo(s).cls" x-text="stageInfo(s).label"></span>
                                        <span class="rounded-full px-1.5 text-[11px] font-semibold" :style="'background-color:' + stageStatus(s).color + '26;color:' + stageStatus(s).color" :title="'สถานะงานของแผนก' + (s.manualStatus ? ' (ตั้งโดย Admin / PM)' : ' (อัตโนมัติ)')" x-text="stageStatus(s).name + (s.manualStatus ? ' •' : '')"></span>
                                        <span x-show="s.done" class="font-bold text-emerald-500">✓</span>
                                        <span x-show="!s.done && progress(cur, s.deptId).total > 0" class="rounded-full bg-slate-100 px-1.5 text-[11px] text-slate-500 tabular-nums" x-text="progress(cur, s.deptId).pct + '%'"></span>
                                    </button>
                                </span>
                            </template>
                            <select x-show="isPmo && freeDepts(cur).length" class="h-8 w-36 rounded-lg border-dashed border-slate-300 py-0 text-xs font-semibold text-blue-600" @change="$event.target.value && openDispatch(cur, null, $event.target.value); $event.target.value = ''">
                                <option value="">+ เพิ่มแผนก</option>
                                <template x-for="d in freeDepts(cur)" :key="d.id"><option :value="d.id" x-text="d.name"></option></template>
                            </select>
                            <a x-show="isPmo" :href="urls.assign" target="_blank" class="inline-flex items-center rounded-lg border border-dashed border-slate-300 px-[11px] py-[5px] text-[12.5px] font-bold text-blue-600 hover:border-blue-500 hover:bg-blue-50/40" title="จ่ายงานให้แผนก (เปิดหน้าจ่ายงานของ PMO)">+</a>
                            <p x-show="cur.deptStages.length === 0" class="text-sm text-slate-400">ยังไม่ได้จ่ายงานให้แผนก</p>
                        </div>
                    </div>

                    {{-- ตู้ + checklist: same look as the original Planning page (borderless rows, ringed status dot, checklist pill, date field, progress bar under the name) --}}
                    <div>
                        <div class="mb-1.5 flex flex-wrap items-center gap-2">
                            <p class="text-xs font-semibold text-slate-600">ตู้ <span class="font-normal text-slate-400" x-text="'(' + cur.subtasks.length + ')'"></span>
                            <span class="font-normal text-slate-400" x-text="'(checklist: ' + (dept ? deptOf(dept).name : 'ทุกแผนก') + ')'"></span></p>
                            <button type="button" x-show="isPmo" @click="openCabDialog(cur)" class="rounded-lg border border-dashed border-slate-300 px-2 py-0.5 text-xs font-bold text-blue-600 hover:border-blue-500">+ เพิ่มตู้</button>
                            <button type="button" x-show="isPmo && freeCount(cur)" @click="openDispatch(cur, null, '')" class="rounded-lg bg-amber-100 px-2 py-0.5 text-xs font-bold text-amber-800 hover:bg-amber-200" x-text="'จ่ายงานที่ค้าง (' + freeCount(cur) + ')'" title="งานย่อยจากเทมเพลตที่ยังไม่ได้จ่ายให้แผนก"></button>
                        </div>
                        <div class="flex flex-col gap-1">
                            <template x-for="c in cur.subtasks" :key="c.id">
                                <div class="rounded-md px-1 py-1.5 hover:bg-slate-50">
                                    <div class="flex items-center gap-2">
                                        <button type="button" @click.stop="toggleCab(cur, c)" class="w-4 shrink-0 text-slate-400 transition-transform hover:text-slate-700" :class="cabOpen[c.id] ? 'rotate-90' : ''" :aria-expanded="!!cabOpen[c.id]" title="รายละเอียดตู้และงานทั้งหมด" aria-label="รายละเอียดตู้">▸</button>
                                        <button type="button" @click.stop="openStatusPop($event, cur, c)" class="h-[18px] w-[18px] shrink-0 rounded-full border-2 border-white" :style="'background-color:' + cabColor(c) + ';box-shadow:0 0 0 1.5px ' + cabColor(c)" :title="cabStatus(c).name + ' — คลิกเพื่อเปลี่ยนสถานะตู้'" :aria-label="'สถานะตู้: ' + cabStatus(c).name"></button>
                                        <div class="flex min-w-0 flex-1 items-center gap-2 text-left text-[13px]" :class="cabDone(c) ? 'line-through text-slate-400' : ''">
                                            <span class="min-w-[44px] flex-1 truncate" :class="cabDone(c) ? '' : 'text-slate-500'" x-text="c.mo"></span>
                                            <span class="flex-[4] truncate" :class="cabDone(c) ? '' : 'text-slate-800'" x-text="c.text"></span>
                                        </div>
                                        <button type="button" @click.stop="openChecklist(cur.id, c.id, $event)" class="inline-flex shrink-0 items-center gap-1 rounded-xl border bg-white px-2.5 py-[3px] text-[11px] font-semibold hover:bg-slate-100"
                                                :class="cabProgress(c, dept || null).total > 0 && cabProgress(c, dept || null).done === cabProgress(c, dept || null).total ? 'border-emerald-500 text-emerald-600' : 'border-slate-300 text-slate-500 hover:border-blue-500 hover:text-blue-600'" title="Checklist ของตู้นี้ (คลิกเพื่อเปิด)">
                                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="3" y="3" width="18" height="18" rx="3"/><path stroke-linecap="round" stroke-linejoin="round" d="M8 12l3 3 5-6"/></svg><span class="tabular-nums" x-text="cabProgress(c, dept || null).done + '/' + cabProgress(c, dept || null).total"></span>
                                        </button>
                                        <template x-for="l in (c.linked || [])" :key="'lk' + l.deptId">
                                            <button type="button" @click.stop="openChecklist(cur.id, c.id, $event)" class="shrink-0 rounded-xl border border-dashed px-2 py-[3px] text-[11px] font-semibold tabular-nums"
                                                    :class="l.total && l.done === l.total ? 'border-emerald-400 text-emerald-600' : 'border-slate-300 text-slate-500 hover:border-blue-500 hover:text-blue-600'"
                                                    :title="'Checklist ของ ' + deptOf(l.deptId).name + ' (ดูอย่างเดียว)'" x-text="'จาก' + deptOf(l.deptId).name + ' ' + l.done + '/' + l.total"></button>
                                        </template>
                                        <button type="button" x-show="isPmo && (c.free || []).length" @click.stop="openDispatch(cur, c.id, '')" class="shrink-0 rounded bg-amber-100 px-1.5 text-[11px] font-semibold text-amber-800 hover:bg-amber-200" :title="(c.free || []).length + ' งานย่อยยังไม่ได้จ่ายให้แผนก'" x-text="'จ่ายงาน ' + (c.free || []).length"></button>
                                        <span x-show="isPmo" class="flex shrink-0 items-center">
                                            <button type="button" @click.stop="renameCabinet(cur, c)" class="px-1 text-slate-400 hover:text-blue-600" title="แก้ชื่อ / เลข MO" aria-label="แก้ชื่อตู้">✎</button>
                                            <button type="button" @click.stop="deleteCabinet(cur, c)" class="px-1 text-slate-400 hover:text-red-600" title="ลบตู้" aria-label="ลบตู้">🗑</button>
                                        </span>
                                        <template x-if="canEdit">
                                            <span class="relative shrink-0" title="กำหนดส่งของตู้นี้ (ไม่ใส่ = ตามวันโปรเจค)">
                                                <input type="text" placeholder="dd/mm/yyyy" x-init="dateField($el, () => c.date, (v) => setCabinetDue(cur, c, v), true)">
                                                <svg class="pointer-events-none absolute right-2 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-400" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><rect x="3" y="5" width="18" height="16" rx="2.5"/><path stroke-linecap="round" d="M3 10h18M8 3v4m8-4v4"/></svg>
                                            </span>
                                        </template>
                                        <span x-show="!canEdit" class="relative shrink-0 w-[88px] rounded-lg border border-slate-200 px-2 py-1 text-[13px] text-slate-500 tabular-nums" x-text="c.date ? fmtBE(c.date) : 'dd/mm/yyyy'"></span>
                                    </div>
                                    <div class="mt-1 ml-[26px] mr-0.5 h-[5px] overflow-hidden rounded-[3px] bg-slate-100" title="ความคืบหน้า checklist">
                                        <div class="h-full rounded-[3px] transition-all" :class="cabProgress(c, dept || null).pct === 100 ? 'bg-emerald-500' : 'bg-blue-600'" :style="'width:' + cabProgress(c, dept || null).pct + '%'"></div>
                                    </div>
                                    {{-- expanded: the cabinet's record + every sub task (dates, the person doing it, remark) --}}
                                    <template x-if="cabOpen[c.id]">
                                        <div class="ml-[26px] mt-2 space-y-2 rounded-lg border border-slate-200 bg-white p-2.5 text-xs" @click.stop>
                                            <p x-show="cabOpen[c.id].loading" class="text-slate-400">กำลังโหลด...</p>
                                            <p x-show="cabOpen[c.id].error" class="text-red-500">โหลดไม่สำเร็จ</p>
                                            <template x-if="cabOpen[c.id].cabinet">
                                                <div class="grid gap-2 sm:grid-cols-3">
                                                    <template x-for="f in [['startDate', 'วันเริ่ม'], ['dueDate', 'วันครบกำหนด'], ['expectedDate', 'คาดว่าเสร็จ']]" :key="f[0]">
                                                        <label class="text-slate-500"><span x-text="f[1]"></span><input type="date" :value="cabOpen[c.id].cabinet[f[0]] || ''" :disabled="!isPmo" @change="editCabInfo(cur, c, f[0], $event.target.value || null)" class="mt-0.5 w-full rounded-lg border-slate-300 py-1 text-xs disabled:bg-slate-50"></label>
                                                    </template>
                                                    <label class="text-slate-500">จำนวน<input type="number" min="1" :value="cabOpen[c.id].cabinet.quantity" :disabled="!isPmo" @change="editCabInfo(cur, c, 'quantity', $event.target.value)" class="mt-0.5 w-full rounded-lg border-slate-300 py-1 text-xs disabled:bg-slate-50"></label>
                                                    <label class="text-slate-500">ประเภทตู้<input type="text" maxlength="100" :value="cabOpen[c.id].cabinet.type" :disabled="!isPmo" @change="editCabInfo(cur, c, 'type', $event.target.value)" class="mt-0.5 w-full rounded-lg border-slate-300 py-1 text-xs disabled:bg-slate-50"></label>
                                                    <label class="text-slate-500">ขนาด<input type="text" maxlength="100" :value="cabOpen[c.id].cabinet.size" :disabled="!isPmo" @change="editCabInfo(cur, c, 'size', $event.target.value)" class="mt-0.5 w-full rounded-lg border-slate-300 py-1 text-xs disabled:bg-slate-50"></label>
                                                    <label class="text-slate-500 sm:col-span-3">รายละเอียด<input type="text" maxlength="5000" :value="cabOpen[c.id].cabinet.description" :disabled="!isPmo" @change="editCabInfo(cur, c, 'description', $event.target.value)" class="mt-0.5 w-full rounded-lg border-slate-300 py-1 text-xs disabled:bg-slate-50"></label>
                                                </div>
                                            </template>
                                            <div x-show="cabOpen[c.id].subtasks.length" class="overflow-x-auto">
                                                <table class="w-full min-w-[640px] text-left">
                                                    <thead class="text-[10.5px] text-slate-400"><tr><th class="py-1 font-medium">งาน</th><th class="font-medium">แผนก / สถานะ</th><th class="font-medium">เริ่ม</th><th class="font-medium">ส่ง</th><th class="font-medium">ผู้ทำ</th><th class="font-medium">หมายเหตุ</th></tr></thead>
                                                    <tbody class="divide-y divide-slate-50">
                                                        <template x-for="s in cabOpen[c.id].subtasks" :key="s.id">
                                                            <tr :class="s.state === 'COMPLETED' ? 'text-slate-400' : ''">
                                                                <td class="py-1 pr-2"><span class="text-slate-400" x-text="s.task + ' › '"></span><span class="font-medium" x-text="s.name"></span> <span class="text-[10.5px] text-slate-400" x-text="s.checklists ? '☑ ' + s.checklistsDone + '/' + s.checklists : ''"></span></td>
                                                                <td class="whitespace-nowrap pr-2"><span x-text="s.deptId ? deptOf(s.deptId).name : '—'"></span> · <span x-text="stateLabel(s.state)"></span></td>
                                                                <td class="pr-1"><input type="date" :value="s.startDate || ''" :disabled="!s.editable || s.state === 'COMPLETED'" @change="editSubInfo(cur, s, 'startDate', $event.target.value || null)" class="w-[8.2rem] rounded border-slate-300 py-0.5 text-xs disabled:bg-slate-50"></td>
                                                                <td class="pr-1"><input type="date" :value="s.dueDate || ''" :disabled="!s.editable || s.state === 'COMPLETED'" @change="editSubInfo(cur, s, 'dueDate', $event.target.value || null)" class="w-[8.2rem] rounded border-slate-300 py-0.5 text-xs disabled:bg-slate-50" :class="!s.dueDate && s.deptId && s.state !== 'COMPLETED' ? 'border-amber-400' : ''"></td>
                                                                <td class="pr-1"><select :disabled="!s.editable" @change="editSubInfo(cur, s, 'ownerId', $event.target.value)" class="w-32 rounded border-slate-300 py-0.5 text-xs disabled:bg-slate-50">
                                                                    <option value="">—</option>
                                                                    <template x-for="p in staffFor(s.deptId)" :key="s.id + 'o' + p.id"><option :value="p.id" x-text="p.name" :selected="String(p.id) === String(s.ownerId)"></option></template>
                                                                </select></td>
                                                                <td><input type="text" maxlength="2000" :value="s.remark" :disabled="!s.editable" @change="editSubInfo(cur, s, 'remark', $event.target.value)" class="w-full min-w-[8rem] rounded border-slate-300 py-0.5 text-xs disabled:bg-slate-50"></td>
                                                            </tr>
                                                        </template>
                                                    </tbody>
                                                </table>
                                            </div>
                                            <a x-show="cabOpen[c.id].cabinet" :href="cabOpen[c.id].cabinet ? cabOpen[c.id].cabinet.url : '#'" class="inline-block text-[11px] text-blue-600 hover:underline">เปิดหน้าตู้ใน PMO →</a>
                                        </div>
                                    </template>
                                </div>
                            </template>
                            <p x-show="cur.subtasks.length === 0" class="px-3 py-6 text-center text-sm text-slate-400">ยังไม่มีตู้ที่จ่ายงานให้แผนก</p>
                        </div>
                    </div>

                    <details class="border-t border-slate-200 pt-2.5" x-data="{ o: !!cur.description }" :open="o" @toggle="o = $el.open">
                        <summary class="flex cursor-pointer list-none items-center gap-1.5 py-0.5 text-[12.5px] font-semibold text-slate-500 [&::-webkit-details-marker]:hidden">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M14 3H7a2 2 0 00-2 2v14a2 2 0 002 2h10a2 2 0 002-2V8zM14 3v5h5M9 13h6M9 17h6"/></svg>
                            <span>รายละเอียด</span><span class="text-[11px]" x-text="o ? '▾' : '▸'"></span>
                        </summary>
                        <div class="mt-2.5">
                            <label class="mb-1 block text-xs font-semibold text-slate-600">รายละเอียด</label>
                            <textarea x-model="cur.description" @input.debounce.800ms="editProject(cur, { description: cur.description })" :readonly="!canEdit" rows="3" maxlength="5000" placeholder="หมายเหตุ / ขอบเขตงาน..." class="min-h-[64px] w-full rounded-lg border-slate-300 text-sm read-only:bg-slate-50"></textarea>
                        </div>
                    </details>
                </div>

                {{-- ============ right: Activity ============ --}}
                <aside class="flex min-h-[18rem] md:min-h-0 shrink-0 flex-col border-t md:border-t-0 md:border-l border-slate-200 bg-slate-50"
                       :class="modalSize === 'normal' ? 'md:w-80' : (modalSize === 'wide' ? 'md:w-96' : 'md:w-[28rem]')">
                    <div class="flex items-center justify-between gap-2 border-b border-slate-200 px-4 py-3 text-[13px] font-bold">
                        <div class="flex items-center gap-1" role="tablist">
                            <button type="button" role="tab" @click="feedTab = 'activity'" :aria-selected="feedTab === 'activity'" class="rounded-full px-2.5 py-1 text-[13px] font-bold" :class="feedTab === 'activity' ? 'bg-slate-800 text-white' : 'text-slate-500 hover:bg-slate-200'">Activity</button>
                            <button type="button" role="tab" x-show="cur.logUrl" @click="openLog()" :aria-selected="feedTab === 'log'" class="rounded-full px-2.5 py-1 text-[13px] font-bold" :class="feedTab === 'log' ? 'bg-slate-800 text-white' : 'text-slate-500 hover:bg-slate-200'" title="บันทึกการเปลี่ยนแปลงทั้งหมดของโครงการ">การดำเนินการ</button>
                        </div>
                    </div>
                    <div x-show="feedTab === 'activity'" x-ref="feedBox" x-init="$nextTick(() => $el.scrollTop = $el.scrollHeight)" class="flex min-h-0 flex-1 flex-col gap-3 overflow-y-auto px-3.5 py-3">
                        <p x-show="cur.comments.length === 0" class="px-2 py-6 text-center text-xs leading-[1.7] text-slate-400">ยังไม่มีความเคลื่อนไหว<br>เขียนอัพเดตหรือความคิดเห็นได้ด้านล่าง</p>
                        <template x-for="c in cur.comments" :key="c.id">
                            <div>
                                <div class="flex gap-2">
                                    <img x-show="c.avatar" :src="c.avatar" alt="" class="h-[26px] w-[26px] shrink-0 rounded-full object-cover">
                                    <span x-show="!c.avatar" class="flex h-[26px] w-[26px] shrink-0 items-center justify-center rounded-full text-xs font-bold text-white" :style="'background-color:' + avatarColor(c.author)" x-text="initial(c.author)"></span>
                                    <div class="min-w-0 flex-1">
                                        <div class="mb-0.5 flex items-baseline gap-1.5"><span class="text-[12.5px] font-semibold text-slate-900" x-text="c.author"></span><span class="text-[11px] text-slate-400" x-text="fmtDateTime(c.at)"></span></div>
                                        <p x-show="c.replyTo && commentById(c.replyTo)" class="mb-1 truncate border-l-2 border-slate-300 pl-2 text-[11px] text-slate-500" x-text="'↩ ' + (commentById(c.replyTo) || {}).author + ': ' + (commentById(c.replyTo) || {}).text"></p>
                                        <div class="flex flex-wrap gap-1.5 empty:hidden" x-show="(c.files || []).length">
                                            <template x-for="f in (c.files || [])" :key="f.name + f.url">
                                                <a :href="f.url || '#'" target="_blank" rel="noopener" @click="openFile(f, $event)" class="inline-flex max-w-full items-center gap-1 overflow-hidden rounded-lg border border-slate-200 bg-white text-[11px] text-slate-600 hover:border-blue-400">
                                                    <img x-show="f.image && f.url" :src="f.url" class="h-14 w-14 object-cover" alt="">
                                                    <span class="truncate px-2 py-1" x-text="'📎 ' + f.name + (f.size ? ' · ' + f.size : '')"></span>
                                                </a>
                                            </template>
                                        </div>
                                        <p class="whitespace-pre-wrap break-words text-[13px] leading-normal text-slate-900"><template x-for="(part, i) in commentParts(c.text)" :key="i"><span :class="part.m ? 'rounded bg-indigo-50 px-[3px] font-semibold text-blue-700' : ''" x-text="part.t"></span></template></p>
                                        <div x-show="c.alert" class="mt-1.5 rounded-lg border border-amber-200 bg-amber-50 px-2 py-1.5 text-[11.5px] text-amber-800">
                                            <span class="font-semibold" x-text="'🔔 แจ้งเตือนถึง ' + (c.alert ? deptOf(c.alert.deptId).name : '')"></span>
                                            <span x-text="c.alert && c.alert.acks.length ? ' · รับทราบแล้ว: ' + c.alert.acks.map((a) => a.name).join(', ') : ' · ยังไม่มีใครรับทราบ'"></span>
                                            <button type="button" x-show="c.alert && c.alert.forMe && !c.alert.acked" @click="ackAlert(c)" class="ml-1 rounded bg-amber-500 px-2 py-0.5 font-semibold text-white hover:bg-amber-600">รับทราบ</button>
                                        </div>
                                        <div class="mt-0.5 flex gap-3 text-[11px] text-slate-400">
                                            <button type="button" @click="startReply(c)" class="hover:text-blue-600">ตอบกลับ</button>
                                            <button type="button" x-show="c.mine" @click="editComment(c)" class="hover:text-blue-600">แก้ไข</button>
                                            <button type="button" x-show="c.mine || isPmo" @click="deleteComment(c)" class="hover:text-red-600">ลบ</button>
                                            <span x-show="c.edited">(แก้ไขแล้ว)</span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </template>
                    </div>
                    {{-- Change log = the project page's Activity Log tree (Cabinet > Task > Sub Task > Checklist > activity), read-only, PMO roles only --}}
                    <div x-show="feedTab === 'log'" x-cloak class="flex min-h-0 flex-1 flex-col overflow-hidden">
                        <div class="flex items-center gap-2 border-b border-slate-200 px-3.5 py-1.5 text-[11px] text-slate-400">
                            <span class="flex-1" x-text="logTree.total ? logTree.total + ' กิจกรรม · ล่าสุดก่อน' : ''"></span>
                            <button type="button" @click="logSetAll(true)" class="text-blue-600 hover:underline">ขยายทั้งหมด</button><span>|</span>
                            <button type="button" @click="logSetAll(false)" class="text-blue-600 hover:underline">ยุบทั้งหมด</button>
                        </div>
                        <div class="min-h-0 flex-1 overflow-y-auto bg-white" style="--ind: 16px">
                            <p x-show="logTree.loading" class="px-4 py-6 text-center text-xs text-slate-400">กำลังโหลด...</p>
                            <p x-show="logTree.error" x-text="logTree.error" class="px-4 py-6 text-center text-xs text-red-500"></p>
                            <p x-show="!logTree.loading && !logTree.error && logTree.nodes.length === 0" class="px-4 py-6 text-center text-xs text-slate-400">ยังไม่มีประวัติการเปลี่ยนแปลง</p>
                            <template x-for="r in logRows()" :key="r.id">
                                <div class="relative border-b border-slate-50">
                                    <template x-for="d in r.depth" :key="d"><span class="absolute bottom-0 top-0 border-l border-slate-200" :style="'left:calc(var(--ind) * ' + (d - 1) + ' + 17px)'"></span></template>
                                    <template x-if="r.node">
                                        <button type="button" @click="logToggle(r.node.key)" class="relative flex min-h-[34px] w-full items-center gap-1.5 pr-3 text-left hover:bg-slate-50" :class="r.node.type === 'cabinet' ? 'bg-slate-50/60' : ''" :style="'padding-left: calc(var(--ind) * ' + r.depth + ' + 14px)'">
                                            <svg class="h-3 w-3 shrink-0 text-slate-400 transition-transform" :class="logTree.open[r.node.key] ? 'rotate-90' : ''" viewBox="0 0 20 20" fill="currentColor"><path d="M7 4l6 6-6 6V4z"/></svg>
                                            <span class="shrink-0 text-[10.5px] text-slate-400" x-text="r.node.word || logWords[r.node.type] || ''"></span>
                                            <span class="min-w-0 flex-1 truncate text-[12.5px]" :class="r.node.type === 'cabinet' ? 'font-semibold text-slate-800' : 'text-slate-700'" x-text="r.node.label"></span>
                                            <span class="shrink-0 rounded-full bg-slate-100 px-1.5 text-[11px] font-medium tabular-nums text-slate-600" x-text="r.node.count"></span>
                                        </button>
                                    </template>
                                    <template x-if="r.act">
                                        <div class="relative py-1.5 pr-3 text-xs" :style="'padding-left: calc(var(--ind) * ' + r.depth + ' + 14px)'">
                                            <div class="flex items-start gap-1.5">
                                                <span class="mt-1.5 h-1.5 w-1.5 shrink-0 rounded-full" :class="logDot(r.act.icon)"></span>
                                                <span class="min-w-0 flex-1 text-slate-800" x-text="r.act.title"></span>
                                            </div>
                                            <div class="ml-3 mt-0.5 text-[11px] text-slate-400"><span x-text="r.act.actor"></span> · <span x-text="r.act.at"></span></div>
                                            <template x-for="c in r.act.changes" :key="c.label">
                                                <p class="ml-3 break-words text-[11px] text-slate-500"><span class="text-slate-400" x-text="c.label + ': '"></span><span x-text="c.old"></span> <span class="text-slate-400">→</span> <span class="font-medium text-slate-800" x-text="c.new"></span></p>
                                            </template>
                                            <p x-show="r.act.detail" class="ml-3 break-words text-[11px] text-slate-500" x-text="r.act.detail"></p>
                                        </div>
                                    </template>
                                </div>
                            </template>
                        </div>
                    </div>
                    <div x-show="feedTab === 'activity' && perms.comments" class="border-t border-slate-200 bg-white px-3 py-2.5">
                        <div class="relative">
                            {{-- @ picker: type "@" and a name; ↑↓ + Enter/Tab picks, Esc closes. The tagged people get a notice (bell + popup). --}}
                            <div x-show="mentionOpen && mentionList().length" x-cloak class="absolute bottom-full left-0 z-10 mb-1 max-h-48 w-full overflow-y-auto rounded-[10px] border border-slate-200 bg-white p-1 shadow-lg" role="listbox" aria-label="แท็กคน">
                                <template x-for="(p, i) in mentionList()" :key="p.id">
                                    <button type="button" @mousedown.prevent="pickMention(p)" @mouseenter="mentionIdx = i" role="option" :aria-selected="i === mentionIdx"
                                            class="flex w-full items-center gap-2 rounded-[7px] px-2 py-1.5 text-left text-[13px]" :class="i === mentionIdx ? 'bg-indigo-50' : ''">
                                        <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full text-[11px] font-bold text-white" :style="'background-color:' + avatarColor(p.name)" x-text="initial(p.name)"></span>
                                        <span class="min-w-0 flex-1 truncate font-semibold" x-text="p.name"></span><span class="shrink-0 text-[11px] text-slate-400" x-text="p.dept || ''"></span>
                                    </button>
                                </template>
                            </div>
                            <textarea x-ref="commentBox" x-model="comment" rows="2" maxlength="2000" placeholder="เขียนอัพเดต / ความคิดเห็น...  (@ แท็กคน · Enter ส่ง)"
                                      @input="onCommentInput($event)" @click="onCommentInput($event)" @keydown="onCommentKey($event)" @blur="setTimeout(() => mentionOpen = false, 150)"
                                      class="min-h-[40px] w-full resize-none rounded-lg border-slate-300 px-2.5 py-2 text-[13px]"></textarea>
                        </div>
                        <div x-show="replyTo" class="mt-2 flex items-center gap-2 rounded-lg bg-slate-100 px-2 py-1 text-[11px] text-slate-600">
                            <span class="min-w-0 flex-1 truncate" x-text="replyTo ? '↩ ตอบกลับ ' + replyTo.author + ': ' + replyTo.text : ''"></span>
                            <button type="button" @click="replyTo = null" class="text-slate-400 hover:text-red-600" aria-label="ยกเลิกการตอบกลับ">×</button>
                        </div>
                        {{-- photos / files picked for the next comment (up to 5; same types and size limit as the project page) --}}
                        <div x-show="files.length" class="mt-2 flex flex-wrap gap-1.5">
                            <template x-for="(f, i) in files" :key="i">
                                <span class="inline-flex max-w-[12rem] items-center gap-1 rounded-lg bg-slate-100 px-2 py-0.5 text-[11px] text-slate-600">
                                    <span class="truncate" x-text="'📎 ' + f.name"></span>
                                    <button type="button" @click="files.splice(i, 1)" class="text-slate-400 hover:text-red-600" aria-label="เอาไฟล์ออก">×</button>
                                </span>
                            </template>
                        </div>
                        <div class="mt-2 flex items-center gap-2">
                            <input type="file" x-ref="fileInput" multiple class="hidden" accept=".pdf,.doc,.docx,.xls,.xlsx,.jpg,.jpeg,.png,.dwg,.dxf" @change="pickFiles($event)">
                            <button type="button" @click="$refs.fileInput.click()" :disabled="files.length >= 5" class="h-8 shrink-0 rounded-lg border border-slate-300 px-2 text-slate-500 hover:bg-slate-50 disabled:opacity-40" title="แนบรูป / ไฟล์ (สูงสุด 5 ไฟล์ ไฟล์ละ 20MB)" aria-label="แนบไฟล์">📎</button>
                            <select x-show="isPmo" x-model="alertDept" class="h-8 w-40 shrink-0 rounded-lg border-slate-300 py-0 text-xs" title="ส่งเป็นการแจ้งเตือนไปแผนก (เด้ง popup)">
                                <option value="">ไม่แจ้งเตือน</option>
                                <template x-for="d in departments" :key="d.id"><option :value="d.id" x-text="'แจ้งเตือน → ' + d.name"></option></template>
                            </select>
                            <div class="flex-1"></div>
                            <button type="button" @click="postComment()" :disabled="posting || (!comment.trim() && !files.length)" class="h-8 rounded-lg bg-blue-600 px-3.5 text-[13px] font-semibold text-white hover:bg-blue-700 disabled:opacity-50">ส่ง</button>
                        </div>
                    </div>
                </aside>
            </div>

            <div class="flex shrink-0 items-center gap-3 border-t border-slate-100 px-5 py-3">
                <button type="button" x-show="isPmo" @click="deleteProject()" class="rounded-lg border border-red-300 px-3 py-1.5 text-sm font-medium text-red-700 hover:bg-red-50">ลบโครงการ</button>
                <div class="flex-1"></div>
                <span class="text-xs text-slate-400" x-text="saving ? 'กำลังบันทึก...' : (savedAt ? '✓ บันทึกอัตโนมัติ ' + savedAt : '✓ บันทึกอัตโนมัติ')"></span>
                <button type="button" @click="closeModal()" class="rounded-lg border border-slate-300 px-4 py-1.5 text-sm font-medium text-slate-700 hover:bg-slate-50">ปิด</button>
            </div>
        </div>

        {{-- department popover: its due date, where its Sub Tasks are in the workflow, and the buttons the user may press --}}
        <template x-if="stageDept && stageOf(cur, stageDept)">
            <div class="fixed z-50 w-72 rounded-xl border border-slate-200 bg-white p-3 shadow-xl space-y-2.5" :style="'top:' + stagePos.top + 'px;left:' + stagePos.left + 'px'" @click.stop>
                <p class="flex items-center gap-2 text-sm font-semibold text-slate-900"><span class="w-2.5 h-2.5 rounded-full" :style="'background-color:' + deptOf(stageDept).color"></span><span x-text="deptOf(stageDept).name"></span>
                    <span x-show="stageOf(cur, stageDept).done" class="rounded-full bg-emerald-100 px-2 text-[11px] font-semibold text-emerald-700">เสร็จแล้ว</span></p>
                <div class="flex items-center gap-2 text-xs">
                    <span class="w-16 shrink-0 font-semibold text-slate-600">สถานะงาน</span>
                    <span class="rounded-full px-2 py-0.5 text-[11px] font-semibold text-white" :style="'background-color:' + stageStatus(stageOf(cur, stageDept)).color" x-text="stageStatus(stageOf(cur, stageDept)).name"></span>
                    <span x-show="!stageOf(cur, stageDept).manualStatus" class="text-[11px] text-slate-400">อัตโนมัติ</span>
                </div>
                <select x-show="canEdit" class="h-8 w-full rounded-lg border-slate-300 py-0 text-xs" @change="setStageStatus(cur, stageDept, $event.target.value)" title="ตั้งสถานะงานของแผนกนี้เอง (Admin / PM) หรือให้ระบบคิดจากงานของแผนก">
                    <option value="" :selected="!stageOf(cur, stageDept).manualStatus">อัตโนมัติ (จากงานของแผนก)</option>
                    <template x-for="st in statuses" :key="'ss' + st.id"><option :value="st.id" x-text="st.name" :selected="stageOf(cur, stageDept).manualStatus && stageOf(cur, stageDept).statusId === st.id"></option></template>
                </select>
                <div class="space-y-1 text-xs text-slate-500">
                    <p class="font-semibold text-slate-600">ช่วงงานของแผนกในโครงการนี้ <span class="font-normal text-slate-400">(ทุกงานของแผนก)</span></p>
                    <div class="flex items-center gap-2"><span class="w-7">เริ่ม</span>
                        <input type="text" placeholder="dd/mm/yyyy" x-init="dateField($el, () => { const s = stageOf(cur, stageDept); return s && s.start; }, (v) => { const s = stageOf(cur, stageDept); setStageDates(cur, stageDept, v, s && s.end); }, canEdit)">
                    </div>
                    <div class="flex items-center gap-2"><span class="w-7">ส่ง</span>
                        <input type="text" placeholder="dd/mm/yyyy" x-init="dateField($el, () => { const s = stageOf(cur, stageDept); return s && s.end; }, (v) => { const s = stageOf(cur, stageDept); setStageDates(cur, stageDept, s && s.start, v); }, canEdit)">
                    </div>
                    <p x-show="stageOf(cur, stageDept) && stageOf(cur, stageDept).undated" class="text-amber-700" x-text="'📅 ยังไม่มีวันส่ง ' + (stageOf(cur, stageDept) || {}).undated + ' งาน'"></p>
                </div>
                {{-- the extra fields (Settings > ฟิลด์เพิ่มเติม) this department fills in for the project --}}
                <template x-for="f in (deptOf(stageDept).fields || [])" :key="f.id">
                    <label class="flex items-center gap-2 text-xs text-slate-500">
                        <span class="w-24 shrink-0 truncate" x-text="f.name" :title="f.name"></span>
                        <template x-if="!f.type">
                            <select class="h-8 min-w-0 flex-1 rounded-lg border-slate-300 py-0 text-xs" :disabled="!canEditStage(stageDept)" @change="setField(cur, stageDept, f, $event.target.value)">
                                <option value="" :selected="!fieldValue(cur, stageDept, f)">— ไม่ระบุ —</option>
                                <template x-for="o in fieldOptions(f)" :key="o.id"><option :value="o.id" x-text="o.text" :selected="fieldValue(cur, stageDept, f) === o.id"></option></template>
                            </select>
                        </template>
                        <template x-if="f.type">
                            <span class="flex min-w-0 flex-1 items-center gap-1">
                                <input :type="f.type === 'link' ? 'url' : 'text'" maxlength="500" class="h-8 min-w-0 flex-1 rounded-lg border-slate-300 py-0 text-xs" :value="fieldValue(cur, stageDept, f)" :disabled="!canEditStage(stageDept)"
                                       :placeholder="f.type === 'link' ? 'https://…' : 'พิมพ์…'" @change="setField(cur, stageDept, f, $event.target.value)">
                                <a x-show="f.type === 'link' && /^https?:\/\//i.test(fieldValue(cur, stageDept, f))" :href="fieldValue(cur, stageDept, f)" target="_blank" rel="noopener noreferrer" class="text-blue-600" title="เปิดลิงก์">↗</a>
                            </span>
                        </template>
                    </label>
                </template>
                <template x-for="p in [stageOf(cur, stageDept).pmo]" :key="'pmo'">
                    <div class="space-y-2">
                        <p class="text-xs text-slate-600">งานของแผนก: รอรับ <b x-text="p.waiting"></b> · รับแล้ว <b x-text="p.accepted"></b> · กำลังทำ <b x-text="p.working"></b> · เสร็จ <b x-text="p.done"></b></p>
                        <div class="flex flex-wrap gap-2" x-show="p.canAccept || p.canStart || p.canComplete">
                            <button type="button" x-show="p.canAccept" @click="step(cur, stageDept, 'accept')" class="rounded-lg bg-blue-600 px-3 py-1 text-xs font-semibold text-white hover:bg-blue-700">รับงาน</button>
                            <button type="button" x-show="p.canStart" @click="step(cur, stageDept, 'start')" class="rounded-lg bg-blue-600 px-3 py-1 text-xs font-semibold text-white hover:bg-blue-700">เริ่มงาน</button>
                            <button type="button" x-show="p.canComplete" @click="step(cur, stageDept, 'complete')" class="rounded-lg bg-emerald-600 px-3 py-1 text-xs font-semibold text-white hover:bg-emerald-700">เสร็จงาน</button>
                        </div>
                        <p x-show="!(p.canAccept || p.canStart || p.canComplete)" class="text-[11px] text-slate-400">รับ / เริ่ม / ปิดงาน ทำได้เฉพาะสมาชิกของแผนกนี้</p>
                    </div>
                </template>
                <button type="button" x-show="isPmo && stageOf(cur, stageDept).pmo.accepted + stageOf(cur, stageDept).pmo.working + stageOf(cur, stageDept).pmo.done === 0" @click="removeStage(cur, stageDept)" class="text-xs font-medium text-red-600 hover:underline">เอาแผนกนี้ออกจากโครงการ</button>
                <p class="text-xs text-slate-500" x-text="'Checklist ' + progress(cur, stageDept).done + '/' + progress(cur, stageDept).total + ' (' + progress(cur, stageDept).pct + '%)'"></p>
            </div>
        </template>
    </div>
</template>
