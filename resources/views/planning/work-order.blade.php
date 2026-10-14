<!doctype html>
<html lang="th">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>ใบสั่งงาน {{ $department->name }} {{ $date->thaiDate() }}</title>
    {{-- A plain sheet for paper: no app layout, no scripts; the browser's print dialog opens on load. --}}
    <style>
        :root { --ink: #0f172a; --muted: #64748b; --line: #cbd5e1; }
        * { box-sizing: border-box; }
        body { margin: 0; padding: 24px; font: 13px/1.5 system-ui, -apple-system, "Sarabun", sans-serif; color: var(--ink); background: #fff; }
        h1 { font-size: 20px; margin: 0; }
        .meta { color: var(--muted); margin: 4px 0 16px; }
        .project { border: 1px solid var(--line); border-radius: 8px; padding: 10px 12px; margin-bottom: 12px; break-inside: avoid; }
        .project h2 { font-size: 15px; margin: 0 0 6px; }
        .row { display: flex; gap: 8px; align-items: baseline; padding: 4px 0; border-top: 1px dashed var(--line); }
        .row:first-of-type { border-top: 0; }
        .mo { width: 110px; font-weight: 600; flex: none; }
        .due { margin-left: auto; color: var(--muted); white-space: nowrap; }
        .late { color: #b91c1c; font-weight: 600; }
        ul { list-style: none; margin: 2px 0 0 118px; padding: 0; }
        li::before { content: "☐ "; } li.done::before { content: "☑ "; }
        .sign { display: flex; gap: 48px; margin-top: 32px; }
        .sign div { flex: 1; border-top: 1px solid var(--ink); padding-top: 4px; text-align: center; color: var(--muted); }
        .empty { color: var(--muted); padding: 24px 0; }
        @media print { body { padding: 0; } .noprint { display: none; } }
    </style>
</head>
<body onload="window.print()">
    <p class="noprint"><button onclick="window.print()">พิมพ์</button> <button onclick="window.close()">ปิด</button></p>
    <h1>ใบสั่งงาน · {{ $department->name }}</h1>
    <p class="meta">งานที่ครบกำหนดภายใน {{ $date->thaiDate() }} (รวมงานที่เลยกำหนด) · พิมพ์โดย {{ $user->name }} {{ now()->thaiDateTime() }}</p>

    @forelse ($groups as $rows)
        @php $project = $rows->first()->cabinetTask->cabinet->project; @endphp
        <section class="project">
            <h2>{{ $project->project_no }} · {{ $project->project_name }}</h2>
            @foreach ($rows as $s)
                <div class="row">
                    <span class="mo">{{ $s->cabinetTask->cabinet->mo_no }}</span>
                    <span>{{ $s->cabinetTask->cabinet->cabinet_name }} — {{ $s->name }}</span>
                    <span class="due {{ $s->due_date && $s->due_date->lt($date) ? 'late' : '' }}">ส่ง {{ $s->due_date?->thaiDate() }}</span>
                </div>
                @if ($s->checklists->isNotEmpty())
                    <ul>
                        @foreach ($s->checklists->sortBy('sequence') as $c)
                            <li class="{{ $c->is_completed ? 'done' : '' }}">{{ $c->name }}</li>
                        @endforeach
                    </ul>
                @endif
            @endforeach
        </section>
    @empty
        <p class="empty">ไม่มีงานค้างของแผนกนี้ภายในวันที่เลือก</p>
    @endforelse

    <div class="sign"><div>ผู้สั่งงาน</div><div>ผู้รับงาน</div><div>วันที่</div></div>
</body>
</html>
