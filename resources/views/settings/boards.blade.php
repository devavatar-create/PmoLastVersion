<x-app-layout>
    <x-slot name="header"><h2 class="text-lg font-semibold text-slate-900">ตั้งค่า</h2></x-slot>
    <div class="max-w-none mx-auto space-y-4">
        <x-settings-nav />
        <x-card title="บอร์ด" subtitle="ปฏิทิน Planning แยกตามกลุ่มงาน เช่น Service — บอร์ดหลัก (PMO) มีอยู่เสมอ ย้ายโครงการไปบอร์ดอื่นได้ในหน้าต่างโครงการบน Planning (Admin/PM)">
            <div class="space-y-2">
                <div class="flex items-center gap-2 rounded-lg bg-slate-50 px-3 py-2 text-sm text-slate-600">PMO <span class="text-xs text-slate-400">(บอร์ดหลัก)</span></div>
                @foreach ($boards as $b)
                    <div class="flex items-center gap-2">
                        <form method="POST" action="{{ route('settings.boards.update', $b) }}" class="flex flex-1 items-center gap-2">@csrf @method('PUT')
                            <input type="text" name="name" value="{{ $b->name }}" maxlength="60" required class="min-w-0 flex-1 rounded-lg border-slate-300 text-sm">
                            <span class="text-xs text-slate-400">{{ $b->projects_count }} โครงการ</span>
                            <button class="rounded-lg border border-slate-300 px-3 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-50">บันทึก</button>
                        </form>
                        <form method="POST" action="{{ route('settings.boards.destroy', $b) }}" onsubmit="return confirm('ลบบอร์ดนี้? โครงการในบอร์ดจะกลับไปอยู่บอร์ดหลัก')">@csrf @method('DELETE')
                            <button class="text-xs text-red-600 hover:underline">ลบ</button>
                        </form>
                    </div>
                @endforeach
            </div>
        </x-card>
        <x-card title="เพิ่มบอร์ด">
            <form method="POST" action="{{ route('settings.boards.store') }}" class="flex gap-2">@csrf
                <input type="text" name="name" required maxlength="60" placeholder="ชื่อบอร์ด เช่น Service" class="min-w-0 flex-1 rounded-lg border-slate-300 text-sm">
                <button class="rounded-lg bg-slate-800 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-700">+ เพิ่ม</button>
            </form>
        </x-card>
    </div>
</x-app-layout>
