{{-- A project's PMO status the way Planning shows it: a dot in the status colour on a light tint of it. --}}
@props(['status'])
@php
    $color = \App\Services\PlanningBoard::statusColor($status);
    $label = ['draft' => 'Draft', 'planning' => 'Planning', 'in_progress' => 'In Progress', 'on_hold' => 'On Hold', 'completed' => 'Completed', 'cancelled' => 'Cancelled'][$status] ?? $status;
@endphp
<span {{ $attributes->merge(['class' => 'inline-flex items-center gap-1.5 whitespace-nowrap rounded-full px-2.5 py-0.5 text-xs font-semibold']) }}
      style="background: {{ $color }}1f; color: color-mix(in srgb, {{ $color }} 70%, #0f172a)">
    <span class="h-1.5 w-1.5 rounded-full" style="background: {{ $color }}"></span>{{ $label }}
</span>
