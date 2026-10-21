{{--
    Checklist popover (the original page's): opens under the checklist pill of a cabinet - in the project modal or in the
    left panel. Header + progress, then Task › Sub Task (department, workflow state, progress, "+") › items (tick = one operation)
    ✎ rename, 📝 remark, ↑↓ order and ✕ delete an item. Every change is checked by PMO
    (only the department that accepted the work, or PMO roles, may tick / add / delete) and bounces back if refused.
--}}
<template x-if="cl && clCab">
    <div class="fixed z-[60] flex flex-col rounded-[10px] border border-slate-200 bg-white p-3 shadow-2xl"
         :style="'top:' + cl.top + 'px;left:' + cl.left + 'px;width:' + cl.w + 'px;max-height:' + cl.h + 'px'" @click.outside="closeChecklist()" role="dialog" aria-label="Checklist">
        <div class="mb-2 flex items-center justify-between">
            <span class="truncate text-[13px] font-bold text-slate-900" x-text="clTitle()"></span>
            <span class="ml-2 flex shrink-0 items-center gap-2">
                <span class="text-xs font-semibold text-slate-500 tabular-nums" x-text="clProgress().done + '/' + clProgress().total + ' · ' + clProgress().pct + '%'"></span>
                <button type="button" @click="clToggleBig()" class="rounded-md border border-slate-200 px-1.5 py-0.5 text-xs text-slate-500 hover:bg-slate-100" :title="cl.big ? 'ย่อ' : 'ขยายเต็มจอ'" :aria-label="cl.big ? 'ย่อหน้าต่าง' : 'ขยายหน้าต่าง'" x-text="cl.big ? '⤡' : '⤢'"></button>
                <button type="button" @click="closeChecklist()" class="px-1 text-lg leading-none text-slate-400 hover:text-slate-700" aria-label="ปิด">&times;</button>
            </span>
        </div>
        <p class="-mt-1 mb-2 flex items-center gap-2 text-[11px] text-slate-400"><span class="min-w-0 flex-1 truncate" x-text="clCab.mo + ' · ' + clCab.text"></span>
            <a :href="cabinetPage(clCab)" class="shrink-0 font-medium text-blue-600 hover:underline" title="หน้าตู้: แก้แบบตาราง เลือกหลายรายการ ย้าย / คัดลอก วางจาก Excel">แก้แบบตาราง ↗</a></p>
        <div class="mb-2.5 h-1.5 overflow-hidden rounded-[3px] bg-slate-100"><div class="h-full rounded-[3px] transition-all" :class="clProgress().pct === 100 ? 'bg-emerald-500' : 'bg-blue-600'" :style="'width:' + clProgress().pct + '%'"></div></div>

        {{-- a Sub Task that compares with another (template pairs): each item with the other list's item of the same name beside it --}}
        <div class="flex min-h-0 flex-1 flex-col gap-1 overflow-y-auto">
          <div class="flex min-w-0 flex-col gap-1">
            {{-- Settings > การส่งต่องาน: the date this department gives its items for the next one, for the whole cabinet at once --}}
            <template x-for="d in clDateDepts()" :key="'cd' + d">
                <div class="flex items-center gap-1.5 rounded-md bg-amber-50 px-1.5 py-1 text-[11px] text-amber-900">
                    <span class="min-w-0 flex-1 truncate" x-text="'ใส่' + deptOf(d).dateLabel + 'ทั้งตู้ · ' + deptOf(d).name"></span>
                    <input type="text" placeholder="dd/mm/yyyy" x-init="dateField($el, () => '', (v) => setCabDate(d, v), true, 'h-6 w-[6.25rem] !py-0 !pr-2 !text-[11px]')">
                </div>
            </template>
            <template x-for="(g, gi) in clGroups()" :key="gi + g.task">
                <div>
                    {{-- Task --}}
                    <p class="truncate pt-1.5 text-[11px] font-bold uppercase tracking-wide text-slate-400" x-text="g.task" :title="g.task"></p>
                    <template x-for="w in g.subs" :key="w.id">
                        <div class="mt-1 rounded-md border border-slate-100">
                            {{-- Sub Task: its department, where it is in the workflow, its progress, "+" --}}
                            <div class="flex items-center gap-1.5 rounded-t-md bg-slate-50 px-1.5 py-1 text-[12px]">
                                <span class="h-2 w-2 shrink-0 rounded-full" :style="'background-color:' + deptOf(w.deptId).color" :title="deptOf(w.deptId).name"></span>
                                <span class="min-w-0 flex-1 break-words font-semibold text-slate-700" x-text="w.name" :title="w.name + ' · ' + deptOf(w.deptId).name"></span>
                                <span class="shrink-0 text-[10.5px] text-slate-400" x-text="stateWord(w.state)"></span>
                                <span class="shrink-0 text-[10.5px] tabular-nums text-slate-500" x-show="w.items.length" x-text="w.done + '/' + w.items.length"></span>
                                <button type="button" @click="clAdd(w.id)" class="shrink-0 rounded px-1 text-sm font-bold leading-none text-blue-600 hover:bg-blue-50" title="เพิ่มรายการในงานนี้" aria-label="เพิ่มรายการ">+</button>
                            </div>
                            {{-- compares with (template pairs): which Sub Task, its department, where its work is, a link to it --}}
                            <template x-for="l in clLinkedOf(w.id)" :key="'lh' + l.id">
                                <a :href="l.href" target="_blank" class="flex flex-wrap items-center gap-x-1.5 border-b border-dashed border-slate-200 bg-slate-50 px-1.5 py-1 text-[11px] hover:bg-blue-50">
                                    <span class="font-bold text-slate-500">⇄ เทียบกับ</span>
                                    <span class="h-2 w-2 shrink-0 rounded-full" :style="'background-color:' + deptOf(l.deptId).color"></span>
                                    <span class="font-semibold text-slate-800" x-text="l.deptName || deptOf(l.deptId).name"></span>
                                    <span class="text-slate-600" x-text="'· ' + l.title"></span>
                                    <span class="rounded-full px-1.5" :class="l.late ? 'bg-red-100 font-semibold text-red-700' : 'bg-white text-slate-600 ring-1 ring-slate-200'" x-text="l.state + (l.due ? ' · ครบ ' + fmtBE(l.due) : '')"></span>
                                    <span class="ml-auto tabular-nums text-slate-500" x-text="l.done + '/' + l.total + ' · ไปดู ↗'"></span>
                                </a>
                            </template>
                            <template x-for="i in w.items" :key="i.id + (i.expected || '')">
                              <div :class="clLinkedOf(w.id).length && clSide() ? 'grid grid-cols-2' : ''">
                              <div>
                                <label class="group relative flex cursor-pointer items-start gap-2 px-1.5 py-1 hover:bg-slate-50">
                                    <input type="checkbox" :checked="i.done" @change="tick(clTask, clCab, i)" class="mt-0.5 h-4 w-4 shrink-0 cursor-pointer rounded border-slate-300 text-emerald-600">
                                    <span class="min-w-0 flex-1 break-words text-[13px] leading-snug" :class="i.done ? 'line-through text-slate-400' : 'text-slate-800'" x-text="i.text" :title="i.text + (i.remark ? ' — ' + i.remark : '')"></span>
                                    <span x-show="i.remark" class="shrink-0 text-[11px]" :title="i.remark">📝</span>
                                    <span x-show="deptOf(i.deptId).dateLabel && !canEditStage(i.deptId) && i.expected" class="shrink-0 rounded bg-amber-50 px-1 text-[10.5px] text-amber-800" :title="deptOf(i.deptId).dateLabel" x-text="'📦 ' + fmtBE(i.expected)"></span>
                                    <span class="flex shrink-0 [@media(hover:hover)]:absolute [@media(hover:hover)]:right-0.5 [@media(hover:hover)]:top-0.5 [@media(hover:hover)]:rounded [@media(hover:hover)]:bg-white [@media(hover:hover)]:shadow-sm [@media(hover:hover)]:ring-1 [@media(hover:hover)]:ring-slate-200 items-center opacity-0 group-hover:opacity-100 focus-within:opacity-100 [@media(hover:none)]:opacity-100">
                                        <button type="button" @click.prevent="clMove(i, 'up')" class="px-0.5 text-[12px] text-slate-400 hover:text-slate-800" title="ขึ้น" aria-label="ย้ายขึ้น">↑</button>
                                        <button type="button" @click.prevent="clMove(i, 'down')" class="px-0.5 text-[12px] text-slate-400 hover:text-slate-800" title="ลง" aria-label="ย้ายลง">↓</button>
                                        <button type="button" @click.prevent="clRename(i)" class="px-0.5 text-[12px] text-slate-400 hover:text-blue-600" title="แก้ชื่อ" aria-label="แก้ชื่อรายการ">✎</button>
                                        <button type="button" @click.prevent="clRemark(i)" class="px-0.5 text-[12px] text-slate-400 hover:text-blue-600" title="หมายเหตุ" aria-label="หมายเหตุ">📝</button>
                                        <button type="button" @click.prevent="clDelete(i)" class="rounded px-1 text-[13px] leading-none text-red-600 hover:bg-red-50" title="ลบ" aria-label="ลบรายการ">✕</button>
                                    </span>
                                </label>
                                <p x-show="i.remark" class="-mt-0.5 whitespace-pre-line break-words pb-1 pl-8 pr-1.5 text-[11px] text-slate-500" x-text="'📝 ' + i.remark"></p>
                                {{-- Settings > การส่งต่องาน: the date this item is expected (e.g. วันของเข้า), for the department that follows --}}
                                <template x-if="deptOf(i.deptId).dateLabel && canEditStage(i.deptId)">
                                    <div class="-mt-0.5 flex items-center gap-1.5 pb-1 pl-8 pr-1.5 text-[11px] text-amber-800">
                                        <span x-text="'📦 ' + deptOf(i.deptId).dateLabel"></span>
                                        <input type="text" placeholder="dd/mm/yyyy" x-init="dateField($el, () => clItemDate(i.id), (v) => setItemDate(i.id, v), true, 'h-6 w-[6.25rem] !py-0 !pr-2 !text-[11px]')">
                                        <button type="button" x-show="i.expected" @click="setItemDate(i.id, null)" class="text-slate-400 hover:text-red-600" title="ล้างวันที่" aria-label="ล้างวันที่">✕</button>
                                    </div>
                                </template>
                              </div>
                              {{-- the other list's item of the same name: beside it when there is room, under it otherwise --}}
                              <div x-show="clLinkedOf(w.id).length" class="border-dashed border-slate-200 bg-slate-50/70 py-1 pr-1.5 text-[13px]" :class="clSide() ? 'border-l pl-1.5' : 'pl-8'">
                                <template x-for="m in clMatches(w.id, i)" :key="m.l.id + m.id">
                                    <div class="flex items-start gap-2">
                                        <span class="w-4 shrink-0 text-center" :class="m.done ? 'text-emerald-600' : 'text-slate-300'" x-text="m.done ? '✓' : '○'"></span>
                                        <span class="min-w-0 flex-1 break-words leading-snug" :class="m.done ? 'text-slate-800' : 'text-slate-500'" x-text="m.text" :title="m.text + (m.remark ? ' — ' + m.remark : '')"></span>
                                        <span x-show="m.expected" class="shrink-0 rounded px-1 text-[10.5px]" :class="linkedLate(m) ? 'bg-red-50 font-semibold text-red-600' : 'bg-amber-50 text-amber-800'"
                                              :title="deptOf(m.l.deptId).dateLabel || ''" x-text="'📦 ' + fmtBE(m.expected)"></span>
                                    </div>
                                </template>
                                <span x-show="!clMatches(w.id, i).length" class="block text-[11px] leading-5 text-slate-400">— ไม่มีในรายการที่เทียบ</span>
                              </div>
                              </div>
                            </template>
                            {{-- in the other list only --}}
                            <template x-for="m in clUnmatched(w)" :key="'um' + m.l.id + m.id">
                                <div :class="clSide() ? 'grid grid-cols-2' : ''">
                                    <div class="flex items-center gap-2 px-1.5 py-1 text-[11px] text-slate-400">— ยังไม่มีในรายการนี้</div>
                                    <div class="flex items-start gap-2 border-dashed border-slate-200 bg-slate-50/70 py-1 pr-1.5 text-[13px]" :class="clSide() ? 'border-l pl-1.5' : 'pl-8'">
                                        <span class="w-4 shrink-0 text-center" :class="m.done ? 'text-emerald-600' : 'text-slate-300'" x-text="m.done ? '✓' : '○'"></span>
                                        <span class="min-w-0 flex-1 break-words leading-snug" :class="m.done ? 'text-slate-800' : 'text-slate-500'" x-text="m.text"></span>
                                        <span x-show="m.expected" class="shrink-0 rounded px-1 text-[10.5px]" :class="linkedLate(m) ? 'bg-red-50 font-semibold text-red-600' : 'bg-amber-50 text-amber-800'" x-text="'📦 ' + fmtBE(m.expected)"></span>
                                    </div>
                                </div>
                            </template>
                            <p x-show="!w.items.length && cl.adding !== w.id" class="px-1.5 py-1 text-[11px] text-slate-400">ยังไม่มีรายการ</p>
                            <input x-show="cl.adding === w.id" :id="'cl-draft-' + w.id" x-model="clDraft" type="text" maxlength="255" placeholder="ชื่อรายการ... (Enter เพิ่ม · วางจาก Excel ได้หลายแถว)"
                                   @keydown.enter.prevent="clCommit()" @keydown.escape.stop.prevent="cl = { ...cl, adding: null }; clDraft = ''" @blur="clCommit()" @paste="clPaste($event, w.id)"
                                   class="m-1 w-[calc(100%-0.5rem)] rounded-md border-slate-300 px-2 py-1 text-[13px]">
                        </div>
                    </template>
                </div>
            </template>
            <p x-show="clGroups().length === 0" class="px-1 py-2.5 text-xs leading-relaxed text-slate-400">ยังไม่มีงานที่จ่ายให้แผนกในตู้นี้</p>
          </div>
        </div>
    </div>
</template>
