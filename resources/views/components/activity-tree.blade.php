@props(['url', 'exportUrl' => null, 'subtitle' => 'ประวัติการเปลี่ยนแปลงทั้งหมด จัดกลุ่มตามโครงสร้างงาน เรียงล่าสุดก่อน'])

{{-- Activity Log tab as a Cabinet > Task > Sub Task > Checklist > Activity tree.
     Pure presentation over the existing activity_logs rows: the server groups them
     (ActivityTreeBuilder) and this renders only the rows under expanded nodes, so a
     project with hundreds of entries stays cheap to draw. --}}
<x-card title="Activity Log" :subtitle="$subtitle">
    <div x-data="activityTree(@js($url), @js($exportUrl))" class="-mx-5 -mb-5" style="--ind: 22px">
        {{-- Filters --}}
        <div class="px-5 pb-3 flex flex-wrap items-center gap-2 text-sm">
            <select x-model="range" @change="applyRange()" class="rounded-lg border-slate-300 text-sm py-1.5">
                <option value="all">ทุกช่วงเวลา</option>
                <option value="today">วันนี้</option>
                <option value="7">7 วันล่าสุด</option>
                <option value="30">30 วันล่าสุด</option>
                <option value="custom">กำหนดเอง</option>
            </select>
            <template x-if="range === 'custom'">
                <span class="flex items-center gap-1">
                    <input type="date" x-model="f.from" @change="load()" class="rounded-lg border-slate-300 text-sm py-1.5">
                    <span class="text-slate-400">→</span>
                    <input type="date" x-model="f.to" @change="load()" class="rounded-lg border-slate-300 text-sm py-1.5">
                </span>
            </template>
            <select x-model="f.category" @change="load()" class="rounded-lg border-slate-300 text-sm py-1.5">
                <option value="">ประเภทกิจกรรม: ทั้งหมด</option>
                <option value="create">สร้าง</option>
                <option value="edit">แก้ไข</option>
                <option value="status">เปลี่ยนสถานะ</option>
                <option value="assignment">Assignment</option>
                <option value="checklist">Checklist</option>
                <option value="attachment">ไฟล์แนบ</option>
                <option value="delete">ลบ</option>
            </select>
            <select x-model="f.entity" @change="load()" class="rounded-lg border-slate-300 text-sm py-1.5">
                <option value="">Entity: ทั้งหมด</option>
                <option value="project">Project</option>
                <option value="cabinet">Cabinet</option>
                <option value="task">Task</option>
                <option value="subtask">Sub Task</option>
                <option value="checklist">Checklist</option>
            </select>
            <select x-model="f.user_id" @change="load()" class="rounded-lg border-slate-300 text-sm py-1.5">
                <option value="">ผู้ดำเนินการ: ทั้งหมด</option>
                <template x-for="u in users" :key="u.id"><option :value="u.id" x-text="u.name"></option></template>
            </select>
            <input type="search" x-model="f.q" @input.debounce.350ms="load()" placeholder="ค้นหา..." class="rounded-lg border-slate-300 text-sm py-1.5 w-full sm:w-52">
            <select x-model="f.sort" @change="load()" class="rounded-lg border-slate-300 text-sm py-1.5">
                <option value="desc">ล่าสุดก่อน</option>
                <option value="asc">เก่าสุดก่อน</option>
            </select>
            <span class="ml-auto flex items-center gap-3 text-xs">
                <button type="button" x-show="exportUrl" @click="exportCsv()" class="text-blue-600 hover:underline">Export CSV</button>
                <span class="text-slate-300">|</span>
                <button type="button" @click="expandAll()" class="text-blue-600 hover:underline">ขยายทั้งหมด</button>
                <span class="text-slate-300">|</span>
                <button type="button" @click="collapseAll()" class="text-blue-600 hover:underline">ยุบทั้งหมด</button>
            </span>
        </div>

        {{-- Column header (desktop) --}}
        <div class="hidden md:grid grid-cols-[minmax(0,1fr)_110px_170px_minmax(0,34%)] gap-3 px-5 py-1.5 border-y border-slate-100 bg-slate-50 text-[11px] font-medium uppercase tracking-wide text-slate-400">
            <span>โครงสร้าง / กิจกรรม <span x-show="total" class="normal-case font-normal" x-text="'· ' + total + ' กิจกรรม'"></span></span>
            <span>เวลา</span><span>ผู้ดำเนินการ</span><span>รายละเอียด</span>
        </div>

        <p x-show="loading" class="px-5 py-6 text-center text-slate-400 text-sm">กำลังโหลด...</p>
        <p x-show="error" x-text="error" class="px-5 py-6 text-center text-red-500 text-sm"></p>
        <p x-show="!loading && !error && nodes.length === 0" x-cloak class="px-5 py-6 text-center text-slate-400 text-sm"
           x-text="hasFilter() ? 'ไม่พบกิจกรรมตามเงื่อนไขที่เลือก' : 'ยังไม่มีประวัติการเปลี่ยนแปลง'"></p>

        <div class="divide-y divide-slate-50 overflow-x-hidden" x-show="!loading">
            <template x-for="r in rows()" :key="r.id">
                <div class="relative">
                    {{-- tree guide lines: one subtle vertical rule per ancestor level --}}
                    <template x-for="d in r.depth" :key="d">
                        <span class="absolute top-0 bottom-0 border-l border-slate-200" :style="'left:calc(var(--ind) * ' + (d - 1) + ' + 21px)'"></span>
                    </template>

                    {{-- node row --}}
                    <template x-if="r.node">
                        <button type="button" @click="toggle(r.node.key)"
                                class="relative w-full text-left flex items-center gap-2 pr-5 hover:bg-slate-50"
                                :class="r.node.type === 'cabinet' ? 'min-h-[42px] bg-slate-50/50' : (r.node.type === 'task' ? 'min-h-[40px]' : 'min-h-[36px]')"
                                :style="'padding-left: calc(var(--ind) * ' + r.depth + ' + 20px)'">
                            <svg class="w-3.5 h-3.5 text-slate-400 shrink-0 transition-transform" :class="open[r.node.key] ? 'rotate-90' : ''" viewBox="0 0 20 20" fill="currentColor"><path d="M7 4l6 6-6 6V4z"/></svg>
                            <span class="shrink-0 text-slate-400" x-html="icon(r.node.type)"></span>
                            <span class="text-[11px] text-slate-400 shrink-0 hidden sm:inline" x-text="typeWord(r.node)"></span>
                            <span class="truncate" :class="r.node.type === 'cabinet' ? 'font-semibold text-ae-navy text-sm' : (r.node.type === 'task' ? 'font-medium text-slate-800 text-sm' : 'text-slate-700 text-[13px]')" x-text="r.node.label"></span>
                            <span class="ml-auto shrink-0 flex items-center gap-3">
                                <span class="hidden lg:inline text-[11px] text-slate-400" x-show="!open[r.node.key] && r.node.latest" x-text="'ล่าสุด ' + r.node.latest"></span>
                                <span class="rounded-full bg-slate-100 text-slate-600 text-xs font-medium px-2 py-0.5 tabular-nums" x-text="r.node.count"></span>
                            </span>
                        </button>
                    </template>

                    {{-- activity leaf --}}
                    <template x-if="r.act">
                        <div class="relative md:grid grid-cols-[minmax(0,1fr)_110px_170px_minmax(0,34%)] gap-3 items-start pr-5 py-2 min-h-[38px] text-[13px]"
                             :style="'padding-left: calc(var(--ind) * ' + r.depth + ' + 20px)'">
                            <div class="flex items-start gap-2 min-w-0">
                                <span class="mt-0.5 shrink-0" :class="actionColor(r.act.icon)" x-html="icon(r.act.icon)"></span>
                                <span class="text-slate-800" x-text="r.act.title"></span>
                            </div>
                            <div class="text-xs text-slate-500 tabular-nums mt-0.5 md:mt-0.5 pl-6 md:pl-0" x-text="r.act.at"></div>
                            <div class="flex items-center gap-1.5 text-xs text-slate-600 min-w-0 pl-6 md:pl-0">
                                <span class="w-5 h-5 rounded-full bg-slate-100 text-[10px] font-semibold text-slate-500 flex items-center justify-center shrink-0" x-text="r.act.initial"></span>
                                <span class="truncate" x-text="r.act.actor"></span>
                            </div>
                            <div class="text-xs text-slate-500 min-w-0 pl-6 md:pl-0 mt-1 md:mt-0">
                                <template x-for="c in r.act.changes" :key="c.label">
                                    <p class="break-words"><span class="text-slate-400" x-text="c.label + ': '"></span><span class="text-slate-500" x-text="c.old"></span> <span class="text-slate-400">→</span> <span class="text-slate-800 font-medium" x-text="c.new"></span></p>
                                </template>
                                <p x-show="r.act.detail" class="break-words" x-text="r.act.detail"></p>
                            </div>
                        </div>
                    </template>
                </div>
            </template>
        </div>
    </div>
