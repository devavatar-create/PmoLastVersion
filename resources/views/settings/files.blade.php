<x-app-layout>
    <x-slot name="header"><h2 class="text-lg font-semibold text-slate-900">ตั้งค่า</h2></x-slot>
    <div class="max-w-none mx-auto space-y-4">
        <x-settings-nav />

        {{-- one form: the standard folders (a row per folder, in order) + the cabinets' folders; saving brings every project in step --}}
        <form method="POST" action="{{ route('settings.files.update') }}" class="space-y-4"
              x-data="{
                  rows: @js(collect(old('rows', $rows))->values()->map(fn ($r) => ['name' => $r['name'] ?? '', 'from' => $r['from'] ?? ($r['name'] ?? ''), 'visibility' => $r['visibility'] ?? 'all', 'departments' => array_map('intval', (array) ($r['departments'] ?? []))])),
                  departments: @js($departments->map(fn ($d) => ['id' => $d->id, 'name' => $d->name])),
                  add() { this.rows.push({ name: '', from: '', visibility: 'all', departments: [] }); this.$nextTick(() => { const el = document.getElementById('row-name-' + (this.rows.length - 1)); el && el.focus(); }); },
                  move(i, d) { const j = i + d; if (j < 0 || j >= this.rows.length) return; const r = this.rows.splice(i, 1)[0]; this.rows.splice(j, 0, r); },
                  names(r) { return r.departments.map((id) => (this.departments.find((d) => d.id === id) || { name: '#' + id }).name).join(', '); },
              }">
            @csrf @method('PUT')

            <x-card title="โฟลเดอร์มาตรฐานของทุกโครงการ"
                    subtitle="ทุกโครงการมีโฟลเดอร์เหล่านี้ ชื่อ ลำดับ และใครเห็นได้ ตั้งที่นี่ที่เดียว (ในโครงการแก้ชื่อ ย้าย ลบ หรือเปลี่ยนสิทธิ์ไม่ได้) — กดบันทึกแล้วใช้กับทุกโครงการทันที รวมโครงการเดิม">
                <div class="space-y-2">
                    <template x-for="(r, i) in rows" :key="i">
                        <div class="rounded-lg border border-slate-200 p-3">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="text-base">📁</span>
                                <input type="hidden" :name="'rows[' + i + '][from]'" :value="r.from">
                                <input :id="'row-name-' + i" type="text" :name="'rows[' + i + '][name]'" x-model="r.name" maxlength="120" required placeholder="ชื่อโฟลเดอร์"
                                       class="min-w-0 flex-1 rounded-lg border-slate-300 text-sm font-semibold" :aria-label="'ชื่อโฟลเดอร์ที่ ' + (i + 1)">
                                <span x-show="r.from && r.name.trim() !== r.from" class="text-[11px] text-amber-700" x-text="'เดิม: ' + r.from"></span>
                                <button type="button" @click="move(i, -1)" :disabled="i === 0" class="h-8 w-8 rounded-lg border border-slate-300 text-slate-600 hover:bg-slate-50 disabled:opacity-30" aria-label="เลื่อนขึ้น">↑</button>
                                <button type="button" @click="move(i, 1)" :disabled="i === rows.length - 1" class="h-8 w-8 rounded-lg border border-slate-300 text-slate-600 hover:bg-slate-50 disabled:opacity-30" aria-label="เลื่อนลง">↓</button>
                                <button type="button" @click="rows.length > 1 && confirm('เอาโฟลเดอร์ “' + (r.name || 'นี้') + '” ออกจากมาตรฐาน?\nในโครงการเดิม: ถ้าว่างจะถูกลบ ถ้ามีไฟล์จะคงไว้เป็นโฟลเดอร์ธรรมดาของโครงการนั้น') && rows.splice(i, 1)"
                                        :disabled="rows.length === 1" class="h-8 rounded-lg px-2 text-xs text-red-600 hover:bg-red-50 disabled:opacity-30">เอาออก</button>
                            </div>
                            <div class="mt-2 flex flex-wrap items-center gap-x-4 gap-y-1 pl-7 text-xs text-slate-600">
                                <span class="font-semibold text-slate-700">ใครเห็นได้:</span>
                                <label class="inline-flex items-center gap-1.5"><input type="radio" :name="'rows[' + i + '][visibility]'" value="all" x-model="r.visibility"> ทุกคนที่เห็นโครงการ</label>
                                <label class="inline-flex items-center gap-1.5"><input type="radio" :name="'rows[' + i + '][visibility]'" value="departments" x-model="r.visibility"> 👥 เฉพาะแผนกที่เลือก</label>
                                <label class="inline-flex items-center gap-1.5"><input type="radio" :name="'rows[' + i + '][visibility]'" value="pmo" x-model="r.visibility"> 🔒 เฉพาะ Admin / PM</label>
                            </div>
                            <div x-show="r.visibility === 'departments'" class="mt-2 ml-7 rounded-lg bg-slate-50 p-2">
                                <div class="grid grid-cols-2 gap-x-4 gap-y-1 text-xs sm:grid-cols-3 md:grid-cols-4">
                                    <template x-for="d in departments" :key="'d' + i + '-' + d.id">
                                        <label class="inline-flex items-center gap-1.5"><input type="checkbox" :name="'rows[' + i + '][departments][]'" :value="d.id" x-model.number="r.departments" class="rounded border-slate-300"><span x-text="d.name"></span></label>
                                    </template>
                                </div>
                                <p class="mt-1.5 text-[11px] text-slate-500" x-text="r.departments.length ? 'เห็นได้: ' + names(r) + ' และ Admin / PM — แผนกอื่นจะไม่เห็นโฟลเดอร์นี้เลย' : 'ยังไม่ได้เลือกแผนก'"></p>
                            </div>
                        </div>
                    </template>
                </div>
                @if ($errors->any())
                    <ul class="mt-3 space-y-0.5 text-xs text-red-600">@foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
                @endif
                <button type="button" @click="add()" class="mt-3 rounded-lg border border-dashed border-slate-300 px-3 py-1.5 text-sm text-slate-600 hover:bg-slate-50">+ เพิ่มโฟลเดอร์มาตรฐาน</button>
                <p class="mt-3 text-xs text-slate-500">โฟลเดอร์ย่อยที่สร้างข้างในจะเห็นได้เฉพาะคนที่เห็นโฟลเดอร์มาตรฐานนั้นด้วย · Admin / PM เห็นทุกโฟลเดอร์เสมอ · โฟลเดอร์อื่นที่สร้างเพิ่มในโครงการ ตั้ง "ใครเห็นได้" เองได้ในหน้าไฟล์</p>
            </x-card>

            <x-card title="โฟลเดอร์ของตู้ (ใบสั่งผลิต / MO)" subtitle="ระบบสร้างโฟลเดอร์ให้ทุกตู้อัตโนมัติ ภายใต้ “ตู้ / ใบสั่งผลิต (MO)”">
                <div class="space-y-2 text-sm">
                    <label class="flex items-start gap-2"><input type="radio" name="cabinets" value="work" @checked(old('cabinets', $cabinets) === 'work') class="mt-1">
                        <span><span class="font-semibold text-slate-800">แต่ละแผนกเห็นเฉพาะตู้ที่แผนกตัวเองมีงาน</span><br><span class="text-xs text-slate-500">ตรงกับที่เห็นใน Planning — Admin / PM, แผนกที่เห็นงานทุกแผนก และผู้รับผิดชอบโครงการเห็นทุกตู้</span></span></label>
                    <label class="flex items-start gap-2"><input type="radio" name="cabinets" value="all" @checked(old('cabinets', $cabinets) === 'all') class="mt-1">
                        <span><span class="font-semibold text-slate-800">ทุกคนที่เห็นโครงการเห็นโฟลเดอร์ของทุกตู้</span></span></label>
                </div>
            </x-card>

            <div class="flex justify-end">
                <button class="rounded-lg bg-blue-600 px-5 py-2 text-sm font-semibold text-white hover:bg-blue-700">บันทึก และใช้กับทุกโครงการ</button>
            </div>
        </form>
    </div>
</x-app-layout>
