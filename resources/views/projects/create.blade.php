<x-app-layout>
    <x-slot name="header">
        <div class="flex min-w-0 items-center gap-2">
            <x-back-link :href="route('projects.index')" label="รายการโครงการ" />
            <h2 class="text-lg font-semibold text-slate-900">New Project</h2>
        </div>
    </x-slot>

    <div class="max-w-4xl mx-auto">
        <x-card>
            <form method="POST" action="{{ route('projects.store') }}">
                @csrf
                @php $project = null; @endphp
                @include('projects._form')

                <div class="mt-6 flex justify-end gap-3">
                    <a href="{{ route('projects.index') }}" class="px-4 py-2 text-sm font-medium text-slate-600 hover:text-slate-900">ยกเลิก</a>
                    <button type="submit" class="px-4 py-2 rounded-lg bg-blue-600 text-white text-sm font-semibold hover:bg-blue-700">สร้างโครงการ</button>
                </div>
            </form>
        </x-card>
    </div>
</x-app-layout>
