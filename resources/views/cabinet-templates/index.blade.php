<x-app-layout>
    <x-slot name="header">
        <h2 class="text-lg font-semibold text-slate-900">ตั้งค่า</h2>
    </x-slot>

    <div class="max-w-none mx-auto space-y-4">
        <x-settings-nav />
        <div class="space-y-4" x-data="{ q: '', importing: {{ $errors->any() ? 'true' : 'false' }} }">

        {{-- title, search, and (for whom may change templates) Excel import + new --}}
        <div class="flex flex-wrap items-center gap-3">
            <div class="min-w-[16rem] flex-1">
                <h1 class="text-lg font-semibold text-slate-900">เทมเพลตตู้ / เช็คลิสต์</h1>
                <p class="text-xs text-slate-500">ตู้ใหม่สร้างจากเทมเพลตเป็นสำเนา — แก้เทมเพลตภายหลังไม่กระทบตู้ที่สร้างไปแล้ว (ส่งรายการใหม่ไปตู้เดิมได้จากหน้าเทมเพลต)</p>
            </div>
            <div class="flex shrink-0 flex-wrap items-center gap-2">
            <label class="relative">
                <span class="sr-only">ค้นหาเทมเพลต</span>
                <svg class="pointer-events-none absolute left-2.5 top-2.5 h-4 w-4 text-slate-400" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" d="M21 21l-4.3-4.3M11 18a7 7 0 100-14 7 7 0 000 14z"/></svg>
                <input type="search" x-model="q" placeholder="ค้นหาชื่อ / รหัส / Task" class="h-9 w-60 rounded-lg border-slate-300 pl-8 text-sm">
            </label>
            @edittemplates
                <button type="button" @click="importing = !importing" class="h-9 rounded-lg border border-slate-300 bg-white px-3 text-sm font-medium text-slate-700 hover:bg-slate-50" :aria-expanded="importing">นำเข้าจาก Excel</button>
                <a href="{{ route('cabinet-templates.create') }}" class="inline-flex h-9 items-center rounded-lg bg-blue-600 px-4 text-sm font-semibold text-white hover:bg-blue-700">+ เทมเพลตใหม่</a>
            @endedittemplates
            </div>
        </div>

        @edittemplates
        {{-- a new template from Excel (CSV: Task | Sub Task | แผนก | Checklist | หมายเหตุ - the same layout "Export Excel" gives) --}}
        <form x-show="importing" x-cloak method="POST" action="{{ route('cabinet-templates.import') }}" enctype="multipart/form-data"
              class="rounded-xl border border-blue-200 bg-blue-50/50 p-4 text-sm">
            @csrf
            <p class="font-semibold text-slate-800">นำเข้าเทมเพลตใหม่จาก Excel (CSV)</p>
            <p class="mt-0.5 text-xs text-slate-500">คอลัมน์: Task | Sub Task | แผนก | Checklist | หมายเหตุ — กด "Export Excel" ที่เทมเพลตเดิมเพื่อได้ไฟล์ตัวอย่าง</p>
            <div class="mt-3 flex flex-wrap items-center gap-2">
                <input name="name" required maxlength="255" value="{{ old('name') }}" placeholder="ชื่อเทมเพลตใหม่" class="h-9 w-64 rounded-lg border-slate-300 text-sm">
                <input type="file" name="file" required accept=".csv,text/csv" class="text-xs file:mr-2 file:rounded-md file:border-0 file:bg-white file:px-3 file:py-2 file:text-slate-700 file:ring-1 file:ring-slate-300">
                <button class="h-9 rounded-lg bg-slate-800 px-4 text-sm font-semibold text-white hover:bg-slate-700">นำเข้า</button>
                <button type="button" @click="importing = false" class="h-9 rounded-lg px-3 text-sm text-slate-500 hover:bg-white">ยกเลิก</button>
            </div>
            @if ($errors->any())<p class="mt-2 text-xs text-red-600">{{ $errors->first() }}</p>@endif
        </form>
        @endedittemplates

        <div class="grid grid-cols-1 gap-4 md:grid-cols-2 2xl:grid-cols-3">
            @forelse ($templates as $template)
                @php
                    $subs = $template->taskTemplates->flatMap->subtaskTemplates;
                    $depts = $subs->pluck('department.name')->filter()->unique()->values();
                    $haystack = mb_strtolower($template->name.' '.$template->code.' '.$template->description.' '.$template->taskTemplates->pluck('name')->implode(' '));
                @endphp
                <div x-show="!q || @js($haystack).includes(q.trim().toLowerCase())"
                     class="relative flex flex-col rounded-xl border bg-white shadow-sm transition hover:border-blue-300 hover:shadow {{ $template->is_active ? 'border-slate-200' : 'border-dashed border-slate-300 opacity-75' }}">
                    {{-- the whole card opens the template; the buttons below sit above this link --}}
                    <a href="{{ route('cabinet-templates.show', $template) }}" class="absolute inset-0 rounded-xl" aria-label="เปิด {{ $template->name }}"></a>

                    <div class="p-5 pb-3">
                        <div class="flex flex-wrap items-center gap-2">
                            <h3 class="font-semibold text-slate-900">{{ $template->name }}</h3>
                            @if ($template->is_default)
                                <span class="rounded bg-blue-100 px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-blue-700" title="ตู้ใหม่ใช้เทมเพลตนี้ถ้าไม่ได้เลือก">ค่าเริ่มต้น</span>
                            @endif
                            @unless ($template->is_active)
                                <span class="rounded bg-slate-100 px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-slate-500" title="ไม่แสดงให้เลือกตอนสร้างตู้">ปิดใช้งาน</span>
                            @endunless
                        </div>
                        <p class="mt-0.5 text-xs text-slate-400">{{ $template->code }}</p>
                        @if ($template->description)
                            <p class="mt-2 line-clamp-2 text-sm text-slate-600">{{ $template->description }}</p>
                        @endif

                        {{-- how big it is, and how much it is used --}}
                        <dl class="mt-3 grid grid-cols-4 gap-2 text-center">
                            @foreach ([['Task', $template->taskTemplates->count()], ['Sub Task', $subs->count()], ['Checklist', $subs->sum('checklist_templates_count')], ['ใช้กับตู้', $template->cabinets_count]] as [$label, $n])
                                <div class="rounded-lg bg-slate-50 py-1.5">
                                    <dd class="text-base font-semibold tabular-nums text-slate-900">{{ number_format($n) }}</dd>
                                    <dt class="text-[11px] text-slate-500">{{ $label }}</dt>
                                </div>
                            @endforeach
                        </dl>

                        @if ($template->taskTemplates->isNotEmpty())
                            <div class="mt-3 flex flex-wrap gap-1.5">
                                @foreach ($template->taskTemplates->take(6) as $taskTemplate)
                                    <span class="max-w-[14rem] truncate rounded-md bg-slate-100 px-2 py-0.5 text-xs text-slate-600" title="{{ $taskTemplate->name }}">{{ $taskTemplate->name }} <span class="text-slate-400">· {{ $taskTemplate->subtaskTemplates->count() }}</span></span>
                                @endforeach
                                @if ($template->taskTemplates->count() > 6)
                                    <span class="rounded-md px-1.5 py-0.5 text-xs text-slate-400">+{{ $template->taskTemplates->count() - 6 }} Task</span>
                                @endif
                            </div>
                        @endif
                        @if ($depts->isNotEmpty())
                            <p class="mt-2 truncate text-xs text-slate-500" title="{{ $depts->implode(', ') }}">แผนก: {{ $depts->take(5)->implode(', ') }}{{ $depts->count() > 5 ? ' +'.($depts->count() - 5) : '' }}</p>
                        @endif
                    </div>

                    @edittemplates
                    <div class="relative z-10 mt-auto flex items-center gap-1 border-t border-slate-100 px-3 py-2 text-xs">
                        <a href="{{ route('cabinet-templates.show', $template) }}" class="rounded-md px-2 py-1 font-medium text-blue-600 hover:bg-blue-50">เปิด / แก้ไข</a>
                        <a href="{{ route('cabinet-templates.export', $template) }}" class="rounded-md px-2 py-1 text-slate-600 hover:bg-slate-100" title="Task | Sub Task | แผนก | Checklist | หมายเหตุ">Export Excel</a>
                        <form method="POST" action="{{ route('cabinet-templates.duplicate', $template) }}" onsubmit="return confirm('ทำสำเนาเทมเพลตนี้ทั้งหมด?')">@csrf
                            <button class="rounded-md px-2 py-1 text-slate-600 hover:bg-slate-100">ทำสำเนา</button></form>
                        <form method="POST" action="{{ route('cabinet-templates.destroy', $template) }}" class="ml-auto"
                              onsubmit="return confirm(@js('ลบเทมเพลต “'.$template->name.'” ทั้งหมด (Task / Sub Task / Checklist)?'.($template->cabinets_count ? "\n\nตู้ {$template->cabinets_count} ตู้ที่สร้างจากเทมเพลตนี้ยังอยู่ครบ (เป็นสำเนาแยกกัน)" : '')."\n\nกู้คืนไม่ได้"))">
                            @csrf @method('DELETE')
                            <button class="rounded-md px-2 py-1 text-red-600 hover:bg-red-50">ลบ</button>
                        </form>
                    </div>
                    @endedittemplates
                </div>
            @empty
                <div class="col-span-full rounded-xl border border-dashed border-slate-300 bg-white p-10 text-center text-sm text-slate-500">
                    ยังไม่มีเทมเพลต @edittemplates — <a href="{{ route('cabinet-templates.create') }}" class="text-blue-600 hover:underline">สร้างเทมเพลตแรก</a> หรือนำเข้าจาก Excel @endedittemplates
                </div>
            @endforelse
        </div>
        <p x-show="q" x-cloak class="text-xs text-slate-400">ค้นหา “<span x-text="q"></span>” — กดล้างช่องค้นหาเพื่อดูทั้งหมด</p>
        </div>
    </div>
</x-app-layout>
