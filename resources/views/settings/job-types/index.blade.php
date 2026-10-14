<x-app-layout>
    <x-slot name="header">
        <h2 class="text-lg font-semibold text-slate-900">ตั้งค่า</h2>
    </x-slot>

    <div class="max-w-none mx-auto space-y-4">
        <x-settings-nav />
        <x-card title="Job Types">
            <x-slot name="actions">
                <button type="button" x-data @click="$dispatch('open-modal', 'add-job-type')" class="text-sm font-medium text-blue-600 hover:underline">+ Add Job Type</button>
            </x-slot>

            <div class="divide-y divide-slate-100 -mx-5 -mb-5">
                @foreach ($jobTypes as $jobType)
                    <div class="px-5 py-3 flex items-center justify-between">
                        <div>
                            <p class="font-medium text-slate-800">{{ $jobType->name }}</p>
                            @if ($jobType->description)
                                <p class="text-xs text-slate-500">{{ $jobType->description }}</p>
                            @endif
                            <p class="text-xs text-slate-400 mt-0.5">{{ $jobType->projects_count }} โครงการ</p>
                        </div>
                        <div class="flex items-center gap-3">
                            <button type="button" x-data @click="$dispatch('open-modal', 'edit-job-type-{{ $jobType->id }}')" class="text-xs font-medium text-blue-600 hover:underline">Edit</button>
                            <form method="POST" action="{{ route('settings.job-types.destroy', $jobType) }}" onsubmit="return confirm('ลบประเภทงานนี้?')">
                                @csrf @method('DELETE')
                                <button class="text-xs font-medium text-red-600 hover:underline">Delete</button>
                            </form>
                        </div>
                    </div>
                @endforeach
            </div>
        </x-card>
    </div>

    <x-modal name="add-job-type">
        <form method="POST" action="{{ route('settings.job-types.store') }}" class="p-6">
            @csrf
            <h3 class="text-base font-semibold text-slate-900 mb-4">Add Job Type</h3>
            <div class="space-y-4">
                <div>
                    <x-input-label value="Name" />
                    <x-text-input name="name" class="mt-1 block w-full" required />
                </div>
                <div>
                    <x-input-label value="Description" />
                    <textarea name="description" rows="2" class="mt-1 block w-full rounded-lg border-slate-300 text-sm"></textarea>
                </div>
            </div>
            <div class="mt-6 flex justify-end gap-3">
                <button type="button" x-data @click="$dispatch('close-modal', 'add-job-type')" class="px-4 py-2 text-sm font-medium text-slate-600">ยกเลิก</button>
                <button type="submit" class="px-4 py-2 rounded-lg bg-blue-600 text-white text-sm font-semibold hover:bg-blue-700">Add</button>
            </div>
        </form>
    </x-modal>

    @foreach ($jobTypes as $jobType)
        <x-modal :name="'edit-job-type-'.$jobType->id">
            <form method="POST" action="{{ route('settings.job-types.update', $jobType) }}" class="p-6">
                @csrf @method('PUT')
                <h3 class="text-base font-semibold text-slate-900 mb-4">Edit Job Type</h3>
                <div class="space-y-4">
                    <div>
                        <x-input-label value="Name" />
                        <x-text-input name="name" class="mt-1 block w-full" :value="$jobType->name" required />
                    </div>
                    <div>
                        <x-input-label value="Description" />
                        <textarea name="description" rows="2" class="mt-1 block w-full rounded-lg border-slate-300 text-sm">{{ $jobType->description }}</textarea>
                    </div>
                    <label class="flex items-center gap-2 text-sm text-slate-600">
                        <input type="checkbox" name="is_active" value="1" @checked($jobType->is_active) class="rounded border-slate-300 text-blue-600">
                        Active
                    </label>
                </div>
                <div class="mt-6 flex justify-end gap-3">
                    <button type="button" x-data @click="$dispatch('close-modal', 'edit-job-type-{{ $jobType->id }}')" class="px-4 py-2 text-sm font-medium text-slate-600">ยกเลิก</button>
                    <button type="submit" class="px-4 py-2 rounded-lg bg-blue-600 text-white text-sm font-semibold hover:bg-blue-700">Save</button>
                </div>
            </form>
        </x-modal>
    @endforeach
</x-app-layout>
