// The Planning page's Alpine component (registered from app.js before Alpine starts; was an inline <script> in the page,
// now in the bundle so the browser caches it instead of every page load carrying ~90 KB).
// The Planning page's Alpine component (same pattern as my-department/partials/_script).
// Data: the board built by App\Services\PlanningBoard (project -> task, cabinet -> subtask with checklist items tagged by
// department, "deptStages" = one chip per department). Writes: ONE operation per user action, POSTed to planning.sync
// (App\Services\PlanningSync -> PMO's own services and rules). The page updates optimistically; the server's answer
// carries the true copy of the edited projects, so a refused edit simply bounces back with the reason in a toast.
import Alpine from 'alpinejs';

export default function registerPlanningBoard() {
    const MONTHS = ['มกราคม','กุมภาพันธ์','มีนาคม','เมษายน','พฤษภาคม','มิถุนายน','กรกฎาคม','สิงหาคม','กันยายน','ตุลาคม','พฤศจิกายน','ธันวาคม'];
    const WEEKDAYS = ['จ.', 'อ.', 'พ.', 'พฤ.', 'ศ.', 'ส.', 'อา.']; // Monday first
    const PRIORITIES = {
        none: { label: 'ไม่ระบุ', color: '#98a2b3' }, low: { label: 'ต่ำ', color: '#3b82f6' }, medium: { label: 'ปานกลาง', color: '#eab308' },
        high: { label: 'สูง', color: '#f97316' }, urgent: { label: 'ด่วน', color: '#ef4444' },
    };
    const TH_DAYS = ['อาทิตย์', 'จันทร์', 'อังคาร', 'พุธ', 'พฤหัสบดี', 'ศุกร์', 'เสาร์'];
    const PRIORITY_RANK = { urgent: 4, high: 3, medium: 2, low: 1, none: 0 };
    const TH_MON_SHORT = ['ม.ค.', 'ก.พ.', 'มี.ค.', 'เม.ย.', 'พ.ค.', 'มิ.ย.', 'ก.ค.', 'ส.ค.', 'ก.ย.', 'ต.ค.', 'พ.ย.', 'ธ.ค.'];
    const AVATAR_COLORS = ['#2563eb', '#0e9384', '#d97706', '#db2777', '#0891b2', '#65a30d', '#dc2626', '#7c3aed'];
    const MAX_LANES = 4; // bars per week before "+N"
    const TL_DAY = 36;   // timeline column width (px)
    const pad = (n) => String(n).padStart(2, '0');
    const ymd = (d) => d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate());
    const parse = (s) => { const [y, m, d] = s.split('-').map(Number); return new Date(y, m - 1, d); };
    const addDays = (s, n) => { const d = parse(s); d.setDate(d.getDate() + n); return ymd(d); };
    const diffDays = (a, b) => Math.round((parse(a) - parse(b)) / 86400000);
    const hexToRgba = (hex, a) => { const n = parseInt(hex.slice(1), 16); return 'rgba(' + ((n >> 16) & 255) + ',' + ((n >> 8) & 255) + ',' + (n & 255) + ',' + a + ')'; };
    // dates are shown dd/mm/yyyy in พ.ศ. (like the rest of this page: "ตุลาคม 2569"); the value sent / stored stays YYYY-MM-DD
    const beFormat = (date, fmt) => (fmt === 'Y-m-d' ? ymd(date) : pad(date.getDate()) + '/' + pad(date.getMonth() + 1) + '/' + (date.getFullYear() + 543));
    const beParse = (str, fmt) => {
        if (!str) return undefined;
        if (fmt === 'Y-m-d') return parse(str);
        const m = String(str).trim().match(/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/);
        if (!m) return undefined;
        const y = Number(m[3]) > 2400 ? Number(m[3]) - 543 : Number(m[3]), d = new Date(y, Number(m[2]) - 1, Number(m[1]));
        return d.getMonth() === Number(m[2]) - 1 ? d : undefined;
    };
    const csrf = () => document.querySelector('meta[name="csrf-token"]').content;

    Alpine.data('planningBoard', (config) => {
        const board = config.root.boards[config.boardId];
        // "★" is a personal bookmark kept in THIS browser only (per user) - nothing is sent to PMO
        const starKey = 'planning.stars.' + config.userId;
        const loadStars = () => { try { const v = JSON.parse(localStorage.getItem(starKey) || '[]'); return Array.isArray(v) ? v : []; } catch (e) { return []; } };
        const t0 = parse(config.today);

        return {
            // ---- data (the board) ----
            tasks: board.tasks,
            departments: board.departments,
            statuses: board.statuses,
            pmoStatusList: board.pmoStatusList || [],
            labels: board.labels || [],
            boards: config.boards || [],
            boardNo: config.boardNo ?? null,
            mine: config.mine || [], mineOnly: false,
            files: [],
            templates: config.templates || [],
            jobTypes: config.jobTypes || [], pmoStatuses: config.pmoStatuses || {}, staff: config.staff || [],
            cabOpen: {},                      // expanded cabinet rows: { c12: { loading, cabinet, subtasks } }
            cabDialog: null,                  // the "new cabinet" window: MO, name, template, and which department gets each of its sub tasks
            dispatch: null,                   // the "hand out work" window: { taskId, cabId, preset, assign: { s12: 'd_5' } }
            me: config.me || { id: config.userId, name: config.userName, avatar: null, deptId: '' },
            canTrash: !!config.canTrash,
            perms: config.perms || {},        // Settings > สิทธิ์การใช้งาน (the server leaves out / refuses the rest anyway)
            trash: config.trash || [], managers: config.managers || [],
            drawer: null,                     // 'trash' | 'stars' side drawer
            newProject: null,                 // the "new project" window's form
            viewer: null,                     // { url, name, kind: 'image' | 'pdf' } photo / PDF shown over the page
            statusPop: null,                  // { taskId, cabId?, top, left } quick status picker opened from a bar / cabinet
            replyTo: null,                    // the comment being answered
            multiDepts: [],
            byCabinet: (() => { try { return localStorage.getItem('planning.byCabinet') === '1'; } catch (e) { return false; } })(),   // "แยกรายตู้"                   // extra filter: projects any of these departments work on
            today: config.today,
            canEdit: config.canEdit,
            isPmo: config.isPmo,
            myDept: config.myDept,
            urls: config.urls,
            priorities: PRIORITIES,

            // ---- view state ----
            mode: 'month',                    // 'month' | 'timeline' | 'dashboard'
            year: t0.getFullYear(),
            month: t0.getMonth(),
            focusDate: config.today,          // the left panel's day
            search: '',
            priority: 'all',                  // 'all' | 'urgent' | 'high'
            dept: config.myDept || '',        // '' = every department; a department user always starts on their own
            hideDone: false,
            moreOpen: false,
            modalId: null,                    // open project modal
            stageDept: null,                  // open department popover (inside the modal)
            dashProject: '',                  // the dashboard shows this one project ('' = every project in view)
            stagePos: { top: 0, left: 0 },
            cl: null,                         // open checklist popover: { taskId, cabId, top, left, adding: deptId|null }
            clDraft: '',                      // text of the item being added
            comment: '', alertDept: '', posting: false,
            drag: null,
            // @-tagging in comments: the people the picker offers (the server re-checks every tag), and the open picker
            people: config.people || [],
            stars: loadStars(), starOnly: false,
            mentionOpen: false, mentionQuery: '', mentionIdx: 0,
            // project modal size: 'normal' | 'wide' | 'full' (remembered in this browser only)
            feedTab: 'activity',
            // CSV of the month on screen (the department filter, or every department for PMO roles with none chosen)
            exportUrl() {
                const p = (n) => String(n).padStart(2, '0'), last = new Date(this.year, this.month + 1, 0).getDate();
                const q = new URLSearchParams({ from: this.year + '-' + p(this.month + 1) + '-01', to: this.year + '-' + p(this.month + 1) + '-' + p(last), department_id: this.dept ? String(this.dept).replace('d_', '') : 'all' });
                return this.urls.export + '?' + q;
            },
            // change log tree (the project page's Activity Log), loaded when the tab is opened
            logTree: { nodes: [], total: 0, open: {}, loading: false, error: '', id: null },
            logWords: { cabinet: 'Cabinet', task: 'Task', subtask: 'Sub Task', checklist: 'Checklist' },
            logDot(icon) { return { check: 'bg-emerald-500', plus: 'bg-blue-500', trash: 'bg-red-400' }[icon] || 'bg-slate-300'; },
            logToggle(key) { this.logTree.open[key] = !this.logTree.open[key]; },
            logSetAll(v) { const walk = (l) => l.forEach((n) => { this.logTree.open[n.key] = v; walk(n.children || []); }); walk(this.logTree.nodes); },
            logRows() {
                const out = [], open = this.logTree.open;
                const walk = (list, depth) => list.forEach((n) => {
                    out.push({ id: 'n-' + n.key, depth, node: n });
                    if (!open[n.key]) return;
                    walk(n.children || [], depth + 1);
                    (n.activities || []).forEach((a) => out.push({ id: 'a-' + n.key + '-' + a.id, depth: depth + 1, act: a }));
                });
                walk(this.logTree.nodes, 0);
                return out;
            },
            async openLog() {
                this.feedTab = 'log';
                const t = this.cur, lt = this.logTree;
                if (!t || !t.logUrl || (lt.id === t.id && !lt.error)) return;
                Object.assign(lt, { nodes: [], total: 0, open: {}, loading: true, error: '', id: t.id });
                try {
                    const res = await fetch(t.logUrl, { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } });
                    if (!res.ok) throw new Error();
                    const data = await res.json();
                    if (lt.id !== t.id) return;
                    lt.nodes = data.nodes; lt.total = data.total;
                } catch (e) { if (lt.id === t.id) lt.error = 'โหลดประวัติการเปลี่ยนแปลงไม่สำเร็จ'; }
                finally { if (lt.id === t.id) lt.loading = false; }
            },
            modalSize: (() => { try { const v = localStorage.getItem('planning.modalSize'); return ['normal', 'wide', 'full'].includes(v) ? v : 'wide'; } catch (e) { return 'wide'; } })(),

            // ---- saving ----
            pending: {}, queue: Promise.resolve(), saving: 0, savedAt: '', etag: null,

            init() {
                // a notification's link (?project=ID) opens that project; an unknown / not-visible one says so instead of doing nothing
                if (config.open) {
                    this.$nextTick(() => {
                        const t = this.taskById('p' + config.open);
                        if (t) this.openModal(t.id); else window.showToast('ไม่พบโครงการนี้ หรือคุณไม่มีสิทธิ์ดู', 'error');
                        if (t && config.openStage) setTimeout(() => this.openStageFromLink(t, config.openStage, config.autoAccept), 150);
                        this.projectInUrl(t ? t.id : null);   // keeps ?project= (and ?board=), drops the one-off ?stage= / ?accept=
                    });
                }
                // pick up other people's changes every minute, but never while something is being sent or edited
                // pick up other people's changes: every 15 s, and at once when the tab comes back into view (an unchanged board costs a 304)
                const poll = () => { if (!this.saving && !document.hidden && !this.stageDept && !this.cl && !this.newProject) this.refresh(); };
                val(poll, 15000);
                document.addEventListener('visibilitychange', () => { if (!document.hidden) poll(); });
                window.addEventListener('resize', () => { if (this.cl && this.cl.anchor) this.clPlace(); });   // e.g. an iPad turned round
            },

            // =================================================== lookups
            // a PROJECT's status: the one Admin / PM picked (Settings > สถานะงาน), else PMO's own project status (pmo_*)
            statusOf(t) { return this.statuses.find((s) => s.id === t.statusId) || this.pmoStatusList.find((s) => s.id === t.statusId) || this.pmoStatusList[0] || this.statuses[0]; },
            statusColor(t) { return this.statusOf(t).color; },
            // the bar colours in use (the calendar colours bars by project status): Settings' list, then PMO's ones shown by a project
            // the departments whose stripes can be on the calendar now (they have work on a project in view)
            legendDepartments() { const used = new Set(this.visible().flatMap((t) => (t.deptStages || []).map((s) => s.deptId))); return this.departments.filter((d) => used.has(d.id)); },
            legendStatuses() { const used = new Set(this.tasks.map((t) => this.statusOf(t).id)); return this.statuses.concat(this.pmoStatusList.filter((s) => used.has(s.id))); },
            // "เกินกำหนด" bars use that status's colour (Settings > สถานะงาน), not a fixed red
            lateColor() { const s = this.statuses.find((x) => x.id === 's_fail'); return s ? s.color : '#ef4444'; },
            isDone(t) { return !!this.statusOf(t).isDone; },
            deptOf(id) { return this.departments.find((d) => d.id === id) || { id, name: id, color: '#98a2b3', icon: '' }; },
            stageOf(t, deptId) { return (t.deptStages || []).find((s) => s.deptId === deptId) || null; },
            // a DEPARTMENT's status in a project: worked out from its work (รอรับ / รับแล้ว / กำลังทำ / เสร็จ / เกินกำหนด), or set by Admin / PM
            stageStatus(s) { return this.statuses.find((x) => x.id === s.statusId) || this.statuses[0] || { name: '', color: '#98a2b3' }; },
            stageDone(s) { return s.manualStatus ? !!this.stageStatus(s).isDone : !!s.done; },
            taskById(id) { return this.tasks.find((t) => t.id === id) || null; },
            get cur() { return this.modalId ? this.taskById(this.modalId) : null; },
            flag(t) { return t.priority && t.priority !== 'none' ? PRIORITIES[t.priority].color : ''; },
            chipStyle(hex, done) { return 'background-color:' + hexToRgba(hex, done ? 0.12 : 0.17) + ';border-left-color:' + hex; },
            fmtBE(s) { if (!s) return '-'; const [y, m, d] = s.split('-'); return d + '/' + m + '/' + (Number(y) + 543); },
            isStarred(t) { return this.stars.includes(t.id); },
            toggleStar(t) {
                this.stars = this.isStarred(t) ? this.stars.filter((x) => x !== t.id) : [...this.stars, t.id];
                try { localStorage.setItem(starKey, JSON.stringify(this.stars)); } catch (e) { /* not remembered */ }
            },
            // a department chip's due date as the original page words it: "17 ต.ค.", "วันนี้" / "พรุ่งนี้" (amber), overdue (red)
            stageInfo(s) {
                const end = 'end' in s ? s.end : s.due;   // the department's real end date (null = nothing dated yet)
                if (!end) return { label: s.done ? '—' : 'ไม่มีวันส่ง', cls: s.done ? 'text-slate-400' : 'text-amber-700 font-semibold' };
                const diff = diffDays(end, this.today), d = parse(end);
                let label = d.getDate() + ' ' + TH_MON_SHORT[d.getMonth()], cls = 'text-slate-500';
                if (!s.done) {
                    if (diff === 0) { label = 'วันนี้'; cls = 'text-amber-700 font-semibold'; }
                    else if (diff === 1) { label = 'พรุ่งนี้'; cls = 'text-amber-700 font-semibold'; }
                    else if (diff === 2) { cls = 'text-amber-700 font-semibold'; }
                    else if (diff < 0) { cls = 'text-red-600 font-bold'; }
                } else { cls = 'text-emerald-700'; }
                return { label, cls };
            },
            fmtDateTime(ts) { const d = new Date(ts); return pad(d.getDate()) + '/' + pad(d.getMonth() + 1) + '/' + (d.getFullYear() + 543) + ' ' + pad(d.getHours()) + ':' + pad(d.getMinutes()); },
            avatarColor(name) { let h = 0; for (const ch of String(name || '')) h = (h * 31 + ch.charCodeAt(0)) >>> 0; return AVATAR_COLORS[h % AVATAR_COLORS.length]; },
            initial(name) { return String(name || '?').trim().charAt(0).toUpperCase(); },
            scrollFeed() { this.$nextTick(() => { const el = this.$refs.feedBox; if (el) el.scrollTop = el.scrollHeight; }); },
            daysFromToday(date) { return date ? diffDays(date, this.today) : 0; },
            fmtShort(s) { return s ? s.slice(8) + '/' + s.slice(5, 7) : ''; },
            isWeekend(date) { const n = parse(date).getDay(); return n === 0 || n === 6; },
            // a task's date as seen in the current department view: that department's own due date, else the project's
            dueFor(t) { if (this.dept) { const s = this.stageOf(t, this.dept); if (s) return s.end; } return t.date; },
            doneFor(t) { if (this.dept) { const s = this.stageOf(t, this.dept); if (s) return this.stageDone(s) || this.isDone(t); } return this.isDone(t); },
            overdueFor(t) { return !this.doneFor(t) && this.dueFor(t) && this.dueFor(t) < this.today; },
            items(t, deptId) { // checklist items of a task, optionally only one department's
                return t.subtasks.flatMap((c) => c.checklist.filter((i) => !deptId || i.deptId === deptId));
            },
            progress(t, deptId) { const it = this.items(t, deptId); const done = it.filter((i) => i.done).length; return { done, total: it.length, pct: it.length ? Math.round(done / it.length * 100) : 0 }; },
            cabStatus(c) { return this.statuses.find((s) => s.id === c.statusId) || this.statuses[0]; },
            cabColor(c) { return this.cabStatus(c).color; },
            cabDone(c) { return !!this.cabStatus(c).isDone; },
            cabProgress(c, deptId) { const it = c.checklist.filter((i) => !deptId || i.deptId === deptId); const done = it.filter((i) => i.done).length; return { done, total: it.length, pct: it.length ? Math.round(done / it.length * 100) : 0 }; },

            // =================================================== filtering
            visible() {
                const q = this.search.trim().toLowerCase();
                return this.tasks.filter((t) => {
                    if (this.hideDone && this.doneFor(t)) return false;
                    if (this.priority !== 'all' && t.priority !== this.priority) return false;
                    if (this.starOnly && !this.isStarred(t)) return false;
                    if (this.mineOnly && !this.mine.includes(Number(t.id.slice(1)))) return false;
                    if (this.dept && !this.stageOf(t, this.dept)) return false;
                    if (this.multiDepts.length && !this.multiDepts.some((d) => this.stageOf(t, d))) return false;
                    if (q && ![t.so, t.title, ...t.subtasks.map((c) => c.mo + ' ' + c.text)].join(' ').toLowerCase().includes(q)) return false;
                    return true;
                });
            },

            // =================================================== month grid
            rangeLabel() { return MONTHS[this.month] + ' ' + (this.year + 543); },
            weekdays() { return WEEKDAYS; },
            gridDays() {
                const first = new Date(this.year, this.month, 1), last = new Date(this.year, this.month + 1, 0);
                const from = new Date(first); from.setDate(from.getDate() - ((from.getDay() + 6) % 7));
                const to = new Date(last); to.setDate(to.getDate() + ((7 - to.getDay()) % 7));
                const days = [];
                for (const d = new Date(from); d <= to; d.setDate(d.getDate() + 1)) {
                    days.push({ date: ymd(d), day: d.getDate(), inMonth: d.getMonth() === this.month });
                }
                return days;
            },
            weekRows() { const days = this.gridDays(), rows = []; for (let i = 0; i < days.length; i += 7) rows.push(days.slice(i, i + 7)); return rows; },
            shiftMonth(n) { const d = new Date(this.year, this.month + n, 1); this.year = d.getFullYear(); this.month = d.getMonth(); },
            goToday() { const d = parse(this.today); this.year = d.getFullYear(); this.month = d.getMonth(); this.focusDate = this.today; },

            // One bar per project over its dates; in a department view one single-day chip on that department's due date.
            // ---- what the calendar draws (month + timeline + "งานในวันที่"): one entry per project - or per cabinet with
            //      "แยกรายตู้" - over its dates in the current view. In a department's view that is the department's own range
            //      (start - end of all its work in the project); unfinished work past its end runs on to TODAY (red), so late
            //      work is always in sight. Work with no due date is not drawn (it is listed in the left panel instead).
            rangeOf(t, c) {
                const today = this.today;
                const make = (start, end, done) => {
                    if (!end) return null;
                    const late = !done && end < today;
                    return { from: start && start <= end ? start : end, to: late ? today : end, end, done, late };
                };
                if (this.dept) {
                    const r = c ? (c.deptRanges || {})[this.dept] : this.stageOf(t, this.dept);
                    return r ? make(r.start, r.end, c ? !!r.done : (this.stageDone(r) || this.isDone(t))) : null;
                }
                if (c) {
                    const rs = Object.values(c.deptRanges || {}).filter((r) => r.end);
                    if (!rs.length) return null;
                    return make(rs.map((r) => r.start || r.end).sort()[0], rs.map((r) => r.end).sort().pop(), rs.every((r) => r.done));
                }
                return make(t.startDate, t.date, this.isDone(t));
            },
            calEntries() {
                const out = [];
                for (const t of this.visible()) {
                    if (this.byCabinet && t.subtasks.length) {
                        t.subtasks.forEach((c) => { const r = this.rangeOf(t, c); if (r) out.push({ t, c, key: t.id + c.id, ...r }); });
                    } else {
                        const r = this.rangeOf(t, null);
                        if (r) out.push({ t, c: null, key: t.id, ...r });
                    }
                }
                return out;
            },
            setByCabinet(on) { this.byCabinet = on; try { localStorage.setItem('planning.byCabinet', on ? '1' : ''); } catch (e) { /* not remembered */ } },
            // the "ทุกแผนก" bar of a project: each department's own range as a coloured stripe along its bottom (mine stands out)
            stripes(b, week) {
                if (this.dept || b.c) return [];
                const days = week.length ? diffDays(b.ce, b.cs) + 1 : 1;
                return (b.t.deptStages || []).filter((s) => s.end).map((s) => {
                    const late = !this.stageDone(s) && s.end < this.today, from = s.start && s.start <= s.end ? s.start : s.end, to = late ? this.today : s.end;
                    if (to < b.cs || from > b.ce) return null;
                    const a = diffDays(from < b.cs ? b.cs : from, b.cs), e = diffDays(to > b.ce ? b.ce : to, b.cs) + 1;
                    return { id: s.deptId, left: (a / days) * 100, width: ((e - a) / days) * 100, color: late ? this.lateColor() : this.deptOf(s.deptId).color, mine: s.deptId === this.myDept, name: this.deptOf(s.deptId).name };
                }).filter(Boolean);
            },
            // how much is going on that day (unfinished entries running through it) and whether any of it is late - the badge on the day
            dayLoad(date) {
                const live = this.calEntries().filter((e) => !e.done && e.from <= date && date <= e.to);
                return { n: live.length, late: live.some((e) => e.late && e.end < date) };
            },
            weekBars(week) {
                const ws = week[0].date, we = week[week.length - 1].date;
                const entries = this.calEntries().filter((e) => e.from <= we && e.to >= ws)
                  .sort((a, b) => (a.from === b.from ? a.t.so.localeCompare(b.t.so) : a.from.localeCompare(b.from)));
                const laneEnd = [], bars = [];
                for (const e of entries) {
                    const cs = e.from < ws ? ws : e.from, ce = e.to > we ? we : e.to;
                    const a = week.findIndex((d) => d.date === cs), b = week.findIndex((d) => d.date === ce);
                    let lane = laneEnd.findIndex((end) => end < a);
                    if (lane === -1) { lane = laneEnd.length; laneEnd.push(-1); }
                    laneEnd[lane] = b;
                    bars.push({ ...e, lane, cs, ce, col: a + 1, span: b - a + 1, before: e.from < ws, after: e.to > we });
                }
                return bars;
            },
            shownBars(week) { return this.weekBars(week).filter((b) => b.lane < MAX_LANES); },
            hiddenCount(week, date) { return this.weekBars(week).filter((b) => b.lane >= MAX_LANES && b.cs <= date && date <= b.ce).length; },
            barStyle(b) {
                const color = this.statusColor(b.t);   // the bar is always the PROJECT's status colour; late shows as "เลย n วัน"
                return 'grid-column:' + b.col + ' / span ' + b.span + ';grid-row:' + (b.lane + 1) + ';' + this.chipStyle(color, b.done);
            },
            barTitle(b) {
                return b.t.so + ' ' + b.t.title + (b.c ? ' · ' + b.c.mo + ' ' + b.c.text : '') + ' — สถานะโครงการ: ' + this.statusOf(b.t).name + (this.dept && this.stageOf(b.t, this.dept) ? ' · ' + this.deptOf(this.dept).name + ': ' + this.stageStatus(this.stageOf(b.t, this.dept)).name : '')
                    + ' · ' + this.fmtBE(b.from) + ' – ' + this.fmtBE(b.end) + (b.late ? ' (เลยกำหนด ' + diffDays(this.today, b.end) + ' วัน)' : '');
            },
            barLabel(b) { return (b.before ? '◄ ' : '') + (b.c ? (b.c.mo || b.c.text) + ' · ' + b.t.so : b.t.so + ' ' + b.t.title) + (b.late ? ' · เลย ' + diffDays(this.today, b.end) + ' วัน' : ''); },

            // drag a bar onto another day (admin / PM): the project moves by that many days
            dragStart(e, t) { if (!this.isPmo) { e.preventDefault(); return; } this.drag = t.id; e.dataTransfer.effectAllowed = 'move'; e.dataTransfer.setData('text/plain', t.id); },
            dropOn(date) {
                const t = this.drag ? this.taskById(this.drag) : null;
                this.drag = null;
                if (!t || !this.isPmo || this.dept) return;
                const anchor = (t.startDate && t.startDate <= t.date) ? t.startDate : t.date;
                const delta = diffDays(date, anchor);
                if (!delta) return;
                const fields = { date: addDays(t.date, delta) };
                if (t.startDate) fields.startDate = addDays(t.startDate, delta);
                this.editProject(t, fields);
            },

            // =================================================== timeline
            tlDays() { const n = new Date(this.year, this.month + 1, 0).getDate(); return Array.from({ length: n }, (_, i) => ({ date: this.year + '-' + pad(this.month + 1) + '-' + pad(i + 1), day: i + 1 })); },
            tlTemplate() { return 'repeat(' + this.tlDays().length + ', ' + TL_DAY + 'px)'; },
            tlRows() {
                const days = this.tlDays(), ws = days[0].date, we = days[days.length - 1].date;
                return this.calEntries().filter((r) => r.from <= we && r.to >= ws).sort((a, b) => a.from.localeCompare(b.from)).map((r) => {
                    const cs = r.from < ws ? ws : r.from, ce = r.to > we ? we : r.to;
                    const a = days.findIndex((d) => d.date === cs), b = days.findIndex((d) => d.date === ce);
                    return { ...r, col: a + 1, span: b - a + 1, before: r.from < ws, after: r.to > we };
                });
            },
            tlTodayLeft() { const i = this.tlDays().findIndex((d) => d.date === this.today); return i < 0 ? null : 220 + i * TL_DAY + TL_DAY / 2; },

            // =================================================== left panel: "งานที่ต้องเสร็จ"
            shiftFocus(n) { this.focusDate = addDays(this.focusDate, n); const d = parse(this.focusDate); this.year = d.getFullYear(); this.month = d.getMonth(); },
            // the day stepper's word and sentence fragments, as the original words them
            focusWord() {
                const n = diffDays(this.focusDate, this.today);
                return { 0: 'วันนี้', 1: 'พรุ่งนี้', 2: 'มะรืนนี้', '-1': 'เมื่อวาน', '-2': 'เมื่อวานซืน' }[n] || 'วัน' + TH_DAYS[parse(this.focusDate).getDay()];
            },
            focusWhen() { // "วันนี้" / "พรุ่งนี้" / ... / "วันพุธที่ 08/10/2569"
                const n = diffDays(this.focusDate, this.today);
                return { 0: 'วันนี้', 1: 'พรุ่งนี้', 2: 'มะรืนนี้', '-1': 'เมื่อวาน', '-2': 'เมื่อวานซืน' }[n] || 'วัน' + TH_DAYS[parse(this.focusDate).getDay()] + 'ที่ ' + this.fmtBE(this.focusDate);
            },
            // the day a cabinet is due, as seen in the current view (a department's own date when one department is in view)
            cabEff(t, c) {
                if (!c) return this.dueFor(t);
                if (this.dept) return (c.deptDates && c.deptDates[this.dept]) || (this.stageOf(t, this.dept) || {}).due || t.date;
                return c.date || t.date;
            },
            cabIsDone(t, c) {
                if (!c) return this.isDone(t);
                if (this.dept) { const p = this.cabProgress(c, this.dept); if (p.total > 0) return p.done === p.total; }
                return this.cabDone(c);
            },
            taskCabs(t) { const done = t.subtasks.filter((c) => this.cabDone(c)).length; return t.subtasks.length ? { done, total: t.subtasks.length } : { done: this.isDone(t) ? 1 : 0, total: 1 }; },
            // Cabinets (not projects) due on the chosen day, and the overdue backlog when looking at today; grouped by project,
            // highest priority first - this is the original panel's bucketing.
            focusBuckets() {
                const today = this.today, late = [], due = [];
                let total = 0, done = 0;
                for (const t of this.visible()) {
                    const units = (t.subtasks.length ? t.subtasks : [null]).map((c) => ({ c, ed: this.cabEff(t, c), done: this.cabIsDone(t, c) })).filter((u) => u.ed);
                    const dueU = units.filter((u) => u.ed === this.focusDate);
                    const lateU = this.focusDate === today ? units.filter((u) => u.ed < today && !u.done) : [];
                    if (dueU.length) { due.push({ t, cabs: dueU }); total += dueU.length; done += dueU.filter((u) => u.done).length; }
                    if (lateU.length) { late.push({ t, cabs: lateU }); total += lateU.length; }
                }
                const byEntry = (a, b) => (PRIORITY_RANK[b.t.priority] || 0) - (PRIORITY_RANK[a.t.priority] || 0) || a.t.so.localeCompare(b.t.so);
                const sortCabs = (g) => g.cabs.sort((a, b) => (a.done - b.done) || a.ed.localeCompare(b.ed) || String(a.c && a.c.mo).localeCompare(String(b.c && b.c.mo)));
                [late, due].forEach((list) => { list.sort(byEntry); list.forEach(sortCabs); });
                return { late, due, total, done };
            },
            focusSections() {
                const b = this.focusBuckets(), count = (groups) => groups.reduce((n, g) => n + g.cabs.length, 0), out = [];
                if (b.late.length) out.push({ key: 'late', label: 'เลยกำหนด', danger: true, count: count(b.late), groups: b.late });
                out.push({ key: 'due', label: 'ครบกำหนด' + this.focusWhen(), danger: false, count: count(b.due), groups: b.due });
                // not in the original: what is merely RUNNING on that day (a click on a calendar day lands here), minus what is already listed above
                const listed = new Set([...b.late, ...b.due].map((g) => g.t.id));
                const run = this.dayTasks(this.focusDate).filter((t) => !listed.has(t.id)).map((t) => ({ t, cabs: (t.subtasks.length ? t.subtasks : [null]).map((c) => ({ c, ed: this.cabEff(t, c), done: this.cabIsDone(t, c) })) }));
                out.push({ key: 'run', label: 'งานในวันที่ ' + this.fmtBE(this.focusDate), danger: false, count: run.length, groups: run });
                // handed out with no due date yet (they cannot be on the calendar): one row per department
                const undated = this.visible().map((t) => ({ t, cabs: (t.deptStages || []).filter((st) => st.undated > 0 && (!this.dept || st.deptId === this.dept))
                    .map((st) => ({ c: null, label: this.deptOf(st.deptId).name + ' · ' + st.undated + ' งาน', ed: '', done: false, key: st.deptId })) })).filter((g) => g.cabs.length);
                // template pairs: what the Sub Tasks my work compares with said is coming (late, or within 7 days) and I have not ticked yet
                const soon = addDays(this.today, 7);
                const incoming = this.visible().map((t) => ({ t, cabs: t.subtasks.flatMap((c) => (c.linked || []).flatMap((l) => l.items
                    .filter((i) => i.expected && !i.received && i.expected <= soon)
                    .map((i) => ({ c, label: this.deptOf(l.deptId).name + ' · ' + i.text, ed: i.expected, done: false, key: i.id })))) }))
                    .filter((g) => g.cabs.length).map((g) => ({ ...g, cabs: g.cabs.sort((a, b) => a.ed.localeCompare(b.ed)) }));
                if (incoming.length) out.push({ key: 'incoming', label: '📦 จากแผนกต้นทาง (ภายใน 7 วัน)', danger: false, count: incoming.reduce((n, g) => n + g.cabs.length, 0), groups: incoming });
                if (undated.length) out.push({ key: 'undated', label: 'ยังไม่มีวันส่ง', danger: true, count: undated.reduce((n, g) => n + g.cabs.length, 0), groups: undated });
                return out;
            },
            focusCounts() { const b = this.focusBuckets(); return { total: b.total, done: b.done, pct: b.total ? Math.round(b.done / b.total * 100) : 0 }; },
            focusEmpty() { return this.focusSections().every((sec) => sec.groups.length === 0); },
            // one cabinet row's date: "เลย N วัน" (red) when overdue, else dd/mm/yyyy (พ.ศ.)
            rowDate(ed) { if (!ed) return 'ไม่มีวันส่ง'; const n = diffDays(ed, this.today); return n < 0 ? 'เลย ' + (-n) + ' วัน' : this.fmtBE(ed); },
            rowName(c, t, u) { if (u && u.label) return u.label; return c ? ((c.mo || '').trim() ? c.mo + ' ' + c.text : c.text) : '(ทั้งโปรเจค — ยังไม่มีตู้)'; },
            rowColor(t, c) { return c ? this.cabColor(c) : this.statusColor(t); },

            // =================================================== day list
            dayTasks(date) {
                const ids = new Set(this.calEntries().filter((e) => e.from <= date && date <= e.to).map((e) => e.t.id));
                return this.visible().filter((t) => ids.has(t.id)).sort((a, b) => a.so.localeCompare(b.so));
            },
            // clicking a day in the calendar shows that day in the left panel ("งานที่ต้องเสร็จ"): what is due, and what is running
            selectDay(date) {
                this.focusDate = date;
                if (window.innerWidth < 1024) window.scrollTo({ top: 0, behavior: 'smooth' });   // on a phone the panel is above the calendar
            },

            // =================================================== dashboard
            // a department's name as a tag in its own colour (the bars stay one colour, so the colour says who, not how far)
            deptTag(id) { const d = this.deptOf(id); return 'background-color:' + hexToRgba(d.color, 0.16) + ';border-color:' + hexToRgba(d.color, 0.55); },
            deptLate(s) { return !this.stageDone(s) && !!s.end && s.end < this.today; },
            // the dashboard of ONE project (the "โครงการ" picker, or "แดชบอร์ด" in a project's window)
            openProjectDash(id) { this.closeModal(); this.dashProject = id; this.mode = 'dashboard'; window.scrollTo({ top: 0, behavior: 'smooth' }); },
            projectDash() {
                const t = this.taskById(this.dashProject);
                if (!t) return null;
                const scope = this.dept || null, cp = this.progress(t, scope);
                const stages = (t.deptStages || []).filter((s) => !scope || s.deptId === scope).map((s) => ({
                    s, dp: this.deptOf(s.deptId), status: this.stageStatus(s), late: this.deptLate(s), prog: this.progress(t, s.deptId),
                    lateDays: this.deptLate(s) ? diffDays(this.today, s.end) : 0,
                }));
                const cabs = t.subtasks.map((c) => ({
                    c, status: this.cabStatus(c), prog: this.cabProgress(c, scope), due: c.ownDue || c.date,
                    depts: Object.keys(c.deptRanges || {}).filter((d) => !scope || d === scope).map((d) => ({ id: d, prog: this.cabProgress(c, d), done: !!c.deptRanges[d].done })),
                }));
                return {
                    t, cp, stages, cabs,
                    cabsDone: cabs.filter((x) => this.cabDone(x.c)).length,
                    stagesDone: stages.filter((x) => this.stageDone(x.s)).length,
                    late: stages.filter((x) => x.late).length,
                    waiting: stages.reduce((n, x) => n + ((x.s.pmo && x.s.pmo.waiting) || 0), 0),
                };
            },
            dash() {
                const list = this.visible(), today = this.today;
                const doneOf = (t) => this.doneFor(t), dueOf = (t) => this.dueFor(t);
                const items = list.flatMap((t) => this.items(t, this.dept || null));
                const doneItems = items.filter((i) => i.done).length;
                const bucket = [{ label: 'เลยกำหนด', n: 0, late: true }, { label: 'ภายใน 7 วัน', n: 0 }, { label: '8–14 วัน', n: 0 }, { label: '15–21 วัน', n: 0 }, { label: '22+ วัน', n: 0 }];
                list.filter((t) => !doneOf(t) && dueOf(t)).forEach((t) => {
                    const d = diffDays(dueOf(t), today);
                    bucket[d < 0 ? 0 : d <= 7 ? 1 : d <= 14 ? 2 : d <= 21 ? 3 : 4].n++;
                });
                const depts = this.departments.map((dp) => {
                    const stages = list.map((t) => this.stageOf(t, dp.id)).filter(Boolean);
                    const its = list.flatMap((t) => this.items(t, dp.id));
                    const done = its.filter((i) => i.done).length;
                    return { dp, stages: stages.length, stagesDone: stages.filter((s) => this.stageDone(s)).length, late: stages.filter((s) => this.deptLate(s)).length, done, total: its.length, pct: its.length ? Math.round(done / its.length * 100) : 0 };
                }).filter((r) => r.stages > 0);
                return {
                    total: list.length,
                    completed: list.filter(doneOf).length,
                    doing: list.filter((t) => !doneOf(t) && this.statusOf(t).id === 's_doing').length,
                    late: list.filter((t) => this.overdueFor(t)).length,
                    checkPct: items.length ? Math.round(doneItems / items.length * 100) : 0, checkDone: doneItems, checkTotal: items.length,
                    bucket, bucketMax: Math.max(1, ...bucket.map((b) => b.n)), depts,
                    rows: list.slice().sort((a, b) => (this.overdueFor(b) - this.overdueFor(a)) || String(dueOf(a)).localeCompare(String(dueOf(b)))),
                };
            },

            // =================================================== saving: one operation per action
            async post(ops, files) {
                if (files && files.length) {   // a comment with photos / files: multipart, to its own endpoint
                    const o = ops[0], fd = new FormData();
                    fd.append('taskId', o.taskId); fd.append('text', o.text || '');
                    if (o.alertDept) fd.append('alertDept', o.alertDept);
                    (o.mentions || []).forEach((id) => fd.append('mentions[]', id));
                    if (o.replyTo) fd.append('replyTo', o.replyTo);
                    files.forEach((f) => fd.append('files[]', f));
                    const r = await fetch(this.urls.comment, { method: 'POST', headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf() }, body: fd });
                    if (r.status === 419 || r.status === 401) throw new Error('หมดเวลาเข้าสู่ระบบ — รีเฟรชหน้านี้แล้วเข้าสู่ระบบใหม่');
                    const d = await r.json().catch(() => ({}));
                    if (!r.ok) throw new Error((d.errors && Object.values(d.errors).flat()[0]) || d.message || ('เกิดข้อผิดพลาด ' + r.status));
                    return d;
                }
                const res = await fetch(this.urls.sync, { method: 'POST', headers: { 'Accept': 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf() }, body: JSON.stringify({ ops }) });
                if (res.status === 419 || res.status === 401) throw new Error('หมดเวลาเข้าสู่ระบบ — รีเฟรชหน้านี้แล้วเข้าสู่ระบบใหม่');
                const data = await res.json().catch(() => ({}));
                if (!res.ok) throw new Error(data.message || ('เกิดข้อผิดพลาด ' + res.status));
                return data;
            },
            // Sends ops one batch at a time (in order). Optimistic edits are made by the caller BEFORE this runs.
            send(ops, files) {
                const ids = [...new Set(ops.map((o) => o.taskId).filter(Boolean))];
                const release = () => ids.forEach((id) => { this.pending[id] = Math.max(0, (this.pending[id] || 1) - 1); });
                ids.forEach((id) => { this.pending[id] = (this.pending[id] || 0) + 1; });
                this.saving++;
                this.queue = this.queue
                    .then(() => this.post(ops, files))
                    .then((data) => {
                        release();
                        const msgs = (data.results || []).filter((r) => r.message).map((r) => (r.ok ? '' : '⚠️ ') + r.message);
                        if (msgs.length) window.showToast([...new Set(msgs)].slice(0, 3).join(' · '), (data.results || []).some((r) => !r.ok) ? 'error' : 'success');
                        this.apply(data);
                        this.savedAt = new Date().toLocaleTimeString('th-TH', { hour: '2-digit', minute: '2-digit' });
                    })
                    .catch((err) => { release(); window.showToast('บันทึกไม่สำเร็จ: ' + err.message, 'error'); return this.refresh(true); })
                    .finally(() => { this.saving--; });
                return this.queue;
            },
            // merge the server's answer: replace only the projects it sent, and never one that has a newer edit still queued
            apply(data) {
                const before = this.cur ? this.cur.comments.length : null;
                const busy = (id) => (this.pending[id] || 0) > 0;
                if (data.partial) {
                    (data.tasks || []).forEach((nt) => {
                        if (busy(nt.id)) return;
                        if ((nt.boardNo ?? null) !== this.boardNo) {   // moved to another board: it leaves this one
                            const k = this.tasks.findIndex((t) => t.id === nt.id);
                            if (k >= 0) this.tasks.splice(k, 1);
                            if (this.modalId === nt.id) this.closeModal();
                            return;
                        }
                        const i = this.tasks.findIndex((t) => t.id === nt.id);
                        if (i >= 0) this.tasks.splice(i, 1, nt); else this.tasks.push(nt);
                    });
                    (data.removed || []).forEach((id) => {
                        if (busy(id)) return;
                        const i = this.tasks.findIndex((t) => t.id === id);
                        if (i >= 0) this.tasks.splice(i, 1);
                        if (this.modalId === id) this.closeModal();
                    });
                } else if (data.root) {
                    this.replaceAll(data.root.boards[config.boardId]);
                }
                if (data.created && this.taskById(data.created)) this.openModal(data.created);   // a project just created / copied / imported
                if (before !== null && this.cur && this.cur.comments.length > before) this.scrollFeed();
            },
            replaceAll(b) {
                const keep = new Map(this.tasks.filter((t) => (this.pending[t.id] || 0) > 0).map((t) => [t.id, t]));
                this.tasks = b.tasks.map((t) => keep.get(t.id) || t);
                this.departments = b.departments;
                this.statuses = b.statuses;
                this.pmoStatusList = b.pmoStatusList || [];
                this.labels = b.labels || [];   // names / colours may have been changed in Settings since this page loaded
                if (this.modalId && !this.taskById(this.modalId)) this.closeModal();
            },
            async refresh(force) {
                try {
                    const res = await fetch(this.urls.data, { headers: Object.assign({ 'Accept': 'application/json' }, (!force && this.etag) ? { 'If-None-Match': this.etag } : {}) });
                    if (res.status === 304 || !res.ok) return;
                    this.etag = res.headers.get('ETag');
                    this.replaceAll((await res.json()).root.boards[config.boardId]);
                } catch (e) { /* offline: keep what we have */ }
            },

            // =================================================== editing (each one: update the page now, then one operation)
            editProject(t, fields) {
                const prev = {};
                Object.keys(fields).forEach((k) => { prev[k] = t[k]; t[k] = fields[k]; });
                this.send([{ type: 'project', taskId: t.id, fields }]);
            },
            setStageDates(t, deptId, start, end) {
                const s = this.stageOf(t, deptId);
                if (!s) return;
                s.start = start; s.end = end; if (end) s.due = end;
                this.send([{ type: 'stage_dates', taskId: t.id, deptId, start, due: end }]);
            },
            setDue(t, deptId, iso) {
                const s = this.stageOf(t, deptId);
                if (!s) return;
                s.due = iso;
                this.send([{ type: 'stage_due', taskId: t.id, deptId, due: iso }]);
            },
            setCabinetDue(t, c, iso) {
                c.date = iso;
                this.send([{ type: 'cabinet_due', taskId: t.id, cabinetId: c.id, due: iso }]);
            },
            tick(t, c, item) {
                item.done = !item.done;
                this.send([{ type: 'checklist', taskId: t.id, cabinetId: c.id, itemId: item.id, done: item.done }]);
            },
            // extra fields per department (Settings > ฟิลด์เพิ่มเติม): the value is kept on the department's stage of the project
            canEditStage(deptId) { return this.isPmo || deptId === this.myDept; },
            fieldValue(t, deptId, f) { const s = this.stageOf(t, deptId); return (s && s.fields && s.fields[f.id]) || ''; },
            fieldOptions(f) { return (f.groups || []).flatMap((g) => (g.options || []).map((o) => ({ id: o.id, text: (g.name ? g.name + ' · ' : '') + o.label }))); },
            setField(t, deptId, f, value) {
                const s = this.stageOf(t, deptId);
                if (!s) return;
                if (!s.fields) s.fields = {};
                if (value) s.fields[f.id] = value; else delete s.fields[f.id];
                this.send([{ type: 'stage_field', taskId: t.id, deptId, fieldId: f.id, value }]);
            },
            step(t, deptId, step) {
                if (step === 'accept' && !confirm('ยืนยันรับงาน? หลังรับงานแล้วจะเปลี่ยนแผนกที่รับผิดชอบไม่ได้')) return;
                this.stageDept = null;
                this.send([{ type: 'stage_step', taskId: t.id, deptId, step }]);
            },
            // ---- @ picker ----
            mentionList() { const q = this.mentionQuery.toLowerCase(); return this.people.filter((p) => !q || p.name.toLowerCase().includes(q)).slice(0, 6); },
            // opens the list while the text just before the caret is "@something" (start of the text or after a space)
            onCommentInput(ev) {
                const el = ev.target, upto = el.value.slice(0, el.selectionStart), m = upto.match(/(?:^|\s)@([^\s@]{0,30})$/);
                if (m) { this.mentionQuery = m[1]; this.mentionOpen = true; this.mentionIdx = 0; } else { this.mentionOpen = false; }
            },
            pickMention(p) {
                const el = this.$refs.commentBox, pos = el.selectionStart, m = el.value.slice(0, pos).match(/(^|\s)@([^\s@]{0,30})$/);
                if (!m) return;
                const start = pos - m[2].length - 1;   // the "@"
                this.comment = el.value.slice(0, start) + '@' + p.name + ' ' + el.value.slice(pos);
                this.mentionOpen = false;
                this.$nextTick(() => { const c = start + p.name.length + 2; el.focus(); el.setSelectionRange(c, c); });
            },
            onCommentKey(ev) {
                const list = this.mentionOpen ? this.mentionList() : [];
                if (list.length) {
                    if (ev.key === 'ArrowDown') { ev.preventDefault(); this.mentionIdx = (this.mentionIdx + 1) % list.length; return; }
                    if (ev.key === 'ArrowUp') { ev.preventDefault(); this.mentionIdx = (this.mentionIdx - 1 + list.length) % list.length; return; }
                    if ((ev.key === 'Enter' || ev.key === 'Tab') && !ev.isComposing) { ev.preventDefault(); this.pickMention(list[this.mentionIdx] || list[0]); return; }
                    if (ev.key === 'Escape') { ev.preventDefault(); ev.stopPropagation(); this.mentionOpen = false; return; }
                }
                if (ev.key === 'Enter' && !ev.shiftKey && !ev.isComposing) { ev.preventDefault(); this.postComment(); }
            },
            // ids of the people whose "@Name" is still in the text when it is sent
            mentionedIds(text) { return this.people.filter((p) => text.includes('@' + p.name)).map((p) => p.id); },
            // a comment split into plain text and "@Name" pieces so tags can be highlighted without injecting HTML
            commentParts(text) {
                const names = this.people.map((p) => p.name).sort((a, b) => b.length - a.length).map((n) => n.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'));
                if (!names.length) return [{ t: text, m: false }];
                return text.split(new RegExp('(@(?:' + names.join('|') + '))', 'g')).filter((x) => x !== '').map((x) => ({ t: x, m: x.startsWith('@') && this.people.some((p) => '@' + p.name === x) }));
            },
            async postComment() {
                const t = this.cur, text = this.comment.trim();
                if (!t || (!text && !this.files.length) || this.posting) return;
                this.posting = true;
                const alert = this.alertDept || null, files = this.files;
                t.comments.push({ id: 'tmp' + Date.now(), author: 'คุณ', text: text || '(แนบไฟล์)', at: Date.now(), files: files.map((f) => ({ name: f.name, url: '', size: '', image: false })) });
                this.comment = ''; this.alertDept = ''; this.files = [];
                this.mentionOpen = false; this.scrollFeed();
                const replyTo = this.replyTo ? this.replyTo.id : null; this.replyTo = null;
                try { await this.send([{ type: 'comment', taskId: t.id, text, alertDept: alert, mentions: this.mentionedIds(text), replyTo }], files); } finally { this.posting = false; }
            },
            pickFiles(ev) {
                const room = 5 - this.files.length;
                this.files = this.files.concat([...ev.target.files].slice(0, Math.max(0, room)));
                ev.target.value = '';
            },
            // ---- status picked by hand, tags, departments, cabinets (the buttons only show to who may use them; PMO enforces the same) ----
            setStatus(t, statusId) {
                t.manualStatus = statusId !== '';
                t.statusId = statusId || 'pmo_' + t.info.pmoStatus;
                this.send([{ type: 'project', taskId: t.id, fields: { statusId } }]);   // '' = none picked: PMO's own project status shows
            },
            setStageStatus(t, deptId, statusId) {
                const s = this.stageOf(t, deptId);
                if (s) { s.manualStatus = statusId !== ''; if (statusId) s.statusId = statusId; }
                this.send([{ type: 'stage_status', taskId: t.id, deptId, statusId }]);   // '' = back to automatic; the answer carries the real one
            },
            labelOf(id) { return this.labels.find((l) => l.id === id) || { id, name: id, color: '#98a2b3' }; },
            setLabels(t, ids) { t.labels = ids; this.send([{ type: 'labels_set', taskId: t.id, labels: ids }]); },
            toggleLabel(t, id) { this.setLabels(t, t.labels.includes(id) ? t.labels.filter((x) => x !== id) : t.labels.concat(id)); },
            createLabel(t) {
                const name = (prompt('ชื่อแท็กใหม่') || '').trim();
                if (name) this.send([{ type: 'label_create', name }]);   // no project on it: PMO answers with the whole board, the new tag is in the list
            },
            freeDepts(t) { return this.departments.filter((d) => !(t.deptStages || []).some((s) => s.deptId === d.id)); },
            addStage(t, deptId) { if (deptId) this.send([{ type: 'stage_add', taskId: t.id, deptId }]); },
            removeStage(t, deptId) {
                if (!confirm('เอาแผนก ' + this.deptOf(deptId).name + ' ออกจากโครงการนี้? (ทำได้เมื่อแผนกยังไม่รับงาน)')) return;
                this.stageDept = null;
                this.send([{ type: 'stage_remove', taskId: t.id, deptId }]);
            },
            // ---- a new cabinet from a template, its sub tasks handed to departments in the same window ----
            templateOf(id) { return this.templates.find((x) => String(x.id) === String(id)) || null; },
            openCabDialog(t) {
                const def = this.templates.find((x) => x.default) || this.templates[0];
                this.cabDialog = { taskId: t.id, mo: '', text: '', templateId: def ? String(def.id) : 'none', assign: {}, dates: {}, cabDue: {},
                    cabStart: '', cabEnd: t.date || '', start: '', due: t.date || '', dueMode: 'same', beforeDays: 0, deptDates: {} };
                this.pickTemplate();
            },
            pickTemplate() {   // the template's own departments are the starting choice
                const d = this.cabDialog, tpl = this.templateOf(d.templateId);
                d.assign = {}; d.dates = {};
                (tpl ? tpl.subtasks : []).forEach((s) => { d.assign[s.id] = s.deptId || ''; d.dates[s.id] = { start: d.start, due: d.due }; });
            },
            // ---- dates of the work being handed out: one date for all, the cabinet's own due date, or N days before it;
            //      every row stays editable afterwards (each piece of work may have its own dates) ----
            applyDates(box) {
                const before = (iso, n) => { if (!iso) return ''; const d = parse(iso); d.setDate(d.getDate() - (parseInt(n, 10) || 0)); return ymd(d); };
                Object.keys(box.dates).forEach((k) => {
                    const cab = box.cabDue[k] || (box === this.cabDialog ? box.cabEnd : '');
                    const due = box.dueMode === 'cabinet' ? (cab || box.due) : box.dueMode === 'before' ? (cab ? before(cab, box.beforeDays) : box.due) : box.due;
                    box.dates[k] = { start: box.start, due };
                });
            },
            // a row of the simple "+ เพิ่มแผนก" list covers the same work in several cabinets: show its date when they agree
            groupDate(g, key) { const v = [...new Set(g.ids.map((id) => (this.dispatch.dates[id] || {})[key] || ''))]; return v.length === 1 ? v[0] : ''; },
            setGroupDate(g, key, value) { g.ids.forEach((id) => { this.dispatch.dates[id] = { ...(this.dispatch.dates[id] || {}), [key]: value }; }); },
            // ---- dates when handing work out: ONE start - end per department per project (the department orders its own work) ----
            chosenDepts(box) {
                if (box === this.dispatch && box.preset && !box.perCabinet) return [box.preset];
                return [...new Set(Object.values(box.assign).filter(Boolean))];
            },
            deptRange(box, deptId) {
                if (!box.deptDates[deptId]) {
                    const t = this.taskById(box.taskId), s = t && this.stageOf(t, deptId);
                    // the department's range already in this project, else the project's own dates
                    box.deptDates[deptId] = { start: (s && s.end ? s.start : t && t.startDate) || '', due: (s && s.end ? s.end : t && t.date) || '' };
                }
                return box.deptDates[deptId];
            },
            setAllDates(box) { this.applyDates(box); },
            setAllCabDepts(deptId) { Object.keys(this.cabDialog.assign).forEach((k) => { this.cabDialog.assign[k] = deptId; }); },
            addCabinet() {
                const d = this.cabDialog;
                if (!d || !d.mo.trim() || !d.text.trim()) return;
                const assign = Object.fromEntries(Object.entries(d.assign).filter(([, v]) => v).map(([k, v]) => [k, { dept: v, start: this.deptRange(d, v).start || null, due: this.deptRange(d, v).due || null }]));
                this.send([{ type: 'cabinet_add', taskId: d.taskId, mo: d.mo.trim(), text: d.text.trim(), templateId: d.templateId, assign, startDate: d.cabStart || null, dueDate: d.cabEnd || null }]);
                this.cabDialog = null;
            },
            // ---- handing out sub tasks that have no department yet (from a template), per cabinet or for the whole project ----
            freeCount(t) { return (t.subtasks || []).reduce((n, c) => n + (c.free || []).length, 0); },
            openDispatch(t, cabId, preset) {
                const assign = {};
                const dates = {}, cabDue = {}, due = t.date || '';
                (t.subtasks || []).filter((c) => !cabId || c.id === cabId).forEach((c) => (c.free || []).forEach((s) => { assign[s.id] = ''; cabDue[s.id] = c.ownDue || ''; dates[s.id] = { start: '', due: c.ownDue || due }; }));
                this.stageDept = null;
                // each cabinet's own due date is the starting point when the cabinets have one
                const mode = Object.values(cabDue).some((v) => v) ? 'cabinet' : 'same';
                this.dispatch = { taskId: t.id, cabId: cabId || null, preset: preset || '', assign, dates, cabDue, start: '', due, dueMode: mode, beforeDays: 0, picked: {}, perCabinet: false, deptDates: {} };
                if (preset) this.suggestFor(preset);
            },
            // "+ เพิ่มแผนก": the same piece of work sits in every cabinet made from one template -> one row per piece of work,
            // ticking it hands that work to the department in every cabinet at once
            dispatchGroups() {
                const groups = new Map();
                this.dispatchCabinets().forEach((c) => (c.free || []).forEach((s) => {
                    const key = s.task + ' › ' + s.name;
                    const g = groups.get(key) || { key, task: s.task, name: s.name, ids: [], checklists: 0, usual: '' };
                    g.ids.push(s.id); g.checklists += s.checklists; g.usual = g.usual || s.usual || '';
                    groups.set(key, g);
                }));
                return [...groups.values()];
            },
            // tick what is this department's work: what the same piece of work was usually given to before (PMO's history);
            // when there is no history, the department's code (e.g. BUSBAR) in the work's name
            suggestFor(deptId) {
                const code = (this.deptOf(deptId).code || '').toLowerCase(), picked = {};
                this.dispatchGroups().forEach((g) => {
                    if (g.usual ? g.usual === deptId : (code.length >= 3 && (g.task + ' ' + g.name).toLowerCase().includes(code))) picked[g.key] = true;
                });
                this.dispatch.picked = picked;
            },
            setPreset(deptId) { this.dispatch.preset = deptId; if (deptId) this.suggestFor(deptId); },
            pickAllGroups(on) { const p = {}; if (on) this.dispatchGroups().forEach((g) => { p[g.key] = true; }); this.dispatch.picked = p; },
            pickedCount() { const d = this.dispatch; return this.dispatchGroups().filter((g) => d.picked[g.key]).reduce((n, g) => n + g.ids.length, 0); },
            dispatchCabinets() {
                const d = this.dispatch, t = d && this.taskById(d.taskId);
                return t ? t.subtasks.filter((c) => (!d.cabId || c.id === d.cabId) && (c.free || []).length) : [];
            },
            setCabDispatch(c, deptId) { (c.free || []).forEach((s) => { this.dispatch.assign[s.id] = deptId; }); },
            sendDispatch() {
                const d = this.dispatch;
                if (d.preset && !d.perCabinet) {   // ticked pieces of work -> that department, in every cabinet
                    this.dispatchGroups().filter((g) => d.picked[g.key]).forEach((g) => g.ids.forEach((id) => { d.assign[id] = d.preset; }));
                }
                const assign = Object.fromEntries(Object.entries(d.assign).filter(([, v]) => v).map(([k, v]) => [k, {
                    dept: v, start: this.deptRange(d, v).start || null, due: this.deptRange(d, v).due || null }]));
                this.dispatch = null;
                if (Object.keys(assign).length) this.send([{ type: 'subtask_assign', taskId: d.taskId, assign }]);
            },
            newWorkForDept() {   // nothing from the template fits: one new sub task "งาน <แผนก>" in every cabinet (no checklist)
                const d = this.dispatch;
                this.dispatch = null;
                this.send([{ type: 'stage_add', taskId: d.taskId, deptId: d.preset, start: this.deptRange(d, d.preset).start || null, due: this.deptRange(d, d.preset).due || null }]);
            },
            renameCabinet(t, c) {
                const mo = prompt('เลข MO', c.mo); if (mo === null) return;
                const text = prompt('ชื่อตู้', c.text); if (text === null) return;
                if (!mo.trim() || !text.trim()) return;
                c.mo = mo.trim(); c.text = text.trim();
                this.send([{ type: 'cabinet_rename', taskId: t.id, cabinetId: c.id, mo: c.mo, text: c.text }]);
            },
            deleteCabinet(t, c) {
                if (!confirm('ลบตู้ ' + c.mo + ' ' + c.text + '? (กู้คืนได้ที่ถังขยะ)')) return;
                t.subtasks = t.subtasks.filter((x) => x.id !== c.id);
                this.send([{ type: 'cabinet_delete', taskId: t.id, cabinetId: c.id }]);
            },
            setBoard(t, boardId) { this.send([{ type: 'board_set', taskId: t.id, boardId }]); },
            // ---- new / copied / restored / imported projects (PMO roles; the trash: who may manage it) ----
            openNewProject() {
                this.newProject = { so: '', title: '', customer: '', customerPhone: '', startDate: '', date: '', priority: 'none', managerId: '', jobTypeId: '', contractValue: '', margin: '', boardId: this.boardNo ? 'b' + this.boardNo : '' };
                this.$nextTick(() => this.$refs.newSo && this.$refs.newSo.focus());
            },
            createProject() {
                const f = this.newProject;
                if (!f || !f.so.trim() || !f.title.trim() || !f.customer.trim()) return;
                this.send([{ type: 'project_create', so: f.so.trim(), title: f.title.trim(), customer: f.customer.trim(), customerPhone: f.customerPhone.trim(), startDate: f.startDate || null, date: f.date || null,
                    priority: f.priority, managerId: f.managerId || null, jobTypeId: f.jobTypeId || null, contractValue: f.contractValue, margin: f.margin, boardId: f.boardId }]);
                this.newProject = null;
            },
            duplicateProject(t) {
                const so = (prompt('เลขที่โครงการใหม่ (คัดลอกจาก ' + t.so + ' พร้อมตู้ งาน และ checklist)') || '').trim();
                if (!so) return;
                const title = prompt('ชื่อโครงการใหม่', t.title + ' (สำเนา)');
                if (title === null) return;
                this.send([{ type: 'project_duplicate', taskId: t.id, so, title: title.trim() }]);
            },
            restoreProject(p) {
                this.trash = this.trash.filter((x) => x.id !== p.id);
                this.send([{ type: 'project_restore', taskId: p.id }]);
            },
            starredTasks() { return this.tasks.filter((t) => this.isStarred(t)); },
            // ---- people in charge ----
            team() { const seen = new Set(), list = []; [this.me, ...this.people].forEach((p) => { if (p && !seen.has(p.id)) { seen.add(p.id); list.push(p); } }); return list; },
            personOf(id) { return this.team().find((p) => p.id === id) || { id, name: '#' + id }; },
            toggleOwner(t, id) {
                id = Number(id);
                t.owners = t.owners.includes(id) ? t.owners.filter((x) => x !== id) : t.owners.concat(id);
                this.send([{ type: 'assignees_set', taskId: t.id, userIds: t.owners }]);
            },
            // ---- status picked from a bar / a cabinet ----
            openStatusPop(ev, t, c) {
                if (!this.canEdit) return;   // statuses are set by Admin / PM
                const r = ev.currentTarget.getBoundingClientRect();
                this.statusPop = { taskId: t.id, cabId: c ? c.id : null, top: Math.min(r.bottom + 4, window.innerHeight - 280), left: Math.max(8, Math.min(r.left, window.innerWidth - 220)) };
            },
            pickStatus(statusId) {
                const p = this.statusPop, t = p && this.taskById(p.taskId);
                this.statusPop = null;
                if (!t) return;
                if (!p.cabId) { this.setStatus(t, statusId); return; }
                const c = t.subtasks.find((x) => x.id === p.cabId);
                if (c) { c.manualStatus = statusId !== ''; if (statusId) c.statusId = statusId; }
                this.send([{ type: 'cabinet_status', taskId: t.id, cabinetId: p.cabId, statusId }]);
            },
            // ---- checklist items: rename, remark, order ----
            clRename(item) {
                const text = prompt('ชื่อรายการ', item.text);
                if (text === null || !text.trim()) return;
                item.text = text.trim();
                this.send([{ type: 'checklist_rename', taskId: this.cl.taskId, itemId: item.id, text: item.text }]);
            },
            clRemark(item) {
                const remark = prompt('หมายเหตุของรายการนี้', item.remark || '');
                if (remark === null) return;
                item.remark = remark.trim();
                this.send([{ type: 'checklist_remark', taskId: this.cl.taskId, itemId: item.id, remark: item.remark }]);
            },
            clMove(item, dir) { this.send([{ type: 'checklist_move', taskId: this.cl.taskId, itemId: item.id, dir }]); },
            // ---- comments: edit / delete your own, reply, acknowledge an alert, view a file ----
            editComment(c) {
                const text = prompt('แก้ไขคอมเมนต์', c.text);
                if (text === null || !text.trim()) return;
                c.text = text.trim(); c.edited = true;
                this.send([{ type: 'comment_edit', taskId: this.cur.id, commentId: c.id, text: c.text }]);
            },
            deleteComment(c) {
                if (!confirm('ลบคอมเมนต์นี้?')) return;
                this.cur.comments = this.cur.comments.filter((x) => x.id !== c.id);
                this.send([{ type: 'comment_delete', taskId: this.cur.id, commentId: c.id }]);
            },
            startReply(c) { this.replyTo = c; this.$nextTick(() => this.$refs.commentBox && this.$refs.commentBox.focus()); },
            commentById(id) { return (this.cur ? this.cur.comments : []).find((c) => c.id === id) || null; },
            ackAlert(c) {
                c.alert.acked = true; c.alert.acks = c.alert.acks.concat({ name: this.me.name, at: Date.now() });
                this.cur.alertPending = this.cur.comments.some((x) => x.alert && x.alert.forMe && !x.alert.acked);
                this.send([{ type: 'alert_ack', taskId: this.cur.id, commentId: c.id }]);
            },
            openFile(f, ev) {
                if (!f.url) return;
                const pdf = /\.pdf$/i.test(f.name);
                if (!f.image && !pdf) return;   // other files: the link opens / downloads them as usual
                ev.preventDefault();
                this.viewer = { url: f.url, name: f.name, kind: f.image ? 'image' : 'pdf' };
            },
            // ---- extra fields shown on the bars (Settings > ฟิลด์เพิ่มเติม › แสดงบนปฏิทิน) ----
            fieldChips(t) {
                const out = [];
                (t.deptStages || []).forEach((s) => (this.deptOf(s.deptId).fields || []).forEach((f) => {
                    const v = f.showOnChip && s.fields && s.fields[f.id];
                    if (!v) return;
                    const o = this.fieldOptions(f).find((x) => x.id === v);
                    out.push(o ? o.text.split(' · ').pop() : (f.type === 'link' ? f.name : v));
                }));
                return out;
            },
            // ---- the project's record (Admin / PM) ----
            editInfo(t, key, value) {
                t.info[key] = value;
                this.send([{ type: 'project', taskId: t.id, fields: { [key]: value } }]);
            },
            // ---- money (มูลค่างาน / Margin): shown 1,250,000.00, typed with or without commas ----
            money(v) { const n = parseFloat(String(v ?? '').replace(/[\s,฿]|บาท/g, '')); return isNaN(n) ? null : n; },
            fmtMoney(v) { const n = this.money(v); return n === null ? '' : n.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); },
            marginPct(o) { const v = this.money(o.contractValue), m = this.money(o.margin); return v && m !== null ? (m / v * 100).toFixed(1) + ' %' : ''; },
            editMoney(t, key, el) {
                const raw = el.value.trim(), n = this.money(raw);
                if (raw !== '' && n === null) { window.showToast('กรุณากรอกตัวเลข', 'error'); el.value = this.fmtMoney(t.info[key]); return; }
                t.info[key] = n;
                el.value = this.fmtMoney(n);
                this.send([{ type: 'project', taskId: t.id, fields: { [key]: n === null ? '' : String(n) } }]);
            },
            staffFor(deptId) { return this.staff.filter((p) => !deptId || p.deptId === deptId || p.pmo); },
            // ---- an expanded cabinet: its record + every sub task (dates, the person doing it, remark), loaded when opened ----
            async toggleCab(t, c) {
                if (this.cabOpen[c.id]) { const o = { ...this.cabOpen }; delete o[c.id]; this.cabOpen = o; return; }
                this.cabOpen = { ...this.cabOpen, [c.id]: { loading: true, cabinet: null, subtasks: [], taskId: t.id } };
                try {
                    const res = await fetch(config.urlsCabinet.replace('__ID__', c.id.slice(1)), { headers: { 'Accept': 'application/json' } });
                    if (!res.ok) throw new Error();
                    const data = await res.json();
                    if (this.cabOpen[c.id]) this.cabOpen = { ...this.cabOpen, [c.id]: { loading: false, taskId: t.id, ...data } };
                } catch (e) { if (this.cabOpen[c.id]) this.cabOpen = { ...this.cabOpen, [c.id]: { loading: false, error: true, cabinet: null, subtasks: [], taskId: t.id } }; }
            },
            editCabInfo(t, c, key, value) {
                const o = this.cabOpen[c.id];
                if (o && o.cabinet) o.cabinet[key] = value;
                if (key === 'dueDate') c.ownDue = value || null;
                this.send([{ type: 'cabinet_info', taskId: t.id, cabinetId: c.id, fields: { [key]: value } }]);
            },
            editSubInfo(t, s, key, value) {
                s[key] = value;
                this.send([{ type: 'subtask_info', taskId: t.id, subtaskId: s.id, fields: { [key]: value } }]);
            },
            stateLabel(state) { return { ASSIGNED: 'รอรับ', ACCEPTED: 'รับแล้ว', IN_PROGRESS: 'กำลังทำ', COMPLETED: 'เสร็จ', UNASSIGNED: 'ยังไม่จ่าย' }[state] || state; },
            // ---- what is still missing on a project ----
            warningList(t) {
                const w = t.warnings || {}, out = [];
                if (w.free) out.push({ text: w.free + ' งานย่อยยังไม่ได้จ่ายให้แผนก', act: 'dispatch' });
                if (w.undated) out.push({ text: w.undated + ' งานที่จ่ายแล้วยังไม่มีวันส่ง', act: '' });
                if (w.cabNoDue) out.push({ text: w.cabNoDue + ' ตู้ยังไม่มีวันครบกำหนด', act: '' });
                if (w.noManager) out.push({ text: 'ยังไม่ได้กำหนด Project Manager', act: 'info' });
                if (w.noDue) out.push({ text: 'โครงการยังไม่มีวันครบกำหนด', act: '' });
                return out;
            },
            shiftProject(t) {
                const n = parseInt(prompt('เลื่อนทั้งโครงการกี่วัน? (บวก = เลื่อนออก, ลบ = เลื่อนเข้า) — โครงการ ตู้ และงานที่ยังไม่เสร็จจะเลื่อนพร้อมกัน') || '', 10);
                if (!n) return;
                this.cabOpen = {};
                this.send([{ type: 'project_shift', taskId: t.id, days: n }]);
            },
            // ---- printing a work order: the department in view, the day in the left panel ----
            printWorkOrder() {
                const dept = this.dept || this.me.deptId;
                if (!dept) { window.showToast('เลือกแผนกก่อน (ตัวเลือกแผนกด้านบน) แล้วกดพิมพ์ใบสั่งงานอีกครั้ง', 'error'); return; }
                window.open(this.urls.workOrder + '?' + new URLSearchParams({ dept, date: this.focusDate }), '_blank');
            },
            deleteProject() {
                const t = this.cur;
                if (!t || !confirm('ย้ายโครงการ ' + t.so + ' ไปถังขยะ? (กู้คืนได้จากหน้าถังขยะ)')) return;
                this.closeModal();
                this.tasks.splice(this.tasks.indexOf(t), 1);   // gone from the page at once; if PMO refuses, its answer puts the project back
                this.send([{ type: 'delete', taskId: t.id }]);
            },

            // =================================================== modal / popovers
            // the open project lives in the address bar (?project=12): Back from a project / cabinet page, a reload or a copied
            // link comes back to it open; ?board= is kept, the one-off ?stage= / ?accept= of a notice are dropped
            projectInUrl(id) {
                try {
                    const u = new URL(location.href);
                    if (id) u.searchParams.set('project', String(id).replace(/^p/, '')); else u.searchParams.delete('project');
                    u.searchParams.delete('stage'); u.searchParams.delete('accept');
                    if (u.href !== location.href) history.replaceState(history.state, '', u);
                } catch (e) { /* old browser: just not remembered */ }
            },
            openModal(id) { this.projectInUrl(id); this.modalId = id; this.replyTo = null; this.cabOpen = {}; this.feedTab = 'activity'; this.logTree.id = null; this.stageDept = null; this.cl = null; this.comment = ''; this.alertDept = ''; this.scrollFeed(); },
            setModalSize(size) { this.modalSize = size; try { localStorage.setItem('planning.modalSize', size); } catch (e) { /* storage blocked: it just won't be remembered */ } },
            closeModal() { this.modalId = null; this.stageDept = null; this.cl = null; this.projectInUrl(null); },
            onEscape() { if (this.dispatch) { this.dispatch = null; return; } if (this.cabDialog) { this.cabDialog = null; return; } if (this.viewer) { this.viewer = null; return; } if (this.statusPop) { this.statusPop = null; return; } if (this.newProject) { this.newProject = null; return; } if (this.drawer) { this.drawer = null; return; } if (this.cl) this.closeChecklist(); else if (this.stageDept) this.stageDept = null; else if (this.modalId) this.closeModal();  },
            // ---- รอรับงาน: work this user may accept (their department's - or any, for Admin / PM allowed to act for departments) ----
            waitingList() {
                return this.tasks.flatMap((t) => (t.deptStages || []).filter((s) => s.pmo && s.pmo.canAccept && (!this.dept || s.deptId === this.dept))
                    .map((s) => ({ t, s, days: s.pmo.waitingSince ? Math.max(0, diffDays(this.today, s.pmo.waitingSince)) : 0 })))
                    .sort((a, b) => b.days - a.days || String(a.s.end || '9999').localeCompare(String(b.s.end || '9999')));
            },
            waitingCount() { return this.waitingList().reduce((n, w) => n + w.s.pmo.waiting, 0); },
            acceptAll() {
                const list = this.waitingList();
                if (!list.length || !confirm('รับงานทั้งหมด ' + this.waitingCount() + ' งาน ใน ' + new Set(list.map((w) => w.t.id)).size + ' โครงการ?\nหลังรับงานแล้วจะเปลี่ยนแผนกที่รับผิดชอบไม่ได้')) return;
                this.send(list.map((w) => ({ type: 'stage_step', taskId: w.t.id, deptId: w.s.deptId, step: 'accept' })));
            },
            // a notice's link: the department's window with its "รับงาน" button (and with ?accept=1, ask to accept right away)
            openStageFromLink(t, deptId, accept) {
                const st = this.stageOf(t, deptId), el = document.querySelector('[data-stage-chip="' + deptId + '"]');
                if (!st || !el) return;
                el.scrollIntoView({ block: 'center' });
                this.openStage(deptId, { currentTarget: el });
                if (!accept) return;
                if (st.pmo.canAccept) this.step(t, deptId, 'accept');
                else window.showToast(st.pmo.waiting ? 'คุณไม่ใช่สมาชิกแผนกนี้ — กดรับงานแทนไม่ได้' : 'งานของแผนกนี้รับไปแล้ว', 'info');
            },
            openStage(deptId, ev) {
                if (this.stageDept === deptId) { this.stageDept = null; return; }
                const r = ev.currentTarget.getBoundingClientRect();
                this.stagePos = { top: Math.min(r.bottom + 6, window.innerHeight - 330), left: Math.max(8, Math.min(r.left, window.innerWidth - 300)) };
                this.stageDept = deptId;
            },
            // ---- checklist popover (as in the original page): header + progress, items you can tick, grouped by department, + add / delete ----
            get clTask() { return this.cl ? this.taskById(this.cl.taskId) : null; },
            get clCab() { const t = this.clTask; return t ? (t.subtasks.find((c) => c.id === this.cl.cabId) || null) : null; },
            openChecklist(taskId, cabId, ev) {
                if (this.cl && this.cl.cabId === cabId && this.cl.taskId === taskId) { this.closeChecklist(); return; }
                const r = ev.currentTarget.getBoundingClientRect();
                this.stageDept = null; this.clDraft = '';
                let big = false;
                try { big = localStorage.getItem('planning.clBig') === '1'; } catch (e) { /* not remembered */ }
                this.cl = { taskId, cabId, adding: null, big, anchor: { top: r.top, bottom: r.bottom, left: r.left, width: r.width } };
                this.clPlace();
            },
            // where the window goes: normal = up to 560 x 720 next to the pill (960 wide when a department passes work to this
            // one, so the two lists sit side by side); big = most of the screen (notebook / iPad); always on screen
            clPlace() {
                const W = window.innerWidth, H = window.innerHeight, a = this.cl.anchor;
                const linked = ((this.clCab && this.clCab.linked) || []).length > 0;
                const w = this.cl.big ? Math.min(1000, W - 16) : Math.min(linked ? 960 : 560, W - 16);
                const h = this.cl.big ? H - 24 : Math.min(720, H - 24);
                const top = this.cl.big ? 12 : Math.max(8, Math.min(a.bottom + 6, H - h - 12));
                const left = this.cl.big ? Math.round((W - w) / 2) : Math.max(8, Math.min(a.left + a.width / 2 - w / 2, W - w - 8));
                this.cl = { ...this.cl, w, h, top, left };
            },
            // Settings > การส่งต่องาน: this department's list and the one passing work to it, next to each other to compare
            clSide() { return !!(this.cl && this.cl.w >= 640 && this.clCab && (this.clCab.linked || []).length); },
            clToggleBig() {
                this.cl = { ...this.cl, big: !this.cl.big };
                try { localStorage.setItem('planning.clBig', this.cl.big ? '1' : ''); } catch (e) { /* not remembered */ }
                this.clPlace();
            },
            closeChecklist() { this.cl = null; this.clDraft = ''; },
            clScope() { return this.dept || null; },
            clProgress() { return this.clCab ? this.cabProgress(this.clCab, this.clScope()) : { done: 0, total: 0, pct: 0 }; },
            clTitle() { return this.dept ? 'Checklist · ' + this.deptOf(this.dept).name : 'Checklist (ทุกแผนก)'; },
            // Task › Sub Task › items, in the cabinet's own order (only the department in view when one is); a Sub Task with no
            // item yet is listed too, so its "+" is there
            clGroups() {
                const c = this.clCab;
                if (!c) return [];
                const tasks = [];
                (c.works || []).filter((w) => !this.dept || w.deptId === this.dept).forEach((w) => {
                    let g = tasks[tasks.length - 1];
                    if (!g || g.task !== w.task) { g = { task: w.task, subs: [] }; tasks.push(g); }
                    const items = c.checklist.filter((i) => i.subId === w.id);
                    g.subs.push({ ...w, items, done: items.filter((i) => i.done).length });
                });
                return tasks;
            },
            // ---- Settings > การส่งต่องาน: the date a department gives its items for the next one, and what the senders did ----
            clItem(id) { const c = this.clCab; return c ? c.checklist.find((x) => x.id === id) || null : null; },
            clItemDate(id) { const i = this.clItem(id); return i ? i.expected : null; },
            setItemDate(id, v) {
                const i = this.clItem(id);
                if (!i || (i.expected || null) === (v || null)) return;
                i.expected = v || null;
                this.send([{ type: 'checklist_date', taskId: this.cl.taskId, itemId: id, date: v || '' }]);
            },
            clDateDepts() {
                const c = this.clCab;
                if (!c) return [];
                return [...new Set((c.works || []).map((w) => w.deptId))].filter((d) => (!this.dept || d === this.dept) && this.deptOf(d).dateLabel && this.canEditStage(d)
                    && c.checklist.some((i) => i.deptId === d));
            },
            setCabDate(deptId, v) {
                const c = this.clCab;
                if (!c || !v) return;
                c.checklist.filter((i) => i.deptId === deptId).forEach((i) => { i.expected = v; });
                this.send([{ type: 'checklist_date_all', taskId: this.cl.taskId, cabinetId: c.id, deptId, date: v }]);
            },
            // template pairs: the lists a Sub Task of mine compares with, and their items matched to mine by name
            clLinkedOf(subId) { return ((this.clCab && this.clCab.linked) || []).filter((l) => l.forId === subId); },
            clNorm(s) { return String(s || '').trim().replace(/\s+/g, ' ').toLowerCase(); },
            clMatches(subId, item) { const n = this.clNorm(item.text); return this.clLinkedOf(subId).flatMap((l) => l.items.filter((x) => this.clNorm(x.text) === n).map((x) => ({ ...x, l }))); },
            clUnmatched(w) {
                const mine = new Set(w.items.map((i) => this.clNorm(i.text)));
                return this.clLinkedOf(w.id).flatMap((l) => l.items.filter((x) => !mine.has(this.clNorm(x.text))).map((x) => ({ ...x, l })));
            },
            linkedLate(i) { return !!i.expected && !i.received && i.expected < this.today; },
            stateWord(state) { return { ASSIGNED: 'รอรับ', ACCEPTED: 'รับแล้ว', IN_PROGRESS: 'กำลังทำ', COMPLETED: 'เสร็จ' }[state] || ''; },
            clAdd(subId) { this.cl = { ...this.cl, adding: subId }; this.clDraft = ''; this.$nextTick(() => { const el = document.getElementById('cl-draft-' + subId); if (el) el.focus(); }); },
            // several lines pasted (e.g. from Excel): one item per line, its cells joined - as the full editor's default
            clPaste(ev, subId) {
                const text = (ev.clipboardData || window.clipboardData).getData('text');
                const lines = text.replace(/\r/g, '').split('\n').map((l) => l.split('\t').map((x) => x.trim()).filter(Boolean).join(' ')).filter(Boolean);
                if (lines.length < 2) return;
                ev.preventDefault();
                const t = this.clTask, c = this.clCab, w = (c.works || []).find((x) => x.id === subId), deptId = w ? w.deptId : '';
                if (!confirm('เพิ่ม ' + lines.length + ' รายการ?\n\n' + lines.slice(0, 5).join('\n') + (lines.length > 5 ? '\n…' : ''))) return;
                this.clDraft = '';
                this.cl = { ...this.cl, adding: null };
                lines.forEach((text, i) => c.checklist.push({ id: 'tmp' + Date.now() + i, text, done: false, deptId, subId }));
                this.send(lines.slice(0, 200).map((text) => ({ type: 'checklist_add', taskId: t.id, cabinetId: c.id, deptId, subtaskId: subId, text })));
            },
            cabinetPage(c, subId) { return (config.urlsCabinetPage || '').replace('__ID__', String(c.id).replace(/^c/, '')) + (subId ? '#subtask-' + String(subId).replace(/^s/, '') : ''); },
            clCommit() {
                if (!this.cl) return;
                const text = this.clDraft.trim(), subId = this.cl.adding;
                this.clDraft = '';
                this.cl = { ...this.cl, adding: null };
                if (!subId || !text) return;
                const t = this.clTask, c = this.clCab, w = (c.works || []).find((x) => x.id === subId);
                const deptId = w ? w.deptId : '';
                c.checklist.push({ id: 'tmp' + Date.now(), text, done: false, deptId, subId });   // shown at once; PMO's answer carries the real item
                this.send([{ type: 'checklist_add', taskId: t.id, cabinetId: c.id, deptId, subtaskId: subId, text }]);
            },
            clDelete(item) {
                const t = this.clTask, c = this.clCab;
                if (!t || !c) return;
                c.checklist.splice(c.checklist.indexOf(item), 1);
                this.send([{ type: 'checklist_delete', taskId: t.id, cabinetId: c.id, itemId: item.id }]);
            },
            timeline() { this.mode = 'timeline'; this.$nextTick(() => { const el = this.$refs.tlScroll; const i = this.tlDays().findIndex((d) => d.date === this.today); if (el && i >= 0) el.scrollLeft = Math.max(0, i * TL_DAY - 160); }); },

            // dd/mm/yyyy date field (flatpickr, same as the rest of the app). `get` reads the current value, `set` receives 'YYYY-MM-DD' or null.
            dateField(el, get, set, enabled, width) {
                if (!window.flatpickr) { el.type = 'date'; el.value = get() || ''; el.disabled = !enabled; el.addEventListener('change', () => set(el.value || null)); return; }
                window.flatpickr(el, {
                    dateFormat: 'Y-m-d', altInput: true, altFormat: 'd/m/Y', allowInput: true, clickOpens: enabled, formatDate: beFormat, parseDate: beParse,
                    altInputClass: (width || 'w-[6.75rem]') + ' rounded-lg border-slate-300 px-2 py-1 pr-7 text-[13px] ' + (enabled ? '' : 'bg-slate-50 text-slate-500'),
                    defaultDate: get() || null,
                    onChange: (d, str) => { if (enabled && str !== (get() || '')) set(str || null); },
                });
                if (!enabled && el._flatpickr && el._flatpickr.altInput) el._flatpickr.altInput.disabled = true;
            },
        };
    });
}
