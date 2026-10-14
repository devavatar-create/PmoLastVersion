<div class="sidebar-logo-row h-16 flex items-center gap-2 px-5 border-b border-white/10 shrink-0">
    <img src="{{ asset('images/logo.png') }}" alt="Avatar Electric" class="w-9 h-9 object-contain shrink-0">
    <div class="sidebar-label leading-tight">
        <p class="text-sm font-semibold text-white">Avatar Electric</p>
        <p class="text-[11px] text-slate-400">Project &amp; Production Tracking</p>
    </div>
</div>

<nav class="flex-1 overflow-y-auto py-4 px-3 space-y-1">
    @foreach ([
        ['route' => 'dashboard', 'perm' => 'page.dashboard', 'label' => 'แดชบอร์ด', 'icon' => 'M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6'],
        ['route' => 'planning.board', 'perm' => 'page.planning', 'label' => 'งานแผนกของฉัน', 'icon' => 'M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z'],
        ['route' => 'projects.index', 'perm' => 'page.projects', 'label' => 'โครงการ', 'icon' => 'M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z'],
    ] as $item)
        @continue (! auth()->user()?->allows($item['perm']))   {{-- Settings > สิทธิ์การใช้งาน --}}
        <a href="{{ route($item['route']) }}" title="{{ $item['label'] }}" aria-label="{{ $item['label'] }}"
           class="flex items-center gap-3 px-3 py-2 rounded-lg text-sm font-medium transition
                  {{ request()->routeIs($item['route'].'*') ? 'bg-blue-600/15 text-blue-200' : 'text-slate-300 hover:bg-white/5 hover:text-white' }}">
            <svg class="w-5 h-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="{{ $item['icon'] }}" /></svg>
            <span class="sidebar-label">{{ $item['label'] }}</span>
        </a>
    @endforeach

    {{-- Admin/PM only - the same role gate SubtaskAssignmentService enforces server-side. --}}
    @if (auth()->user()?->canDispatchWork() && auth()->user()->allows('page.assignments'))
        <a href="{{ route('assignments.index') }}" title="จ่ายงาน" aria-label="จ่ายงาน"
           class="flex items-center gap-3 px-3 py-2 rounded-lg text-sm font-medium transition
                  {{ request()->routeIs('assignments.*') ? 'bg-blue-600/15 text-blue-200' : 'text-slate-300 hover:bg-white/5 hover:text-white' }}">
            <svg class="w-5 h-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4" /></svg>
            <span class="sidebar-label">จ่ายงาน</span>
        </a>
    @endif

    @if (auth()->user()?->allows('page.cabinets'))
    <a href="{{ route('cabinets.index') }}" title="ตู้ไฟฟ้า" aria-label="ตู้ไฟฟ้า"
       class="flex items-center gap-3 px-3 py-2 rounded-lg text-sm font-medium transition
              {{ request()->routeIs('cabinets.*') ? 'bg-blue-600/15 text-blue-200' : 'text-slate-300 hover:bg-white/5 hover:text-white' }}">
        <svg class="w-5 h-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M5 3h14a1 1 0 011 1v16a1 1 0 01-1 1H5a1 1 0 01-1-1V4a1 1 0 011-1zM8 7h8M8 11h8M8 15h4" /></svg>
        <span class="sidebar-label">ตู้ไฟฟ้า</span>
    </a>
    @endif

    @if (auth()->user()?->canManageTrash())
        <a href="{{ route('trash.index') }}" title="ถังขยะ" aria-label="ถังขยะ"
           class="flex items-center gap-3 px-3 py-2 rounded-lg text-sm font-medium transition
                  {{ request()->routeIs('trash.*') ? 'bg-blue-600/15 text-blue-200' : 'text-slate-300 hover:bg-white/5 hover:text-white' }}">
            <svg class="w-5 h-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" /></svg>
            <span class="sidebar-label">ถังขยะ</span>
        </a>
    @endif

    {{-- One entry for all settings; the tab bar inside (<x-settings-nav>) shows each person only what they may open. --}}
    <a href="{{ route('settings.index') }}" title="ตั้งค่า" aria-label="ตั้งค่า"
       class="flex items-center gap-3 px-3 py-2 rounded-lg text-sm font-medium transition
              {{ request()->routeIs('settings.*') || request()->routeIs('cabinet-templates.*') ? 'bg-blue-600/15 text-blue-200' : 'text-slate-300 hover:bg-white/5 hover:text-white' }}">
        <svg class="w-5 h-5 shrink-0" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/><circle cx="12" cy="12" r="3"/></svg>
        <span class="sidebar-label">ตั้งค่า</span>
    </a>
</nav>

<div class="sidebar-label p-4 border-t border-white/10 text-[11px] text-slate-500">
    V1 &middot; Core Architecture
</div>
