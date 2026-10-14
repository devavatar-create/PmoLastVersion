<x-app-layout>
    <x-slot name="header">
        <h2 class="text-lg font-semibold text-slate-900">ตั้งค่า</h2>
    </x-slot>

    <div class="max-w-none mx-auto space-y-6">
        <x-settings-nav />
        <p class="text-sm text-slate-500">โครงการ ตู้ไฟฟ้า Task และ Sub Task ที่ถูกลบจะอยู่ที่นี่ พร้อมงาน/Checklist/ประวัติทั้งหมดใต้รายการนั้น กู้คืนได้ตลอด</p>

        <x-card title="โครงการที่ถูกลบ">
            <div class="divide-y divide-slate-100 -mx-5 -mb-5">
                @forelse ($projects as $project)
                    <div class="px-5 py-3 flex items-center justify-between gap-3">
                        <div class="min-w-0">
                            <p class="font-medium text-slate-800 truncate">{{ $project->project_no }} · {{ $project->project_name }}</p>
                            <p class="text-xs text-slate-400">ลบเมื่อ {{ $project->deleted_at->thaiDateTime() }} · {{ $project->cabinets_count }} ตู้ที่ลบไปพร้อมกัน</p>
                        </div>
                        <form method="POST" action="{{ route('trash.projects.restore', $project->id) }}" onsubmit="return confirm('กู้คืนโครงการนี้พร้อมงานทั้งหมดใต้โครงการ?')">
                            @csrf
                            <button class="text-sm font-medium text-blue-600 hover:underline">กู้คืน</button>
                        </form>
                    </div>
                @empty
                    <p class="px-5 py-6 text-center text-slate-400 text-sm">ไม่มีโครงการที่ถูกลบ</p>
                @endforelse
            </div>
        </x-card>

        <x-card title="ตู้ไฟฟ้าที่ถูกลบ">
            <div class="divide-y divide-slate-100 -mx-5 -mb-5">
                @forelse ($cabinets as $cabinet)
                    <div class="px-5 py-3 flex items-center justify-between gap-3">
                        <div class="min-w-0">
                            <p class="font-medium text-slate-800 truncate">{{ $cabinet->mo_no }} · {{ $cabinet->cabinet_name }}</p>
                            <p class="text-xs text-slate-400">โครงการ {{ $cabinet->project->project_no }} · ลบเมื่อ {{ $cabinet->deleted_at->thaiDateTime() }}</p>
                        </div>
                        <form method="POST" action="{{ route('trash.cabinets.restore', $cabinet->id) }}" onsubmit="return confirm('กู้คืนตู้นี้พร้อมงานทั้งหมดในตู้?')">
                            @csrf
                            <button class="text-sm font-medium text-blue-600 hover:underline">กู้คืน</button>
                        </form>
                    </div>
                @empty
                    <p class="px-5 py-6 text-center text-slate-400 text-sm">ไม่มีตู้ที่ถูกลบ</p>
                @endforelse
            </div>
        </x-card>

        <x-card title="Task ที่ถูกลบ">
            <div class="divide-y divide-slate-100 -mx-5 -mb-5">
                @forelse ($tasks as $task)
                    <div class="px-5 py-3 flex items-center justify-between gap-3">
                        <div class="min-w-0">
                            <p class="font-medium text-slate-800 truncate">{{ $task->name }}</p>
                            <p class="text-xs text-slate-400">ตู้ {{ $task->cabinet->mo_no }} · โครงการ {{ $task->cabinet->project->project_no }} · ลบเมื่อ {{ $task->deleted_at->thaiDateTime() }} · {{ $task->subtasks_count }} Sub Task ที่ลบไปพร้อมกัน</p>
                        </div>
                        <form method="POST" action="{{ route('trash.tasks.restore', $task->id) }}" onsubmit="return confirm('กู้คืน Task นี้พร้อม Sub Task และ Checklist ที่ลบไปพร้อมกัน?')">
                            @csrf
                            <button class="text-sm font-medium text-blue-600 hover:underline">กู้คืน</button>
                        </form>
                    </div>
                @empty
                    <p class="px-5 py-6 text-center text-slate-400 text-sm">ไม่มี Task ที่ถูกลบ</p>
                @endforelse
            </div>
        </x-card>

        <x-card title="Sub Task ที่ถูกลบ">
            <div class="divide-y divide-slate-100 -mx-5 -mb-5">
                @forelse ($subtasks as $subtask)
                    <div class="px-5 py-3 flex items-center justify-between gap-3">
                        <div class="min-w-0">
                            <p class="font-medium text-slate-800 truncate">{{ $subtask->name }}</p>
                            <p class="text-xs text-slate-400">{{ $subtask->cabinetTask->name }} · ตู้ {{ $subtask->cabinetTask->cabinet->mo_no }} · โครงการ {{ $subtask->cabinetTask->cabinet->project->project_no }}{{ $subtask->department ? ' · แผนก '.$subtask->department->name : '' }} · ลบเมื่อ {{ $subtask->deleted_at->thaiDateTime() }}</p>
                        </div>
                        <form method="POST" action="{{ route('trash.subtasks.restore', $subtask->id) }}" onsubmit="return confirm('กู้คืน Sub Task นี้พร้อม Checklist?')">
                            @csrf
                            <button class="text-sm font-medium text-blue-600 hover:underline">กู้คืน</button>
                        </form>
                    </div>
                @empty
                    <p class="px-5 py-6 text-center text-slate-400 text-sm">ไม่มี Sub Task ที่ถูกลบ</p>
                @endforelse
            </div>
        </x-card>
    </div>
</x-app-layout>
