<x-app-layout>
    <x-slot name="header"><h2 class="text-lg font-semibold text-slate-900">ตั้งค่า</h2></x-slot>
    <div class="max-w-none mx-auto space-y-4">   {{-- full width: a column per department --}}
        <x-settings-nav />

        {{-- one table: a row per permission (App\Support\Access), a column per department (+ no department); Admin / PM may everything and are not in it --}}
        <form method="POST" action="{{ route('settings.permissions.update') }}">
            @csrf @method('PUT')
            <x-card title="สิทธิ์การใช้งานตามแผนก"
                    subtitle="ติ๊ก = คนในแผนกนั้นทำได้ · Admin และ Project Manager ({{ $fullRoles }} คน) ทำได้ทุกอย่างเสมอ ไม่ว่าอยู่แผนกไหน · มีผลทันทีกับทุกหน้า ข้อมูลที่ไม่มีสิทธิ์จะไม่ถูกส่งออกจาก server เลย (ไม่ใช่แค่ซ่อน) · ย้ายแผนกของแต่ละคนได้ที่ ตั้งค่า → ผู้ใช้งาน">
                <div class="-mx-5 overflow-x-auto">
                    <table class="w-full min-w-[720px] text-sm">
                        <thead>
                            <tr class="border-b border-slate-200 text-left text-xs text-slate-500">
                                <th class="sticky left-0 z-10 w-64 min-w-[13rem] bg-white px-4 py-2 font-semibold">สิทธิ์</th>
                                <th class="px-2 py-2 text-center font-semibold">Admin / PM</th>
                                @foreach ($columns as $c)
                                    <th class="min-w-[4.75rem] px-1.5 py-2 text-center font-semibold {{ $c['key'] === 'none' ? 'text-slate-400' : '' }}">{{ $c['name'] }}<br><span class="font-normal text-slate-400">{{ $c['people'] }} คน</span></th>
                                @endforeach
                            </tr>
                        </thead>
                        @foreach (\App\Support\Access::GROUPS as $group => $groupLabel)
                            <tbody>
                                <tr class="bg-slate-50"><th colspan="{{ count($columns) + 2 }}" class="px-5 py-1.5 text-left text-xs font-bold uppercase tracking-wide text-slate-600">{{ $groupLabel }}</th></tr>
                                @foreach (\App\Support\Access::PERMISSIONS as $key => [$g, $label, $hint])
                                    @continue ($g !== $group)
                                    <tr class="group border-b border-slate-100 hover:bg-blue-50/40">
                                        <td class="sticky left-0 z-10 bg-white px-4 py-2 shadow-[1px_0_0_#e2e8f0] group-hover:bg-blue-50">
                                            <p class="font-medium text-slate-800">{{ $label }}</p>
                                            @if ($hint)<p class="text-[11px] text-slate-500">{{ $hint }}</p>@endif
                                        </td>
                                        <td class="px-2 py-2 text-center text-emerald-600" title="Admin / PM ทำได้เสมอ">✓</td>
                                        @foreach ($columns as $c)
                                            <td class="px-2 py-2 text-center">
                                                <input type="checkbox" name="perm[{{ $c['key'] }}][{{ $key }}]" value="1" @checked($table[$c['key']][$key])
                                                       class="h-4 w-4 rounded border-slate-300 text-blue-600" aria-label="{{ $c['name'] }}: {{ $label }}">
                                            </td>
                                        @endforeach
                                    </tr>
                                @endforeach
                            </tbody>
                        @endforeach
                    </table>
                </div>
                <div class="mt-4 space-y-1 text-xs text-slate-500">
                    <p>• <b>เห็นโครงการไหน</b> ใช้กับทุกหน้าพร้อมกัน: รายการโครงการ หน้าโครงการ หน้าตู้ แดชบอร์ด Export และ Planning — พิมพ์ URL ตรงก็เปิดไม่ได้</p>
                    <p>• “เห็นทุกโครงการ” คือช่องเดียวกับ “เห็นงานทุกแผนก” ในตั้งค่า → แผนก (แก้ที่ไหนก็ได้ ค่าเดียวกัน)</p>
                    <p>• แผนกใหม่ได้ค่าเริ่มต้น: เข้าแดชบอร์ด / โครงการ / ตู้ / Planning, เห็นไฟล์และคอมเมนต์ — ที่เหลือปิด</p>
                    <p>• ไฟล์: ต้องมีสิทธิ์ “ไฟล์โครงการ” และเห็นโฟลเดอร์นั้นด้วย (ตั้งค่า → ไฟล์และโฟลเดอร์)</p>
                </div>
            </x-card>
            <div class="mt-4 flex items-center justify-end gap-3">
                <button type="submit" form="reset-permissions" class="text-sm text-slate-500 hover:text-slate-700 hover:underline">กลับไปใช้ค่าเริ่มต้น</button>
                <button class="rounded-lg bg-blue-600 px-5 py-2 text-sm font-semibold text-white hover:bg-blue-700">บันทึก</button>
            </div>
        </form>
        <form id="reset-permissions" method="POST" action="{{ route('settings.permissions.reset') }}" onsubmit="return confirm('กลับไปใช้สิทธิ์ค่าเริ่มต้นทั้งหมด? (ทุกแผนกจะเห็นเฉพาะโครงการที่มีงาน)')">@csrf @method('DELETE')</form>
    </div>
</x-app-layout>
