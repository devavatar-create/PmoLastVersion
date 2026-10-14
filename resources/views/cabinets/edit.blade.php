<x-app-layout>
    <x-slot name="header">
        <div class="flex min-w-0 items-center gap-2">
            <x-back-link :href="route('cabinets.show', $cabinet)" :label="$cabinet->mo_no" />
            <h2 class="text-lg font-semibold text-slate-900">Edit Cabinet — {{ $cabinet->mo_no }}</h2>
        </div>
    </x-slot>

    <div class="max-w-3xl mx-auto">
        <x-card>
            <form method="POST" action="{{ route('cabinets.update', $cabinet) }}">
                @csrf @method('PUT')
                <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                    <div>
                        <x-input-label value="MO Number" />
                        <x-text-input name="mo_no" class="mt-1 block w-full" :value="old('mo_no', $cabinet->mo_no)" required />
                        <x-input-error :messages="$errors->get('mo_no')" class="mt-1" />
                    </div>
                    <div>
                        <x-input-label value="Cabinet Name" />
                        <x-text-input name="cabinet_name" class="mt-1 block w-full" :value="old('cabinet_name', $cabinet->cabinet_name)" required />
                    </div>
                    <div>
                        <x-input-label value="Cabinet Type" />
                        <x-text-input name="cabinet_type" class="mt-1 block w-full" :value="old('cabinet_type', $cabinet->cabinet_type)" />
                    </div>
                    <div>
                        <x-input-label value="ขนาดตู้" />
                        <x-text-input name="size" class="mt-1 block w-full" placeholder="เช่น 600x800x2000 มม." :value="old('size', $cabinet->size)" />
                    </div>
                    <div>
                        <x-input-label value="Start Date" />
                        <x-text-input type="date" name="start_date" class="mt-1 block w-full" :value="old('start_date', $cabinet->start_date?->format('Y-m-d'))" />
                    </div>
                    <div>
                        <x-input-label value="Due Date" />
                        <x-text-input type="date" name="due_date" class="mt-1 block w-full" :value="old('due_date', $cabinet->due_date?->format('Y-m-d'))" />
                    </div>
                    <div>
                        <x-input-label value="วันคาดว่าจะเสร็จ" />
                        <x-text-input type="date" name="expected_completion_date" class="mt-1 block w-full" :value="old('expected_completion_date', $cabinet->expected_completion_date?->format('Y-m-d'))" />
                        <p class="text-xs text-slate-400 mt-1">ต้องไม่เกินวัน Due Date</p>
                        <x-input-error :messages="$errors->get('expected_completion_date')" class="mt-1" />
                    </div>
                    <div class="md:col-span-2">
                        <x-input-label value="Description" />
                        <textarea name="description" rows="3" class="mt-1 block w-full rounded-lg border-slate-300 text-sm">{{ old('description', $cabinet->description) }}</textarea>
                    </div>
                </div>

                <div class="mt-6 flex justify-end gap-3">
                    <a href="{{ route('cabinets.show', $cabinet) }}" class="px-4 py-2 text-sm font-medium text-slate-600 hover:text-slate-900">ยกเลิก</a>
                    <button type="submit" class="px-4 py-2 rounded-lg bg-blue-600 text-white text-sm font-semibold hover:bg-blue-700">บันทึกการแก้ไข</button>
                </div>
            </form>
        </x-card>
    </div>
</x-app-layout>