</x-card>

@once
    @push('scripts')
        <script>
            function activityTree(url, exportUrl) {
                const svg = (d) => '<svg class="w-4 h-4" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">' + d + '</svg>';
                const ICONS = {
                    cabinet: svg('<rect x="4" y="3" width="12" height="14" rx="1.5"/><path d="M10 3v14M7.5 10h.01M12.5 10h.01"/>'),
                    task: svg('<rect x="4" y="3" width="12" height="14" rx="1.5"/><path d="M7 7h6M7 10h6M7 13h3"/>'),
                    subtask: svg('<path d="M5 4v6a2 2 0 002 2h8M12 9l3 3-3 3"/>'),
                    checklist: svg('<circle cx="10" cy="10" r="6.5"/><path d="M7.5 10l2 2 3-4"/>'),
                    project: svg('<path d="M3 6a1.5 1.5 0 011.5-1.5H8l1.5 2h6A1.5 1.5 0 0117 8v6.5a1.5 1.5 0 01-1.5 1.5h-11A1.5 1.5 0 013 14.5V6z"/>'),
                    other: svg('<circle cx="10" cy="10" r="6.5"/><path d="M10 6.5v4M10 13.5h.01"/>'),
                    plus: svg('<path d="M10 5v10M5 10h10"/>'),
                    edit: svg('<path d="M12.5 4.5l3 3L8 15l-3.5.5L5 12l7.5-7.5z"/>'),
                    check: svg('<path d="M4.5 10.5l3.5 3.5 7.5-8"/>'),
                    circle: svg('<circle cx="10" cy="10" r="5.5"/>'),
                    arrow: svg('<path d="M4 10h11M11 6l4 4-4 4"/>'),
                    play: svg('<path d="M7 5l8 5-8 5V5z"/>'),
                    copy: svg('<rect x="7" y="7" width="9" height="9" rx="1.5"/><path d="M13 7V5.5A1.5 1.5 0 0011.5 4h-6A1.5 1.5 0 004 5.5v6A1.5 1.5 0 005.5 13H7"/>'),
                    clip: svg('<path d="M15 9.5l-5 5a3 3 0 01-4.2-4.2l5.5-5.5a2 2 0 112.8 2.8l-5.5 5.5a1 1 0 01-1.4-1.4L12 7"/>'),
                    trash: svg('<path d="M5 6h10M8 6V4.5h4V6M6.5 6l.5 9.5h6l.5-9.5"/>'),
                    dot: svg('<circle cx="10" cy="10" r="1.5"/>'),
                };
                const WORDS = { cabinet: 'Cabinet', task: 'Task', subtask: 'Sub Task', checklist: 'Checklist' };
                const iso = (d) => d.toISOString().slice(0, 10);

                return {
                    url, exportUrl, nodes: [], users: [], total: 0, open: {}, loading: true, error: '', range: 'all',
                    f: { from: '', to: '', category: '', entity: '', user_id: '', q: '', sort: 'desc' },
                    seq: 0,

                    init() { this.load(); },

                    hasFilter() { return Object.values(this.f).some((v, i) => v && Object.keys(this.f)[i] !== 'sort'); },

                    applyRange() {
                        const today = new Date();
                        if (this.range === 'all') { this.f.from = ''; this.f.to = ''; }
                        else if (this.range === 'today') { this.f.from = this.f.to = iso(today); }
                        else if (this.range !== 'custom') {
                            this.f.from = iso(new Date(today.getTime() - (Number(this.range) - 1) * 864e5));
                            this.f.to = iso(today);
                        }
                        if (this.range !== 'custom') this.load();
                    },

                    params() {
                        const params = new URLSearchParams();
                        Object.entries(this.f).forEach(([k, v]) => v && params.set(k, v));
                        return params;
                    },

                    // What's on screen is what's exported: same filters, flattened to rows.
                    exportCsv() { window.location = this.exportUrl + '?' + this.params(); },

                    async load() {
                        const mine = ++this.seq;
                        const params = this.params();
                        this.loading = true; this.error = '';
                        try {
                            const res = await fetch(this.url + '?' + params, { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } });
                            if (!res.ok) throw new Error();
                            const data = await res.json();
                            if (mine !== this.seq) return; // a newer filter change already won
                            this.nodes = data.nodes; this.total = data.total;
                            if (this.users.length === 0) this.users = data.users;
                            // A search shows its ancestor path: open every node that survived it.
                            if (this.f.q) this.setAll(true);
                        } catch (e) {
                            this.error = 'โหลดประวัติการเปลี่ยนแปลงไม่สำเร็จ';
                        } finally {
                            if (mine === this.seq) this.loading = false;
                        }
                    },

                    toggle(key) { this.open[key] = !this.open[key]; },

                    setAll(value) {
                        const walk = (list) => list.forEach((n) => { this.open[n.key] = value; walk(n.children); });
                        walk(this.nodes);
                    },
                    expandAll() {
                        // Rendering every activity of a big project at once is what this
                        // tree exists to avoid - make that an explicit choice.
                        if (this.total > 400 && !confirm('มี ' + this.total + ' กิจกรรม การขยายทั้งหมดอาจทำให้หน้าช้า ต้องการดำเนินการต่อหรือไม่?')) return;
                        this.setAll(true);
                    },
                    collapseAll() { this.setAll(false); },

                    // Flatten only what's visible: a collapsed node contributes its own row and nothing below it.
                    rows() {
                        const out = [];
                        const walk = (list, depth) => list.forEach((n) => {
                            out.push({ id: 'n-' + n.key, depth, node: n });
                            if (!this.open[n.key]) return;
                            walk(n.children, depth + 1);
                            n.activities.forEach((a) => out.push({ id: 'a-' + n.key + '-' + a.id, depth: depth + 1, act: a }));
                        });
                        walk(this.nodes, 0);
                        return out;
                    },

                    icon(kind) { return ICONS[kind] || ICONS.dot; },
                    typeWord(n) { return n.word || WORDS[n.type] || ''; },
                    actionColor(kind) {
                        return { check: 'text-emerald-600', plus: 'text-blue-600', trash: 'text-red-500', circle: 'text-slate-400' }[kind] || 'text-slate-500';
                    },
                };
            }
        </script>
    @endpush
@endonce
