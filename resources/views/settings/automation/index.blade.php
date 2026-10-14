<x-app-layout>
    <x-slot name="header">
        <h2 class="text-lg font-semibold text-slate-900">ตั้งค่า</h2>
    </x-slot>

    <div class="max-w-none mx-auto space-y-6">
        <x-settings-nav />
        {{-- ===== Workflow rules ===== --}}
        <x-card title="กฎเลื่อนสถานะงานอัตโนมัติ" subtitle="ทำงานเมื่อมีคนติ๊ก Checklist ของ Sub Task เท่านั้น (ยกเลิกการติ๊กจะไม่ย้อนสถานะกลับ) และผ่านขั้นตอนรับงาน/เริ่มงาน/ปิดงานเดิม ไม่ข้ามสิทธิ์">
            <div class="divide-y divide-slate-100 -mx-5 -mt-2">
                @forelse ($rules as $rule)
                    <div class="px-5 py-3 flex items-center justify-between gap-3 {{ $rule->is_active ? '' : 'opacity-50' }}">
                        <div class="min-w-0">
                            <p class="font-medium text-slate-800 truncate">{{ $rule->name }}</p>
                            <p class="text-xs text-slate-500">
                                แผนก: {{ $rule->department?->name ?? 'ทุกแผนก' }} · เมื่อ{{ $triggers[$rule->trigger] ?? $rule->trigger }} → {{ $actions[$rule->action] ?? $rule->action }}
                            </p>
                        </div>
                        <div class="flex items-center gap-3 shrink-0">
                            <form method="POST" action="{{ route('settings.automation.rules.toggle', $rule) }}">@csrf @method('PATCH')
                                <button class="text-xs font-medium text-blue-600 hover:underline">{{ $rule->is_active ? 'ปิดใช้งาน' : 'เปิดใช้งาน' }}</button>
                            </form>
                            <form method="POST" action="{{ route('settings.automation.rules.destroy', $rule) }}" onsubmit="return confirm('ลบกฎนี้?')">@csrf @method('DELETE')
                                <button class="text-xs font-medium text-red-600 hover:underline">ลบ</button>
                            </form>
                        </div>
                    </div>
                @empty
                    <p class="px-5 py-4 text-sm text-slate-400">ยังไม่มีกฎ</p>
                @endforelse
            </div>

            <form method="POST" action="{{ route('settings.automation.rules.store') }}" class="mt-4 pt-4 border-t border-slate-100 grid grid-cols-1 sm:grid-cols-2 gap-3">
                @csrf
                <div class="sm:col-span-2">
                    <x-input-label value="ชื่อกฎ" />
                    <x-text-input name="name" class="mt-1 block w-full" placeholder="เช่น QC ติ๊กครบ → ปิดงาน" required />
                    <x-input-error :messages="$errors->get('name')" class="mt-1" />
                </div>
                <div>
                    <x-input-label value="แผนก" />
                    <select name="department_id" class="mt-1 block w-full rounded-lg border-slate-300 text-sm">
                        <option value="">ทุกแผนก</option>
                        @foreach ($departments as $d)<option value="{{ $d->id }}">{{ $d->name }}</option>@endforeach
                    </select>
                </div>
                <div>
                    <x-input-label value="เมื่อ" />
                    <select name="trigger" class="mt-1 block w-full rounded-lg border-slate-300 text-sm">
                        @foreach ($triggers as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach
                    </select>
                </div>
                <div>
                    <x-input-label value="ให้ระบบทำ" />
                    <select name="action" class="mt-1 block w-full rounded-lg border-slate-300 text-sm">
                        @foreach ($actions as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach
                    </select>
                </div>
                <div class="flex items-end justify-end">
                    <button type="submit" class="px-4 py-2 rounded-lg bg-blue-600 text-white text-sm font-semibold hover:bg-blue-700">+ เพิ่มกฎ</button>
                </div>
            </form>
        </x-card>

        {{-- ===== Date limits ===== --}}
        <x-card title="กฎจำกัดวันกำหนดส่ง" subtitle="Sub Task ของแผนกที่ถูกจำกัด จะตั้งกำหนดส่งเกินวันของแผนกอ้างอิง (ในตู้เดียวกัน) ไม่ได้ เช่น ห้ามเลย QC">
            <div class="divide-y divide-slate-100 -mx-5 -mt-2">
                @forelse ($limits as $limit)
                    <div class="px-5 py-3 flex items-center justify-between gap-3 {{ $limit->is_active ? '' : 'opacity-50' }}">
                        <p class="text-sm text-slate-800">
                            <span class="font-medium">{{ $limit->blockedDepartment->name }}</span> ต้องไม่เกินวันของ <span class="font-medium">{{ $limit->anchorDepartment->name }}</span>
                        </p>
                        <div class="flex items-center gap-3 shrink-0">
                            <form method="POST" action="{{ route('settings.automation.limits.toggle', $limit) }}">@csrf @method('PATCH')
                                <button class="text-xs font-medium text-blue-600 hover:underline">{{ $limit->is_active ? 'ปิดใช้งาน' : 'เปิดใช้งาน' }}</button>
                            </form>
                            <form method="POST" action="{{ route('settings.automation.limits.destroy', $limit) }}" onsubmit="return confirm('ลบกฎนี้?')">@csrf @method('DELETE')
                                <button class="text-xs font-medium text-red-600 hover:underline">ลบ</button>
                            </form>
                        </div>
                    </div>
                @empty
                    <p class="px-5 py-4 text-sm text-slate-400">ยังไม่มีกฎ</p>
                @endforelse
            </div>

            <form method="POST" action="{{ route('settings.automation.limits.store') }}" class="mt-4 pt-4 border-t border-slate-100 grid grid-cols-1 sm:grid-cols-3 gap-3 items-end">
                @csrf
                <div>
                    <x-input-label value="แผนกที่ถูกจำกัด" />
                    <select name="blocked_department_id" class="mt-1 block w-full rounded-lg border-slate-300 text-sm" required>
                        @foreach ($departments as $d)<option value="{{ $d->id }}">{{ $d->name }}</option>@endforeach
                    </select>
                    <x-input-error :messages="$errors->get('blocked_department_id')" class="mt-1" />
                </div>
                <div>
                    <x-input-label value="ต้องไม่เกินวันของ (แผนกอ้างอิง)" />
                    <select name="anchor_department_id" class="mt-1 block w-full rounded-lg border-slate-300 text-sm" required>
                        @foreach ($departments as $d)<option value="{{ $d->id }}">{{ $d->name }}</option>@endforeach
                    </select>
                </div>
                <div class="flex justify-end">
                    <button type="submit" class="px-4 py-2 rounded-lg bg-blue-600 text-white text-sm font-semibold hover:bg-blue-700">+ เพิ่มกฎ</button>
                </div>
            </form>
        </x-card>
    </div>
</x-app-layout>
