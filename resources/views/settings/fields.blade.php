<x-app-layout>
    <x-slot name="header">
        <h2 class="text-lg font-semibold text-slate-900">ตั้งค่า</h2>
    </x-slot>

    <div class="max-w-none mx-auto space-y-4">
        <x-settings-nav />

        @if (! $current)
            <x-card><p class="text-sm text-slate-500">ยังไม่มีแผนก — เพิ่มได้ที่แท็บ "แผนก"</p></x-card>
        @else
            <x-card title="ฟิลด์เพิ่มเติม (ต่อแผนก)" subtitle="แผนกกรอกในหน้า Planning › เลือกแผนกในโครงการ › ข้อมูลเพิ่มเติม (เช่น รถขนส่ง, ลิงก์แผนที่)">
                <div class="flex flex-wrap items-center gap-2 text-sm">
                    <span class="text-slate-500">แผนก</span>
                    @foreach ($departments as $d)
                        <a href="{{ route('settings.fields.index', ['department' => $d->id]) }}"
                           class="rounded-full border px-3 py-1 {{ $d->id === $current->id ? 'border-slate-800 bg-slate-800 text-white' : 'border-slate-300 text-slate-600 hover:bg-slate-50' }}">{{ $d->name }}</a>
                    @endforeach
                </div>
            </x-card>

            @forelse ($fields as $field)
                <x-card :title="$field->name" :subtitle="$types[$field->type]">
                    <x-slot name="actions">
                        <form method="POST" action="{{ route('settings.fields.chip', $field) }}" class="mr-3 inline">@csrf @method('PATCH')
                            <label class="inline-flex cursor-pointer items-center gap-1 text-xs text-slate-600"><input type="checkbox" onchange="this.form.submit()" @checked($field->show_on_chip) class="rounded border-slate-300 text-blue-600"> แสดงบนปฏิทิน</label>
                        </form>
                        <form method="POST" action="{{ route('settings.fields.destroy', $field) }}" onsubmit="return confirm('ลบฟิลด์ “{{ $field->name }}”? ตัวเลือกและค่าที่เลือกไว้ในโครงการทั้งหมดจะหาย')">
                            @csrf @method('DELETE')
                            <button class="text-xs font-medium text-red-600 hover:underline">ลบฟิลด์</button>
                        </form>
                    </x-slot>

                    @if ($field->type === 'select')
                        <div class="space-y-1">
                            @forelse ($field->options->groupBy(fn ($o) => $o->group_name ?? '') as $group => $options)
                                <div>
                                    @if ($group !== '') <p class="text-[11px] font-semibold uppercase tracking-wide text-slate-400 mt-2">{{ $group }}</p> @endif
                                    @foreach ($options as $option)
                                        <div class="flex items-center justify-between rounded-lg px-2 py-1 text-sm hover:bg-slate-50">
                                            <span class="text-slate-800">{{ $option->label }}</span>
                                            <form method="POST" action="{{ route('settings.field-options.destroy', $option) }}" onsubmit="return confirm('ลบตัวเลือกนี้? โครงการที่เลือกไว้จะถูกล้างค่า')">
                                                @csrf @method('DELETE')
                                                <button class="text-xs text-red-600 hover:underline">ลบ</button>
                                            </form>
                                        </div>
                                    @endforeach
                                </div>
                            @empty
                                <p class="text-sm text-slate-400">ยังไม่มีตัวเลือก</p>
                            @endforelse
                        </div>

                        <form method="POST" action="{{ route('settings.fields.options.store', $field) }}" class="mt-4 grid gap-2 border-t border-slate-100 pt-4 sm:grid-cols-[10rem_1fr_auto]">
                            @csrf
                            <input type="text" name="group_name" placeholder="กลุ่ม (เว้นว่างได้)" maxlength="100" class="rounded-lg border-slate-300 text-sm">
                            <textarea name="labels" rows="2" required placeholder="ตัวเลือก — หนึ่งบรรทัดต่อหนึ่งตัวเลือก" class="rounded-lg border-slate-300 text-sm"></textarea>
                            <button class="self-start rounded-lg bg-slate-800 px-3 py-2 text-sm font-semibold text-white hover:bg-slate-700">+ เพิ่ม</button>
                        </form>
                    @else
                        <p class="text-sm text-slate-500">แผนกพิมพ์{{ $field->type === 'link' ? 'ลิงก์ (http/https)' : 'ข้อความ' }}เองในแต่ละโครงการ</p>
                    @endif
                </x-card>
            @empty
                <x-card><p class="text-sm text-slate-400">แผนกนี้ยังไม่มีฟิลด์เพิ่มเติม</p></x-card>
            @endforelse

            <x-card title="เพิ่มฟิลด์ให้แผนก {{ $current->name }}">
                <form method="POST" action="{{ route('settings.fields.store') }}" class="grid gap-2 sm:grid-cols-[1fr_12rem_auto]">
                    @csrf
                    <input type="hidden" name="department_id" value="{{ $current->id }}">
                    <input type="text" name="name" required maxlength="100" placeholder="ชื่อฟิลด์ เช่น รถขนส่ง" class="rounded-lg border-slate-300 text-sm">
                    <select name="type" class="rounded-lg border-slate-300 text-sm">
                        @foreach ($types as $key => $label) <option value="{{ $key }}">{{ $label }}</option> @endforeach
                    </select>
                    <button class="rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-700">+ เพิ่มฟิลด์</button>
                </form>
            </x-card>
        @endif
    </div>
</x-app-layout>
