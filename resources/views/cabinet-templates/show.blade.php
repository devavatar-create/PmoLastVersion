<x-app-layout>
    <x-slot name="header">
        <div class="flex min-w-0 items-center gap-2">
            <x-back-link :href="route('cabinet-templates.index')" label="เทมเพลตทั้งหมด" />
            <h2 class="text-lg font-semibold text-slate-900">{{ $template->name }}</h2>
            @if ($template->is_default)
                <span class="text-[10px] uppercase tracking-wide bg-blue-100 text-blue-700 px-1.5 py-0.5 rounded font-semibold">Default</span>
            @endif
        </div>
    </x-slot>

    <div class="max-w-none mx-auto space-y-6">
        <x-settings-nav />
        <x-card>
            @php
                $subs = $template->taskTemplates->flatMap->subtaskTemplates;
                $usedBy = $template->cabinets()->withTrashed()->count();
            @endphp
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div class="min-w-0">
                    <div class="flex flex-wrap items-center gap-2 text-sm text-slate-500">
                        <span>รหัส <span class="font-medium text-slate-700">{{ $template->code }}</span></span>
                        @unless ($template->is_active)<span class="rounded bg-slate-100 px-1.5 py-0.5 text-[10px] font-semibold text-slate-500" title="ไม่แสดงให้เลือกตอนสร้างตู้">ปิดใช้งาน</span>@endunless
                    </div>
                    @if ($template->description)
                        <p class="text-sm text-slate-600 mt-1">{{ $template->description }}</p>
                    @endif
                    {{-- the size of it, and how much it is used (as the page was loaded) --}}
                    <div class="mt-3 flex flex-wrap gap-2 text-xs">
                        @foreach ([['Task', $template->taskTemplates->count()], ['Sub Task', $subs->count()], ['Checklist', $subs->sum(fn ($s) => $s->checklistTemplates->count())], ['ใช้กับตู้', $usedBy]] as [$label, $n])
                            <span class="rounded-lg bg-slate-50 px-2.5 py-1 text-slate-500 ring-1 ring-slate-200"><b class="tabular-nums text-slate-900">{{ number_format($n) }}</b> {{ $label }}</span>
                        @endforeach
                    </div>
                </div>
                @edittemplates
                <div class="flex shrink-0 flex-wrap justify-end gap-2">
                    <button type="button" x-data @click="$dispatch('open-modal', 'template-sync')" class="px-3 py-2 rounded-lg bg-blue-50 text-sm font-medium text-blue-700 hover:bg-blue-100"
                            title="เพิ่มรายการที่ตู้เดิม (ที่สร้างจากเทมเพลตนี้) ยังไม่มี">ส่งรายการใหม่ไปตู้เดิม</button>
                    <a href="{{ route('cabinet-templates.export', $template) }}" class="px-3 py-2 rounded-lg bg-slate-100 text-sm font-medium text-slate-700 hover:bg-slate-200" title="Task | Sub Task | แผนก | Checklist | หมายเหตุ">Export Excel</a>
                    <form method="POST" action="{{ route('cabinet-templates.duplicate', $template) }}" onsubmit="return confirm('ทำสำเนาเทมเพลตนี้ทั้งหมด?')">@csrf
                        <button class="px-3 py-2 rounded-lg bg-slate-100 text-sm font-medium text-slate-700 hover:bg-slate-200">ทำสำเนา</button></form>
                    <a href="{{ route('cabinet-templates.edit', $template) }}" class="px-3 py-2 rounded-lg bg-slate-100 text-sm font-medium text-slate-700 hover:bg-slate-200" title="ชื่อ รหัส คำอธิบาย ค่าเริ่มต้น เปิด/ปิดใช้งาน">แก้ไขข้อมูล</a>
                    <form method="POST" action="{{ route('cabinet-templates.destroy', $template) }}"
                          onsubmit="return confirm(@js('ลบเทมเพลต “'.$template->name.'” ทั้งหมด (Task / Sub Task / Checklist)?'.($usedBy ? "\n\nตู้ {$usedBy} ตู้ที่สร้างจากเทมเพลตนี้ยังอยู่ครบ (เป็นสำเนาแยกกัน) แต่จะส่งรายการใหม่จากเทมเพลตไปให้ไม่ได้อีก" : '').($template->is_default ? "\n\nเทมเพลตนี้เป็นค่าเริ่มต้น (Default) — ตู้ใหม่จะไม่มีเทมเพลตเริ่มต้นจนกว่าจะตั้งอันอื่น" : '')."\n\nกู้คืนไม่ได้"))">
                        @csrf @method('DELETE')
                        <button class="px-3 py-2 rounded-lg bg-red-50 text-sm font-medium text-red-700 hover:bg-red-100">ลบเทมเพลต</button>
                    </form>
                </div>
                @endedittemplates
            </div>
        </x-card>

        <x-card title="โครงสร้างงาน" subtitle="Task → Sub Task (แผนก) → Checklist — ลาก ⋮⋮ เพื่อจัดลำดับ · กดชื่อ Task เพื่อยุบ / ขยาย">
            <div class="-mt-2 mb-3 flex justify-end gap-1 text-xs">
                <button type="button" x-data @click="$dispatch('tpl-fold', true)" class="rounded-md px-2 py-1 text-slate-500 hover:bg-slate-100">ยุบทั้งหมด</button>
                <button type="button" x-data @click="$dispatch('tpl-fold', false)" class="rounded-md px-2 py-1 text-slate-500 hover:bg-slate-100">ขยายทั้งหมด</button>
            </div>
            <div class="space-y-3" data-task-list data-reorder-url="{{ route('task-templates.reorder') }}">
                @foreach ($template->taskTemplates as $taskTemplate)
                    @include('cabinet-templates.partials._task', ['taskTemplate' => $taskTemplate, 'departments' => $departments])
                @endforeach
            </div>

            @edittemplates
            <div class="mt-4 pt-4 border-t border-slate-100" x-data="{ addTask: false }">
                <button type="button" @click="addTask = !addTask" class="rounded-lg border border-dashed border-slate-300 px-3 py-1.5 text-sm font-medium text-blue-600 hover:bg-blue-50">+ เพิ่ม Task</button>
                <div x-show="addTask" x-cloak class="mt-3">
                    <form class="task-template-add-form" method="POST" action="{{ route('task-templates.store', $template) }}">
                        @csrf
                        <div class="flex items-end gap-2">
                            <div class="flex-1">
                                <x-input-label value="ชื่อ Task" class="text-xs" />
                                <x-text-input name="name" class="mt-1 block w-full text-sm" placeholder="เช่น QC / แพ็กสินค้า" required />
                            </div>
                            <button class="px-3 py-2 rounded-lg bg-blue-600 text-white text-sm font-semibold hover:bg-blue-700">เพิ่ม</button>
                        </div>
                    </form>
                </div>
            </div>
            @endedittemplates
        </x-card>
    </div>

    @edittemplates
    {{-- "ส่งรายการใหม่ไปตู้เดิม": preview per cabinet, then add (App\Services\TemplateTools) --}}
    <x-modal name="template-sync" maxWidth="2xl">
        <div class="p-6" x-data="{
                url: @js(route('cabinet-templates.sync', $template)), applyUrl: @js(route('cabinet-templates.sync.apply', $template)),
                rows: null, picked: [], busy: false,
                async load() { this.rows = null; try { this.rows = (await (await fetch(this.url, { headers: { Accept: 'application/json' } })).json()).cabinets; this.picked = this.rows.map((r) => r.id); } catch (e) { window.showToast('โหลดไม่สำเร็จ', 'error'); } },
                async apply() {
                    if (this.busy || !this.picked.length) return;
                    this.busy = true;
                    try {
                        const res = await fetch(this.applyUrl, { method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content }, body: JSON.stringify({ cabinet_ids: this.picked }) });
                        const data = await res.json().catch(() => ({}));
                        if (!res.ok) throw new Error(data.message || 'ไม่สำเร็จ');
                        window.showToast(data.message);
                        this.$dispatch('close-modal', 'template-sync');
                    } catch (e) { window.showToast(e.message, 'error'); } finally { this.busy = false; }
                },
             }" @open-modal.window="$event.detail === 'template-sync' && load()">
            <h3 class="text-base font-semibold text-slate-900">ส่งรายการใหม่ไปตู้เดิม</h3>
            <p class="mb-3 text-xs text-slate-500">เพิ่ม Checklist ของเทมเพลตนี้ที่ตู้ที่สร้างไปแล้วยังไม่มี — <b>เพิ่มเท่านั้น</b> ไม่แก้ / ไม่ลบรายการเดิม · ข้ามตู้ที่เสร็จแล้ว งานที่เสร็จแล้ว และโครงการที่ปิดแล้ว · รายการที่ตู้ลบทิ้งเองจะไม่ถูกเพิ่มกลับ</p>
            <p x-show="rows === null" class="py-6 text-center text-sm text-slate-400">กำลังตรวจ…</p>
            <p x-show="rows && !rows.length" class="py-6 text-center text-sm text-emerald-700">✓ ทุกตู้มีรายการครบตามเทมเพลตแล้ว</p>
            <div x-show="rows && rows.length" class="max-h-80 overflow-y-auto rounded-lg border border-slate-200">
                <table class="w-full text-xs">
                    <thead class="sticky top-0 bg-slate-50 text-left text-slate-500"><tr><th class="w-8 p-2"></th><th class="p-2">ตู้</th><th class="p-2">โครงการ</th><th class="p-2 text-right">จะเพิ่ม</th></tr></thead>
                    <tbody>
                        <template x-for="r in (rows || [])" :key="r.id">
                            <tr class="border-t border-slate-100 align-top">
                                <td class="p-2"><input type="checkbox" :value="r.id" x-model.number="picked" class="rounded border-slate-300"></td>
                                <td class="p-2"><span class="font-medium text-slate-800" x-text="r.mo"></span> <span class="text-slate-500" x-text="r.name"></span>
                                    <div class="text-[11px] text-slate-400" x-text="r.sample.join(', ') + (r.missing > r.sample.length ? ' …' : '')"></div></td>
                                <td class="p-2 text-slate-600" x-text="r.project"></td>
                                <td class="p-2 text-right font-semibold tabular-nums" x-text="r.missing + ' รายการ'"></td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>
            <div class="mt-4 flex justify-end gap-2">
                <button type="button" @click="$dispatch('close-modal', 'template-sync')" class="rounded-lg px-4 py-2 text-sm text-slate-600 hover:bg-slate-100">ปิด</button>
                <button type="button" x-show="rows && rows.length" @click="apply()" :disabled="busy || !picked.length" class="rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-700 disabled:opacity-50"
                        x-text="'เพิ่มให้ ' + picked.length + ' ตู้'"></button>
            </div>
        </div>
    </x-modal>
    @endedittemplates

    @push('scripts')
    <script>
        document.addEventListener('submit', async (e) => {
            if (!e.target.matches('.task-template-edit-form')) return;
            e.preventDefault();

            const form = e.target;
            const taskId = form.dataset.taskId;
            const errorEl = document.querySelector('.task-edit-error[data-task-id="' + taskId + '"]');
            const submitBtn = form.querySelector('button');
            submitBtn.disabled = true;
            if (errorEl) errorEl.hidden = true;

            const formData = new FormData(form);

            try {
                const res = await fetch(form.action, {
                    method: 'PUT',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                    },
                    body: JSON.stringify({
                        name: formData.get('name'),
                        description: formData.get('description'),
                    }),
                });
                const data = await res.json();
                if (!res.ok) throw new Error(data.message || Object.values(data.errors || {}).flat()[0] || 'Request failed');

                const nameEl = document.querySelector('[data-task-name="' + taskId + '"]');
                if (nameEl) nameEl.textContent = data.task_template.name;

                const container = form.closest('[x-data]');
                if (container && window.Alpine) {
                    window.Alpine.$data(container).editTask = false;
                }
                window.showToast('Changes saved');
            } catch (err) {
                if (errorEl) {
                    errorEl.textContent = err.message || 'ไม่สามารถบันทึกได้ กรุณาลองใหม่';
                    errorEl.hidden = false;
                } else {
                    window.showToast(err.message || 'ไม่สามารถบันทึกได้ กรุณาลองใหม่', 'error');
                }
            } finally {
                submitBtn.disabled = false;
            }
        });

        document.addEventListener('submit', async (e) => {
            if (!e.target.matches('.subtask-template-edit-form')) return;
            e.preventDefault();

            const form = e.target;
            const id = form.dataset.subtaskId;
            const submitBtn = form.querySelector('button');
            const formData = new FormData(form);
            submitBtn.disabled = true;

            try {
                const res = await fetch(form.action, {
                    method: 'PUT',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                    },
                    body: JSON.stringify({
                        name: formData.get('name'),
                        department_id: formData.get('department_id') || null,
                    }),
                });
                const data = await res.json();
                if (!res.ok) throw new Error(data.message || Object.values(data.errors || {}).flat()[0] || 'Request failed');

                const nameEl = document.querySelector('[data-subtask-name="' + id + '"]');
                if (nameEl) nameEl.textContent = data.subtask_template.name;

                const deptEl = document.querySelector('[data-subtask-department="' + id + '"]');
                if (deptEl) deptEl.textContent = data.subtask_template.department_name ?? '';

                const container = form.closest('[x-data]');
                if (container && window.Alpine) {
                    window.Alpine.$data(container).editSubtask = false;
                }
                window.showToast('Changes saved');
            } catch (err) {
                window.showToast(err.message || 'ไม่สามารถบันทึกได้ กรุณาลองใหม่', 'error');
            } finally {
                submitBtn.disabled = false;
            }
        });

        function csrfToken() {
            return document.querySelector('meta[name="csrf-token"]').content;
        }

        function removeEmptyPlaceholder(container) {
            const placeholder = container.querySelector(':scope > [data-empty-placeholder]');
            if (placeholder) placeholder.remove();
        }

        function maybeRestoreEmptyPlaceholder(container, itemSelector, text, tag, className) {
            if (container.querySelector(':scope > ' + itemSelector)) return;
            if (container.querySelector(':scope > [data-empty-placeholder]')) return;
            const el = document.createElement(tag);
            el.className = className;
            el.setAttribute('data-empty-placeholder', '');
            el.textContent = text;
            container.appendChild(el);
        }

        // ---- Add Task / Sub Task (async, no reload) ----

        document.addEventListener('submit', async (e) => {
            if (!e.target.matches('.task-template-add-form')) return;
            e.preventDefault();

            const form = e.target;
            const formData = new FormData(form);
            const submitBtn = form.querySelector('button');
            submitBtn.disabled = true;

            try {
                const res = await fetch(form.action, {
                    method: 'POST',
                    headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': csrfToken() },
                    body: formData,
                });
                const data = await res.json();
                if (!res.ok) throw new Error(data.message || Object.values(data.errors || {}).flat()[0] || 'Request failed');

                const list = document.querySelector('[data-task-list]');
                list.insertAdjacentHTML('beforeend', data.html);
                initSortable(list.parentElement);

                form.reset();
                const container = form.closest('[x-data]');
                if (container && window.Alpine) window.Alpine.$data(container).addTask = false;
                window.showToast('Task added');
            } catch (err) {
                window.showToast(err.message || 'ไม่สามารถเพิ่ม Task ได้ กรุณาลองใหม่', 'error');
            } finally {
                submitBtn.disabled = false;
            }
        });

        document.addEventListener('submit', async (e) => {
            if (!e.target.matches('.subtask-template-add-form')) return;
            e.preventDefault();

            const form = e.target;
            const formData = new FormData(form);
            const submitBtn = form.querySelector('button');
            submitBtn.disabled = true;

            try {
                const res = await fetch(form.action, {
                    method: 'POST',
                    headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': csrfToken() },
                    body: formData,
                });
                const data = await res.json();
                if (!res.ok) throw new Error(data.message || Object.values(data.errors || {}).flat()[0] || 'Request failed');

                const taskRow = document.querySelector('[data-task-row="' + form.dataset.taskId + '"]');
                const list = taskRow.querySelector(':scope > [data-subtask-list]');
                removeEmptyPlaceholder(list);
                list.insertAdjacentHTML('beforeend', data.html);
                initSortable(list);

                form.reset();
                const container = form.closest('[x-data]');
                if (container && window.Alpine) window.Alpine.$data(container).addSubtask = false;
                window.showToast('Sub Task added');
            } catch (err) {
                window.showToast(err.message || 'ไม่สามารถเพิ่ม Sub Task ได้ กรุณาลองใหม่', 'error');
            } finally {
                submitBtn.disabled = false;
            }
        });

        // ---- Delete Task / Sub Task (async, no reload); checklist items: x-checklist-editor ----

        document.addEventListener('submit', async (e) => {
            const form = e.target;
            let row = null;
            let emptyInfo = null;
            let label = '';

            if (form.matches('.task-template-delete-form')) {
                row = form.closest('[data-task-row]');
                label = 'Task deleted';
            } else if (form.matches('.subtask-template-delete-form')) {
                row = form.closest('[data-subtask-row]');
                label = 'Sub Task deleted';
                emptyInfo = { container: row.closest('[data-task-row]').querySelector(':scope > [data-subtask-list]'), selector: '[data-subtask-row]', text: 'ยังไม่มี Sub Task', tag: 'p', className: 'px-4 py-3 text-xs text-slate-400' };
            } else {
                return;
            }

            e.preventDefault();
            if (!confirm(form.dataset.confirm || 'ลบรายการนี้?')) return;

            const btn = form.querySelector('button');
            btn.disabled = true;

            try {
                const res = await fetch(form.action, {
                    method: 'DELETE',
                    headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': csrfToken() },
                });
                if (!res.ok) {
                    const data = await res.json().catch(() => ({}));
                    throw new Error(data.message || 'Request failed');
                }

                row.remove();
                if (emptyInfo) {
                    maybeRestoreEmptyPlaceholder(emptyInfo.container, emptyInfo.selector, emptyInfo.text, emptyInfo.tag, emptyInfo.className);
                }
                window.showToast(label);
            } catch (err) {
                window.showToast(err.message || 'ไม่สามารถลบได้ กรุณาลองใหม่', 'error');
                btn.disabled = false;
            }
        });

        // ---- Drag & drop reordering (SortableJS) ----
        // Each level only reorders within its own parent container - Sortable's
        // `group` is left undefined per instance (default), so items can never
        // be dragged between different containers (different Sub Tasks,
        // different Tasks, etc.), only reordered within the one they started in.

        function persistOrder(container, attr, snapshot) {
            const url = container.dataset.reorderUrl;
            const items = [...container.querySelectorAll(':scope > [' + attr + ']')].map((el, index) => ({
                id: Number(el.getAttribute(attr)),
                sequence: index + 1,
            }));

            fetch(url, {
                method: 'PATCH',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': csrfToken(),
                },
                body: JSON.stringify({ items }),
            }).then((res) => {
                if (!res.ok) throw new Error('Request failed');
                window.showToast('Order updated');
            }).catch(() => {
                if (snapshot) snapshot.forEach((el) => container.appendChild(el));
                window.showToast('ไม่สามารถบันทึกลำดับได้ กรุณาลองใหม่', 'error');
            });
        }

        function initSortableList(container, attr) {
            if (!container || container.dataset.sortableInit) return;
            container.dataset.sortableInit = '1';

            let snapshot = null;

            new window.Sortable(container, {
                handle: '.drag-handle',
                animation: 150,
                filter: '[data-empty-placeholder]',
                // Pointer-based dragging instead of native HTML5 DnD - gives full
                // control over the drag/ghost styling below (native DnD's system
                // drag image can't be restyled) and works on touch devices.
                forceFallback: true,
                fallbackOnBody: true,
                fallbackClass: 'sortable-fallback',
                ghostClass: 'sortable-ghost',
                chosenClass: 'sortable-chosen',
                dragClass: 'sortable-drag',
                onStart: (evt) => {
                    snapshot = [...evt.from.children];
                },
                onEnd: () => {
                    persistOrder(container, attr, snapshot);
                    snapshot = null;
                },
            });
        }

        function initSortable(scope) {
            (scope || document).querySelectorAll('[data-task-list]').forEach((el) => initSortableList(el, 'data-task-row'));
            (scope || document).querySelectorAll('[data-subtask-list]').forEach((el) => initSortableList(el, 'data-subtask-row'));
        }

        document.addEventListener('DOMContentLoaded', () => initSortable(document));
    </script>
    @endpush
</x-app-layout>
