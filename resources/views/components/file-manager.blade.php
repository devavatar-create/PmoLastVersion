{{--
    The project file manager - folders like on a computer (resources/js/file-manager.js + App\Services\ProjectFiles).
    $start: a folder to open first (e.g. a cabinet's own folder on the cabinet page).
--}}
@props(['project' => null, 'start' => null, 'expr' => null])
{{-- $expr: the page builds the settings itself (Planning: whichever project's window is open) --}}

<div x-data="{{ $expr ?: 'fileManager('.\Illuminate\Support\Js::from([
        'start' => $start,
        'isPmo' => (bool) auth()->user()?->canDispatchWork(),
        'urls' => $expr ? [] : [
            'tree' => route('project-files.tree', $project), 'index' => route('project-files.index', $project), 'upload' => route('project-files.upload', $project),
            'ops' => route('project-files.ops', $project), 'zip' => route('project-files.zip', $project), 'versions' => route('project-files.versions', ['file' => '__ID__']),
        ],
     ]).')' }}"
     {{ $attributes->merge(['class' => 'overflow-hidden rounded-xl border border-slate-200 bg-white text-sm']) }}
     @keydown.window="onKey($event)" @click.window="menu = null" tabindex="-1">

    <input type="file" multiple x-ref="fileInput" class="hidden" @change="fromInput($event)">
    <input type="file" multiple webkitdirectory x-ref="dirInput" class="hidden" @change="fromInput($event)">

    {{-- bar: where we are, search, view, add --}}
    <div class="flex flex-wrap items-center gap-2 border-b border-slate-100 px-3 py-2">
        <nav class="flex min-w-0 flex-1 flex-wrap items-center gap-1 text-sm" aria-label="ตำแหน่ง">
            <template x-for="(c, i) in list.crumbs" :key="'cr' + i">
                <span class="inline-flex items-center gap-1">
                    <span x-show="i > 0" class="text-slate-300">›</span>
                    <button type="button" @click="go(c.id)" class="max-w-[14rem] truncate rounded px-1 hover:bg-slate-100" :class="i === list.crumbs.length - 1 ? 'font-semibold text-slate-900' : 'text-slate-500'" x-text="c.name"></button>
                </span>
            </template>
        </nav>
        <input type="search" x-model="q" placeholder="🔍 ค้นหาไฟล์ทั้งโครงการ" class="h-8 w-44 rounded-lg border-slate-300 py-0 text-xs">
        <div class="flex overflow-hidden rounded-lg border border-slate-300 text-xs" role="group" aria-label="มุมมอง">
            <button type="button" @click="setView('grid')" :class="view === 'grid' ? 'bg-slate-800 text-white' : 'bg-white text-slate-600'" class="h-8 px-2.5" title="ไอคอน">▦</button>
            <button type="button" @click="setView('list')" :class="view === 'list' ? 'bg-slate-800 text-white' : 'bg-white text-slate-600'" class="h-8 border-l border-slate-300 px-2.5" title="รายการ">☰</button>
        </div>
        <template x-if="canAddHere()">
            <div class="flex gap-1.5">
                <button type="button" @click="askMkdir()" class="h-8 rounded-lg border border-slate-300 px-2.5 text-xs font-medium text-slate-700 hover:bg-slate-50">📁 โฟลเดอร์ใหม่</button>
                <button type="button" @click="pick(true)" class="hidden h-8 rounded-lg border border-slate-300 px-2.5 text-xs font-medium text-slate-700 hover:bg-slate-50 sm:block" title="อัปทั้งโฟลเดอร์จากคอม">⬆ ทั้งโฟลเดอร์</button>
                <button type="button" @click="pick(false)" class="h-8 rounded-lg bg-blue-600 px-3 text-xs font-semibold text-white hover:bg-blue-700">⬆ อัปโหลด</button>
            </div>
        </template>
    </div>
    <p x-show="tree.locked" class="border-b border-amber-200 bg-amber-50 px-3 py-1.5 text-xs text-amber-800">โครงการปิดแล้ว — ดู / ดาวน์โหลดได้ แก้ไขไม่ได้</p>

    <div class="grid min-h-[22rem] md:grid-cols-[15rem_1fr]">
        {{-- folders --}}
        <aside class="max-h-[34rem] overflow-y-auto border-b border-slate-100 bg-slate-50/60 p-2 md:border-b-0 md:border-r">
            <button type="button" @click="go('')" @dragover.prevent @drop.prevent="onDrop($event, null)"
                    class="flex w-full items-center gap-1.5 rounded-md px-2 py-1 text-left font-semibold" :class="where === '' && !q ? 'bg-blue-100 text-blue-800' : 'text-slate-700 hover:bg-slate-100'">
                🗂 <span class="truncate" x-text="list.crumbs[0] && where === '' ? list.crumbs[0].name : 'ไฟล์โครงการ'"></span>
                <span x-show="tree.rootCount" class="ml-auto text-[11px] font-normal text-slate-400" x-text="tree.rootCount"></span>
            </button>
            <template x-for="n in flatTree()" :key="'t' + n.id">
                <div class="flex items-center" :style="'padding-left:' + (n.depth * 14 + 4) + 'px'">
                    <button type="button" x-show="n.children.length" @click="open[n.id] = !open[n.id]" class="w-4 shrink-0 text-xs text-slate-500 hover:text-slate-900" x-text="open[n.id] ? '▾' : '▸'" :aria-label="open[n.id] ? 'ยุบ' : 'ขยาย'"></button>
                    <span x-show="!n.children.length" class="w-4 shrink-0"></span>
                    <button type="button" @click="go(n.id)" @dragover.prevent="$event.currentTarget.classList.add('ring-2','ring-blue-400')" @dragleave="$event.currentTarget.classList.remove('ring-2','ring-blue-400')"
                            @drop.prevent="$event.currentTarget.classList.remove('ring-2','ring-blue-400'); onDrop($event, n.id)"
                            class="flex min-w-0 flex-1 items-center gap-1.5 rounded-md px-1.5 py-1 text-left" :class="String(where) === String(n.id) && !q ? 'bg-blue-100 font-semibold text-blue-800' : 'text-slate-700 hover:bg-slate-100'">
                        <span x-text="n.system === 'cabinets' ? '🗄' : (n.system === 'cabinet' ? '🔌' : '📁')"></span>
                        <span class="truncate" x-text="n.name"></span>
                        <span x-show="n.restricted" title="เห็นเฉพาะ Admin / PM">🔒</span>
                        <span x-show="!n.restricted && n.departments.length" :title="'เห็นได้เฉพาะแผนก ' + deptNames(n.departments) + ' และ Admin / PM'">👥</span>
                        <span x-show="n.count" class="ml-auto text-[11px] font-normal text-slate-400" x-text="n.count"></span>
                    </button>
                </div>
            </template>
            <div class="mt-2 space-y-0.5 border-t border-slate-200 pt-2">
                <button type="button" @click="go('comments')" class="flex w-full items-center gap-1.5 rounded-md px-2 py-1 text-left" :class="where === 'comments' ? 'bg-blue-100 font-semibold text-blue-800' : 'text-slate-600 hover:bg-slate-100'">
                    📎 จากคอมเมนต์ <span class="ml-auto text-[11px] text-slate-400" x-text="tree.comments || ''"></span></button>
                <button type="button" @click="go('trash')" class="flex w-full items-center gap-1.5 rounded-md px-2 py-1 text-left" :class="where === 'trash' ? 'bg-blue-100 font-semibold text-blue-800' : 'text-slate-600 hover:bg-slate-100'">
                    🗑 ถังขยะ <span class="ml-auto text-[11px] text-slate-400" x-text="tree.trash || ''"></span></button>
            </div>
        </aside>

        {{-- what is in it (drop files here) --}}
        <section class="relative min-w-0" @dragover.prevent="if (canAddHere() && !dragItems) dragOver = true" @dragleave.self="dragOver = false" @drop.prevent="canAddHere() ? onDrop($event) : (dragOver = false)">
            <div x-show="dragOver" x-cloak class="pointer-events-none absolute inset-2 z-10 flex items-center justify-center rounded-xl border-2 border-dashed border-blue-500 bg-blue-50/80 text-sm font-semibold text-blue-700">วางไฟล์หรือโฟลเดอร์เพื่ออัปโหลดที่นี่</div>

            {{-- several selected --}}
            <div x-show="selected.length" x-cloak class="flex flex-wrap items-center gap-2 border-b border-blue-100 bg-blue-50 px-3 py-1.5 text-xs">
                <span class="font-semibold text-blue-900" x-text="'เลือก ' + selected.length + ' รายการ'"></span>
                <template x-if="!inTrash">
                    <span class="flex flex-wrap gap-1.5">
                        <button type="button" @click="downloadSelected()" class="rounded-md bg-white px-2 py-1 ring-1 ring-slate-200 hover:bg-slate-50" x-text="selFiles().length + selFolders().length > 1 || selFolders().length ? 'ดาวน์โหลด .zip' : 'ดาวน์โหลด'"></button>
                        <template x-if="!readOnly">
                            <span class="flex gap-1.5">
                                <button type="button" @click="askMove(sel().filter((i) => i.canEdit))" class="rounded-md bg-white px-2 py-1 ring-1 ring-slate-200 hover:bg-slate-50">ย้ายไป…</button>
                                <button type="button" x-show="selected.length === 1 && sel()[0] && sel()[0].canEdit" @click="askRename(sel()[0])" class="rounded-md bg-white px-2 py-1 ring-1 ring-slate-200 hover:bg-slate-50" x-text="sel()[0] && sel()[0].type === 'folder' ? 'เปลี่ยนชื่อ / ใครเห็นได้' : 'เปลี่ยนชื่อ'"></button>
                                <button type="button" @click="remove(sel().filter((i) => i.canEdit))" class="rounded-md bg-white px-2 py-1 text-red-600 ring-1 ring-red-200 hover:bg-red-50">ลบ</button>
                            </span>
                        </template>
                    </span>
                </template>
                <template x-if="inTrash">
                    <span class="flex gap-1.5">
                        <button type="button" @click="restore(sel())" class="rounded-md bg-white px-2 py-1 ring-1 ring-slate-200 hover:bg-slate-50">กู้คืน</button>
                        <button type="button" x-show="isPmo" @click="purge(sel())" class="rounded-md bg-white px-2 py-1 text-red-600 ring-1 ring-red-200 hover:bg-red-50">ลบถาวร</button>
                    </span>
                </template>
                <button type="button" @click="selected = []" class="ml-auto text-slate-500 hover:text-slate-800">ยกเลิกการเลือก</button>
            </div>

            <p x-show="inTrash" class="px-3 pt-2 text-xs text-slate-500">รายการในถังขยะลบถาวรเองหลัง 30 วัน</p>
            <p x-show="where === 'comments'" class="px-3 pt-2 text-xs text-slate-500">ไฟล์ที่แนบในคอมเมนต์ (ดูอย่างเดียว — แนบ / ลบที่คอมเมนต์)</p>
            <p x-show="loading && !list.items.length" class="py-10 text-center text-sm text-slate-400">กำลังโหลด…</p>
            <div x-show="!loading && !list.items.length" class="py-12 text-center text-sm text-slate-400">
                <p x-text="q ? 'ไม่พบไฟล์ที่ชื่อมี &quot;' + q + '&quot;' : (inTrash ? 'ถังขยะว่าง' : 'ยังไม่มีไฟล์')"></p>
                <p x-show="canAddHere()" class="mt-1 text-xs">ลากไฟล์หรือทั้งโฟลเดอร์จากคอมมาวางที่นี่ หรือกด "⬆ อัปโหลด"</p>
            </div>

            {{-- icons --}}
            <div x-show="view === 'grid' && list.items.length" class="grid grid-cols-[repeat(auto-fill,minmax(8.5rem,1fr))] gap-2 p-3">
                <template x-for="i in list.items" :key="key(i)">
                    <div class="group relative cursor-pointer rounded-lg border p-2 text-center transition"
                         :class="isSel(i) ? 'border-blue-500 bg-blue-50 ring-1 ring-blue-500' : 'border-transparent hover:border-slate-200 hover:bg-slate-50'"
                         @click="clickItem($event, i)" @contextmenu="context($event, i)" :title="i.name + (i.path ? ' — ' + i.path : '')"
                         :draggable="!readOnly && !inTrash && i.canEdit && i.type !== 'comment'" @dragstart="dragItems = isSel(i) ? sel() : [i]" @dragend="dragItems = null"
                         @dragover.prevent="i.type === 'folder' && $event.currentTarget.classList.add('ring-2','ring-blue-400')" @dragleave="$event.currentTarget.classList.remove('ring-2','ring-blue-400')"
                         @drop.prevent.stop="$event.currentTarget.classList.remove('ring-2','ring-blue-400'); i.type === 'folder' && !i.trashed && onDrop($event, i.id)">
                        <input type="checkbox" :checked="isSel(i)" @click.stop="toggle(i)" class="absolute left-1.5 top-1.5 h-4 w-4 rounded border-slate-300 opacity-0 group-hover:opacity-100 [@media(hover:none)]:opacity-100" :class="isSel(i) ? 'opacity-100' : ''" :aria-label="'เลือก ' + i.name">
                        <div class="mx-auto flex h-16 w-16 items-center justify-center overflow-hidden rounded-md">
                            <template x-if="i.type !== 'folder' && isImage(i) && !i.trashed"><img :src="srcOf(i)" loading="lazy" alt="" class="h-16 w-16 rounded-md object-cover"></template>
                            <template x-if="i.type === 'folder' || !isImage(i) || i.trashed">
                                <span :class="i.type === 'folder' ? 'text-5xl' : 'rounded-md px-2 py-3 text-xs font-bold ' + icon(i).c" x-text="i.type === 'folder' ? (i.system === 'cabinet' ? '🔌' : (i.system === 'cabinets' ? '🗄' : '📁')) : icon(i).t"></span>
                            </template>
                        </div>
                        <p class="mt-1 line-clamp-2 break-words text-xs text-slate-800" x-text="i.name"></p>
                        <p class="text-[10.5px] text-slate-400" x-text="i.type === 'folder' ? (i.trashed ? 'ลบเมื่อ ' + i.at : (i.count || 0) + ' ไฟล์') + (i.restricted ? ' · 🔒' : '') + (!i.restricted && i.departments && i.departments.length ? ' · 👥' : '') : size(i.size) + (i.version > 1 ? ' · v' + i.version : '')"></p>
                    </div>
                </template>
            </div>

            {{-- list --}}
            <table x-show="view === 'list' && list.items.length" class="w-full text-left text-xs">
                <thead class="bg-slate-50 text-slate-500"><tr><th class="w-8 px-3 py-2"></th><th class="py-2">ชื่อ</th><th class="hidden py-2 sm:table-cell">ขนาด</th><th class="hidden py-2 md:table-cell">โดย</th><th class="py-2 pr-3">เมื่อ</th></tr></thead>
                <tbody class="divide-y divide-slate-100">
                    <template x-for="i in list.items" :key="'l' + key(i)">
                        <tr class="cursor-pointer" :class="isSel(i) ? 'bg-blue-50' : 'hover:bg-slate-50'" @click="clickItem($event, i)" @contextmenu="context($event, i)"
                            :draggable="!readOnly && !inTrash && i.canEdit && i.type !== 'comment'" @dragstart="dragItems = isSel(i) ? sel() : [i]" @dragend="dragItems = null"
                            @dragover.prevent @drop.prevent.stop="i.type === 'folder' && !i.trashed && onDrop($event, i.id)">
                            <td class="px-3 py-1.5"><input type="checkbox" :checked="isSel(i)" @click.stop="toggle(i)" class="h-4 w-4 rounded border-slate-300" :aria-label="'เลือก ' + i.name"></td>
                            <td class="py-1.5">
                                <span class="inline-flex min-w-0 items-center gap-2">
                                    <span class="shrink-0" :class="i.type === 'folder' ? 'text-base' : 'rounded px-1.5 py-0.5 text-[10px] font-bold ' + icon(i).c" x-text="i.type === 'folder' ? '📁' : icon(i).t"></span>
                                    <span class="break-all text-slate-800" x-text="i.name"></span>
                                    <span x-show="i.restricted" title="เห็นเฉพาะ Admin / PM">🔒</span>
                                    <span x-show="i.system === 'default'" class="rounded bg-slate-100 px-1.5 text-[10px] text-slate-500" title="โฟลเดอร์มาตรฐาน — Admin ตั้งชื่อและสิทธิ์ได้ที่ ตั้งค่า → ไฟล์และโฟลเดอร์">มาตรฐาน</span>
                                    <span x-show="!i.restricted && i.departments && i.departments.length" class="rounded bg-violet-50 px-1.5 text-[10px] text-violet-700" :title="'เห็นได้เฉพาะแผนกเหล่านี้ และ Admin / PM'" x-text="'👥 ' + deptNames(i.departments || [])"></span>
                                    <span x-show="i.version > 1" class="rounded bg-slate-100 px-1 text-[10px] text-slate-500" x-text="'v' + i.version"></span>
                                </span>
                                <p x-show="i.path || i.note" class="text-[11px] text-slate-400" x-text="i.path || ('💬 ' + i.note)"></p>
                            </td>
                            <td class="hidden py-1.5 tabular-nums text-slate-500 sm:table-cell" x-text="i.type === 'folder' ? (i.count || 0) + ' ไฟล์' : size(i.size)"></td>
                            <td class="hidden py-1.5 text-slate-500 md:table-cell" x-text="i.by || ''"></td>
                            <td class="py-1.5 pr-3 text-slate-500" x-text="i.trashed ? 'ลบ · หายถาวร ' + i.expires : (i.at || '')"></td>
                        </tr>
                    </template>
                </tbody>
            </table>

            {{-- uploads in progress --}}
            <div x-show="queue.length" x-cloak class="m-3 space-y-1 rounded-lg border border-slate-200 bg-white p-2 text-xs shadow-sm">
                <template x-for="(j, n) in queue" :key="'q' + n">
                    <div class="flex items-center gap-2">
                        <span class="min-w-0 flex-1 truncate" :class="j.error ? 'text-red-600' : 'text-slate-700'" x-text="j.name"></span>
                        <span x-show="j.error" class="shrink-0 text-red-600" x-text="j.error"></span>
                        <div x-show="!j.error" class="h-1.5 w-28 shrink-0 overflow-hidden rounded bg-slate-100"><div class="h-full rounded" :class="j.done ? 'bg-emerald-500' : 'bg-blue-600'" :style="'width:' + j.pct + '%'"></div></div>
                    </div>
                </template>
            </div>
        </section>
    </div>

    <template x-teleport="body">
        <div>
            {{-- right-click menu --}}
            <template x-if="menu">
                <div class="fixed z-[80] w-52 rounded-lg border border-slate-200 bg-white py-1 text-sm shadow-xl" :style="'top:' + menu.y + 'px;left:' + menu.x + 'px'" @click.stop>
                    <template x-if="!menu.item.trashed">
                        <div>
                            <button type="button" @click="openItem(menu.item)" class="block w-full px-3 py-1.5 text-left hover:bg-slate-50" x-text="menu.item.type === 'folder' ? 'เปิด' : (menu.item.preview ? 'เปิดดู' : 'ดาวน์โหลด')"></button>
                            <a x-show="menu.item.type !== 'folder'" :href="menu.item.url" class="block px-3 py-1.5 hover:bg-slate-50">ดาวน์โหลด</a>
                            <button type="button" x-show="menu.item.type === 'folder'" @click="downloadFolder(menu.item.id); menu = null" class="block w-full px-3 py-1.5 text-left hover:bg-slate-50">ดาวน์โหลดทั้งโฟลเดอร์ (.zip)</button>
                            <template x-if="!readOnly && menu.item.canEdit">
                                <div class="border-t border-slate-100">
                                    <button type="button" @click="askRename(menu.item)" class="block w-full px-3 py-1.5 text-left hover:bg-slate-50"><span x-text="menu.item.type === 'folder' ? 'เปลี่ยนชื่อ / ใครเห็นได้' : 'เปลี่ยนชื่อ'"></span> <span class="float-right text-xs text-slate-400">F2</span></button>
                                    <button type="button" @click="askMove(sel())" class="block w-full px-3 py-1.5 text-left hover:bg-slate-50">ย้ายไป…</button>
                                </div>
                            </template>
                            <button type="button" x-show="menu.item.type === 'file' && menu.item.version > 1" @click="showVersions(menu.item)" class="block w-full px-3 py-1.5 text-left hover:bg-slate-50">ประวัติเวอร์ชัน</button>
                            <button type="button" x-show="!readOnly && menu.item.canEdit" @click="remove(sel()); menu = null" class="block w-full border-t border-slate-100 px-3 py-1.5 text-left text-red-600 hover:bg-red-50">ลบ <span class="float-right text-xs text-slate-400">Del</span></button>
                        </div>
                    </template>
                    <template x-if="menu.item.trashed">
                        <div>
                            <button type="button" @click="restore(sel()); menu = null" class="block w-full px-3 py-1.5 text-left hover:bg-slate-50">กู้คืน</button>
                            <button type="button" x-show="isPmo" @click="purge(sel()); menu = null" class="block w-full px-3 py-1.5 text-left text-red-600 hover:bg-red-50">ลบถาวร</button>
                        </div>
                    </template>
                </div>
            </template>

            {{-- viewer: pictures / PDF, ← → to the next one --}}
            <template x-if="viewing">
                <div class="fixed inset-0 z-[80] flex flex-col bg-slate-900/90" @keydown.window.escape="viewer = null" @keydown.window.arrow-right="step(1)" @keydown.window.arrow-left="step(-1)">
                    <div class="flex items-center gap-3 px-4 py-2 text-sm text-white">
                        <span class="min-w-0 flex-1 truncate font-semibold" x-text="viewing.name"></span>
                        <span class="text-slate-300" x-text="(viewer.index + 1) + ' / ' + previewables().length"></span>
                        <a :href="viewing.url" class="rounded-lg bg-white/15 px-3 py-1 hover:bg-white/25">ดาวน์โหลด</a>
                        <button type="button" x-show="viewing.version > 1" @click="showVersions(viewing)" class="rounded-lg bg-white/15 px-3 py-1 hover:bg-white/25" x-text="'v' + viewing.version + ' · ประวัติ'"></button>
                        <button type="button" @click="viewer = null" class="text-2xl leading-none text-white/70 hover:text-white" aria-label="ปิด">&times;</button>
                    </div>
                    <div class="relative flex min-h-0 flex-1 items-center justify-center px-12 pb-4">
                        <button type="button" x-show="previewables().length > 1" @click="step(-1)" class="absolute left-2 rounded-full bg-white/15 px-3 py-2 text-xl text-white hover:bg-white/25" aria-label="ก่อนหน้า">‹</button>
                        <template x-if="viewing.ext === 'pdf'"><iframe :src="srcOf(viewing)" class="h-full w-full rounded-lg bg-white" title="PDF"></iframe></template>
                        <template x-if="viewing.ext !== 'pdf'"><img :src="srcOf(viewing)" :alt="viewing.name" class="max-h-full max-w-full rounded-lg object-contain"></template>
                        <button type="button" x-show="previewables().length > 1" @click="step(1)" class="absolute right-2 rounded-full bg-white/15 px-3 py-2 text-xl text-white hover:bg-white/25" aria-label="ถัดไป">›</button>
                    </div>
                </div>
            </template>

            {{-- versions --}}
            <template x-if="versions">
                <div class="fixed inset-0 z-[85] flex items-center justify-center bg-slate-900/40 p-4" @click.self="versions = null">
                    <div class="w-full max-w-md rounded-xl bg-white p-4 shadow-2xl" role="dialog" aria-label="ประวัติเวอร์ชัน">
                        <h3 class="mb-2 font-semibold text-slate-900" x-text="'ประวัติ — ' + versions.file.name"></h3>
                        <ul class="divide-y divide-slate-100 text-sm">
                            <template x-for="v in versions.rows" :key="v.id">
                                <li class="flex items-center gap-2 py-1.5">
                                    <span class="w-10 font-semibold" x-text="'v' + v.version"></span>
                                    <span class="min-w-0 flex-1 truncate text-xs text-slate-500" x-text="(v.by || '') + ' · ' + (v.at || '') + ' · ' + size(v.size)"></span>
                                    <span x-show="v.current" class="rounded bg-emerald-50 px-1.5 text-xs font-semibold text-emerald-700">ล่าสุด</span>
                                    <a :href="v.url" class="text-xs text-blue-600 hover:underline">ดาวน์โหลด</a>
                                    <button type="button" x-show="!v.current && !readOnly" @click="useVersion(v)" class="text-xs text-blue-600 hover:underline">ใช้เวอร์ชันนี้</button>
                                </li>
                            </template>
                        </ul>
                        <div class="mt-3 text-right"><button type="button" @click="versions = null" class="rounded-lg px-3 py-1.5 text-sm text-slate-600 hover:bg-slate-100">ปิด</button></div>
                    </div>
                </div>
            </template>

            {{-- new folder / rename / move --}}
            <template x-if="dialog">
                <div class="fixed inset-0 z-[85] flex items-center justify-center bg-slate-900/40 p-4" @click.self="dialog = null" @keydown.escape.window="dialog = null">
                    <form @submit.prevent="runDialog()" class="w-full max-w-md rounded-xl bg-white p-4 shadow-2xl" role="dialog">
                        <h3 class="mb-3 font-semibold text-slate-900" x-text="{ mkdir: 'โฟลเดอร์ใหม่', rename: dialog.item && dialog.item.type === 'folder' ? 'เปลี่ยนชื่อ / ใครเห็นได้' : 'เปลี่ยนชื่อ', move: 'ย้าย ' + (dialog.items ? dialog.items.length : 0) + ' รายการไป…' }[dialog.kind]"></h3>
                        <template x-if="dialog.kind !== 'move'">
                            <div class="space-y-2">
                                <input id="fm-name" x-model="dialog.name" maxlength="200" required class="w-full rounded-lg border-slate-300 text-sm">
                                <fieldset x-show="dialog.kind === 'mkdir' || (dialog.item && dialog.item.type === 'folder')" class="space-y-1.5 rounded-lg border border-slate-200 px-3 pb-2.5 pt-1 text-xs text-slate-600">
                                    <legend class="px-1 font-semibold text-slate-700">ใครเห็นโฟลเดอร์นี้ได้</legend>
                                    <label class="flex items-center gap-2"><input type="radio" name="fm-who" value="all" x-model="dialog.who"> ทุกคนที่เห็นโครงการ</label>
                                    <label class="flex items-center gap-2"><input type="radio" name="fm-who" value="departments" x-model="dialog.who" @change="keepMyDepartment()"> 👥 เฉพาะแผนกที่เลือก (Admin / PM เห็นเสมอ)</label>
                                    <div x-show="dialog.who === 'departments'" class="ml-6 grid max-h-40 grid-cols-2 gap-x-3 gap-y-1 overflow-y-auto">
                                        <template x-for="d in tree.departments" :key="'vd' + d.id">
                                            <label class="flex items-center gap-1.5" :class="!isPmo && d.id === tree.myDepartment ? 'text-slate-400' : ''" :title="!isPmo && d.id === tree.myDepartment ? 'แผนกของคุณเห็นเสมอ' : ''">
                                                <input type="checkbox" :value="d.id" x-model.number="dialog.depts" :disabled="!isPmo && d.id === tree.myDepartment" class="rounded border-slate-300"><span x-text="d.name"></span></label>
                                        </template>
                                    </div>
                                    <label x-show="isPmo" class="flex items-center gap-2"><input type="radio" name="fm-who" value="pmo" x-model="dialog.who"> 🔒 เฉพาะ Admin / PM (เช่น เอกสารการเงิน)</label>
                                    <p class="text-[11px] text-slate-400">โฟลเดอร์ย่อยข้างในจะเห็นได้เฉพาะคนที่เห็นโฟลเดอร์นี้ด้วย</p>
                                </fieldset>
                            </div>
                        </template>
                        <template x-if="dialog.kind === 'move'">
                            <div class="max-h-72 space-y-0.5 overflow-y-auto rounded-lg border border-slate-200 p-1 text-sm">
                                <label class="flex cursor-pointer items-center gap-2 rounded px-2 py-1 hover:bg-slate-50"><input type="radio" name="fm-to" value="" x-model="dialog.to"> 🗂 ชั้นบนสุดของโครงการ</label>
                                <template x-for="f in allFolders()" :key="'mv' + f.id">
                                    <label class="flex cursor-pointer items-center gap-2 rounded py-1 pr-2 hover:bg-slate-50" :style="'padding-left:' + (f.depth * 14 + 8) + 'px'">
                                        <input type="radio" name="fm-to" :value="String(f.id)" x-model="dialog.to"><span x-text="(f.system === 'cabinet' ? '🔌 ' : '📁 ') + f.name"></span></label>
                                </template>
                            </div>
                        </template>
                        <div class="mt-3 flex justify-end gap-2">
                            <button type="button" @click="dialog = null" class="rounded-lg px-3 py-1.5 text-sm text-slate-600 hover:bg-slate-100">ยกเลิก</button>
                            <button class="rounded-lg bg-blue-600 px-4 py-1.5 text-sm font-semibold text-white hover:bg-blue-700">ตกลง</button>
                        </div>
                    </form>
                </div>
            </template>
        </div>
    </template>
</div>
