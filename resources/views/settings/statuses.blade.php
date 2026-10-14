<x-app-layout>
    <x-slot name="header">
        <h2 class="text-lg font-semibold text-slate-900">ตั้งค่า</h2>
    </x-slot>

    <div class="max-w-none mx-auto space-y-4">
        <x-settings-nav />

        <x-card title="สถานะงานบน Planning" subtitle="สถานะที่ระบบคำนวณเอง (มีป้าย “อัตโนมัติ”) ตามความคืบหน้าของแผนก — เปลี่ยนชื่อ/สีได้แต่ลบไม่ได้ ส่วนสถานะที่เพิ่มเอง ผู้ใช้เลือกให้แต่ละโครงการเองได้ในหน้า Planning">
            <form method="POST" action="{{ route('settings.statuses.update') }}" id="status-form">@csrf @method('PUT')</form>

            <div class="space-y-2">
                @foreach ($statuses as $s)
                    <div class="flex flex-wrap items-center gap-2">
                        <input form="status-form" type="color" name="statuses[{{ $s->id }}][color]" value="{{ $s->color }}" class="h-9 w-12 rounded border-slate-300">
                        <input form="status-form" type="text" name="statuses[{{ $s->id }}][name]" value="{{ $s->name }}" maxlength="40" required class="min-w-0 flex-1 rounded-lg border-slate-300 text-sm">
                        @if ($s->key)
                            <span class="w-24 text-center text-[11px] text-slate-400">อัตโนมัติ{{ $s->is_done ? ' · เสร็จ' : '' }}</span>
                        @else
                            <label class="flex w-24 items-center gap-1 text-xs text-slate-600" title="ถือเป็นเสร็จ — ขีดฆ่าและนับเป็นงานที่เสร็จ">
                                <input form="status-form" type="checkbox" name="statuses[{{ $s->id }}][is_done]" value="1" @checked($s->is_done) class="rounded border-slate-300 text-blue-600"> เสร็จ
                            </label>
                        @endif
                        <form method="POST" action="{{ route('settings.statuses.move', $s) }}" class="flex">@csrf @method('PATCH')
                            <button name="dir" value="up" class="px-1.5 text-slate-500 hover:text-slate-900" title="ขึ้น">↑</button>
                            <button name="dir" value="down" class="px-1.5 text-slate-500 hover:text-slate-900" title="ลง">↓</button>
                        </form>
                        @unless ($s->key)
                            <form method="POST" action="{{ route('settings.statuses.destroy', $s) }}" onsubmit="return confirm('ลบสถานะนี้? โครงการที่เลือกไว้จะกลับไปใช้สถานะอัตโนมัติ')">@csrf @method('DELETE')
                                <button class="px-1.5 text-xs text-red-600 hover:underline">ลบ</button>
                            </form>
                        @else
                            <span class="w-8"></span>
                        @endunless
                    </div>
                @endforeach
            </div>
            <div class="mt-4 flex justify-end">
                <button form="status-form" type="submit" class="rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-700">บันทึก</button>
            </div>
        </x-card>

        <x-card title="เพิ่มสถานะ">
            <form method="POST" action="{{ route('settings.statuses.store') }}" class="flex flex-wrap items-center gap-2">
                @csrf
                <input type="color" name="color" value="#7b68ee" class="h-9 w-12 rounded border-slate-300">
                <input type="text" name="name" required maxlength="40" placeholder="ชื่อสถานะ เช่น รอลูกค้าอนุมัติ" class="min-w-0 flex-1 rounded-lg border-slate-300 text-sm">
                <label class="flex items-center gap-1 text-xs text-slate-600"><input type="checkbox" name="is_done" value="1" class="rounded border-slate-300 text-blue-600"> ถือเป็นเสร็จ</label>
                <button class="rounded-lg bg-slate-800 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-700">+ เพิ่ม</button>
            </form>
        </x-card>
    </div>
</x-app-layout>
