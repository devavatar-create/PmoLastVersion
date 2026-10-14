<x-app-layout>
    <x-slot name="header">
        <div class="flex min-w-0 items-center gap-2">
            <x-back-link :href="route('cabinet-templates.index')" label="เทมเพลตทั้งหมด" />
            <h2 class="text-lg font-semibold text-slate-900">New Cabinet Template</h2>
        </div>
    </x-slot>

    <div class="max-w-none mx-auto">
        <x-settings-nav />
        <x-card>
            <form method="POST" action="{{ route('cabinet-templates.store') }}">
                @csrf
                <div class="space-y-4">
                    <div>
                        <x-input-label value="Template Name" />
                        <x-text-input name="name" class="mt-1 block w-full" :value="old('name')" required placeholder="e.g. MCC Cabinet Template" />
                        <x-input-error :messages="$errors->get('name')" class="mt-1" />
                    </div>
                    <div>
                        <x-input-label value="Code" />
                        <x-text-input name="code" class="mt-1 block w-full" :value="old('code')" required placeholder="e.g. MCC" />
                        <x-input-error :messages="$errors->get('code')" class="mt-1" />
                    </div>
                    <div>
                        <x-input-label value="Description" />
                        <textarea name="description" rows="3" class="mt-1 block w-full rounded-lg border-slate-300 text-sm">{{ old('description') }}</textarea>
                    </div>
                </div>
                <p class="text-xs text-slate-400 mt-4">หลังสร้างเทมเพลตแล้ว คุณสามารถเพิ่ม Task / Sub Task / Checklist ได้จากหน้ารายละเอียดเทมเพลต</p>
                <div class="mt-6 flex justify-end gap-3">
                    <a href="{{ route('cabinet-templates.index') }}" class="px-4 py-2 text-sm font-medium text-slate-600">ยกเลิก</a>
                    <button type="submit" class="px-4 py-2 rounded-lg bg-blue-600 text-white text-sm font-semibold hover:bg-blue-700">สร้างเทมเพลต</button>
                </div>
            </form>
        </x-card>
    </div>
</x-app-layout>
