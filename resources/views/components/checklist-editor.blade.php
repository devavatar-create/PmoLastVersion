{{--
    The one checklist editor (resources/js/checklist-editor.js + App\Services\ChecklistEditor) for a cabinet's Sub Task
    (kind="subtask": tick, remark, date) or a template's (kind="template": name + remark only).
    $items: the list already loaded by the page (as ChecklistEditor::items() shapes it) - saves a query per Sub Task.
--}}
@props(['kind', 'owner', 'items', 'editable' => false, 'dateLabel' => null, 'linked' => []])

<div x-data="checklistEditor(@js(['kind' => $kind, 'url' => route('checklist-editor.apply', [$kind, $owner->id]), 'items' => $items, 'editable' => (bool) $editable, 'dateLabel' => $dateLabel, 'linked' => $linked]))"
     {{ $attributes->merge(['class' => 'text-sm']) }} @keydown.escape.stop="paste = null; dialog = null; cancelEdit()">

    {{-- several selected: one bar of actions --}}
    <div x-show="editable && selected.length" x-cloak class="mb-2 flex flex-wrap items-center gap-2 rounded-lg border border-blue-200 bg-blue-50 px-2.5 py-1.5 text-xs">
        <span class="font-semibold text-blue-900" x-text="'เลือก ' + selected.length + ' รายการ'"></span>
        <button type="button" @click="remove(selected)" class="rounded-md bg-white px-2 py-1 font-medium text-red-600 ring-1 ring-red-200 hover:bg-red-50">ลบ</button>
        <button type="button" @click="openDialog('move')" class="rounded-md bg-white px-2 py-1 font-medium text-slate-700 ring-1 ring-slate-200 hover:bg-slate-50">ย้ายไป…</button>
        <button type="button" @click="openDialog('copyTo')" class="rounded-md bg-white px-2 py-1 font-medium text-slate-700 ring-1 ring-slate-200 hover:bg-slate-50" x-text="kind === 'subtask' ? 'คัดลอกไปตู้อื่น…' : 'คัดลอกไป…'"></button>
        <template x-if="dateLabel">
            <label class="inline-flex items-center gap-1 text-slate-700"><span x-text="dateLabel"></span>
                <input type="date" x-model="bulkDate" @change="bulkDate && setDate(selected, bulkDate)" class="h-7 rounded-md border-slate-300 py-0 text-xs"></label>
        </template>
        <button type="button" @click="selected = []" class="ml-auto text-slate-500 hover:text-slate-800">ยกเลิกการเลือก</button>
    </div>

    <ul class="divide-y divide-slate-100 rounded-lg border border-slate-200 bg-white">
        <li x-show="items.length > 1 && editable" x-cloak class="flex items-center gap-2 bg-slate-50 px-2 py-1 text-[11px] text-slate-500">
            <input type="checkbox" :checked="allSelected" @change="toggleAll()" class="h-3.5 w-3.5 rounded border-slate-300" aria-label="เลือกทั้งหมด">
            <span x-text="items.length + ' รายการ' + (kind === 'subtask' ? ' · เสร็จ ' + doneCount : '')"></span>
            <span class="ml-auto hidden sm:inline">คลิกข้อความเพื่อแก้ · Enter บันทึก · ลาก ⋮⋮ หรือ Alt+↑↓ จัดลำดับ</span>
        </li>
        <template x-for="item in items" :key="item.id">
            <li class="group flex items-start gap-2 px-2 py-1.5" :class="[dragId === item.id ? 'opacity-40' : '', isSelected(item.id) ? 'bg-blue-50/60' : '']"
                tabindex="-1" @keydown.alt.arrow-up.prevent="nudge(item, -1)" @keydown.alt.arrow-down.prevent="nudge(item, 1)"
                @dragover.prevent @drop.prevent="drop(item)">
                <input x-show="editable" type="checkbox" :checked="isSelected(item.id)" @change="toggleSelect(item.id)" class="mt-1 h-3.5 w-3.5 shrink-0 rounded border-slate-300" :aria-label="'เลือก ' + item.name">
                <span x-show="editable" draggable="true" @dragstart="dragStart($event, item)" @dragend="dragId = null" class="mt-0.5 shrink-0 cursor-grab select-none text-slate-300 hover:text-slate-500" title="ลากเพื่อจัดลำดับ">⋮⋮</span>
                <template x-if="kind === 'subtask'">
                    <input type="checkbox" :checked="item.done" @change="tick(item, $event.target.checked)" :disabled="!editable" class="mt-0.5 h-4 w-4 shrink-0 rounded border-slate-300 text-emerald-600" :aria-label="'ทำแล้ว: ' + item.name">
                </template>
                <div class="min-w-0 flex-1">
                    {{-- name --}}
                    <template x-if="editing && editing.id === item.id && editing.field === 'name'">
                        <input data-edit-input type="text" x-model="editing.value" maxlength="255" class="w-full rounded-md border-blue-400 px-1.5 py-0.5 text-sm"
                               @keydown.enter.prevent="commitEdit('down')" @keydown.tab.prevent="commitEdit('right')" @blur="commitEdit()">
                    </template>
                    <span x-show="!(editing && editing.id === item.id && editing.field === 'name')" @click="startEdit(item, 'name')"
                          class="block break-words leading-snug" :class="[item.done ? 'text-slate-400 line-through' : 'text-slate-800', editable ? 'cursor-text hover:bg-slate-50 rounded' : '']" x-text="item.name"></span>
                    {{-- remark / date / who ticked it --}}
                    <div x-show="item.remark || dateLabel || (item.handoff && item.handoff.length) || (item.done && item.doneBy) || (editing && editing.id === item.id && editing.field === 'remark')"
                         class="mt-0.5 flex flex-wrap items-center gap-x-3 gap-y-0.5 text-xs text-slate-500">
                        <template x-if="editing && editing.id === item.id && editing.field === 'remark'">
                            <input data-edit-input type="text" x-model="editing.value" maxlength="1000" placeholder="หมายเหตุ" class="min-w-[12rem] flex-1 rounded-md border-blue-400 px-1.5 py-0.5 text-xs"
                                   @keydown.enter.prevent="commitEdit('down')" @blur="commitEdit()">
                        </template>
                        <span x-show="item.remark && !(editing && editing.id === item.id && editing.field === 'remark')" @click="startEdit(item, 'remark')"
                              class="cursor-text text-slate-600" x-text="'📝 ' + item.remark"></span>
                        <template x-if="dateLabel">
                            <span class="inline-flex items-center gap-1">📦 <span x-text="dateLabel"></span>
                                <input type="date" :value="item.date || ''" :disabled="!editable" @change="send({ type: 'update', id: item.id, date: $event.target.value }, { quiet: true })"
                                       class="h-6 rounded border-slate-300 px-1 py-0 text-xs"></span>
                        </template>
                        {{-- Settings > การส่งต่องาน: has the next department (e.g. คลัง) got it --}}
                        <template x-for="h in (item.handoff || [])" :key="h.dept">
                            <span class="rounded-full px-1.5 font-medium" :class="h.received ? 'bg-emerald-50 text-emerald-700' : (item.date && item.date < today ? 'bg-red-50 text-red-600' : 'bg-slate-100 text-slate-500')"
                                  x-text="h.received ? '✓ ' + h.dept + ' รับแล้ว' : (item.date && item.date < today ? 'เลยวันแล้ว · ' + h.dept + ' ยังไม่รับ' : 'รอ ' + h.dept + ' รับ')"></span>
                        </template>
                        <span x-show="item.done && item.doneBy" x-text="'✓ ' + item.doneBy + (item.doneAt ? ' · ' + item.doneAt : '')"></span>
                    </div>
                </div>
                <span x-show="editable" class="flex shrink-0 items-center gap-0.5 opacity-0 group-hover:opacity-100 focus-within:opacity-100 [@media(hover:none)]:opacity-100">
                    <button type="button" x-show="!item.remark" @click="startEdit(item, 'remark')" class="px-1 text-slate-400 hover:text-blue-600" title="เพิ่มหมายเหตุ" aria-label="เพิ่มหมายเหตุ">📝</button>
                    <button type="button" @click="nudge(item, -1)" class="px-1 text-slate-400 hover:text-slate-800" title="ขึ้น (Alt+↑)" aria-label="ย้ายขึ้น">↑</button>
                    <button type="button" @click="nudge(item, 1)" class="px-1 text-slate-400 hover:text-slate-800" title="ลง (Alt+↓)" aria-label="ย้ายลง">↓</button>
                    <button type="button" @click="remove([item.id])" class="rounded px-1 text-red-500 hover:bg-red-50" title="ลบ" aria-label="ลบรายการ">✕</button>
                </span>
            </li>
        </template>
        <li x-show="!items.length" class="px-2 py-2 text-xs text-slate-400">ยังไม่มีรายการ</li>
        {{-- add: type one, or paste many (from Excel: name | remark | date) --}}
        <li x-show="editable" class="flex items-center gap-2 px-2 py-1.5">
            <span class="text-slate-300">＋</span>
            <input x-ref="draft" type="text" x-model="draft" @keydown.enter.prevent="addDraft()" @paste="onPaste($event)" maxlength="2000"
                   placeholder="พิมพ์เพื่อเพิ่มรายการ แล้วกด Enter — วางจาก Excel ได้หลายแถว" class="min-w-0 flex-1 border-0 px-0 py-0.5 text-sm placeholder:text-slate-400 focus:ring-0">
            <button type="button" @click="openDialog('copyFrom')" class="shrink-0 text-xs font-medium text-blue-600 hover:underline" x-text="kind === 'subtask' ? 'คัดลอกจาก Sub Task อื่น' : 'ใส่ชุดจาก Sub Task อื่น'"></button>
        </li>
    </ul>

    {{-- Settings > การส่งต่องาน: what the department(s) passing work to this one did in this cabinet (read only) --}}
    <template x-for="l in linked" :key="'ln' + l.deptId">
        {{-- folded when everything in it is already received; open while something is still on its way --}}
        <details class="mt-2 rounded-lg border border-dashed border-slate-300 bg-slate-50/60" :open="l.items.some((i) => !i.received)">
            <summary class="flex cursor-pointer list-none items-center gap-2 px-2 py-1 text-xs">
                <span class="text-slate-400">▸</span>
                <span class="font-semibold text-slate-700" x-text="'ข้อมูลจาก ' + l.deptName"></span>
                <span class="text-slate-400">ส่งต่องาน · ดูอย่างเดียว</span>
                <span x-show="l.items.length && l.items.every((i) => i.received)" class="font-semibold text-emerald-600">✓ รับครบแล้ว</span>
                <span x-show="l.items.some((i) => !i.received && i.expected && i.expected < today)" class="font-semibold text-red-600"
                      x-text="'เลยวันแล้ว ' + l.items.filter((i) => !i.received && i.expected && i.expected < today).length + ' รายการ'"></span>
                <span class="ml-auto tabular-nums text-slate-500" x-text="l.done + '/' + l.total"></span>
            </summary>
            <ul class="divide-y divide-slate-100 border-t border-slate-200 bg-white">
                <template x-for="i in l.items" :key="i.id">
                    <li class="flex items-start gap-2 px-2 py-1 text-xs">
                        <span class="w-4 shrink-0 text-center" :class="i.done ? 'text-emerald-600' : 'text-slate-300'" x-text="i.done ? '✓' : '○'"></span>
                        <div class="min-w-0 flex-1">
                            <span class="break-words" :class="i.done ? 'text-slate-800' : 'text-slate-500'" x-text="i.text"></span>
                            <span x-show="i.remark" class="block text-slate-500" x-text="'📝 ' + i.remark"></span>
                        </div>
                        <span x-show="i.expected" class="shrink-0 rounded px-1" :class="!i.received && i.expected < today ? 'bg-red-50 font-semibold text-red-600' : 'bg-amber-50 text-amber-800'"
                              x-text="'📦 ' + (l.dateLabel ? l.dateLabel + ' ' : '') + fmtDate(i.expected)"></span>
                        <span x-show="i.received" class="shrink-0 font-semibold text-emerald-600">✓ รับแล้ว</span>
                    </li>
                </template>
                <li x-show="!l.items.length" class="px-2 py-1 text-xs text-slate-400">ยังไม่มีรายการ</li>
            </ul>
        </details>
    </template>

    {{-- "ยกเลิก" right after a change --}}
    <div x-show="undo" x-cloak class="mt-2 flex items-center gap-3 rounded-lg bg-slate-800 px-3 py-1.5 text-xs text-white">
        <span x-text="undo && undo.text"></span>
        <button type="button" @click="doUndo()" class="ml-auto font-semibold text-amber-300 hover:underline">ยกเลิก</button>
    </div>

    {{-- pasted rows: say what each column is, check, then add (teleported: a closed <details> would hide it) --}}
    <template x-teleport="body">
        <div>
            <template x-if="paste">
                <div class="fixed inset-0 z-[70] flex items-center justify-center bg-slate-900/40 p-4" @click.self="paste = null" @keydown.escape.window="paste = null">
                    <div class="flex max-h-[85vh] w-full max-w-3xl flex-col rounded-xl bg-white p-4 shadow-2xl" role="dialog" aria-label="เพิ่มรายการที่วาง">
                        <h3 class="font-semibold text-slate-900" x-text="'เพิ่ม ' + pasteRows().length + ' รายการ'"></h3>
                        <p class="mb-2 text-xs text-slate-500">เลือกว่าแต่ละคอลัมน์จาก Excel คืออะไร — "รวมกับชื่อ" ต่อท้ายชื่อ (เช่น จำนวน / หน่วย)</p>
                        <div class="min-h-0 flex-1 overflow-auto rounded-lg border border-slate-200">
                            <table class="w-full text-xs">
                                <thead class="sticky top-0 bg-slate-50">
                                    <tr>
                                        <template x-for="(use, c) in paste.map" :key="'h' + c">
                                            <th class="p-1 text-left font-normal">
                                                <select x-model="paste.map[c]" class="h-7 w-full min-w-[7rem] rounded border-slate-300 py-0 text-xs">
                                                    <option value="name">ชื่อ</option>
                                                    <option value="join">รวมกับชื่อ</option>
                                                    <option value="remark">หมายเหตุ</option>
                                                    <option value="date" x-show="dateLabel" x-text="dateLabel || 'วันที่'"></option>
                                                    <option value="skip">ไม่ใช้</option>
                                                </select>
                                            </th>
                                        </template>
                                        <th class="w-6"></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <template x-for="(row, r) in paste.cells" :key="'r' + r">
                                        <tr class="border-t border-slate-100">
                                            <template x-for="(use, c) in paste.map" :key="'c' + r + '-' + c">
                                                <td class="px-2 py-1 align-top" :class="use === 'skip' ? 'text-slate-300 line-through' : (use === 'name' || use === 'join' ? 'text-slate-800' : 'text-slate-500')" x-text="row[c] || ''"></td>
                                            </template>
                                            <td class="px-1 text-right"><button type="button" @click="paste.cells.splice(r, 1)" class="text-red-500" aria-label="ไม่เอาแถวนี้">✕</button></td>
                                        </tr>
                                    </template>
                                </tbody>
                            </table>
                        </div>
                        <p class="mt-2 truncate text-xs text-slate-500" x-show="pasteRows()[0]" x-text="'ตัวอย่างแถวแรก: ' + (pasteRows()[0] ? pasteRows()[0].name + (pasteRows()[0].remark ? ' · 📝 ' + pasteRows()[0].remark : '') + (pasteRows()[0].date ? ' · 📦 ' + pasteRows()[0].date : '') : '')"></p>
                        <div class="mt-3 flex justify-end gap-2">
                            <button type="button" @click="paste = null" class="rounded-lg px-3 py-1.5 text-sm text-slate-600 hover:bg-slate-100">ยกเลิก</button>
                            <button type="button" @click="addPasted()" :disabled="!pasteRows().length" class="rounded-lg bg-blue-600 px-4 py-1.5 text-sm font-semibold text-white hover:bg-blue-700 disabled:opacity-50" x-text="'เพิ่ม ' + pasteRows().length + ' รายการ'"></button>
                        </div>
                    </div>
                </div>
            </template>
        </div>
    </template>

    {{-- move to / copy to / copy in from --}}
    <template x-teleport="body"><div>
    <template x-if="dialog && targets">
        <div class="fixed inset-0 z-[70] flex items-center justify-center bg-slate-900/40 p-4" @click.self="dialog = null">
            <div class="flex max-h-[80vh] w-full max-w-lg flex-col rounded-xl bg-white p-4 shadow-2xl" role="dialog">
                <h3 class="mb-2 font-semibold text-slate-900"
                    x-text="{ move: 'ย้าย ' + selected.length + ' รายการไป…', copyTo: 'คัดลอก ' + selected.length + ' รายการไป…', copyFrom: 'เพิ่มรายการทั้งหมดจาก…' }[dialog]"></h3>
                <p class="mb-2 text-xs text-slate-500"
                   x-text="{ move: kind === 'subtask' ? 'Sub Task อื่นในตู้นี้' : 'Sub Task อื่นในเทมเพลตนี้', copyTo: kind === 'subtask' ? 'Sub Task เดียวกันในตู้อื่นของโครงการนี้ — รายการที่มีอยู่แล้วจะข้าม' : 'Sub Task ของเทมเพลตใดก็ได้ — รายการที่มีอยู่แล้วจะข้าม', copyFrom: 'รายการที่มีอยู่แล้ว (ชื่อเดียวกัน) จะข้าม' }[dialog]"></p>
                <div class="min-h-0 flex-1 space-y-0.5 overflow-y-auto rounded-lg border border-slate-200 p-1">
                    <template x-for="t in targets[dialog]" :key="t.id">
                        <label class="flex cursor-pointer items-center gap-2 rounded px-2 py-1 text-sm hover:bg-slate-50">
                            <input :type="dialog === 'copyTo' ? 'checkbox' : 'radio'" name="ck-target" :value="t.id"
                                   @change="dialogPick = dialog === 'copyTo' ? ($event.target.checked ? [...dialogPick, t.id] : dialogPick.filter((x) => x !== t.id)) : [t.id]">
                            <span x-text="t.label"></span>
                        </label>
                    </template>
                    <p x-show="!targets[dialog].length" class="px-2 py-3 text-center text-xs text-slate-400">ไม่มีปลายทางให้เลือก</p>
                </div>
                <div class="mt-3 flex justify-end gap-2">
                    <button type="button" @click="dialog = null" class="rounded-lg px-3 py-1.5 text-sm text-slate-600 hover:bg-slate-100">ยกเลิก</button>
                    <button type="button" @click="runDialog()" :disabled="!dialogPick.length" class="rounded-lg bg-blue-600 px-4 py-1.5 text-sm font-semibold text-white hover:bg-blue-700 disabled:opacity-50">ตกลง</button>
                </div>
            </div>
        </div>
    </template>
    </div></template>
</div>
