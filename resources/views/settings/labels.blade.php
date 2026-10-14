<x-app-layout>
    <x-slot name="header"><h2 class="text-lg font-semibold text-slate-900">ตั้งค่า</h2></x-slot>
    <div class="max-w-none mx-auto space-y-4">
        <x-settings-nav />
        <x-card title="แท็ก (ป้ายกำกับ)" subtitle="ติดแท็กให้โครงการได้ในหน้าต่างโครงการบน Planning ทุกคนที่แก้โครงการได้ติด/ถอดแท็กได้ — สร้างและลบแท็กทำได้เฉพาะ Admin/PM">
            <div class="space-y-2">
                @forelse ($labels as $l)
                    <div class="flex items-center gap-2">
                        <form method="POST" action="{{ route('settings.labels.update', $l) }}" class="flex flex-1 items-center gap-2">@csrf @method('PUT')
                            <input type="color" name="color" value="{{ $l->color }}" class="h-9 w-12 rounded border-slate-300">
                            <input type="text" name="name" value="{{ $l->name }}" maxlength="40" required class="min-w-0 flex-1 rounded-lg border-slate-300 text-sm">
                            <span class="text-xs text-slate-400">{{ $l->projects_count }} โครงการ</span>
                            <button class="rounded-lg border border-slate-300 px-3 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-50">บันทึก</button>
                        </form>
                        <form method="POST" action="{{ route('settings.labels.destroy', $l) }}" onsubmit="return confirm('ลบแท็กนี้? จะถูกถอดออกจากทุกโครงการ')">@csrf @method('DELETE')
                            <button class="text-xs text-red-600 hover:underline">ลบ</button>
                        </form>
                    </div>
                @empty
                    <p class="text-sm text-slate-400">ยังไม่มีแท็ก</p>
                @endforelse
            </div>
        </x-card>
        <x-card title="เพิ่มแท็ก">
            <form method="POST" action="{{ route('settings.labels.store') }}" class="flex gap-2">@csrf
                <input type="color" name="color" value="#7b68ee" class="h-9 w-12 rounded border-slate-300">
                <input type="text" name="name" required maxlength="40" placeholder="ชื่อแท็ก เช่น ด่วนลูกค้า" class="min-w-0 flex-1 rounded-lg border-slate-300 text-sm">
                <button class="rounded-lg bg-slate-800 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-700">+ เพิ่ม</button>
            </form>
        </x-card>
    </div>
</x-app-layout>
