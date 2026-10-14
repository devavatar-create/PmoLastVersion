// The one checklist editor (x-checklist-editor): a cabinet's Sub Task list or a template's. Edit in place (click the text),
// add many at once (type, or paste rows from Excel: name | remark | date), select several to delete / move / copy / date,
// drag or Alt+↑↓ to reorder, tick (cabinet lists), and "ยกเลิก" right after a change. Every change goes to
// POST /checklist-editor/{kind}/{id} (ChecklistEditor on the server checks who may and answers with the list as it now is).
export default (cfg) => ({
    kind: cfg.kind,
    url: cfg.url,
    items: cfg.items || [],
    editable: !!cfg.editable,
    dateLabel: cfg.dateLabel || null,
    linked: cfg.linked || [],     // Settings > การส่งต่องาน: the departments passing work to this one (read only)
    today: (() => { const d = new Date(); return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0'); })(),
    busy: false,
    selected: [],
    editing: null,          // { id, field, value }
    draft: '',
    paste: null,            // rows waiting for "เพิ่ม n รายการ" after a paste
    undo: null,             // { op, text, timer }
    targets: null,          // { move, copyTo, copyFrom } - fetched the first time a dialog needs them
    dialog: null,           // 'move' | 'copyTo' | 'copyFrom'
    dialogPick: [],
    bulkDate: '',
    dragId: null,

    get doneCount() { return this.items.filter((i) => i.done).length; },
    get allSelected() { return this.items.length > 0 && this.selected.length === this.items.length; },
    isSelected(id) { return this.selected.includes(id); },
    toggleSelect(id) { this.selected = this.isSelected(id) ? this.selected.filter((x) => x !== id) : [...this.selected, id]; },
    toggleAll() { this.selected = this.allSelected ? [] : this.items.map((i) => i.id); },
    fmtDate(d) { if (!d) return ''; const [y, m, day] = d.split('-'); return day + '/' + m + '/' + (Number(y) + 543); },

    // ---- talking to the server ----
    async send(op, { quiet = false, offerUndo = true } = {}) {
        if (this.busy) return null;
        this.busy = true;
        try {
            const res = await fetch(this.url, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content },
                body: JSON.stringify(op),
            });
            const data = await res.json().catch(() => ({}));
            if (!res.ok || !data.ok) throw new Error(data.message || 'บันทึกไม่สำเร็จ ลองใหม่อีกครั้ง');
            this.items = data.items;
            this.selected = this.selected.filter((id) => this.items.some((i) => i.id === id));
            if (data.progress) window.dispatchEvent(new CustomEvent('checklist-progress', { detail: data.progress }));
            if (offerUndo && data.undo) this.offerUndo(data.undo, data.message || 'บันทึกแล้ว');
            else if (!quiet && data.message) window.showToast(data.message);
            return data;
        } catch (e) {
            window.showToast(e.message, 'error');
            return null;
        } finally {
            this.busy = false;
        }
    },
    offerUndo(op, text) {
        if (this.undo) clearTimeout(this.undo.timer);
        this.undo = { op, text, timer: setTimeout(() => { this.undo = null; }, 8000) };
    },
    async doUndo() {
        const u = this.undo;
        if (!u) return;
        clearTimeout(u.timer);
        this.undo = null;
        await this.send(u.op, { offerUndo: false });
    },

    // ---- edit in place ----
    startEdit(item, field) {
        if (!this.editable) return;
        this.editing = { id: item.id, field, value: field === 'date' ? (item.date || '') : (item[field] || '') };
        this.$nextTick(() => { const el = this.$root.querySelector('[data-edit-input]'); if (el) { el.focus(); if (el.select) el.select(); } });
    },
    async commitEdit(next) {
        const e = this.editing;
        if (!e) return;
        this.editing = null;
        const item = this.items.find((i) => i.id === e.id);
        const value = e.field === 'date' ? e.value : e.value.trim();
        if (item && value !== (e.field === 'date' ? (item.date || '') : (item[e.field] || ''))) {
            if (e.field === 'name' && !value) { window.showToast('ชื่อรายการต้องไม่ว่าง', 'error'); return; }
            await this.send({ type: 'update', id: e.id, [e.field]: value }, { quiet: true });
        }
        if (next === 'down') {   // Enter: on to the next row's name (or the "add" box after the last row)
            const i = this.items.findIndex((x) => x.id === e.id);
            if (this.items[i + 1]) this.startEdit(this.items[i + 1], e.field);
            else this.$nextTick(() => this.$refs.draft && this.$refs.draft.focus());
        } else if (next === 'right' && e.field === 'name') {
            this.startEdit(this.items.find((x) => x.id === e.id), 'remark');
        }
    },
    cancelEdit() { this.editing = null; },

    // ---- add: one typed line, or many pasted rows ----
    async addDraft() {
        const text = this.draft.trim();
        if (!text) return;
        if (/[\n\t]/.test(text)) { this.paste = this.parsePaste(text); return; }
        this.draft = '';
        await this.send({ type: 'add', items: [{ name: text }] }, { quiet: true, offerUndo: false });
        this.$nextTick(() => this.$refs.draft && this.$refs.draft.focus());
    },
    onPaste(ev) {
        const text = (ev.clipboardData || window.clipboardData).getData('text');
        if (!/[\n\t]/.test(text.trim())) return;   // a plain word: let it paste normally
        ev.preventDefault();
        this.paste = this.parsePaste(text);
    },
    // Excel / Sheets: a row per line, cells split by tabs. Each column is given a use the person can change in the preview:
    // the first = ชื่อ, the rest = "รวมกับชื่อ" (e.g. a BOM's qty / unit stay in the name, as typed today); a column of dates
    // becomes the date when this list has one.
    parsePaste(text) {
        const cells = text.replace(/\r/g, '').split('\n').map((l) => l.split('\t').map((c) => c.trim())).filter((r) => r.some((c) => c));
        const width = Math.max(...cells.map((r) => r.length));
        const isDate = (v) => /^\d{1,2}\/\d{1,2}\/\d{4}$|^\d{4}-\d{2}-\d{2}$/.test(v);
        const map = Array.from({ length: width }, (_, i) => {
            if (i === 0) return 'name';
            const col = cells.map((r) => r[i] || '').filter(Boolean);
            return this.dateLabel && col.length && col.every(isDate) ? 'date' : 'join';
        });
        return { cells, map };
    },
    pasteRows() {
        const p = this.paste;
        if (!p) return [];
        return p.cells.map((r) => {
            const pick = (use) => p.map.map((m, i) => (m === use ? r[i] || '' : '')).filter(Boolean);
            const name = p.map.map((m, i) => (m === 'name' || m === 'join' ? r[i] || '' : '')).filter(Boolean).join(' ');
            return { name, remark: pick('remark').join(' '), date: pick('date')[0] || '' };
        }).filter((r) => r.name);
    },
    async addPasted() {
        const rows = this.pasteRows();
        this.paste = null;
        this.draft = '';
        if (rows.length) await this.send({ type: 'add', items: rows });
    },

    // ---- one or many at once ----
    async remove(ids) {
        if (!ids.length) return;
        await this.send({ type: 'delete', ids });   // no "are you sure": the bar below offers ยกเลิก
        this.selected = [];
    },
    async setDate(ids, date) { for (const id of ids) await this.send({ type: 'update', id, date }, { quiet: true, offerUndo: false }); this.bulkDate = ''; },
    async openDialog(name) {
        this.dialogPick = [];
        if (!this.targets) {
            try { this.targets = await (await fetch(this.url + '?targets=1', { headers: { 'Accept': 'application/json' } })).json(); }
            catch (e) { window.showToast('โหลดรายการปลายทางไม่สำเร็จ', 'error'); return; }
        }
        this.dialog = name;
    },
    async runDialog() {
        const name = this.dialog, pick = this.dialogPick;
        if (!pick.length && name !== 'copyFrom') return;
        this.dialog = null;
        if (name === 'move') await this.send({ type: 'move', ids: this.selected, to: pick[0] });
        if (name === 'copyTo') await this.send({ type: 'copy', ids: this.selected, to: pick }, { offerUndo: false });
        if (name === 'copyFrom' && pick.length) await this.send({ type: 'copyFrom', from: pick[0] });
        this.selected = [];
    },

    // ---- order: drag a row, or Alt+↑ / Alt+↓ ----
    async moveTo(fromId, toIndex) {
        const ids = this.items.map((i) => i.id), from = ids.indexOf(fromId);
        if (from < 0 || toIndex < 0 || toIndex >= ids.length || from === toIndex) return;
        ids.splice(toIndex, 0, ids.splice(from, 1)[0]);
        this.items = ids.map((id) => this.items.find((i) => i.id === id));   // at once; the answer confirms it
        await this.send({ type: 'reorder', ids }, { quiet: true, offerUndo: false });
    },
    nudge(item, step) { this.moveTo(item.id, this.items.indexOf(item) + step); },
    dragStart(ev, item) { if (!this.editable) return; this.dragId = item.id; ev.dataTransfer.effectAllowed = 'move'; ev.dataTransfer.setData('text/plain', String(item.id)); },
    drop(item) { const id = this.dragId; this.dragId = null; if (id && id !== item.id) this.moveTo(id, this.items.indexOf(item)); },

    // ---- tick (a cabinet's list) ----
    async tick(item, done) {
        item.done = done;
        try {
            const res = await fetch(item.toggleUrl, {
                method: 'PATCH',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content },
                body: JSON.stringify({ is_completed: done }),
            });
            const data = await res.json().catch(() => ({}));
            if (!res.ok) throw new Error(data.message || 'บันทึกไม่สำเร็จ');
            item.doneBy = data.checklist.completed_by_name; item.doneAt = data.checklist.completed_at_formatted;
            window.dispatchEvent(new CustomEvent('checklist-progress', { detail: data }));
        } catch (e) {
            item.done = !done;
            window.showToast(e.message, 'error');
        }
    },
});
