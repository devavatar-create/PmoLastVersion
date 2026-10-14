<x-app-layout>
    <x-slot name="header">
        <h2 class="text-lg font-semibold text-slate-900">งานแผนกของฉัน</h2>
    </x-slot>

    {{--
        Planning, written the way the rest of the app is: this Blade page + partials + one Alpine component
        (resources/js/planning-board.js). Left "งานที่ต้องเสร็จ" panel, one toolbar, เดือน / ไทม์ไลน์ / แดชบอร์ด, and a wide project modal.
        Data = PlanningBoard (controller@board), every edit = one operation through planning.sync (PlanningSync), so the
        permissions and workflow rules are PMO's own. Bleeds to the page edges (negative margins cancel layouts/app's padding).
    --}}
    @if (! empty($noDepartment))
        <x-card>
            <p class="text-sm text-slate-600">บัญชีของคุณยังไม่ได้ผูกกับแผนก จึงยังไม่มีงานให้แสดง กรุณาติดต่อผู้ดูแลระบบเพื่อกำหนดแผนกในหน้า Users</p>
        </x-card>
    @else
    {{-- the page's data as plain JSON (Thai kept as is, half the size of an escaped x-data attribute and quicker for the browser to read);
         JSON_HEX_TAG turns every < and > into \u003C / \u003E, so nothing inside can close this tag --}}
    <script type="application/json" id="planning-config">{!! json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_THROW_ON_ERROR) !!}</script>
    <div class="-m-4 sm:-m-6 lg:-m-8 flex flex-col lg:flex-row min-h-[calc(100vh-4rem)] bg-white"
         x-data="planningBoard(JSON.parse(document.getElementById('planning-config').textContent))" @keydown.escape.window="onEscape()">

        @include('planning.partials._left-panel')

        <div class="flex-1 min-w-0 flex flex-col">
            @include('planning.partials._toolbar')
            @include('planning.partials._month')
            @include('planning.partials._timeline')
            @include('planning.partials._dashboard')
        </div>

        @include('planning.partials._task-modal')
        @include('planning.partials._checklist-popover')
        @include('planning.partials._overlays')
    </div>

    {{-- the page's Alpine component: resources/js/planning-board.js (in the app bundle) --}}
    @endif
</x-app-layout>
