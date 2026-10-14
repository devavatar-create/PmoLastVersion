<x-app-layout>
    <x-slot name="header"><h2 class="text-lg font-semibold text-slate-900">ตั้งค่า</h2></x-slot>
    @php
        $deptName = $departments->pluck('name', 'id');
        $statusName = $statuses->pluck('name', 'id');
        $describe = fn (array $c) => match ($c['when']) {
            'all_done' => 'เช็คลิสต์ครบทุกแผนก',
            'dept_done' => 'เช็คลิสต์ '.($deptName[$c['department_id'] ?? 0] ?? '?').' ครบ',
            'dept_started' => ($deptName[$c['department_id'] ?? 0] ?? '?').' เริ่มติ๊กเช็คลิสต์',
            'item' => 'ติ๊ก “'.($c['item'] ?? '').'” ('.($deptName[$c['department_id'] ?? 0] ?? '?').')',
            'status' => 'สถานะเป็น “'.($statusName[$c['status_id'] ?? 0] ?? '?').'”',
            'not_status' => 'สถานะไม่ใช่ “'.($statusName[$c['status_id'] ?? 0] ?? '?').'”',
            default => '?',
        };
    @endphp
    <div class="max-w-none mx-auto space-y-4">
        <x-settings-nav />

        <x-card title="กฎสถานะ (อัตโนมัติ)" subtitle="เมื่อเงื่อนไขเป็นจริง (หลังมีคนติ๊ก Checklist) ระบบตั้งสถานะบน Planning ของตู้หรือโครงการให้ — ไม่ย้อนกลับเมื่อเงื่อนไขไม่จริงแล้ว และไม่แตะขั้นรับงาน/เสร็จงานของ PMO">
            <div class="divide-y divide-slate-100 -mx-5 -mt-2">
                @forelse ($rules as $rule)
                    <div class="flex items-center justify-between gap-3 px-5 py-3 {{ $rule->is_active ? '' : 'opacity-50' }}">
                        <p class="min-w-0 text-sm text-slate-700">
                            <span class="font-semibold">{{ \App\Models\StatusRule::TARGETS[$rule->target] }}</span> เมื่อ
                            {{ collect($rule->conditions)->map($describe)->implode($rule->logic === 'or' ? ' หรือ ' : ' และ ') }}
                            → <span class="rounded px-1.5 py-0.5 text-xs font-semibold text-white" style="background-color: {{ $rule->boardStatus->color }}">{{ $rule->boardStatus->name }}</span>
                        </p>
                        <div class="flex shrink-0 items-center gap-3">
                            <form method="POST" action="{{ route('settings.status-rules.toggle', $rule) }}">@csrf @method('PATCH')
                                <button class="text-xs font-medium text-blue-600 hover:underline">{{ $rule->is_active ? 'ปิด' : 'เปิด' }}</button></form>
                            <form method="POST" action="{{ route('settings.status-rules.destroy', $rule) }}" onsubmit="return confirm('ลบกฎนี้?')">@csrf @method('DELETE')
                                <button class="text-xs font-medium text-red-600 hover:underline">ลบ</button></form>
                        </div>
                    </div>
                @empty
                    <p class="px-5 py-4 text-sm text-slate-400">ยังไม่มีกฎ</p>
                @endforelse
            </div>
        </x-card>

        <x-card title="เพิ่มกฎ">
            <form method="POST" action="{{ route('settings.status-rules.store') }}" class="space-y-3 text-sm">
                @csrf
                <div class="flex flex-wrap items-center gap-2">
                    <span>ตั้งสถานะของ</span>
                    <select name="target" class="rounded-lg border-slate-300 text-sm">@foreach (\App\Models\StatusRule::TARGETS as $k => $v)<option value="{{ $k }}">{{ $v }}</option>@endforeach</select>
                    <span>เป็น</span>
                    <select name="board_status_id" class="rounded-lg border-slate-300 text-sm">@foreach ($statuses as $s)<option value="{{ $s->id }}">{{ $s->name }}</option>@endforeach</select>
                    <span>เมื่อ</span>
                    <select name="logic" class="rounded-lg border-slate-300 text-sm"><option value="and">ครบทุกเงื่อนไข (และ)</option><option value="or">ข้อใดข้อหนึ่ง (หรือ)</option></select>
                </div>
                @for ($i = 0; $i < 3; $i++)
                    <div class="grid gap-2 rounded-lg bg-slate-50 p-2 sm:grid-cols-4">
                        <select name="conditions[{{ $i }}][when]" class="rounded-lg border-slate-300 text-xs">
                            <option value="">— เงื่อนไข {{ $i + 1 }} {{ $i ? '(ไม่บังคับ)' : '' }} —</option>
                            @foreach (\App\Models\StatusRule::WHEN as $k => $v)<option value="{{ $k }}">{{ $v }}</option>@endforeach
                        </select>
                        <select name="conditions[{{ $i }}][department_id]" class="rounded-lg border-slate-300 text-xs"><option value="">แผนก (ถ้าใช้)</option>@foreach ($departments as $d)<option value="{{ $d->id }}">{{ $d->name }}</option>@endforeach</select>
                        <input type="text" name="conditions[{{ $i }}][item]" maxlength="255" placeholder="ชื่อรายการ checklist (ถ้าใช้)" class="rounded-lg border-slate-300 text-xs">
                        <select name="conditions[{{ $i }}][status_id]" class="rounded-lg border-slate-300 text-xs"><option value="">สถานะ (ถ้าใช้)</option>@foreach ($statuses as $s)<option value="{{ $s->id }}">{{ $s->name }}</option>@endforeach</select>
                    </div>
                @endfor
                <x-input-error :messages="$errors->get('conditions')" />
                <div class="flex justify-end"><button class="rounded-lg bg-blue-600 px-4 py-2 font-semibold text-white hover:bg-blue-700">+ เพิ่มกฎ</button></div>
            </form>
        </x-card>

        <x-card title="สถานะที่ห้ามเปลี่ยนอัตโนมัติ" subtitle="ตู้/โครงการที่ถูกตั้งสถานะเหล่านี้ไว้ กฎด้านบนจะไม่เปลี่ยนให้">
            <form method="POST" action="{{ route('settings.status-rules.locked') }}" class="space-y-3">@csrf @method('PUT')
                <div class="grid grid-cols-2 gap-2 sm:grid-cols-3">
                    @foreach ($statuses as $s)
                        <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="locked[]" value="{{ $s->id }}" @checked(in_array($s->id, $locked, true)) class="rounded border-slate-300 text-blue-600">
                            <span class="h-2.5 w-2.5 rounded-full" style="background-color: {{ $s->color }}"></span>{{ $s->name }}</label>
                    @endforeach
                </div>
                <div class="flex justify-end"><button class="rounded-lg bg-slate-800 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-700">บันทึก</button></div>
            </form>
        </x-card>
    </div>
</x-app-layout>
