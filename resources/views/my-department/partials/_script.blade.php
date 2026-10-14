<script>
    document.addEventListener('alpine:init', () => {
        const MONTHS = ['มกราคม','กุมภาพันธ์','มีนาคม','เมษายน','พฤษภาคม','มิถุนายน','กรกฎาคม','สิงหาคม','กันยายน','ตุลาคม','พฤศจิกายน','ธันวาคม'];
        const WEEKDAYS = ['วันอาทิตย์','วันจันทร์','วันอังคาร','วันพุธ','วันพฤหัสบดี','วันศุกร์','วันเสาร์'];
        const STATUS_RANK = { IN_PROGRESS: 0, ACCEPTED: 1, ASSIGNED: 2, COMPLETED: 3 };
        // Categorical colors for "which Project is this" (bar identity), kept away from
        // blue/indigo since those are the brand/primary-action color everywhere else
        // in the app and would be confused for "selected"/"primary" rather than a category.
        // `hex` is the Tailwind 500-shade of the same color (this app doesn't
        // customize these in tailwind.config.js, only blue/indigo) - used as the
        // swatch preview and as the base for a user's custom per-Project color
        // (projects.color) in taskColor() below; bar/dot/accent stay Tailwind
        // classes for the (far more common) auto-assigned, uncustomized case.
        const TASK_PALETTE = [
            { bar: 'bg-orange-100 text-orange-800', dot: 'bg-orange-500', accent: 'border-l-orange-400', hex: '#f97316' },
            { bar: 'bg-emerald-100 text-emerald-800', dot: 'bg-emerald-500', accent: 'border-l-emerald-400', hex: '#10b981' },
            { bar: 'bg-purple-100 text-purple-800', dot: 'bg-purple-500', accent: 'border-l-purple-400', hex: '#a855f7' },
            { bar: 'bg-pink-100 text-pink-800', dot: 'bg-pink-500', accent: 'border-l-pink-400', hex: '#ec4899' },
            { bar: 'bg-teal-100 text-teal-800', dot: 'bg-teal-500', accent: 'border-l-teal-400', hex: '#14b8a6' },
            { bar: 'bg-amber-100 text-amber-800', dot: 'bg-amber-500', accent: 'border-l-amber-400', hex: '#f59e0b' },
            { bar: 'bg-cyan-100 text-cyan-800', dot: 'bg-cyan-500', accent: 'border-l-cyan-400', hex: '#06b6d4' },
            { bar: 'bg-rose-100 text-rose-800', dot: 'bg-rose-500', accent: 'border-l-rose-400', hex: '#f43f5e' },
            { bar: 'bg-fuchsia-100 text-fuchsia-800', dot: 'bg-fuchsia-500', accent: 'border-l-fuchsia-400', hex: '#d946ef' },
            { bar: 'bg-lime-100 text-lime-800', dot: 'bg-lime-500', accent: 'border-l-lime-400', hex: '#84cc16' },
        ];
        // Avatar Planning status colours (the 5 dots in its toolbar legend, same order): a bar/chip is tinted with its
        // status colour and carries a 3px border in the solid colour. Key = assignment status, plus the derived 'overdue'.
        const STATUS_STYLES = {
            assigned: { hex: '#98a2b3', label: 'รอรับงาน' },
            in_progress: { hex: '#3b82f6', label: 'กำลังทำ' },
            accepted: { hex: '#f59e0b', label: 'รับงานแล้ว' },
            completed: { hex: '#10b981', label: 'เสร็จแล้ว' },
            overdue: { hex: '#ef4444', label: 'เกินกำหนด' },
        };
        const MAX_LANES = 4; // bars shown per week before "+N" takes over
        const hashString = (s) => { let h = 0; for (let i = 0; i < s.length; i++) h = (h * 31 + s.charCodeAt(i)) >>> 0; return h; };
        const hexToRgba = (hex, alpha) => {
            const full = hex.replace('#', '').replace(/^([0-9a-f]{3})$/i, '$1$1');
            const n = parseInt(full, 16);
            return 'rgba(' + ((n >> 16) & 255) + ', ' + ((n >> 8) & 255) + ', ' + (n & 255) + ', ' + alpha + ')';
        };
        const pad = (n) => String(n).padStart(2, '0');
        const ymd = (d) => d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate());
        const parse = (s) => { const [y, m, d] = s.split('-').map(Number); return new Date(y, m - 1, d); };
        const csrf = () => document.querySelector('meta[name="csrf-token"]').content;

        async function api(url, method = 'GET', body) {
            const res = await fetch(url, {
                method,
                headers: {
                    'Accept': 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrf(),
                },
                body: body ? JSON.stringify(body) : undefined,
            });
            const data = await res.json().catch(() => ({}));
            if (!res.ok) throw new Error(data.message || 'ไม่สามารถดำเนินการได้ กรุณาลองใหม่');
            return data;
        }

        Alpine.data('myDepartment', (config) => {
            const start = parse(config.today);

            return {
                mainView: 'calendar', // 'calendar' (Month/Week/List) | 'timeline' (Gantt-style, Project rows or Department rows under "ทุกแผนก")
                // Calendar/List: hide individual completed Sub Tasks, see visibleItems()/
                // dayItems()/listItems(). Timeline still aggregates into one bar per group
                // (Project, or Department under "ทุกแผนก") - see visibleProjectGroups()/
                // visibleDepartmentGroups() below, which hide a whole group only once
                // every Sub Task inside it is COMPLETED.
                hideCompleted: false, // remembered per-browser via localStorage
                // Personal quick filters (Avatar Planning "👤 งานของฉัน" / "⭐"), client-side only, remembered per-browser.
                // They narrow the already-loaded items; they never widen the server's department scope.
                mineOnly: false,
                starredOnly: false,
                userId: config.userId,
                search: '', // free-text filter over project / customer / cabinet / sub task / department (Avatar's search box)
                priorityFilter: 'all', // 'all' | 'urgent' | 'high'
                splitBars: false, // false = one bar per Project (Avatar Planning), true = one bar per Sub Task (the original schedule)
                moreOpen: false, // "ตัวเลือก" popover
                focusDate: config.today, // the left panel's "งานที่ต้องเสร็จ" day
                dash: null, // data for the แดชบอร์ด tab (my-department.dashboard)
                dashLoading: false,
                statusStyles: STATUS_STYLES,
                // project modal (the wide "โครงการ" dialog)
                pmOpen: false, pm: null, pmLoading: false, pmSaving: false, pmSavedAt: '', pmError: '',
                pmExpanded: {}, pmComment: '', pmFiles: [], pmAlertDept: '', pmPosting: false,
                view: 'calendar',
                range: 'month', // 'month' | '7' (rolling 7 days, "สัปดาห์")
                anchor: config.today, // first day of a rolling range
                today: config.today,
                year: start.getFullYear(),
                month: start.getMonth(),
                selectedDate: config.today,
                departmentId: config.departmentId, // numeric id, or the string 'all' (PMO "ทุกแผนก")
                departmentName: config.departmentName,
                isAllDepartments: false, // mirrors the server's response, since departmentId can be 'all'
                projectId: '', // '' = ทั้งหมด (point 5) - sent to the server, narrows within whatever Department scope applies
                statusFilter: '', // '' = ทั้งหมด - ASSIGNED|ACCEPTED|IN_PROGRESS|COMPLETED|OVERDUE, sent to the server
                items: [], // raw Sub Tasks from the server - unchanged shape/scope
                projectGroupsList: [], // Sub Tasks grouped by Project - see computeProjectGroups() (Timeline, single Department)
                departmentGroupsList: [], // Sub Tasks grouped by Department - see computeDepartmentGroups() (Timeline, "ทุกแผนก")
                loading: false,
                loadSeq: 0,
                detail: null, // single Sub Task detail (existing modal, unchanged)
                detailLoading: false,
                panel: null, // open Right Detail Panel - one Project group, or null
                panelExpanded: {}, // cabinet_id -> bool, which Cabinets are expanded inside the open panel
                colorPickerOpen: false, // open Project color picker popover in the panel header
                // Hides COMPLETED Sub Task rows inside the open panel's Cabinet list -
                // presentation only (see visibleTasksFor()), never touches the true
                // counts in the Project Summary / Cabinet row. Deliberately a separate
                // state from hideCompleted above, which hides whole 100%-done PROJECTS
                // on Calendar/Timeline/List - different granularity, different scope.
                hideCompletedSubtasks: false,
                dayPanelOpen: false, // open Day Detail Panel (selected day's Projects) - see selectDate()/openDayPanel()
                detailPanelOpen: false, // open Right Side Work Detail Panel (one Sub Task) - see openDetail()/closeDetailPanel()

                init() {
                    // Remembered per-browser only (not synced anywhere) - if it can't be
                    // read (private mode, blocked storage), just falls back to unchecked.
                    try {
                        this.hideCompleted = localStorage.getItem('myDepartment.hideCompleted') === '1';
                        this.hideCompletedSubtasks = localStorage.getItem('avatar_pmo_hide_completed_subtasks') === '1';
                        this.mineOnly = localStorage.getItem('myDepartment.mineOnly') === '1';
                        this.starredOnly = localStorage.getItem('myDepartment.starredOnly') === '1';
                    } catch (e) { /* ignore */ }
                    this.load();
                },
                toggleHideCompleted() {
                    this.rebuild();
                    try {
                        localStorage.setItem('myDepartment.hideCompleted', this.hideCompleted ? '1' : '0');
                    } catch (e) { /* ignore */ }
                },
                toggleQuickFilter(key) {
                    this[key] = !this[key];
                    this.rebuild();
                    try {
                        localStorage.setItem('myDepartment.' + key, this[key] ? '1' : '0');
                    } catch (e) { /* ignore */ }
                },
                toggleHideCompletedSubtasks() {
                    try {
                        localStorage.setItem('avatar_pmo_hide_completed_subtasks', this.hideCompletedSubtasks ? '1' : '0');
                    } catch (e) { /* ignore */ }
                },

                // ---- Calendar math ----
                addDays(dateStr, n) {
                    const d = parse(dateStr);
                    d.setDate(d.getDate() + n);
                    return ymd(d);
                },

                // First and last day of the range being viewed (excludes grid padding).
                rangeBounds() {
                    if (this.range === 'month') {
                        return [ymd(new Date(this.year, this.month, 1)), ymd(new Date(this.year, this.month + 1, 0))];
                    }
                    return [this.anchor, this.addDays(this.anchor, 6)];
                },

                rangeLabel() {
                    if (this.range === 'month') return MONTHS[this.month] + ' ' + (this.year + 543);
                    const [from, to] = this.rangeBounds();
                    return this.fmt(from) + ' – ' + this.fmt(to);
                },

                // Month: Monday-aligned weeks like Avatar Planning (days outside the range are dimmed). 7-day: exactly 7 cells from the anchor.
                gridDays() {
                    const [first, last] = this.rangeBounds();
                    let from = parse(first), to = parse(last);
                    if (this.range !== '7') {
                        from.setDate(from.getDate() - ((from.getDay() + 6) % 7));
                        to.setDate(to.getDate() + ((7 - to.getDay()) % 7));
                    }
                    const days = [];
                    for (let d = new Date(from); d <= to; d.setDate(d.getDate() + 1)) {
                        const date = ymd(d);
                        days.push({ date, day: d.getDate(), inRange: date >= first && date <= last });
                    }
                    return days;
                },

                weekdayHeaders() {
                    const names = ['จ.', 'อ.', 'พ.', 'พฤ.', 'ศ.', 'ส.', 'อา.']; // Monday first
                    return this.range === '7'
                        ? this.gridDays().map((d) => names[(parse(d.date).getDay() + 6) % 7])
                        : names;
                },

                // Calendar grid as weeks (rows of 7) so a multi-day Project bar can be drawn
                // as one continuous bar spanning its columns, instead of a separate chip
                // repeated in every day it touches.
                weekRows() {
                    const days = this.gridDays();
                    const weeks = [];
                    for (let i = 0; i < days.length; i += 7) weeks.push(days.slice(i, i + 7));
                    return weeks;
                },

                fmt(s) { return s ? s.split('-').reverse().join('/') : '-'; },

                // Days remaining until a due date (Project Header, Cabinet row) - date-only
                // comparison (both sides parsed via parse(), which drops time entirely), so
                // no off-by-one from timezones. `isComplete` suppresses the "เกินกำหนด" text
                // for an already-fully-done Project/Cabinet (section 16) - purely a derived
                // UI label, never touches assignment_status/is_overdue on any Sub Task.
                remainingDaysInfo(dateStr, isComplete) {
                    if (!dateStr) return null;
                    const n = Math.round((parse(dateStr) - parse(this.today)) / 86400000);
                    if (n < 0) {
                        if (isComplete) return null;
                        return { text: 'เกินกำหนด ' + Math.abs(n) + ' วัน', class: 'text-red-600 font-medium' };
                    }
                    if (n === 0) return { text: 'ครบกำหนดวันนี้', class: 'text-orange-600 font-medium' };
                    if (n <= 3) return { text: 'เหลือ ' + n + ' วัน', class: 'text-orange-600 font-medium' };
                    return { text: 'เหลือ ' + n + ' วัน', class: 'text-slate-400' };
                },

                dayHeading() {
                    const d = parse(this.selectedDate);
                    return WEEKDAYS[d.getDay()] + ' ' + this.fmt(this.selectedDate);
                },

                // ---- Navigation ----
                prevMonth() { this.shift(-1); },
                nextMonth() { this.shift(1); },
                shift(dir) {
                    if (this.range === 'month') {
                        const d = new Date(this.year, this.month + dir, 1);
                        this.year = d.getFullYear();
                        this.month = d.getMonth();
                        this.selectedDate = ymd(d);
                    } else {
                        this.anchor = this.addDays(this.anchor, dir * 7);
                        this.selectedDate = this.anchor;
                    }
                    this.load();
                },
                goToday() {
                    const t = parse(this.today);
                    this.year = t.getFullYear();
                    this.month = t.getMonth();
                    this.anchor = this.today;
                    this.selectedDate = this.today;
                    this.load();
                },
                setRange(range) {
                    if (range !== this.range) {
                        // Keep the day being looked at in view when switching.
                        const focus = this.selectedDate;
                        this.range = range;
                        const d = parse(focus);
                        this.year = d.getFullYear();
                        this.month = d.getMonth();
                        this.anchor = focus;
                        this.load();
                    }
                },
                // Month/Week/List are one 3-way toggle - the first two also switch back to the calendar.
                setCalendarRange(range) {
                    this.view = 'calendar';
                    this.setRange(range);
                },
                selectDate(date) {
                    const d = parse(date);
                    if (this.range === 'month' && (d.getFullYear() !== this.year || d.getMonth() !== this.month)) {
                        this.year = d.getFullYear();
                        this.month = d.getMonth();
                        this.selectedDate = date;
                        this.load();
                        this.openDayPanel();
                        return;
                    }
                    this.selectedDate = date;
                    this.openDayPanel();
                },
                openDayPanel() {
                    this.dayPanelOpen = true;
                },
                closeDayPanel() {
                    this.dayPanelOpen = false;
                },
                changeDepartment() {
                    this.load();
                    if (this.mainView === 'dashboard') this.loadDashboard();
                },
                changeProjectFilter() {
                    this.load();
                },
                changeStatusFilter() {
                    this.load();
                },

                // The exact filter set the screen is showing right now (window + Department/
                // Project/Status) - shared by the data fetch and the CSV export.
                queryParams() {
                    const days = this.gridDays();
                    const params = {
                        from: days[0].date,
                        to: days[days.length - 1].date,
                        department_id: this.departmentId,
                    };
                    // Point 5/25 - Project/Status filter together with Department,
                    // kept across Calendar/Week/Day/Timeline/List (point 25) since
                    // they're all read from this same Alpine state, not per-view state.
                    if (this.projectId) params.project_id = this.projectId;
                    if (this.statusFilter) params.status = this.statusFilter;
                    return new URLSearchParams(params);
                },
                exportCsv() {
                    window.location = config.exportUrl + '?' + this.queryParams().toString();
                },

                // ---- Data ----
                async load() {
                    const seq = ++this.loadSeq;
                    this.loading = true;
                    try {
                        const q = this.queryParams();
                        const data = await api(config.tasksUrl + '?' + q.toString());
                        if (seq !== this.loadSeq) return; // a newer request superseded this one
                        this.items = data.items.map((i) => ({ ...i, busy: false }));
                        this.departmentName = data.department.name;
                        this.isAllDepartments = !!data.is_all_departments;
                        this.panel = null; // department/window changed - a stale drill-down panel would show wrong data
                        this.rebuild();
                    } catch (err) {
                        if (seq === this.loadSeq) window.showToast(err.message, 'error');
                    } finally {
                        if (seq === this.loadSeq) this.loading = false;
                    }
                },

                rank(i) {
                    return [i.is_overdue ? 0 : 1, this.isHigh(i) ? 0 : 1, STATUS_RANK[i.assignment_status] ?? 9];
                },
                compare(a, b) {
                    const ra = this.rank(a), rb = this.rank(b);
                    for (let k = 0; k < ra.length; k++) if (ra[k] !== rb[k]) return ra[k] - rb[k];
                    return a.id - b.id;
                },

                // ---- Project/Department grouping (Timeline only - Calendar/List read
                // items directly, one Sub Task/Assignment at a time) ----
                // Shared aggregation core: every Sub Task is folded into whichever group
                // keyFn() puts it in (one Project, or one Department) - same counts/dates/
                // items shape either way, so Timeline's bar-drawing code (timelineRows())
                // doesn't need to know which kind of group it's drawing.
                groupItemsBy(keyFn, extra) {
                    const map = new Map();
                    for (const i of this.scopedItems()) {
                        const key = keyFn(i);
                        if (!map.has(key)) {
                            map.set(key, {
                                ...extra(i),
                                projectIds: new Set(),
                                cabinetIds: new Set(),
                                taskIds: new Set(),
                                waiting: 0, accepted: 0, working: 0, done: 0, overdue: 0,
                                dept_start: null, dept_due: null,
                                items: [],
                            });
                        }
                        const g = map.get(key);
                        g.projectIds.add(i.project_id);
                        g.cabinetIds.add(i.cabinet_id);
                        g.taskIds.add(i.cabinet_task_id);
                        g.items.push(i);
                        if (i.is_overdue) g.overdue++;
                        if (i.assignment_status === 'ASSIGNED') g.waiting++;
                        else if (i.assignment_status === 'ACCEPTED') g.accepted++;
                        else if (i.assignment_status === 'IN_PROGRESS') g.working++;
                        else if (i.assignment_status === 'COMPLETED') g.done++;
                        const from = i.start_date || i.due_date, to = i.due_date || i.start_date;
                        if (from && (!g.dept_start || from < g.dept_start)) g.dept_start = from;
                        if (to && (!g.dept_due || to > g.dept_due)) g.dept_due = to;
                    }
                    return [...map.values()].map((g) => {
                        const total = g.waiting + g.accepted + g.working + g.done;
                        return {
                            ...g,
                            project_count: g.projectIds.size,
                            cabinet_count: g.cabinetIds.size,
                            task_count: g.taskIds.size,
                            total_subtasks: total,
                            // Point 9: completed_subtasks / total_department_subtasks * 100 - a
                            // distinct metric from each Sub Task's own checklist-based progress.
                            progress: total ? Math.round((g.done / total) * 100) : 0,
                        };
                    });
                },

                computeProjectGroups() {
                    return this.groupItemsBy((i) => i.project_id, (i) => ({
                        kind: 'project',
                        project_id: i.project_id,
                        project_no: i.project_no,
                        project_name: i.project_name,
                        customer_name: i.customer_name,
                        priority: i.priority,
                        priority_label: i.priority_label,
                        color: i.color,
                        project_color_url: i.project_color_url,
                    }));
                },

                // One group per Department (point 41) - only meaningful once "ทุกแผนก" can
                // mix multiple Departments' Sub Tasks together; Timeline draws one bar per
                // Department instead of per Project in that case, since a Department's own
                // Project-level breakdown is one click away (selectDepartment() below).
                computeDepartmentGroups() {
                    return this.groupItemsBy((i) => i.department_id, (i) => ({
                        kind: 'department',
                        department_id: i.department_id,
                        department_name: i.department_name,
                    }));
                },

                // Project-level equivalent of the old visibleItems(): hides a whole Project
                // only once every one of its department Sub Tasks is COMPLETED.
                visibleProjectGroups() {
                    return this.hideCompleted
                        ? this.projectGroupsList.filter((g) => g.progress !== 100)
                        : this.projectGroupsList;
                },
                visibleDepartmentGroups() {
                    return this.hideCompleted
                        ? this.departmentGroupsList.filter((g) => g.progress !== 100)
                        : this.departmentGroupsList;
                },

                rankGroup(g) {
                    return [g.overdue > 0 ? 0 : 1, this.isHigh(g) ? 0 : 1];
                },
                compareGroups(a, b) {
                    const ra = this.rankGroup(a), rb = this.rankGroup(b);
                    for (let k = 0; k < ra.length; k++) if (ra[k] !== rb[k]) return ra[k] - rb[k];
                    return a.project_id - b.project_id;
                },

                // The Calendar itself no longer aggregates into per-Project bars
                // (dayMap/weekBarsMap/computeWeekBars retired below) - Month/Week/Day now
                // read items directly via dayItems(), one Sub Task/Assignment at a time,
                // per the Department Work Schedule redesign. List (listItems()/
                // listSections() below) does the same. Only Timeline still draws one bar
                // per group (Project, or Department under "ทุกแผนก") - see timelineRows().
                rebuild() {
                    this.projectGroupsList = this.computeProjectGroups();
                    this.departmentGroupsList = this.computeDepartmentGroups();
                },

                // Presentation-only Hide Completed (point 14) - hides individual
                // completed Sub Tasks from the Calendar, never changes panel.progress/
                // total_subtasks or any real count elsewhere.
                visibleItems() {
                    const items = this.scopedItems();
                    return this.hideCompleted ? items.filter((i) => i.assignment_status !== 'COMPLETED') : items;
                },

                // items after the personal quick filters (งานของฉัน = I am the Sub Task's owner, ⭐ = my stars)
                scopedItems() {
                    const q = this.search.trim().toLowerCase();
                    return this.items.filter((i) =>
                        (!this.mineOnly || i.owner_id === this.userId)
                        && (!this.starredOnly || i.is_starred)
                        && (this.priorityFilter === 'all' || i.priority === this.priorityFilter)
                        && (!q || [i.project_no, i.project_name, i.customer_name, i.cabinet_mo, i.cabinet_name, i.name, i.department_name]
                            .some((v) => (v || '').toLowerCase().includes(q)))
                    );
                },

                // "งานที่ต้องเสร็จวันนี้" - unfinished Sub Tasks whose due date is today
                dueTodayItems() {
                    return this.visibleItems()
                        .filter((i) => i.due_date === this.today && i.assignment_status !== 'COMPLETED')
                        .sort((a, b) => this.compare(a, b));
                },

                async toggleStar(item) {
                    try {
                        const data = await api(item.urls.star, 'POST');
                        item.is_starred = data.starred;
                        const shared = this.items.find((i) => i.id === item.id);
                        if (shared) shared.is_starred = data.starred;
                        if (this.detail && this.detail.id === item.id) this.detail.is_starred = data.starred;
                        if (this.starredOnly) this.rebuild();
                    } catch (err) {
                        window.showToast(err.message, 'error');
                    }
                },

                // Every Sub Task/Assignment active on one date (point 6/9) - the
                // Calendar's actual work items, not an aggregated Project bar.
                dayItems(date) {
                    return this.visibleItems()
                        .filter((i) => {
                            const from = i.start_date || i.due_date, to = i.due_date || i.start_date;
                            return from && to && from <= date && date <= to;
                        })
                        .sort((a, b) => this.compare(a, b));
                },

                overdueCount(date) {
                    return this.dayItems(date).filter((i) => i.is_overdue).length;
                },

                // A multi-day Sub Task is drawn as ONE continuous bar spanning its
                // columns within the week, instead of a chip repeated in every day it
                // touches - same lane-packing/clip technique the old per-Project calendar
                // bars used, just against individual Sub Tasks (visibleItems()) now
                // instead of Project aggregates, so Calendar stays Sub-Task-level
                // everywhere else (dayItems(), the Day Panel, etc.) while still reading
                // as one bar per task at a glance.
                weekItemBars(week) {
                    const weekStart = week[0].date, weekEnd = week[week.length - 1].date;
                    const items = this.visibleItems()
                        .filter((i) => {
                            const from = i.start_date || i.due_date, to = i.due_date || i.start_date;
                            return from && to && from <= weekEnd && to >= weekStart;
                        })
                        .sort((a, b) => this.compare(a, b));

                    const laneEnd = []; // lane index -> last occupied day index (0-based) in this week
                    const bars = [];
                    for (const i of items) {
                        const from = i.start_date || i.due_date, to = i.due_date || i.start_date;
                        const clipStart = from < weekStart ? weekStart : from;
                        const clipEnd = to > weekEnd ? weekEnd : to;
                        const colStartIdx = week.findIndex((d) => d.date === clipStart);
                        const colEndIdx = week.findIndex((d) => d.date === clipEnd);
                        let lane = laneEnd.findIndex((end) => end < colStartIdx);
                        if (lane === -1) { lane = laneEnd.length; laneEnd.push(-1); }
                        laneEnd[lane] = colEndIdx;
                        bars.push({
                            item: i,
                            lane,
                            colStart: colStartIdx + 1,
                            colSpan: colEndIdx - colStartIdx + 1,
                            clipStart, clipEnd,
                            continuesBefore: from < weekStart,
                            continuesAfter: to > weekEnd,
                        });
                    }
                    return bars;
                },
                // At most 2 lanes shown per week (same "2 cards, then +N งาน" budget the
                // per-day cards used before) - overflow still opens the full Day Panel
                // (selectDate()), never hides a Sub Task entirely.
                weekVisibleBars(week) {
                    return this.weekItemBars(week).filter((b) => b.lane < 2);
                },
                // A day's own "+N งาน" count once the 2 shown lanes don't cover everything
                // active that day - same number dayItems(date).length always gave, minus
                // whatever's already visible as a bar through this day's column.
                dayOverflowCount(week, date) {
                    const shown = this.weekVisibleBars(week).filter((b) => b.clipStart <= date && date <= b.clipEnd).length;
                    return this.dayItems(date).length - shown;
                },

                // Sub Tasks with no Start/Due Date at all (point 29) - must never
                // disappear just because they can't be placed on the grid.
                unscheduledItems() {
                    return this.visibleItems().filter((i) => !i.start_date && !i.due_date);
                },

                // Deterministic Department color (point 8) - same hash technique as
                // taskColor() (Project color), just keyed by Department name instead;
                // Department has no color column of its own, this is UI-only.
                departmentColor(name) {
                    // Same shape as taskColor() (style/dotStyle/accentStyle always present,
                    // just empty) even though Departments have no color override - so the
                    // legend/card markup can read either color object the same way.
                    const base = TASK_PALETTE[hashString(name || '') % TASK_PALETTE.length];
                    return { ...base, style: '', dotStyle: '', accentStyle: '' };
                },

                // Distinct Departments currently visible, for the Calendar's legend -
                // only meaningful once "ทุกแผนก" can mix multiple Departments together;
                // alphabetical so it doesn't reorder as data reloads.
                departmentLegendItems() {
                    const seen = new Map();
                    for (const i of this.visibleItems()) {
                        if (i.department_name && !seen.has(i.department_name)) {
                            seen.set(i.department_name, this.departmentColor(i.department_name));
                        }
                    }
                    return [...seen.entries()].sort((a, b) => a[0].localeCompare(b[0])).map(([name, color]) => ({ name, color }));
                },

                // ---- List (Sub Task level, point 25/41) ----
                // List follows the same range as the Calendar: a Sub Task overlapping it,
                // plus any Sub Task that's overdue or has no date at all (point 29 - never
                // hidden just because it can't be placed on the grid).
                inWindowItem(i) {
                    if (i.is_overdue || (!i.start_date && !i.due_date)) return true;
                    const [from, to] = this.rangeBounds();
                    const s = i.start_date || i.due_date, e = i.due_date || i.start_date;
                    return s <= to && e >= from;
                },

                // One row per Sub Task/Assignment - same granularity as the Calendar now,
                // not an aggregated Project row (point 41's redesign direction applied to
                // List too).
                listItems() {
                    return this.visibleItems().filter((i) => this.inWindowItem(i)).sort((a, b) => this.compare(a, b));
                },

                // Grouped under a Department header only once "ทุกแผนก" can mix multiple
                // Departments together; a single-department view returns one section with
                // no header shown (see the List table's x-show on the header row).
                listSections() {
                    const rows = this.listItems();
                    if (!this.isAllDepartments) return [{ department_id: null, department_name: null, rows }];
                    const map = new Map();
                    for (const i of rows) {
                        if (!map.has(i.department_id)) {
                            map.set(i.department_id, { department_id: i.department_id, department_name: i.department_name, rows: [] });
                        }
                        map.get(i.department_id).rows.push(i);
                    }
                    return [...map.values()].sort((a, b) => (a.department_name || '').localeCompare(b.department_name || ''));
                },

                // ---- Timeline (Gantt-style) ----
                // Reuses this.items as-is (already scoped to the selected department by
                // the backend - see MyDepartmentController@tasks) and the SAME date
                // window as the Calendar (range/anchor/year/month). One row per Project.
                timelineDays() {
                    return this.gridDays().filter((d) => d.inRange);
                },
                // Fixed pixel columns, not 1fr - inside an overflow-x-auto/shrink-to-fit
                // container, 1fr has no definite width to resolve against and blows up
                // to an oversized, unpredictable column width.
                timelineColWidth() { return this.range === '7' ? 96 : 32; },
                timelineColTemplate() {
                    return 'repeat(' + (this.timelineDays().length || 1) + ', ' + this.timelineColWidth() + 'px)';
                },
                // Scrolls the Timeline's horizontal container so "today" starts in
                // view instead of the window's first day - called once when switching
                // into Timeline (doesn't touch Calendar's own nav/scroll at all).
                scrollTimelineToToday() {
                    const days = this.timelineDays();
                    const idx = days.findIndex((d) => d.date === this.today);
                    if (idx === -1 || !this.$refs.timelineScroll) return;
                    this.$refs.timelineScroll.scrollLeft = Math.max(0, idx * this.timelineColWidth() - 150);
                },
                // "Where are we" marker: a vertical line through every row at today's
                // column, in addition to the header's own highlighted day cell - useful
                // once there are many Project rows and the header alone scrolls out of view.
                todayLineLeft() {
                    const days = this.timelineDays();
                    const idx = days.findIndex((d) => d.date === this.today);
                    if (idx === -1) return null;
                    return 200 + idx * this.timelineColWidth() + this.timelineColWidth() / 2;
                },
                // One row per group - Project normally, or Department when "ทุกแผนก" is
                // selected (point 41: a Department's own Sub Tasks can span many
                // Projects at once, so one bar per Project there would be far too many
                // rows to scan; drilling into one Department via selectDepartment()
                // below switches this back to the familiar per-Project rows). Each bar
                // spans its group's Start -> Due, clipped to the visible window (same
                // clip/column technique as the old computeWeekBars, just against the
                // whole window instead of one week).
                timelineRows() {
                    const days = this.timelineDays();
                    if (!days.length) return [];
                    const winStart = days[0].date, winEnd = days[days.length - 1].date;
                    const dayIndex = (date) => days.findIndex((d) => d.date === date);
                    const groups = this.visibleProjectGroups(); // Avatar Planning: one row per Project, whatever the department filter

                    return groups
                        .filter((g) => g.dept_start && !(g.dept_start > winEnd || g.dept_due < winStart))
                        .map((g) => {
                            const from = g.dept_start, to = g.dept_due;
                            const clipStart = from < winStart ? winStart : from;
                            const clipEnd = to > winEnd ? winEnd : to;
                            const colStart = dayIndex(clipStart) + 1;
                            return {
                                group: g,
                                colStart,
                                colSpan: dayIndex(clipEnd) - colStart + 1,
                                continuesBefore: from < winStart,
                                continuesAfter: to > winEnd,
                            };
                        })
                        .sort((a, b) => (a.colStart !== b.colStart ? a.colStart - b.colStart : this.compareGroups(a.group, b.group)));
                },
                // Groups that can't be drawn as a bar in the current window: no dated
                // work at all, or entirely outside it (e.g. an old overdue Project before
                // this month).
                timelineOffWindowCount() {
                    const days = this.timelineDays();
                    const groups = this.visibleProjectGroups();
                    if (!days.length) return groups.length;
                    const winStart = days[0].date, winEnd = days[days.length - 1].date;
                    return groups.filter((g) => !g.dept_start || g.dept_start > winEnd || g.dept_due < winStart).length;
                },
                // Drills from a Department's aggregate Timeline bar into that single
                // Department (point 41) - same as picking it from the toolbar's select,
                // just one click from the bar itself. Switches Timeline's own rows back
                // to per-Project (isAllDepartments becomes false), where the existing
                // openProjectPanel() drill-down already works.
                selectDepartment(departmentId) {
                    this.departmentId = departmentId;
                    this.changeDepartment();
                },
                // Bar color: Department identity color under "ทุกแผนก" (same palette
                // Calendar's legend uses), Project identity color otherwise - unchanged.
                rowColor(g) {
                    return g.kind === 'department' ? this.departmentColor(g.department_name) : this.taskColor(g);
                },
                rowTitle(g) {
                    return g.kind === 'department'
                        ? g.department_name + ' — ' + g.project_count + ' Project' +
                            ' — รอ ' + g.waiting + ' · รับงานแล้ว ' + g.accepted + ' · กำลังทำ ' + g.working + ' · เสร็จ ' + g.done +
                            (g.overdue ? ' · เกินกำหนด ' + g.overdue : '')
                        : this.groupTitle(g);
                },
                rowSummaryText(g) {
                    return g.kind === 'department'
                        ? '· ' + g.project_count + ' Project · รอ ' + g.waiting + ' · ทำ ' + g.working + ' · เสร็จ ' + g.done
                        : '· ' + g.cabinet_count + ' Cab · ' + g.task_count + ' Task · รอ ' + g.waiting + ' · ทำ ' + g.working + ' · เสร็จ ' + g.done;
                },

                // ---- Avatar Planning style views: toolbar switch, chips, left panel, dashboard ----
                showMonth() {
                    this.mainView = 'calendar';
                    this.view = 'calendar';
                    if (this.range !== 'month') this.setRange('month');
                },
                showTimeline() {
                    this.mainView = 'timeline';
                    this.$nextTick(() => requestAnimationFrame(() => this.scrollTimelineToToday()));
                },
                showDashboard() {
                    this.mainView = 'dashboard';
                    this.loadDashboard();
                },
                async loadDashboard() {
                    this.dashLoading = true;
                    try {
                        this.dash = await api(config.dashboardUrl + '?department_id=' + encodeURIComponent(this.departmentId));
                    } catch (err) {
                        window.showToast(err.message, 'error');
                    } finally {
                        this.dashLoading = false;
                    }
                },
                setPriorityFilter(v) { this.priorityFilter = v; this.rebuild(); },

                statusColor(key) { return (STATUS_STYLES[key] || STATUS_STYLES.assigned).hex; },
                statusLabel(key) { return (STATUS_STYLES[key] || STATUS_STYLES.assigned).label; },
                // chip / bar look: tinted fill + solid 3px left border, both from the status colour
                chipStyle(key) {
                    const hex = this.statusColor(key);
                    return 'background-color:' + hexToRgba(hex, 0.16) + ';border-left-color:' + hex;
                },
                // a single Sub Task's status colour key
                itemStatusKey(i) {
                    if (i.assignment_status === 'COMPLETED') return 'completed';
                    if (i.is_overdue) return 'overdue';
                    return { IN_PROGRESS: 'in_progress', ACCEPTED: 'accepted' }[i.assignment_status] || 'assigned';
                },
                // a Project's status colour key: the worst thing going on inside it
                groupStatusKey(g) {
                    if (g.overdue > 0) return 'overdue';
                    if (g.total_subtasks > 0 && g.done === g.total_subtasks) return 'completed';
                    if (g.working > 0) return 'in_progress';
                    if (g.accepted > 0) return 'accepted';
                    return 'assigned';
                },
                // priority flag colour (urgent red / high orange / low blue; normal = no flag), for Projects and Sub Tasks alike
                flagClass(x) { return this.priorityFlagClass(x); },

                // Bars for one calendar week, as ONE shape for both modes so the template draws them identically:
                // per Project (default, Avatar Planning: title + cabinet-count badge + flag) or per Sub Task (splitBars).
                weekBars(week) {
                    const ws = week[0].date, we = week[week.length - 1].date;
                    const entries = this.splitBars
                        ? this.visibleItems().map((i) => ({
                            kind: 'item', ref: i, key: 'i' + i.id,
                            from: i.start_date || i.due_date, to: i.due_date || i.start_date,
                            status: this.itemStatusKey(i),
                            title: i.name,
                            sub: i.cabinet_mo + ' · ' + i.cabinet_name + (i.department_name ? ' · ' + i.department_name : ''),
                            badge: i.checklists_total > 0 ? i.checklists_completed + '/' + i.checklists_total : '',
                            done: i.assignment_status === 'COMPLETED',
                        })).sort((a, b) => this.compare(a.ref, b.ref))
                        : this.visibleProjectGroups().filter((g) => g.dept_start).map((g) => ({
                            kind: 'project', ref: g, key: 'p' + g.project_id,
                            from: g.dept_start, to: g.dept_due,
                            status: this.groupStatusKey(g),
                            title: g.project_no + ' ' + g.project_name,
                            sub: g.cabinet_count + ' ตู้ · เสร็จ ' + g.done + '/' + g.total_subtasks + ' งาน',
                            badge: String(g.cabinet_count),
                            done: g.total_subtasks > 0 && g.done === g.total_subtasks,
                        })).sort((a, b) => (a.from === b.from ? this.compareGroups(a.ref, b.ref) : a.from.localeCompare(b.from)));

                    const laneEnd = [];
                    const bars = [];
                    for (const e of entries) {
                        if (!e.from || !e.to || e.from > we || e.to < ws) continue;
                        const clipStart = e.from < ws ? ws : e.from;
                        const clipEnd = e.to > we ? we : e.to;
                        const colStartIdx = week.findIndex((d) => d.date === clipStart);
                        const colEndIdx = week.findIndex((d) => d.date === clipEnd);
                        let lane = laneEnd.findIndex((end) => end < colStartIdx);
                        if (lane === -1) { lane = laneEnd.length; laneEnd.push(-1); }
                        laneEnd[lane] = colEndIdx;
                        bars.push({
                            ...e, lane, clipStart, clipEnd,
                            colStart: colStartIdx + 1, colSpan: colEndIdx - colStartIdx + 1,
                            continuesBefore: e.from < ws, continuesAfter: e.to > we,
                        });
                    }
                    return bars;
                },
                weekShownBars(week) { return this.weekBars(week).filter((b) => b.lane < MAX_LANES); },
                // bars hidden by the lane cap that are active on this date -> the "+N" under that day
                weekHiddenCount(week, date) {
                    return this.weekBars(week).filter((b) => b.lane >= MAX_LANES && b.clipStart <= date && date <= b.clipEnd).length;
                },
                openBar(b) { b.kind === 'project' ? this.openProjectModal(b.ref.project_id) : this.openDetail(b.ref); },

                // Left panel "งานที่ต้องเสร็จ": the work due on focusDate, grouped by Project (+ what is overdue when looking at today)
                fmtBE(s) { if (!s) return '-'; const [y, m, d] = s.split('-'); return d + '/' + m + '/' + (Number(y) + 543); },
                shiftFocus(n) {
                    this.focusDate = this.addDays(this.focusDate, n);
                    const days = this.gridDays();
                    if (this.range === 'month' && (this.focusDate < days[0].date || this.focusDate > days[days.length - 1].date)) {
                        const d = parse(this.focusDate);
                        this.year = d.getFullYear();
                        this.month = d.getMonth();
                        this.load();
                    }
                },
                focusToday() { this.focusDate = this.today; },
                groupByProject(items) {
                    const map = new Map();
                    for (const i of items) {
                        if (!map.has(i.project_id)) map.set(i.project_id, { project_id: i.project_id, project_no: i.project_no, project_name: i.project_name, priority: i.priority, items: [] });
                        map.get(i.project_id).items.push(i);
                    }
                    return [...map.values()];
                },
                focusAll() { return this.scopedItems().filter((i) => i.due_date === this.focusDate); },
                focusDueGroups() {
                    return this.groupByProject(this.focusAll().filter((i) => i.assignment_status !== 'COMPLETED').sort((a, b) => this.compare(a, b)));
                },
                focusOverdueGroups() {
                    if (this.focusDate !== this.today) return [];
                    return this.groupByProject(this.scopedItems().filter((i) => i.is_overdue).sort((a, b) => this.compare(a, b)));
                },
                focusCounts() {
                    const all = this.focusAll();
                    const done = all.filter((i) => i.assignment_status === 'COMPLETED').length;
                    return { total: all.length, done, pct: all.length ? Math.round((done / all.length) * 100) : 0 };
                },
                // sections of the left panel: overdue (only when looking at today) then due that day
                focusSections() {
                    const sections = [];
                    const overdue = this.focusOverdueGroups();
                    if (overdue.length) sections.push({ key: 'overdue', label: 'เลยกำหนด', tone: 'text-red-600', groups: overdue, count: this.groupCount(overdue) });
                    const due = this.focusDueGroups();
                    sections.push({ key: 'due', label: this.focusDate === this.today ? 'ครบกำหนดวันนี้' : 'ครบกำหนด ' + this.fmtBE(this.focusDate), tone: 'text-slate-500', groups: due, count: this.groupCount(due) });
                    return sections;
                },
                isWeekend(date) { const n = parse(date).getDay(); return n === 0 || n === 6; },
                groupCount(groups) { return groups.reduce((n, g) => n + g.items.length, 0); },

                // ---- Project modal ----
                async openProjectModal(projectId) {
                    this.pmOpen = true;
                    this.pm = null;
                    this.pmLoading = true;
                    this.pmError = '';
                    this.pmSavedAt = '';
                    this.pmComment = '';
                    this.pmFiles = [];
                    this.pmAlertDept = '';
                    this.pmExpanded = {};
                    try {
                        this.pm = await api(config.projectUrl.replace('__ID__', projectId));
                    } catch (err) {
                        this.pmOpen = false;
                        window.showToast(err.message, 'error');
                    } finally {
                        this.pmLoading = false;
                    }
                },
                closeProjectModal() {
                    this.pmOpen = false;
                    this.pm = null;
                },
                // re-read the modal's data in place (after a Sub Task moved on, a checklist was ticked, or a save was refused)
                async reloadProjectModal() {
                    if (!this.pm) return;
                    const id = this.pm.project.id;
                    try {
                        const fresh = await api(config.projectUrl.replace('__ID__', id));
                        if (this.pm && this.pm.project.id === id) this.pm = { ...this.pm, project: fresh.project, departments: fresh.departments, cabinets: fresh.cabinets };
                    } catch (e) { /* the modal keeps what it has */ }
                },
                // Autosave: every edit is sent as soon as it is made (no บันทึก button), like Avatar Planning's task modal.
                async saveProject(field, value) {
                    if (!this.pm || !this.pm.project.can_edit) return;
                    this.pmSaving = true;
                    this.pmError = '';
                    try {
                        const data = await api(this.pm.project.urls.update, 'PATCH', { [field]: value });
                        this.pm.project = data.project;
                        this.pmSavedAt = new Date().toLocaleTimeString('th-TH', { hour: '2-digit', minute: '2-digit' });
                        this.load(); // flags / dates on the calendar follow the edit
                    } catch (err) {
                        this.pmError = err.message;
                        window.showToast(err.message, 'error');
                        await this.reloadProjectModal(); // put the fields back to what is really saved
                    } finally {
                        this.pmSaving = false;
                    }
                },
                // dd/mm/yyyy date field (flatpickr, same as the rest of the app) that autosaves on change
                initDate(el, field) {
                    if (!window.flatpickr) { el.type = 'date'; el.value = this.pm.project[field] || ''; el.addEventListener('change', () => this.saveProject(field, el.value || null)); return; }
                    window.flatpickr(el, {
                        dateFormat: 'Y-m-d', altInput: true, altFormat: 'd/m/Y', allowInput: true, formatDate: window.thaiFormat, parseDate: window.thaiParse,
                        altInputClass: 'w-[6.75rem] rounded-lg border-slate-300 px-2 py-1 text-sm',
                        defaultDate: this.pm.project[field] || null,
                        onChange: (dates, str) => { if (str !== (this.pm.project[field] || '')) this.saveProject(field, str || null); },
                    });
                },
                toggleCabinet(id) { this.pmExpanded = { ...this.pmExpanded, [id]: !this.pmExpanded[id] }; },
                cabinetStatusKey(c) {
                    const keys = c.items.map((i) => this.itemStatusKey(i));
                    if (keys.includes('overdue')) return 'overdue';
                    if (keys.length && keys.every((k) => k === 'completed')) return 'completed';
                    if (keys.includes('in_progress')) return 'in_progress';
                    if (keys.includes('accepted')) return 'accepted';
                    return 'assigned';
                },
                dueLabel(date) {
                    if (!date) return '';
                    if (date === this.today) return 'วันนี้';
                    return this.fmt(date).slice(0, 5);
                },
                pickFiles(event) { this.pmFiles = [...event.target.files].slice(0, 5); },
                async postComment() {
                    const text = this.pmComment.trim();
                    if (!text || this.pmPosting || !this.pm) return;
                    this.pmPosting = true;
                    const form = new FormData();
                    form.append('note', text);
                    this.pmFiles.forEach((f) => form.append('attachments[]', f));
                    if (this.pmAlertDept) form.append('alert_department_id', this.pmAlertDept);
                    try {
                        const res = await fetch(this.pm.project.urls.note, { method: 'POST', headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf() }, body: form });
                        const data = await res.json().catch(() => ({}));
                        if (!res.ok) throw new Error(data.message || 'ส่งไม่สำเร็จ กรุณาลองใหม่');
                        this.pm.activity.unshift(data.activity);
                        this.pmComment = '';
                        this.pmFiles = [];
                        this.pmAlertDept = '';
                        if (this.$refs.pmFileInput) this.$refs.pmFileInput.value = '';
                        if (data.notified) window.showToast('ส่งแจ้งเตือนถึง ' + data.notified + ' คนแล้ว');
                    } catch (err) {
                        window.showToast(err.message, 'error');
                    } finally {
                        this.pmPosting = false;
                    }
                },
                deleteProject() {
                    if (!this.pm || !confirm('ย้ายโครงการ ' + this.pm.project.project_no + ' ไปถังขยะ? (กู้คืนได้จากหน้าถังขยะ)')) return;
                    const form = document.createElement('form');
                    form.method = 'POST';
                    form.action = this.pm.project.urls.destroy;
                    form.innerHTML = '<input type="hidden" name="_token" value="' + csrf() + '"><input type="hidden" name="_method" value="DELETE">';
                    document.body.appendChild(form);
                    form.submit();
                },

                // ---- Visuals ----
                isHigh(x) { return x.priority === 'high' || x.priority === 'urgent'; },

                // Identity color for a Project - the same Project always gets the same
                // color everywhere (Calendar bar, Timeline row, legend, and the accent on
                // each Sub Task card inside the Right Detail Panel), since every item now
                // also carries its own project_no. A user can override the auto-assigned
                // palette color per Project (see setProjectColor() below) - `bar`/`dot`/
                // `accent` stay empty strings in that case (nothing to add to :class) and
                // `style`/`dotStyle`/`accentStyle` carry the inline-style equivalent
                // instead, since an arbitrary hex can't be expressed as a Tailwind class.
                taskColor(x) {
                    const base = TASK_PALETTE[hashString(x.project_no || '') % TASK_PALETTE.length];
                    if (!x.color) return { ...base, style: '', dotStyle: '', accentStyle: '' };
                    return {
                        bar: '', dot: '', accent: '', hex: x.color,
                        style: 'background-color:' + hexToRgba(x.color, 0.16) + ';color:' + x.color,
                        dotStyle: 'background-color:' + x.color,
                        accentStyle: 'border-left-color:' + x.color,
                    };
                },

                // Hex swatch presets for the color picker - one per TASK_PALETTE entry,
                // so a user picking a "preset" still lands on a color the auto-assign
                // could have picked anyway (no jarring new hues entering the app).
                presetColors() {
                    return TASK_PALETTE.map((p) => p.hex);
                },

                // Distinct Projects currently on the calendar, for the color legend.
                // Alphabetical so the legend order doesn't jump around as data reloads.
                legendItems() {
                    const seen = new Map();
                    for (const g of this.visibleProjectGroups()) {
                        if ((g.dept_start || g.dept_due) && !seen.has(g.project_no)) {
                            seen.set(g.project_no, this.taskColor(g));
                        }
                    }
                    return [...seen.entries()].sort((a, b) => a[0].localeCompare(b[0])).map(([name, color]) => ({ name, color }));
                },

                // Small solid-color indicator dot (checklist progress bar), status precedence.
                dotClass(i) {
                    return {
                        completed: 'bg-emerald-500',
                        overdue: 'bg-red-500',
                        near_due: 'bg-orange-500',
                        ASSIGNED: 'bg-slate-400',
                        ACCEPTED: 'bg-blue-500',
                        IN_PROGRESS: 'bg-teal-500',
                    }[this.statusKey(i)] || 'bg-slate-400';
                },

                // Shared precedence for a single Sub Task's "dominant" color, used inside
                // the Right Detail Panel's item cards (same as the old per-item cards).
                statusKey(i) {
                    if (i.assignment_status === 'COMPLETED') return 'completed';
                    if (i.is_overdue) return 'overdue';
                    if (i.is_near_due) return 'near_due';
                    return i.assignment_status; // ASSIGNED | ACCEPTED | IN_PROGRESS
                },

                // Pure assignment-status dot (bar indicator) - unlike dotClass() this
                // never lets near_due/overdue override it, so "accepted" vs "not yet
                // accepted" stays visible at a glance even on a task that's also due
                // soon (which already has its own red ring/badge for that separately).
                assignmentDotClass(i) {
                    return {
                        ASSIGNED: 'bg-slate-400',
                        ACCEPTED: 'bg-blue-500',
                        IN_PROGRESS: 'bg-teal-500',
                    }[i.assignment_status] || 'bg-slate-400';
                },

                badgeClass(i) {
                    return {
                        ASSIGNED: 'bg-slate-100 text-slate-600',
                        ACCEPTED: 'bg-blue-100 text-blue-700',
                        IN_PROGRESS: 'bg-teal-100 text-teal-700',
                        COMPLETED: 'bg-emerald-100 text-emerald-700',
                    }[i.assignment_status] || 'bg-slate-100 text-slate-600';
                },
                // Short status word for the compact Sub Task row's badge - the server's
                // own assignment_status_label (e.g. "กำลังดำเนินงาน", "เสร็จงานแล้ว") is
                // meant for the full-size detail view and is too long to fit a narrow
                // badge column without wrapping to 2 lines; this is display-only, the
                // underlying assignment_status/workflow is untouched.
                shortStatusLabel(i) {
                    return {
                        ASSIGNED: 'รอรับ',
                        ACCEPTED: 'รับแล้ว',
                        IN_PROGRESS: 'กำลังทำ',
                        COMPLETED: 'เสร็จ',
                    }[i.assignment_status] || i.assignment_status_label;
                },
                // Visual priority for the compact Sub Task row's name (point 10): a
                // COMPLETED Sub Task is still fully readable but de-emphasized (muted,
                // no bold) so the eye lands on what's still outstanding first; Overdue
                // stands out the most, In Progress a step above the Waiting/Accepted
                // default. No row ever gets a dark full-row background - color/weight
                // on the name text is the only emphasis signal.
                subtaskNameClass(i) {
                    if (i.assignment_status === 'COMPLETED') return 'text-slate-400 font-normal';
                    if (i.is_overdue) return 'text-slate-900 font-semibold';
                    if (i.assignment_status === 'IN_PROGRESS') return 'text-slate-800 font-medium';
                    return 'text-slate-700 font-medium';
                },
                // Priority flag colour for the calendar bar (Avatar Planning style). `normal` shows no
                // flag at all - same as "none" there - so only the priorities worth noticing add noise.
                priorityFlagClass(i) {
                    return { urgent: 'text-red-600', high: 'text-orange-500', low: 'text-sky-500' }[i.priority] || '';
                },
                priorityClass(i) {
                    return this.isHigh(i) ? 'bg-red-50 text-red-700 font-semibold' : 'bg-slate-100 text-slate-600';
                },

                // Whole calendar days spanned, inclusive of both ends (e.g. 28/09-02/10 = 5 วัน). Null if no date at all.
                durationDays(i) {
                    if (!i.start_date && !i.due_date) return null;
                    const s = parse(i.start_date || i.due_date), e = parse(i.due_date || i.start_date);
                    return Math.round((e - s) / 86400000) + 1;
                },

                // Full breakdown for a Project bar's hover tooltip / title.
                groupTitle(g) {
                    return g.project_no + ' · ' + g.project_name +
                        ' — ' + g.cabinet_count + ' Cabinets · ' + g.task_count + ' Tasks' +
                        ' — รอ ' + g.waiting + ' · รับงานแล้ว ' + g.accepted + ' · กำลังทำ ' + g.working + ' · เสร็จ ' + g.done +
                        (g.overdue ? ' · เกินกำหนด ' + g.overdue : '');
                },

                // ---- Right Detail Panel (Project -> Cabinet -> Sub Task drill-down) ----
                // Panel is a drill-down into a Project the user already picked, not a
                // query page (no Search/Filter/Sort - see Calendar/Timeline/List for
                // that). Every Cabinet always opens collapsed, even when there's only
                // one - a big Project can have many Cabinets, so the user should see a
                // clean overview first and decide for themselves what to open; expanded
                // state is never carried over between Projects either.
                openProjectPanel(group) {
                    this.panel = group;
                    this.panelExpanded = {};
                    this.colorPickerOpen = false;
                },
                closeProjectPanel() {
                    this.panel = null;
                    this.colorPickerOpen = false;
                },

                // Tags the open Project with a custom Calendar color (or null to go back
                // to the auto-assigned palette) - optimistic update across items/panel so
                // Calendar/Timeline/Legend/List repaint immediately, then persisted via
                // projects.color; reverted with a toast if the request fails.
                async setProjectColor(hex) {
                    if (!this.panel) return;
                    const projectId = this.panel.project_id;
                    const url = this.panel.project_color_url;
                    const prev = this.panel.color;
                    this.colorPickerOpen = false;
                    this.panel.color = hex;
                    for (const i of this.items) if (i.project_id === projectId) i.color = hex;
                    this.rebuild();
                    const fresh = this.projectGroupsList.find((g) => g.project_id === projectId);
                    if (fresh) this.panel = fresh;
                    try {
                        await api(url, 'PATCH', { color: hex });
                    } catch (err) {
                        this.panel.color = prev;
                        for (const i of this.items) if (i.project_id === projectId) i.color = prev;
                        this.rebuild();
                        const revert = this.projectGroupsList.find((g) => g.project_id === projectId);
                        if (revert) this.panel = revert;
                        window.showToast(err.message, 'error');
                    }
                },
                resetProjectColor() {
                    this.setProjectColor(null);
                },
                toggleCabinetExpand(cabinetId) {
                    this.panelExpanded[cabinetId] = !this.panelExpanded[cabinetId];
                },
                expandAllCabinets() {
                    for (const c of this.panelCabinets()) this.panelExpanded[c.cabinet_id] = true;
                },
                collapseAllCabinets() {
                    this.panelExpanded = {};
                },
                anyCabinetExpanded() {
                    return Object.values(this.panelExpanded).some(Boolean);
                },
                toggleExpandAllCabinets() {
                    if (this.anyCabinetExpanded()) this.collapseAllCabinets();
                    else this.expandAllCabinets();
                },
                // The open Project's Sub Tasks, grouped by Cabinet for the panel's
                // Cabinet rows (each expandable to reveal its Sub Tasks, grouped again
                // by Task - see groupByTask()).
                panelCabinets() {
                    if (!this.panel) return [];
                    const byCabinet = new Map();
                    for (const i of this.panel.items) {
                        if (!byCabinet.has(i.cabinet_id)) {
                            byCabinet.set(i.cabinet_id, { cabinet_id: i.cabinet_id, cabinet_mo: i.cabinet_mo, cabinet_name: i.cabinet_name, items: [] });
                        }
                        byCabinet.get(i.cabinet_id).items.push(i);
                    }
                    return [...byCabinet.values()]
                        .sort((a, b) => a.cabinet_mo.localeCompare(b.cabinet_mo))
                        .map((c) => {
                            const items = c.items.sort((a, b) => this.compare(a, b));
                            const dueDates = items.map((i) => i.due_date).filter(Boolean).sort();
                            return {
                                ...c,
                                items,
                                done: items.filter((i) => i.assignment_status === 'COMPLETED').length,
                                waiting: items.filter((i) => i.assignment_status === 'ASSIGNED').length,
                                accepted: items.filter((i) => i.assignment_status === 'ACCEPTED').length,
                                working: items.filter((i) => i.assignment_status === 'IN_PROGRESS').length,
                                overdue: items.filter((i) => i.is_overdue).length,
                                dueDate: dueDates[0] || null,
                                tasks: this.groupByTask(items),
                            };
                        });
                },

                // Cabinets sharing the exact same cabinet_name (often an equipment/
                // spec type on real data, e.g. "RMU Siemens 3 Function (LOCAL)"
                // repeated across many cabinets of the same kind) are grouped so
                // that name renders once as a section header instead of being
                // repeated on every row underneath it - pure presentation, no data
                // change. A "group" of exactly one Cabinet renders with no header at
                // all (point below), so Projects where every Cabinet already has a
                // distinct name look exactly as before.
                //
                // If every Cabinet in a group also shares the identical due date
                // (and none of them are fully done), the "เหลือ/เกินกำหนด" line is
                // likewise shown once on the group header instead of being repeated
                // identically on every row - this is the exact pattern that made an
                // 18-Cabinet list look like 18x the same two lines of text.
                cabinetGroups() {
                    const cabinets = this.panelCabinets();
                    const groups = [];
                    const bySpec = new Map();
                    for (const c of cabinets) {
                        const key = c.cabinet_name || '';
                        if (!bySpec.has(key)) {
                            const group = { spec: key, cabinets: [] };
                            bySpec.set(key, group);
                            groups.push(group);
                        }
                        bySpec.get(key).cabinets.push(c);
                    }
                    for (const g of groups) {
                        const allDone = g.cabinets.every((c) => c.done === c.items.length);
                        const dueDates = [...new Set(g.cabinets.map((c) => c.dueDate))];
                        g.sameDueInfo = (g.cabinets.length > 1 && !allDone && dueDates.length === 1)
                            ? this.remainingDaysInfo(dueDates[0], false)
                            : null;
                    }
                    return groups;
                },

                // What a Cabinet's Task groups actually render, once hideCompletedSubtasks
                // is applied - a COMPLETED Sub Task drops out of its Task's item list, and
                // a Task left with none drops out entirely (point 5), so e.g. an all-done
                // EQUIPMENT section disappears rather than showing "EQUIPMENT · 0". Purely
                // a display filter over c.tasks (itself built from the true, unfiltered
                // items in panelCabinets()) - Project/Cabinet summary counts always read
                // the unfiltered c.tasks/c.items, never this.
                visibleTasksFor(c) {
                    if (!this.hideCompletedSubtasks) return c.tasks;
                    return c.tasks
                        .map((t) => ({ ...t, items: t.items.filter((i) => i.assignment_status !== 'COMPLETED') }))
                        .filter((t) => t.items.length > 0);
                },

                // Sub Tasks of one Cabinet, grouped by their parent Task - the panel's
                // Task -> Sub Task display layer (point 5's hierarchy), purely a display
                // grouping over the same items, no new data.
                groupByTask(items) {
                    const map = new Map();
                    for (const i of items) {
                        if (!map.has(i.cabinet_task_id)) map.set(i.cabinet_task_id, { task_name: i.task_name, items: [] });
                        map.get(i.cabinet_task_id).items.push(i);
                    }
                    return [...map.values()];
                },

                // ---- Actions (existing accept/start/complete endpoints) ----
                async act(item, action) {
                    if (item.busy) return;
                    // Accepting locks the department immediately (same rule as the Cabinet page) - confirm before it's irreversible.
                    if (action === 'accept' && !confirm('ยืนยันรับงาน? หลังรับงานแล้วจะไม่สามารถเปลี่ยนแผนกได้')) return;
                    item.busy = true;
                    try {
                        const data = await api(item.urls[action], 'POST');
                        this.applyAssignment(item.id, data.assignment);
                        window.showToast(data.message);
                    } catch (err) {
                        window.showToast(err.message, 'error');
                    } finally {
                        item.busy = false;
                    }
                },

                applyAssignment(id, a) {
                    const patch = {
                        assignment_status: a.assignment_status,
                        assignment_status_label: a.assignment_status_label,
                        is_department_locked: a.is_department_locked,
                        can_accept: a.can_accept,
                        can_start: a.can_start,
                        can_complete: a.can_complete,
                    };
                    if (a.assignment_status === 'COMPLETED') {
                        patch.is_overdue = false;
                        patch.is_near_due = false;
                    }

                    const shared = this.items.find((i) => i.id === id);
                    if (shared) Object.assign(shared, patch);

                    if (this.detail && this.detail.id === id) {
                        Object.assign(this.detail, patch);
                        // Checklist ticking: only department members, and only between accept and complete.
                        this.detail.checklist_editable = ['ACCEPTED', 'IN_PROGRESS'].includes(a.assignment_status) && (a.can_start || a.can_complete);
                    }

                    // the project modal holds its own copies of the Sub Tasks - patch the one that changed, then re-read the totals
                    if (this.pm) {
                        this.pm.cabinets.forEach((c) => c.items.forEach((i) => { if (i.id === id) Object.assign(i, patch); }));
                        this.reloadProjectModal();
                    }

                    this.rebuild();

                    // A status transition changes its Project's aggregate counts (waiting/
                    // accepted/working/done) - if that Project's panel is open, point it at
                    // the freshly recomputed group so the counts shown don't go stale.
                    if (this.panel && shared) {
                        const fresh = this.projectGroupsList.find((g) => g.project_id === shared.project_id);
                        if (fresh) this.panel = fresh;
                    }
                },

                // Opens the Sub Task Right Side Work Detail Panel. Deliberately does not
                // touch `this.panel`/`this.dayPanelOpen` - whichever panel the user drilled
                // down from (Project Panel or Day Panel) stays open underneath, so closing
                // this one drops them right back into the same Cabinet/Task/Day they were
                // browsing instead of needing to reopen it from scratch. Stacks above both
                // (z-50 - see _detail-panel.blade.php) for that reason.
                async openDetail(item) {
                    this.detail = { ...item, busy: false, checklists: [] };
                    this.detailLoading = true;
                    this.detailPanelOpen = true;
                    try {
                        const data = await api(item.urls.detail);
                        // Sync back into the shared item so calendar/list/detail stay one object.
                        if (this.detail && this.detail.id === item.id) this.detail = { ...data.item, busy: false, checklists: data.item.checklists.map((c) => ({ ...c, busy: false })) };
                    } catch (err) {
                        window.showToast(err.message, 'error');
                    } finally {
                        this.detailLoading = false;
                    }
                },
                closeDetailPanel() {
                    this.detailPanelOpen = false;
                    if (this.pmOpen) this.reloadProjectModal(); // checklists may have been ticked meanwhile
                },

                async toggleChecklist(c, event) {
                    const want = event.target.checked;
                    c.busy = true;
                    try {
                        const data = await api(c.toggle_url, 'PATCH', { is_completed: want });
                        c.is_completed = data.checklist.is_completed;
                        c.completed_by_name = data.checklist.completed_by_name;
                        c.completed_at = data.checklist.completed_at_formatted;

                        const patch = {
                            progress: data.subtask.progress,
                            checklists_completed: data.subtask.completed_checklists,
                            checklists_total: data.subtask.total_checklists,
                        };
                        // An automation rule (Settings > Automation) may have started/closed the Sub Task as a result
                        // of this tick - the server sends the new workflow state only in that case.
                        if (data.assignment) {
                            Object.assign(patch, data.assignment, { is_overdue: data.assignment.assignment_status === 'COMPLETED' ? false : this.detail.is_overdue });
                            window.showToast('ระบบอัปเดตสถานะงานให้อัตโนมัติ: ' + data.assignment.assignment_status_label);
                        }
                        Object.assign(this.detail, patch);
                        const shared = this.items.find((i) => i.id === this.detail.id);
                        if (shared) Object.assign(shared, patch);
                    } catch (err) {
                        event.target.checked = !want;
                        window.showToast(err.message, 'error');
                    } finally {
                        c.busy = false;
                    }
                },
            };
        });
    });
</script>
