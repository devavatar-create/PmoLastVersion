{{-- One toolbar, sticky under the app header: navigation, เดือน / ไทม์ไลน์ / แดชบอร์ด, search, priority, department, status legend. --}}
<div class="sticky top-16 z-20 bg-white border-b border-slate-200 px-4 py-2.5 space-y-2">
    {{-- one tab per board (main PMO + extra ones from Settings > บอร์ด); switching reloads the page for that board --}}
    <nav x-show="boards.length > 1" class="flex flex-wrap gap-1 text-xs" aria-label="บอร์ด">
        <template x-for="b in boards" :key="b.id">
            <a :href="b.url" class="rounded-full px-3 py-1 font-semibold" :class="b.id === (boardNo ? 'b' + boardNo : 'main') ? 'bg-slate-800 text-white' : 'bg-slate-100 text-slate-600 hover:bg-slate-200'" x-text="b.name"></a>
        </template>
    </nav>
    <div class="flex flex-wrap items-center gap-x-3 gap-y-2">
        <div class="inline-flex overflow-hidden rounded-lg border border-slate-300 text-sm">
            <button type="button" @click="shiftMonth(-1)" class="px-2.5 h-8 text-slate-600 hover:bg-slate-50" aria-label="เดือนก่อน">&lsaquo;</button>
            <button type="button" @click="goToday()" class="px-3 h-8 border-x border-slate-300 font-medium text-slate-700 hover:bg-slate-50">วันนี้</button>
            <button type="button" @click="shiftMonth(1)" class="px-2.5 h-8 text-slate-600 hover:bg-slate-50" aria-label="เดือนถัดไป">&rsaquo;</button>
        </div>
        <h3 class="text-base font-bold text-slate-900" x-text="mode === 'dashboard' ? 'แดชบอร์ด' : rangeLabel()"></h3>
        <span x-show="saving > 0" class="text-xs text-slate-400">กำลังบันทึก...</span>
        <span x-show="!saving && savedAt" class="text-xs text-slate-400" x-text="'✓ บันทึกแล้ว ' + savedAt"></span>

        <div class="inline-flex overflow-hidden rounded-lg border border-slate-300 text-sm font-medium">
            <button type="button" @click="mode = 'month'" :class="mode === 'month' ? 'bg-blue-600 text-white' : 'bg-white text-slate-600 hover:bg-slate-50'" class="px-3 h-8">เดือน</button>
            <button type="button" @click="timeline()" :class="mode === 'timeline' ? 'bg-blue-600 text-white' : 'bg-white text-slate-600 hover:bg-slate-50'" class="px-3 h-8 border-l border-slate-300">ไทม์ไลน์</button>
            <button type="button" @click="mode = 'dashboard'" :class="mode === 'dashboard' ? 'bg-blue-600 text-white' : 'bg-white text-slate-600 hover:bg-slate-50'" class="px-3 h-8 border-l border-slate-300">แดชบอร์ด</button>
        </div>

        <div class="relative flex-1 min-w-[12rem] max-w-md">
            <svg class="pointer-events-none absolute left-2.5 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-400" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="11" cy="11" r="7"/><path stroke-linecap="round" d="M21 21l-4.3-4.3"/></svg>
            <input type="search" x-model="search" placeholder="ค้นหางาน / ตู้..." class="w-full h-8 rounded-lg border-slate-300 pl-8 pr-2 text-sm placeholder:text-slate-400">
        </div>

        <div class="inline-flex items-center gap-1.5 text-xs font-medium">
            <button type="button" @click="priority = 'all'" :class="priority === 'all' ? 'border-slate-900 text-slate-900 ring-1 ring-slate-900' : 'border-slate-300 text-slate-600 hover:bg-slate-50'" class="h-7 rounded-md border bg-white px-2.5">ทั้งหมด</button>
            <button type="button" @click="priority = 'urgent'" :class="priority === 'urgent' ? 'border-red-500 ring-1 ring-red-500' : 'border-slate-300 hover:bg-slate-50'" class="h-7 inline-flex items-center gap-1 rounded-md border bg-white px-2.5 text-slate-700"><svg class="w-3.5 h-3.5 text-red-600" viewBox="0 0 24 24" fill="currentColor"><path d="M5 3v18h2v-7h11l-2-4 2-4H7V3H5z"/></svg>ด่วน</button>
            <button type="button" @click="priority = 'high'" :class="priority === 'high' ? 'border-orange-500 ring-1 ring-orange-500' : 'border-slate-300 hover:bg-slate-50'" class="h-7 inline-flex items-center gap-1 rounded-md border bg-white px-2.5 text-slate-700"><svg class="w-3.5 h-3.5 text-orange-500" viewBox="0 0 24 24" fill="currentColor"><path d="M5 3v18h2v-7h11l-2-4 2-4H7V3H5z"/></svg>สูง</button>
        </div>
    </div>

    <div class="flex flex-wrap items-center gap-x-3 gap-y-2">
        {{-- department view: one chip per project on that department's own due date (a department user's default) --}}
        <select x-model="dept" class="h-8 rounded-lg border-slate-300 py-0 pl-3 pr-8 text-xs font-medium" aria-label="แผนก">
            <option value="">ทุกแผนก</option>
            <template x-for="d in departments" :key="d.id"><option :value="d.id" x-text="d.name" :selected="d.id === dept"></option></template>
        </select>
        {{-- several departments at once: only projects any of them works on --}}
        <details class="relative" x-show="!dept">
            <summary class="h-8 inline-flex cursor-pointer list-none items-center gap-1 rounded-lg border border-slate-300 bg-white px-2.5 text-xs font-medium text-slate-600 hover:bg-slate-50" x-text="multiDepts.length ? 'หลายแผนก (' + multiDepts.length + ')' : 'หลายแผนก ▾'"></summary>
            <div class="absolute left-0 z-30 mt-1 max-h-72 w-56 overflow-y-auto rounded-lg border border-slate-200 bg-white p-2 shadow-lg">
                <template x-for="d in departments" :key="'md' + d.id">
                    <label class="flex cursor-pointer items-center gap-2 rounded px-1.5 py-1 text-xs hover:bg-slate-50"><input type="checkbox" :value="d.id" x-model="multiDepts" class="rounded border-slate-300 text-blue-600"><span class="h-2 w-2 rounded-full" :style="'background-color:' + d.color"></span><span x-text="d.name"></span></label>
                </template>
                <button type="button" x-show="multiDepts.length" @click="multiDepts = []" class="mt-1 w-full text-xs text-blue-600 hover:underline">ล้าง</button>
            </div>
        </details>
        <label class="inline-flex items-center gap-1.5 text-xs text-slate-600 cursor-pointer select-none" title="แต่ละตู้เป็นแถบของตัวเองตามวันของตู้นั้น"><input type="checkbox" :checked="byCabinet" @change="setByCabinet($event.target.checked)" class="rounded border-slate-300 text-blue-600"> แยกรายตู้</label>
        <label class="inline-flex items-center gap-1.5 text-xs text-slate-600 cursor-pointer select-none"><input type="checkbox" x-model="hideDone" class="rounded border-slate-300 text-blue-600"> ซ่อนงานที่เสร็จแล้ว</label>
        <button type="button" @click="mineOnly = !mineOnly" :aria-pressed="mineOnly" :class="mineOnly ? 'bg-blue-600 border-blue-600 text-white' : 'bg-white border-slate-300 text-slate-600 hover:bg-slate-50'" class="h-8 inline-flex items-center gap-1 rounded-lg border px-2.5 text-xs font-medium" title="งานของแผนกฉันที่ยังไม่เสร็จ โครงการที่ฉันเป็น PM หรือมีคนแท็ก / แจ้งเตือนถึงฉัน">👤 ของฉัน</button>
        <button type="button" @click="starOnly = !starOnly" :aria-pressed="starOnly" :class="starOnly ? 'bg-amber-500 border-amber-500 text-white' : 'bg-white border-slate-300 text-slate-600 hover:bg-slate-50'" class="h-8 inline-flex items-center gap-1 rounded-lg border px-3 text-xs font-medium" title="เฉพาะโครงการที่ติดดาว (เก็บไว้ในเบราว์เซอร์นี้)">★ ดาว</button>

        <div class="ml-auto flex items-center gap-3">
            {{-- what the colours mean: opens on hover (mouse) or tap (iPad / phone) --}}
            <div class="relative" x-data="{ open: false, pinned: false }" @mouseenter="open = true" @mouseleave="if (!pinned) open = false" @click.outside="open = false; pinned = false" @keydown.escape.window="open = false; pinned = false">
                <button type="button" @click="pinned = !pinned; open = pinned" :aria-expanded="open" aria-label="ความหมายของสี"
                        class="h-8 inline-flex items-center gap-1.5 rounded-lg border border-slate-300 bg-white px-2.5 text-xs font-medium text-slate-600 hover:bg-slate-50">
                    <span class="flex items-center gap-1"><template x-for="s in legendStatuses().slice(0, 6)" :key="'ld' + s.id"><span class="w-2.5 h-2.5 rounded-full" :style="'background-color:' + s.color"></span></template></span>
                    <span>สี</span>
                </button>
                <div x-show="open" x-transition.opacity x-cloak class="absolute right-0 top-full z-40 mt-1.5 w-[min(22rem,calc(100vw-2rem))] max-h-[70vh] overflow-y-auto rounded-xl border border-slate-200 bg-white p-3 text-xs shadow-xl" role="dialog" aria-label="ความหมายของสี">
                    <p class="mb-1.5 font-bold text-slate-800">สีของแถบในปฏิทิน = สถานะโครงการ</p>
                    <ul class="grid grid-cols-2 gap-x-3 gap-y-1">
                        <template x-for="s in legendStatuses()" :key="'lg' + s.id">
                            <li class="flex items-center gap-1.5 text-slate-700"><span class="h-3 w-5 shrink-0 rounded-sm border-l-[3px]" :style="chipStyle(s.color, false)"></span>
                                <span class="truncate" x-text="s.name + (s.pmo ? ' (PMO)' : '')" :title="s.pmo ? 'ยังไม่ได้ตั้งสถานะโครงการ — แสดงสถานะ PMO' : 'ตั้งใน ตั้งค่า › สถานะงาน'"></span></li>
                        </template>
                    </ul>
                    <p class="mt-1 text-[11px] text-slate-400">(PMO) = ยังไม่ได้ตั้งสถานะโครงการ จึงแสดงสถานะ PMO · แถบจาง + ขีดฆ่า = เสร็จแล้ว · "เลย n วัน" = เกินกำหนด</p>

                    <p class="mb-1.5 mt-3 font-bold text-slate-800">เส้นสีใต้แถบ = ช่วงงานของแต่ละแผนก</p>
                    <ul class="grid grid-cols-2 gap-x-3 gap-y-1">
                        <template x-for="d in legendDepartments()" :key="'lgd' + d.id">
                            <li class="flex items-center gap-1.5 text-slate-700"><span class="h-1 w-5 shrink-0 rounded-full" :style="'background-color:' + d.color"></span><span class="truncate" x-text="d.name"></span></li>
                        </template>
                        <li class="flex items-center gap-1.5 text-slate-700"><span class="h-1 w-5 shrink-0 rounded-full" :style="'background-color:' + lateColor()"></span><span>แผนกที่เกินกำหนด</span></li>
                    </ul>

                    <p class="mb-1.5 mt-3 font-bold text-slate-800">ป้ายสถานะงานของแผนก (ในหน้าต่างโครงการ)</p>
                    <ul class="flex flex-wrap gap-1">
                        <template x-for="s in statuses" :key="'lgs' + s.id"><li class="rounded-full px-2 py-0.5 text-[11px] font-semibold" :style="'background-color:' + s.color + '26;color:' + s.color" x-text="s.name"></li></template>
                    </ul>
                    <p class="mt-1 text-[11px] text-slate-400">คิดจากงานของแผนกเอง · มีจุด • ต่อท้าย = Admin / PM ตั้งเอง</p>

                    <p class="mb-1.5 mt-3 font-bold text-slate-800">ตัวเลขที่มุมวัน</p>
                    <ul class="space-y-1 text-slate-700">
                        <li class="flex items-center gap-1.5"><span class="rounded-full bg-blue-50 px-1.5 text-[10px] font-bold text-blue-700">3</span> งานที่ยังไม่เสร็จในวันนั้น</li>
                        <li class="flex items-center gap-1.5"><span class="rounded-full bg-red-100 px-1.5 text-[10px] font-bold text-red-700">3</span> มีงานที่เลยกำหนดอยู่ในวันนั้น</li>
                    </ul>
                </div>
            </div>
            <button type="button" x-show="waitingCount()" x-cloak @click="document.getElementById('waiting-box').scrollIntoView({ behavior: 'smooth', block: 'start' })"
                    class="h-8 inline-flex items-center gap-1 rounded-lg border border-amber-300 bg-amber-50 px-2.5 text-xs font-semibold text-amber-800 hover:bg-amber-100" title="งานที่ยังรอแผนกกดรับ">📥 รอรับ <span class="rounded-full bg-amber-600 px-1.5 text-[10px] text-white" x-text="waitingCount()"></span></button>
            <a x-show="perms.export" :href="exportUrl()" class="h-8 inline-flex items-center rounded-lg border border-slate-300 bg-white px-3 text-xs font-medium text-slate-600 hover:bg-slate-50" title="ดาวน์โหลดงานของเดือนที่แสดงเป็น CSV">Export CSV</a>
            <button type="button" @click="drawer = drawer === 'stars' ? null : 'stars'" class="h-8 rounded-lg border border-slate-300 bg-white px-2.5 text-xs font-medium text-slate-600 hover:bg-slate-50" title="โครงการที่ติดดาว">★ <span x-text="starredTasks().length"></span></button>
            <button type="button" x-show="canTrash" @click="drawer = drawer === 'trash' ? null : 'trash'" class="h-8 rounded-lg border border-slate-300 bg-white px-2.5 text-xs font-medium text-slate-600 hover:bg-slate-50" title="ถังขยะ — กู้คืนโครงการ">🗑 <span x-text="trash.length"></span></button>
            <a :href="urls.settings" class="h-8 inline-flex items-center rounded-lg border border-slate-300 bg-white px-2.5 text-xs font-medium text-slate-600 hover:bg-slate-50" title="ตั้งค่า">⚙</a>
            <button type="button" x-show="isPmo" @click="openNewProject()" class="w-9 h-9 inline-flex items-center justify-center rounded-full bg-blue-600 text-white text-xl leading-none shadow hover:bg-blue-700" title="สร้างโครงการใหม่" aria-label="สร้างโครงการใหม่">+</button>
        </div>
    </div>
</div>
