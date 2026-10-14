{{-- Priority as Planning shows it: a red flag for ด่วน, orange for สูง; normal / low show nothing unless $always. --}}
@props(['priority', 'always' => false])
@php
    [$color, $label] = ['urgent' => ['#dc2626', 'ด่วน'], 'high' => ['#ea580c', 'สูง'], 'normal' => ['#94a3b8', 'ปกติ'], 'low' => ['#94a3b8', 'ต่ำ']][$priority] ?? ['#94a3b8', $priority];
@endphp
@if ($always || in_array($priority, ['urgent', 'high'], true))
    <span {{ $attributes->merge(['class' => 'inline-flex items-center gap-1 whitespace-nowrap text-xs font-semibold']) }} style="color: {{ $color }}">
        <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M5 3a1 1 0 0 1 1 1v.3c1.7-.9 3.6-.9 5.6 0 1.8.8 3.5.8 5.3-.1A1 1 0 0 1 18.3 5v8.2a1 1 0 0 1-.5.9c-2.4 1.3-4.9 1.4-7.2.4-1.6-.7-3.1-.7-4.6.1V20a1 1 0 1 1-2 0V4a1 1 0 0 1 1-1z"/></svg>{{ $label }}
    </span>
@endif
