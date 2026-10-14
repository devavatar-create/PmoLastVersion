<x-app-layout>
    <x-slot name="header">
        <h2 class="text-lg font-semibold text-slate-900">การแจ้งเตือน</h2>
    </x-slot>

    <div class="max-w-3xl mx-auto">
        <div class="mb-3 flex gap-1 text-sm">
            <a href="{{ route('notifications.index') }}" class="rounded-lg px-3 py-1.5 font-medium {{ $unreadOnly ? 'text-slate-600 hover:bg-slate-100' : 'bg-slate-800 text-white' }}">ทั้งหมด</a>
            <a href="{{ route('notifications.index', ['filter' => 'unread']) }}" class="rounded-lg px-3 py-1.5 font-medium {{ $unreadOnly ? 'bg-slate-800 text-white' : 'text-slate-600 hover:bg-slate-100' }}">ยังไม่อ่าน ({{ $unreadCount }})</a>
            <a href="{{ route('settings.notifications.edit') }}" class="ml-auto rounded-lg px-3 py-1.5 text-slate-500 hover:bg-slate-100">ตั้งค่าการแจ้งเตือน</a>
        </div>
        <x-card title="การแจ้งเตือนของฉัน">
            <x-slot name="actions">
                @if (auth()->user()->unreadNotifications()->exists())
                    <form method="POST" action="{{ route('notifications.read-all') }}">
                        @csrf
                        <button class="text-sm font-medium text-blue-600 hover:underline">อ่านทั้งหมดแล้ว</button>
                    </form>
                @endif
            </x-slot>

            <div class="divide-y divide-slate-100 -mx-5 -mb-5">
                @forelse ($notifications as $n)
                    <form method="POST" action="{{ route('notifications.read', $n->id) }}">
                        @csrf
                        <button class="w-full text-left px-5 py-3 flex items-start gap-3 hover:bg-slate-50">
                            <span class="mt-1.5 w-2 h-2 rounded-full shrink-0 {{ $n->read_at ? 'bg-transparent' : 'bg-blue-500' }}"></span>
                            <span class="min-w-0 flex-1">
                                <span class="block text-sm {{ $n->read_at ? 'text-slate-600' : 'font-semibold text-slate-900' }}">{{ $n->data['title'] }}</span>
                                <span class="block text-xs text-slate-600">{{ $n->data['body'] }}</span>
                                @if (! empty($n->data['latest']))
                                    <span class="block text-[11px] text-slate-400 truncate">ล่าสุด: {{ $n->data['latest'] }}</span>
                                @endif
                                <span class="block text-[11px] text-slate-400 mt-0.5">{{ $n->created_at->thaiDateTime() }}</span>
                            </span>
                        </button>
                    </form>
                @empty
                    <p class="px-5 py-6 text-center text-slate-400 text-sm">{{ $unreadOnly ? 'อ่านครบหมดแล้ว' : 'ยังไม่มีการแจ้งเตือน' }}</p>
                @endforelse
            </div>
            @if ($notifications->hasPages())
                <div class="mt-4 -mx-5 -mb-5 px-5 pb-2">{{ $notifications->links() }}</div>
            @endif
        </x-card>
    </div>
</x-app-layout>
