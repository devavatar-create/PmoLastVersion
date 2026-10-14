<x-app-layout>
    <x-slot name="header">
        <h2 class="text-lg font-semibold text-slate-900">ตั้งค่า</h2>
    </x-slot>

    <div class="max-w-none mx-auto">
        <x-settings-nav />

        <form method="POST" action="{{ route('settings.rules.update') }}" class="space-y-6">
            @csrf @method('PUT')

            <x-card title="บล็อกการกดเสร็จ" subtitle="ใช้กับทุกหน้า (Planning, หน้าโครงการ, หน้าตู้)">
                <div class="space-y-3">
                    <label class="flex items-start gap-3 text-sm">
                        <input type="checkbox" name="block_done_needs_checklist" value="1" @checked($values['block_done_needs_checklist']) class="mt-0.5 rounded border-slate-300 text-blue-600">
                        <span><span class="font-semibold text-slate-800">ปิดงานของแผนกไม่ได้ ถ้าเช็คลิสต์ยังไม่ครบ</span><br><span class="text-xs text-slate-500">ปุ่ม "เสร็จงาน" จะถูกปฏิเสธจนกว่าจะติ๊กครบทุกรายการ (รวมที่ระบบ Automation กดให้)</span></span>
                    </label>
                    <label class="flex items-start gap-3 text-sm">
                        <input type="checkbox" name="block_project_done_needs_work" value="1" @checked($values['block_project_done_needs_work']) class="mt-0.5 rounded border-slate-300 text-blue-600">
                        <span><span class="font-semibold text-slate-800">ปิดโครงการ (Completed) ไม่ได้ ถ้ายังมีงานของแผนกที่ไม่เสร็จ</span><br><span class="text-xs text-slate-500">นับงานที่จ่ายให้แผนกแล้วแต่ยังไม่ปิด</span></span>
                    </label>
                </div>
            </x-card>

            <x-card title="สถานะโครงการที่ห้าม Automation ขยับงาน" subtitle="โครงการที่อยู่ในสถานะที่เลือก กฎใน Automation จะไม่เริ่ม/ปิดงานให้อัตโนมัติ">
                <div class="grid grid-cols-2 gap-2 sm:grid-cols-3">
                    @foreach ($statuses as $key => $label)
                        <label class="flex items-center gap-2 text-sm text-slate-700">
                            <input type="checkbox" name="automation_locked_statuses[]" value="{{ $key }}" @checked(in_array($key, $values['automation_locked_statuses'], true)) class="rounded border-slate-300 text-blue-600">
                            {{ $label }}
                        </label>
                    @endforeach
                </div>
            </x-card>

            <x-card title="การรับงาน" subtitle="งานที่จ่ายให้แผนกแล้ว แผนกต้องกด &quot;รับงาน&quot; ก่อนทำ">
                <div class="space-y-3">
                    <label class="flex items-center gap-3 text-sm">
                        <span class="font-semibold text-slate-800">เตือนเมื่องานรอรับเกิน</span>
                        <input type="number" name="waiting_reminder_days" min="0" max="30" value="{{ old('waiting_reminder_days', $values['waiting_reminder_days']) }}" class="w-20 rounded-lg border-slate-300 text-sm">
                        <span class="text-slate-600">วัน</span>
                        <span class="text-xs text-slate-500">แจ้งแผนกนั้นและ PM ของโครงการทุกเช้า (0 = ไม่เตือน)</span>
                    </label>
                    <x-input-error :messages="$errors->get('waiting_reminder_days')" />
                    <label class="flex items-start gap-3 text-sm">
                        <input type="checkbox" name="pmo_acts_for_departments" value="1" @checked($values['pmo_acts_for_departments']) class="mt-0.5 rounded border-slate-300 text-blue-600">
                        <span><span class="font-semibold text-slate-800">Admin / PM กดรับงาน เริ่มงาน และเสร็จงานแทนแผนกได้</span><br><span class="text-xs text-slate-500">สำหรับแผนกที่ยังไม่มีคนใช้ระบบ — บันทึกในประวัติว่า "ทำแทนโดย …" (ปิดอยู่ = เฉพาะสมาชิกของแผนกนั้น)</span></span>
                    </label>
                </div>
            </x-card>

            <x-card title="การทำงานร่วมกัน">
                <label class="flex items-start gap-3 text-sm">
                    <input type="checkbox" name="cross_mention" value="1" @checked($values['cross_mention']) class="mt-0.5 rounded border-slate-300 text-blue-600">
                    <span><span class="font-semibold text-slate-800">@ แท็กคนต่างแผนกได้</span><br><span class="text-xs text-slate-500">ปิดอยู่ = แท็กได้เฉพาะคนแผนกเดียวกันกับ Admin/PM (คนที่ถูกแท็กต้องเปิดโครงการนั้นได้ด้วย)</span></span>
                </label>
            </x-card>

            <div class="flex justify-end">
                <button type="submit" class="rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-700">บันทึก</button>
            </div>
        </form>
    </div>
</x-app-layout>
