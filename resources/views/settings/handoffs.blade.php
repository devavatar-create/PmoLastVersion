<x-app-layout>
    <x-slot name="header"><h2 class="text-lg font-semibold text-slate-900">ตั้งค่า</h2></x-slot>
    <div class="max-w-none mx-auto space-y-4">
        <x-settings-nav />
        <x-card title="การส่งต่องานระหว่างแผนก" subtitle="แผนกปลายทางจะเห็น Checklist ของแผนกต้นทาง (ดูอย่างเดียว) ในโครงการที่ทั้งสองแผนกมีงาน และได้แจ้งเตือนเมื่อแผนกต้นทางลงวันที่หรือติ๊กรายการ — ไม่บังคับลำดับงาน ใช้ติดตามอย่างเดียว">
            <div class="space-y-2">
                @forelse ($links as $l)
                    <div class="flex flex-wrap items-center gap-2 rounded-lg border border-slate-200 px-3 py-2">
                        <span class="text-sm font-semibold text-slate-800">{{ $l->from?->name }}</span>
                        <span class="text-slate-400">→</span>
                        <span class="text-sm font-semibold text-slate-800">{{ $l->to?->name }}</span>
                        <form method="POST" action="{{ route('settings.handoffs.update', $l) }}" class="ml-auto flex items-center gap-2">@csrf @method('PUT')
                            <label class="text-xs text-slate-500" for="label-{{ $l->id }}">วันที่ที่ต้นทางลงต่อรายการ</label>
                            <input id="label-{{ $l->id }}" type="text" name="date_label" value="{{ $l->date_label }}" maxlength="40" placeholder="ไม่มี" class="w-36 rounded-lg border-slate-300 text-sm">
                            <button class="rounded-lg border border-slate-300 px-3 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-50">บันทึก</button>
                        </form>
                        <form method="POST" action="{{ route('settings.handoffs.destroy', $l) }}" onsubmit="return confirm('ลบการส่งต่อนี้? วันที่ที่ลงไว้แล้วยังอยู่ในรายการ')">@csrf @method('DELETE')
                            <button class="text-xs text-red-600 hover:underline">ลบ</button>
                        </form>
                    </div>
                @empty
                    <p class="text-sm text-slate-400">ยังไม่มีการส่งต่องาน</p>
                @endforelse
            </div>
        </x-card>
        <x-card title="เพิ่มการส่งต่อ" subtitle="ตัวอย่าง: จัดซื้อ → คลัง (วันที่: วันของเข้า) แล้ว คลัง → Production (ไม่ต้องมีวันที่)">
            <form method="POST" action="{{ route('settings.handoffs.store') }}" class="flex flex-wrap items-center gap-2">@csrf
                <select name="from_department_id" required class="rounded-lg border-slate-300 text-sm" aria-label="แผนกต้นทาง">
                    <option value="">แผนกต้นทาง…</option>
                    @foreach ($departments as $d)<option value="{{ $d->id }}" @selected(old('from_department_id') == $d->id)>{{ $d->name }}</option>@endforeach
                </select>
                <span class="text-slate-400">→</span>
                <select name="to_department_id" required class="rounded-lg border-slate-300 text-sm" aria-label="แผนกปลายทาง">
                    <option value="">แผนกปลายทาง…</option>
                    @foreach ($departments as $d)<option value="{{ $d->id }}" @selected(old('to_department_id') == $d->id)>{{ $d->name }}</option>@endforeach
                </select>
                <input type="text" name="date_label" value="{{ old('date_label') }}" maxlength="40" placeholder="วันที่ต่อรายการ เช่น วันของเข้า (เว้นว่างได้)" class="min-w-0 flex-1 rounded-lg border-slate-300 text-sm">
                <button class="rounded-lg bg-slate-800 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-700">+ เพิ่ม</button>
            </form>
            @error('to_department_id')<p class="mt-2 text-xs text-red-600">{{ $message }}</p>@enderror
        </x-card>
    </div>
</x-app-layout>
