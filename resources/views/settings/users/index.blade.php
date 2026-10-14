<x-app-layout>
    <x-slot name="header">
        <h2 class="text-lg font-semibold text-slate-900">ตั้งค่า</h2>
    </x-slot>

    <div class="max-w-none mx-auto space-y-4">
        <x-settings-nav />
        <div class="flex justify-end">
            <a href="{{ route('settings.users.create') }}" class="inline-flex items-center gap-2 px-4 py-2 rounded-lg bg-blue-600 text-white text-sm font-semibold hover:bg-blue-700">
                + New User
            </a>
        </div>

        <x-card class="!p-0">
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead>
                        <tr class="text-left text-xs font-semibold text-slate-500 uppercase tracking-wide border-b border-slate-100">
                            <th class="px-5 py-3">Name</th>
                            <th class="px-5 py-3">Username</th>
                            <th class="px-5 py-3">Role</th>
                            <th class="px-5 py-3">Department</th>
                            <th class="px-5 py-3">Active</th>
                            <th class="px-5 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach ($users as $user)
                            <tr class="hover:bg-blue-50">
                                <td class="px-5 py-3 font-medium text-slate-800">{{ $user->name }}</td>
                                <td class="px-5 py-3 text-slate-600">{{ $user->email }}</td>
                                <td class="px-5 py-3 text-slate-600">{{ str($user->role)->headline() }}</td>
                                <td class="px-5 py-3 text-slate-600">{{ $user->department?->name ?? '-' }}</td>
                                <td class="px-5 py-3">
                                    @if ($user->is_active)
                                        <span class="text-emerald-600 text-xs font-semibold">Active</span>
                                    @else
                                        <span class="text-slate-400 text-xs font-semibold">Inactive</span>
                                    @endif
                                </td>
                                <td class="px-5 py-3 text-right">
                                    <a href="{{ route('settings.users.edit', $user) }}" class="text-xs font-medium text-blue-600 hover:underline">Edit</a>
                                    <form method="POST" action="{{ route('settings.users.destroy', $user) }}" class="inline" onsubmit="return confirm('ลบผู้ใช้งานนี้?')">
                                        @csrf @method('DELETE')
                                        <button class="text-xs font-medium text-red-600 hover:underline ml-2">Delete</button>
                                    </form>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-card>

        {{ $users->links() }}
    </div>
</x-app-layout>
