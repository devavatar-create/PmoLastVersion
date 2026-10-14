{{-- "จ่ายงานทั้งขั้น": one step across every cabinet of this template, to one department (Admin / PM). Opened from the step numbers. --}}
<div x-show="step !== null" x-cloak class="fixed inset-0 z-50 flex items-end justify-center bg-slate-900/40 p-4 sm:items-center" @keydown.escape.window="step = null" @click.self="step = null">
    <div role="dialog" aria-modal="true" aria-labelledby="step-dispatch-title" class="w-full max-w-lg rounded-xl bg-white shadow-xl" @click.stop>
        <div class="border-b border-slate-100 px-5 py-4">
            <h3 id="step-dispatch-title" class="font-bold text-ae-navy">จ่ายงานทั้งขั้น: <span x-text="current && current.label"></span></h3>
            <p class="mt-0.5 text-xs text-slate-500">ทุกตู้ในกลุ่มนี้พร้อมกัน · แผนกที่ได้รับจะได้แจ้งเตือนรวมข้อความเดียว · งานที่แผนกรับไปแล้วเปลี่ยนแผนกไม่ได้ และจะถูกข้าม</p>
        </div>
        <div class="max-h-[50vh] space-y-2 overflow-y-auto px-5 py-4">
            <template x-for="(part, i) in (current ? current.parts : [])" :key="i">
                <label class="flex items-start gap-3 rounded-lg border border-slate-100 p-3 text-sm" :class="part.ids.length ? 'cursor-pointer hover:bg-slate-50' : 'opacity-60'">
                    <input type="checkbox" class="mt-0.5 rounded border-slate-300 text-[#0F4C81]" :value="i" x-model.number="picked" :disabled="! part.ids.length">
                    <span class="min-w-0">
                        <span class="font-semibold text-slate-800" x-text="part.name"></span>
                        <span class="block text-xs text-slate-500" x-text="part.ids.length + ' งานที่จ่ายได้ · ตอนนี้: ' + part.departments + (part.locked ? ' · รับงานแล้ว ' + part.locked + ' (ข้าม)' : '')"></span>
                    </span>
                </label>
            </template>
        </div>
        <div class="flex flex-wrap items-center gap-2 border-t border-slate-100 px-5 py-4">
            <label for="step-dispatch-dept" class="text-sm text-slate-600">จ่ายให้แผนก</label>
            <select id="step-dispatch-dept" x-model="department" class="min-w-0 flex-1 rounded-lg border-slate-300 text-sm">
                <option value="">— เลือกแผนก —</option>
                @foreach ($departments->where('is_active', true) as $d)
                    <option value="{{ $d->id }}">{{ $d->name }}</option>
                @endforeach
            </select>
            <div class="flex w-full justify-end gap-2 pt-1">
                <button type="button" @click="step = null" class="px-3 py-2 text-sm font-medium text-slate-600">ยกเลิก</button>
                <button type="button" @click="send('{{ route('cabinet-subtasks.bulk-department') }}')" :disabled="busy || ! department || ! ids().length"
                        class="rounded-lg bg-[#0F4C81] px-4 py-2 text-sm font-semibold text-white hover:bg-ae-navy disabled:opacity-50"
                        x-text="'จ่ายงาน ' + ids().length + ' งาน'">จ่ายงาน</button>
            </div>
        </div>
    </div>
</div>
