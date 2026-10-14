{{-- The one tab bar every Settings page shares (Avatar Planning's settings window, as separate Laravel pages).
     Everyone gets "การแจ้งเตือน" and the templates; the rest is admin-only (the routes enforce it, this only hides what you cannot open). --}}
@php
    $u = auth()->user();
    $tabs = array_filter([
        $u?->isAdmin() ? ['ผู้ใช้งาน', route('settings.users.index'), 'settings.users.*'] : null,
        $u?->isAdmin() ? ['แผนก', route('settings.departments.index'), 'settings.departments.*'] : null,
        $u?->isAdmin() ? ['สิทธิ์การใช้งาน', route('settings.permissions.edit'), 'settings.permissions.*'] : null,
        $u?->isAdmin() ? ['ประเภทงาน', route('settings.job-types.index'), 'settings.job-types.*'] : null,
        $u?->allows('page.templates') ? ['เทมเพลตตู้/เช็คลิสต์', route('cabinet-templates.index'), 'cabinet-templates.*'] : null,   // Settings > สิทธิ์การใช้งาน
        $u?->isAdmin() ? ['กฎสถานะ', route('settings.status-rules.index'), 'settings.status-rules.*'] : null,
        $u?->isAdmin() ? ['Automation', route('settings.automation.index'), 'settings.automation.*'] : null,
        $u?->isAdmin() ? ['สถานะงาน', route('settings.statuses.edit'), 'settings.statuses.*'] : null,
        $u?->isAdmin() ? ['แท็ก', route('settings.labels.index'), 'settings.labels.*'] : null,
        $u?->isAdmin() ? ['บอร์ด', route('settings.boards.index'), 'settings.boards.*'] : null,
        $u?->isAdmin() ? ['ฟิลด์เพิ่มเติม', route('settings.fields.index'), 'settings.fields.*'] : null,
        $u?->isAdmin() ? ['การส่งต่องาน', route('settings.handoffs.index'), 'settings.handoffs.*'] : null,
        $u?->isAdmin() ? ['ไฟล์และโฟลเดอร์', route('settings.files.edit'), 'settings.files.*'] : null,
        $u?->isAdmin() ? ['กฎการทำงาน', route('settings.rules.edit'), 'settings.rules.*'] : null,
        ['การแจ้งเตือน', route('settings.notifications.edit'), 'settings.notifications.*'],
        $u?->canManageTrash() ? ['ถังขยะ', route('trash.index'), 'trash.*'] : null,
    ]);
@endphp
<nav class="mb-5 flex flex-wrap gap-1 rounded-xl border border-slate-200 bg-white p-1.5 text-sm" aria-label="ตั้งค่า">
    @foreach ($tabs as [$label, $url, $pattern])
        <a href="{{ $url }}" @if (request()->routeIs($pattern)) aria-current="page" @endif
           class="rounded-lg px-3 py-1.5 font-medium transition {{ request()->routeIs($pattern) ? 'bg-slate-800 text-white' : 'text-slate-600 hover:bg-slate-100' }}">{{ $label }}</a>
    @endforeach
</nav>
