<x-app-layout>
    <x-slot name="header">
        <div class="flex min-w-0 items-center gap-2">
            <x-back-link :href="route('cabinet-templates.show', $template)" :label="$template->name" />
            <h2 class="text-lg font-semibold text-slate-900">Edit Template — {{ $template->name }}</h2>
        </div>
    </x-slot>

    <div class="max-w-none mx-auto">
        <x-settings-nav />
        <x-card>
            <form method="POST" action="{{ route('cabinet-templates.update', $template) }}">
                @csrf @method('PUT')
                <div class="space-y-4">
                    <div>
                        <x-input-label value="Template Name" />
                        <x-text-input name="name" class="mt-1 block w-full" :value="old('name', $template->name)" required />
                    </div>
                    <div>
                        <x-input-label value="Code" />
                        <x-text-input name="code" class="mt-1 block w-full" :value="old('code', $template->code)" required />
                    </div>
                    <div>
                        <x-input-label value="Description" />
                        <textarea name="description" rows="3" class="mt-1 block w-full rounded-lg border-slate-300 text-sm">{{ old('description', $template->description) }}</textarea>
                    </div>
                    <label class="flex items-center gap-2 text-sm text-slate-600">
                        <input type="checkbox" name="is_default" value="1" @checked($template->is_default) class="rounded border-slate-300 text-blue-600">
                        ตั้งเป็นเทมเพลตเริ่มต้น (Default) สำหรับตู้ใหม่
                    </label>
                    <label class="flex items-center gap-2 text-sm text-slate-600">
                        <input type="checkbox" name="is_active" value="1" @checked($template->is_active) class="rounded border-slate-300 text-blue-600">
                        เปิดใช้งาน (Active)
                    </label>
                </div>
                <div class="mt-6 flex justify-end gap-3">
                    <a href="{{ route('cabinet-templates.show', $template) }}" class="px-4 py-2 text-sm font-medium text-slate-600">ยกเลิก</a>
                    <button type="submit" class="px-4 py-2 rounded-lg bg-blue-600 text-white text-sm font-semibold hover:bg-blue-700">บันทึก</button>
                </div>
            </form>
        </x-card>
    </div>
</x-app-layout>
