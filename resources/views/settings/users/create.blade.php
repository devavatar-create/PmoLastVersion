<x-app-layout>
    <x-slot name="header">
        <div class="flex min-w-0 items-center gap-2">
            <x-back-link :href="route('settings.users.index')" label="ผู้ใช้งาน" />
            <h2 class="text-lg font-semibold text-slate-900">New User</h2>
        </div>
    </x-slot>

    <div class="max-w-none mx-auto">
        <x-settings-nav />
        <x-card>
            <form method="POST" action="{{ route('settings.users.store') }}">
                @csrf
                @php $user = null; @endphp
                @include('settings.users._form')
                <div class="mt-6 flex justify-end gap-3">
                    <a href="{{ route('settings.users.index') }}" class="px-4 py-2 text-sm font-medium text-slate-600">ยกเลิก</a>
                    <button type="submit" class="px-4 py-2 rounded-lg bg-blue-600 text-white text-sm font-semibold hover:bg-blue-700">Create User</button>
                </div>
            </form>
        </x-card>
    </div>
</x-app-layout>
