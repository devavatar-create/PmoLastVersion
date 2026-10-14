{{--
    "แก้ Checklist ทั้งโครงการ" (Admin / PM): the same change in the same Sub Task of every cabinet - add / rename / delete -
    always previewed first. App\Services\ChecklistBulk does the work (and the checks).
--}}
<x-modal name="checklist-bulk" maxWidth="2xl">
    <div class="p-6" x-data="{
            url: @js(route('projects.checklist-bulk.run', $project)), optionsUrl: @js(route('projects.checklist-bulk.options', $project)),
            subtasks: [], subtask: '', action: 'add', names: '', find: '', replace: '', contains: false, picked: [],
            preview: null, busy: false,
            async load() { try { this.subtasks = (await (await fetch(this.optionsUrl, { headers: { Accept: 'application/json' } })).json()).subtasks; } catch (e) { window.showToast('โหลดรายการไม่สำเร็จ', 'error'); } },
            get current() { return this.subtasks.find((s) => s.name === this.subtask) || null; },
            body() { return { action: this.action, subtask: this.subtask, names: this.action === 'delete' ? this.picked : this.names, find: this.find, replace: this.replace, contains: this.contains }; },
            async go(previewOnly) {
                if (this.busy) return;
                this.busy = true;
                try {
                    const res = await fetch(this.url, { method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content }, body: JSON.stringify({ ...this.body(), preview: previewOnly }) });
                    const data = await res.json().catch(() => ({}));
                    if (!res.ok || !data.ok) throw new Error(data.message || 'ไม่สำเร็จ');
                    if (previewOnly) { this.preview = data; return; }
                    window.showToast(data.message);
                    this.$dispatch('close-modal', 'checklist-bulk');
                    setTimeout(() => location.reload(), 600);
                } catch (e) { window.showToast(e.message, 'error'); } finally { this.busy = false; }
            },
         }" x-init="$watch('subtask', () => { preview = null; picked = []; }); $watch('action', () => preview = null)"
         @open-modal.window="$event.detail === 'checklist-bulk' && !subtasks.length && load()">
        <h3 class="text-base font-semibold text-slate-900">แก้ Checklist ทั้งโครงการ</h3>
        <p class="mb-4 text-xs text-slate-500">ทำการเปลี่ยนแปลงเดียวกันกับ Sub Task เดียวกันในทุกตู้ของ {{ $project->project_no }} — ดูตัวอย่างก่อนเสมอ</p>

        <div class="space-y-3 text-sm">
            <label class="block"><span class="text-xs font-semibold text-slate-600">Sub Task</span>
                <select x-model="subtask" class="mt-1 block w-full rounded-lg border-slate-300 text-sm">
                    <option value="">— เลือก Sub Task —</option>
                    <template x-for="s in subtasks" :key="s.name"><option :value="s.name" x-text="s.name + ' (' + s.cabinets + ' ตู้)'"></option></template>
                </select></label>

            <div class="flex flex-wrap gap-2" role="radiogroup" aria-label="สิ่งที่จะทำ">
                <template x-for="a in [['add', '＋ เพิ่มรายการ'], ['rename', '✎ เปลี่ยนชื่อ'], ['delete', '✕ ลบรายการ']]" :key="a[0]">
                    <button type="button" @click="action = a[0]" :aria-pressed="action === a[0]" class="rounded-lg border px-3 py-1.5 text-xs font-medium"
                            :class="action === a[0] ? 'border-blue-600 bg-blue-600 text-white' : 'border-slate-300 text-slate-600 hover:bg-slate-50'" x-text="a[1]"></button>
                </template>
            </div>

            <label x-show="action === 'add'" class="block"><span class="text-xs font-semibold text-slate-600">รายการที่จะเพิ่ม (บรรทัดละ 1 รายการ · วางจาก Excel ได้)</span>
                <textarea x-model="names" rows="4" class="mt-1 block w-full rounded-lg border-slate-300 text-sm" placeholder="ตรวจแรงบิดน็อต&#10;Hi-pot test"></textarea>
                <span class="text-[11px] text-slate-400">ตู้ที่มีรายการนี้แล้วจะข้าม · Sub Task ที่เสร็จแล้วจะข้าม (ไม่เปิดงานกลับ)</span></label>

            <div x-show="action === 'rename'" class="grid gap-2 sm:grid-cols-2">
                <label><span class="text-xs font-semibold text-slate-600" x-text="contains ? 'ข้อความที่จะหา (ในชื่อ)' : 'ชื่อเดิม'"></span>
                    <input x-model="find" list="ck-bulk-items" class="mt-1 block w-full rounded-lg border-slate-300 text-sm"></label>
                <label><span class="text-xs font-semibold text-slate-600" x-text="contains ? 'แทนที่ด้วย' : 'ชื่อใหม่'"></span>
                    <input x-model="replace" class="mt-1 block w-full rounded-lg border-slate-300 text-sm"></label>
                <label class="flex items-center gap-2 text-xs text-slate-600 sm:col-span-2"><input type="checkbox" x-model="contains" class="rounded border-slate-300"> หาแค่บางส่วนของชื่อ (เช่น แก้คำที่สะกดผิด)</label>
                <datalist id="ck-bulk-items"><template x-for="n in (current ? current.items : [])" :key="n"><option :value="n"></option></template></datalist>
            </div>

            <div x-show="action === 'delete'">
                <p class="text-xs font-semibold text-slate-600">เลือกรายการที่จะลบจากทุกตู้ <span class="font-normal text-slate-400">(ตู้ที่ติ๊กรายการนั้นแล้วจะเก็บไว้)</span></p>
                <div class="mt-1 max-h-48 overflow-y-auto rounded-lg border border-slate-200 p-1">
                    <template x-for="n in (current ? current.items : [])" :key="n">
                        <label class="flex items-center gap-2 rounded px-2 py-0.5 text-xs hover:bg-slate-50">
                            <input type="checkbox" :value="n" x-model="picked" class="rounded border-slate-300"><span x-text="n"></span></label>
                    </template>
                    <p x-show="!current" class="px-2 py-2 text-xs text-slate-400">เลือก Sub Task ก่อน</p>
                </div>
            </div>
        </div>

        <template x-if="preview">
            <div class="mt-4 rounded-lg border border-amber-200 bg-amber-50 p-3 text-xs">
                <p class="font-semibold text-amber-900" x-text="preview.message"></p>
                <ul class="mt-1 max-h-40 space-y-0.5 overflow-y-auto text-slate-700">
                    <template x-for="(r, i) in preview.rows" :key="i"><li><span class="font-medium" x-text="r.mo"></span> · <span x-text="r.change"></span></li></template>
                </ul>
            </div>
        </template>

        <div class="mt-5 flex justify-end gap-2">
            <button type="button" @click="$dispatch('close-modal', 'checklist-bulk')" class="rounded-lg px-4 py-2 text-sm text-slate-600 hover:bg-slate-100">ยกเลิก</button>
            <button type="button" x-show="!preview" @click="go(true)" :disabled="!subtask || busy" class="rounded-lg border border-blue-600 px-4 py-2 text-sm font-semibold text-blue-600 hover:bg-blue-50 disabled:opacity-50">ดูตัวอย่าง</button>
            <button type="button" x-show="preview" @click="go(false)" :disabled="busy || !preview || !preview.changes" class="rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-700 disabled:opacity-50" x-text="'ยืนยัน ' + (preview ? preview.changes : '') + ' รายการ'"></button>
        </div>
    </div>
</x-modal>
