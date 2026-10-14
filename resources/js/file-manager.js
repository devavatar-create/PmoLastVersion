// The project file manager (x-file-manager): folders like on a computer. Click opens (a folder; a picture / PDF in the
// viewer; anything else downloads), the box or Ctrl / ⌘ / Shift-click selects, right-click gives the menu, F2 renames,
// Delete deletes, files or whole folders dragged in from the computer upload (with progress), items dragged onto a folder
// move. Every change goes to the server (App\Services\ProjectFiles), which checks who may.
export default (cfg) => ({
    urls: cfg.urls,
    isPmo: !!cfg.isPmo,
    tree: { folders: [], comments: 0, trash: 0, rootCount: 0, canAddRoot: false, locked: false, maxMb: 50, extensions: [], departments: [], myDepartment: null },
    where: cfg.start ? String(cfg.start) : '',   // folder id, '' = top level, 'comments', 'trash'
    list: { crumbs: [], items: [], canAdd: false, folder: null },
    open: {},                 // tree: expanded folder ids
    q: '',
    view: (() => { try { return localStorage.getItem('files.view') || 'grid'; } catch (e) { return 'grid'; } })(),
    selected: [],             // 'f12' (file) / 'd7' (folder)
    lastClicked: null,
    loading: false,
    queue: [],                // uploads: { name, pct, error, done }
    dragOver: false,
    dragItems: null,          // items being dragged inside the page (to move)
    menu: null,               // { x, y, item }
    viewer: null,             // { index } into previewables()
    versions: null,           // { file, rows }
    dialog: null,             // { kind: 'mkdir' | 'rename' | 'move', ... }

    async init() {
        await this.loadTree();
        if (this.where) this.expandTo(this.where);
        await this.load();
        this.$watch('q', () => { clearTimeout(this._qt); this._qt = setTimeout(() => this.load(), 300); });
    },

    // ---- reading ----
    async getJson(url) {
        const res = await fetch(url, { headers: { Accept: 'application/json' } });
        const data = await res.json().catch(() => ({}));
        if (!res.ok || data.ok === false) throw new Error(data.message || 'โหลดไม่สำเร็จ');
        return data;
    },
    async loadTree() { try { this.tree = await this.getJson(this.urls.tree); } catch (e) { window.showToast(e.message, 'error'); } },
    async load() {
        this.loading = true;
        try {
            const p = new URLSearchParams({ in: this.where });
            if (this.q.trim()) p.set('q', this.q.trim());
            this.list = await this.getJson(this.urls.index + '?' + p);
            this.selected = this.selected.filter((k) => this.list.items.some((i) => this.key(i) === k));
        } catch (e) { window.showToast(e.message, 'error'); } finally { this.loading = false; }
    },
    go(where) { this.where = where === null || where === undefined ? '' : String(where); this.q = ''; this.selected = []; this.menu = null; this.expandTo(this.where); this.load(); },
    async refresh() { await Promise.all([this.loadTree(), this.load()]); },
    expandTo(id) {
        const path = [], walk = (nodes, trail) => nodes.some((n) => (String(n.id) === String(id) ? (path.push(...trail), true) : walk(n.children || [], [...trail, n.id])));
        walk(this.tree.folders, []);
        path.forEach((p) => { this.open[p] = true; });
    },
    flatTree() {   // the tree as rows (only open branches), for the side panel and the "move to" dialog
        const out = [], walk = (nodes, depth) => nodes.forEach((n) => { out.push({ ...n, depth }); if (this.open[n.id] && n.children && n.children.length) walk(n.children, depth + 1); });
        walk(this.tree.folders, 0);
        return out;
    },
    allFolders() { const out = [], walk = (nodes, depth) => nodes.forEach((n) => { out.push({ ...n, depth }); walk(n.children || [], depth + 1); }); walk(this.tree.folders, 0); return out; },

    // ---- selection ----
    key(i) { return (i.type === 'folder' ? 'd' : i.type === 'comment' ? 'c' : 'f') + i.id; },
    isSel(i) { return this.selected.includes(this.key(i)); },
    toggle(i) { const k = this.key(i); this.selected = this.isSel(i) ? this.selected.filter((x) => x !== k) : [...this.selected, k]; this.lastClicked = k; },
    clickItem(ev, i) {
        if (ev.shiftKey && this.lastClicked) {   // a range, like a computer
            const keys = this.list.items.map((x) => this.key(x)), a = keys.indexOf(this.lastClicked), b = keys.indexOf(this.key(i));
            if (a >= 0 && b >= 0) this.selected = [...new Set([...this.selected, ...keys.slice(Math.min(a, b), Math.max(a, b) + 1)])];
            return;
        }
        if (ev.metaKey || ev.ctrlKey) { this.toggle(i); return; }
        this.openItem(i);
    },
    sel() { return this.list.items.filter((i) => this.isSel(i)); },
    selFiles() { return this.sel().filter((i) => i.type === 'file').map((i) => i.id); },
    selFolders() { return this.sel().filter((i) => i.type === 'folder').map((i) => i.id); },
    get inTrash() { return this.where === 'trash'; },
    get readOnly() { return this.tree.locked || this.where === 'comments' || !!this.q.trim(); },

    // ---- opening ----
    openItem(i) {
        this.menu = null;
        if (i.type === 'folder') { if (!i.trashed) this.go(i.id); return; }
        if (i.trashed) return;
        if (i.preview) { this.viewer = { index: this.previewables().findIndex((x) => this.key(x) === this.key(i)) }; return; }
        window.location.href = i.url;
    },
    previewables() { return this.list.items.filter((i) => i.preview && !i.trashed); },
    get viewing() { return this.viewer ? this.previewables()[this.viewer.index] : null; },
    step(n) { const len = this.previewables().length; if (this.viewer && len) this.viewer.index = (this.viewer.index + n + len) % len; },
    srcOf(i) { return i.type === 'comment' ? i.url : i.viewUrl; },

    // ---- changing (the server checks each) ----
    async op(body, { reload = true } = {}) {
        try {
            const res = await fetch(this.urls.ops, { method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content }, body: JSON.stringify(body) });
            const data = await res.json().catch(() => ({}));
            if (!res.ok || data.ok === false) throw new Error(data.message || 'ไม่สำเร็จ');
            if (data.message) window.showToast(data.message);
            if (reload) { this.selected = []; await this.refresh(); }
            return true;
        } catch (e) { window.showToast(e.message, 'error'); return false; }
    },
    remove(items) {
        const files = items.filter((i) => i.type === 'file').map((i) => i.id), folders = items.filter((i) => i.type === 'folder').map((i) => i.id);
        if (!files.length && !folders.length) return;
        if (!confirm('ย้าย ' + (files.length + folders.length) + ' รายการไปถังขยะ?' + (folders.length ? '\nไฟล์ทั้งหมดในโฟลเดอร์ไปด้วย' : '') + '\n(กู้คืนได้ภายใน 30 วัน)')) return;
        this.op({ type: 'delete', files, folders });
    },
    restore(items) { this.op({ type: 'restore', files: items.filter((i) => i.type === 'file').map((i) => i.id), folders: items.filter((i) => i.type === 'folder').map((i) => i.id) }); },
    purge(items) {
        if (!confirm('ลบถาวร ' + items.length + ' รายการ? กู้คืนไม่ได้อีก')) return;
        this.op({ type: 'purge', files: items.filter((i) => i.type === 'file').map((i) => i.id), folders: items.filter((i) => i.type === 'folder').map((i) => i.id) });
    },
    askRename(i) { this.menu = null; this.dialog = { kind: 'rename', item: i, name: i.name, ...this.whoSees(i) }; this.$nextTick(() => { const el = document.getElementById('fm-name'); if (el) { el.focus(); el.setSelectionRange(0, i.type === 'file' && i.name.includes('.') ? i.name.lastIndexOf('.') : i.name.length); } }); },
    askMkdir() { this.dialog = { kind: 'mkdir', name: '', who: 'all', depts: [] }; this.$nextTick(() => document.getElementById('fm-name') && document.getElementById('fm-name').focus()); },
    // ---- who sees a folder: 'all' / 'departments' (+ Admin / PM) / 'pmo'; the server has the last word ----
    whoSees(f) { const depts = (f.departments || []).map(Number); return { who: f.restricted ? 'pmo' : (depts.length ? 'departments' : 'all'), depts }; },
    keepMyDepartment() { const mine = this.tree.myDepartment; if (!this.isPmo && mine && !this.dialog.depts.includes(mine)) this.dialog.depts.push(mine); },
    deptNames(ids) { return ids.map((id) => (this.tree.departments.find((d) => d.id === Number(id)) || { name: '#' + id }).name).join(', '); },
    askMove(items) { this.menu = null; this.dialog = { kind: 'move', items, to: '' }; },
    async runDialog() {
        const d = this.dialog;
        if (!d) return;
        let ok = false;
        if (d.kind === 'mkdir') ok = await this.op({ type: 'mkdir', parent: this.where && /^\d+$/.test(this.where) ? Number(this.where) : null, name: d.name, visibility: d.who, departments: d.depts });
        if (d.kind === 'rename') ok = await this.op({ type: 'rename', kind: d.item.type, id: d.item.id, name: d.name, ...(d.item.type === 'folder' ? { visibility: d.who, departments: d.depts } : {}) });
        if (d.kind === 'move') ok = await this.op({ type: 'move', to: d.to === '' ? null : Number(d.to), files: d.items.filter((i) => i.type === 'file').map((i) => i.id), folders: d.items.filter((i) => i.type === 'folder').map((i) => i.id) });
        if (ok) this.dialog = null;
    },
    async showVersions(i) {
        this.menu = null;
        try { this.versions = { file: i, rows: (await this.getJson(this.urls.versions.replace('__ID__', i.id))).versions }; } catch (e) { window.showToast(e.message, 'error'); }
    },
    async useVersion(v) { if (await this.op({ type: 'restoreVersion', id: v.id })) this.versions = null; },
    downloadSelected() {
        const files = this.selFiles(), folders = this.selFolders();
        if (files.length === 1 && !folders.length) { window.location.href = this.sel()[0].url; return; }
        const p = new URLSearchParams(); files.forEach((id) => p.append('files[]', id)); folders.forEach((id) => p.append('folders[]', id));
        window.location.href = this.urls.zip + '?' + p;
    },
    downloadFolder(id) { window.location.href = this.urls.zip + '?folders[]=' + id; },

    // ---- uploading: one request per file (with its own progress), then one line for the history ----
    canAddHere() { return !this.readOnly && !this.inTrash && this.list.canAdd; },
    pick(dir) { const el = dir ? this.$refs.dirInput : this.$refs.fileInput; el.value = ''; el.click(); },
    fromInput(ev) { this.upload([...ev.target.files].map((f) => ({ file: f, path: f.webkitRelativePath || '' })), this.currentFolderId()); },
    currentFolderId() { return /^\d+$/.test(this.where) ? Number(this.where) : null; },
    async onDrop(ev, folderId) {
        this.dragOver = false;
        if (this.dragItems) {   // items from this page, dropped on a folder: move
            const items = this.dragItems; this.dragItems = null;
            if (folderId !== undefined) this.op({ type: 'move', to: folderId, files: items.filter((i) => i.type === 'file').map((i) => i.id), folders: items.filter((i) => i.type === 'folder').map((i) => i.id) });
            return;
        }
        const entries = [...(ev.dataTransfer.items || [])].map((it) => it.webkitGetAsEntry && it.webkitGetAsEntry()).filter(Boolean);
        const files = entries.length ? (await Promise.all(entries.map((e) => this.readEntry(e, '')))).flat() : [...ev.dataTransfer.files].map((f) => ({ file: f, path: '' }));
        if (files.length) this.upload(files, folderId !== undefined ? folderId : this.currentFolderId());
    },
    readEntry(entry, prefix) {   // a folder dragged in: every file in it, with its path
        return new Promise((resolve) => {
            if (entry.isFile) { entry.file((f) => resolve([{ file: f, path: prefix ? prefix + '/' + f.name : '' }]), () => resolve([])); return; }
            const reader = entry.createReader(), all = [];
            const next = () => reader.readEntries(async (batch) => {
                if (!batch.length) { resolve((await Promise.all(all.map((e) => this.readEntry(e, (prefix ? prefix + '/' : '') + entry.name)))).flat()); return; }
                all.push(...batch); next();
            }, () => resolve([]));
            next();
        });
    },
    async upload(files, folderId) {
        const max = this.tree.maxMb * 1048576, ids = [];
        const jobs = files.map(({ file, path }) => {
            const job = { name: (path || file.name), pct: 0, error: '', done: false };
            const ext = (file.name.split('.').pop() || '').toLowerCase();
            if (file.size > max) job.error = 'ใหญ่เกิน ' + this.tree.maxMb + ' MB';
            else if (!this.tree.extensions.includes(ext)) job.error = 'ไม่รับไฟล์ .' + ext;
            return { job, file, path };
        });
        this.queue = this.queue.filter((j) => !j.done && !j.error).concat(jobs.map((j) => j.job));
        for (const { job, file, path } of jobs) {
            if (job.error) continue;
            try {
                const item = await this.send(file, path, folderId, (p) => { job.pct = p; });
                ids.push(item.id); job.done = true; job.pct = 100;
            } catch (e) { job.error = e.message; }
        }
        if (ids.length) { await this.op({ type: 'log', ids }, { reload: false }); await this.refresh(); window.showToast('อัปโหลดแล้ว ' + ids.length + ' ไฟล์'); }
        setTimeout(() => { this.queue = this.queue.filter((j) => !j.done); }, 3000);
    },
    send(file, path, folderId, progress) {
        return new Promise((resolve, reject) => {
            const fd = new FormData();
            fd.append('file', file); if (path) fd.append('path', path); if (folderId) fd.append('folder', folderId);
            const xhr = new XMLHttpRequest();
            xhr.open('POST', this.urls.upload);
            xhr.setRequestHeader('Accept', 'application/json');
            xhr.setRequestHeader('X-CSRF-TOKEN', document.querySelector('meta[name=csrf-token]').content);
            xhr.upload.onprogress = (e) => { if (e.lengthComputable) progress(Math.round(e.loaded / e.total * 100)); };
            xhr.onload = () => {
                let data = {}; try { data = JSON.parse(xhr.responseText); } catch (e) { /* not JSON: too big for the server, usually */ }
                if (xhr.status >= 200 && xhr.status < 300 && data.ok) resolve(data.item);
                else reject(new Error(data.message || (data.errors && Object.values(data.errors).flat()[0]) || (xhr.status === 413 ? 'ไฟล์ใหญ่เกินที่เซิร์ฟเวอร์รับ' : 'อัปโหลดไม่สำเร็จ (' + xhr.status + ')')));
            };
            xhr.onerror = () => reject(new Error('การเชื่อมต่อขาด'));
            xhr.send(fd);
        });
    },

    // ---- keys / menus / look ----
    onKey(ev) {
        if (this.dialog || this.viewer || this.versions || /input|textarea|select/i.test(ev.target.tagName)) return;
        const items = this.sel();
        if (ev.key === 'Escape') { this.selected = []; this.menu = null; }
        if (!items.length || this.readOnly) return;
        if (ev.key === 'Delete' || ev.key === 'Backspace') { ev.preventDefault(); this.inTrash ? null : this.remove(items.filter((i) => i.canEdit)); }
        if (ev.key === 'F2' && items.length === 1 && items[0].canEdit && !this.inTrash) { ev.preventDefault(); this.askRename(items[0]); }
        if (ev.key === 'Enter' && items.length === 1) this.openItem(items[0]);
    },
    context(ev, i) {
        ev.preventDefault();
        if (!this.isSel(i)) { this.selected = [this.key(i)]; this.lastClicked = this.key(i); }
        this.menu = { x: Math.min(ev.clientX, window.innerWidth - 220), y: Math.min(ev.clientY, window.innerHeight - 260), item: i };
    },
    setView(v) { this.view = v; try { localStorage.setItem('files.view', v); } catch (e) { /* not remembered */ } },
    size(b) { if (!b && b !== 0) return ''; if (b >= 1048576) return (b / 1048576).toFixed(1) + ' MB'; if (b >= 1024) return Math.round(b / 1024) + ' KB'; return b + ' B'; },
    icon(i) {
        if (i.type === 'folder') return { t: '📁', c: 'text-amber-500' };
        const e = i.ext || '';
        if (e === 'pdf') return { t: 'PDF', c: 'bg-red-100 text-red-700' };
        if (['dwg', 'dxf', 'dwf', 'step', 'stp', 'igs', 'iges'].includes(e)) return { t: e.toUpperCase(), c: 'bg-violet-100 text-violet-700' };
        if (['xls', 'xlsx', 'xlsm', 'csv'].includes(e)) return { t: 'XLS', c: 'bg-emerald-100 text-emerald-700' };
        if (['doc', 'docx', 'rtf', 'txt'].includes(e)) return { t: 'DOC', c: 'bg-blue-100 text-blue-700' };
        if (['ppt', 'pptx'].includes(e)) return { t: 'PPT', c: 'bg-orange-100 text-orange-700' };
        if (['zip', 'rar', '7z'].includes(e)) return { t: 'ZIP', c: 'bg-slate-200 text-slate-700' };
        if (['mp4', 'mov'].includes(e)) return { t: '▶', c: 'bg-slate-800 text-white' };
        return { t: (e || 'ไฟล์').toUpperCase().slice(0, 4), c: 'bg-slate-100 text-slate-600' };
    },
    isImage(i) { return ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'].includes(i.ext); },
});
