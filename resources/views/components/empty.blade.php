@props(['title', 'art' => 'platform'])
{{-- Empty states: a small Metro platform drawing, a line of copy, and an optional action. --}}
<div {{ $attributes->class('rounded-xl border border-dashed border-rule px-6 py-10 text-center') }}>
    <svg viewBox="0 0 160 56" class="mx-auto mb-3 h-12 w-auto text-mute" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true">
        @if ($art === 'platform')
            <path d="M8 46h144M20 46V30M140 46V30M14 30h132" />
            <path d="M44 22h72" stroke-dasharray="2 6" />
            <circle cx="80" cy="12" r="5" fill="var(--color-flag)" stroke="none" />
        @else
            <path d="M30 46h100M44 46V24l36-14 36 14v22" />
            <path d="M70 46V32h20v14" />
            <path d="M52 46v-4h56v4M58 42v-4h44v4" />
        @endif
    </svg>
    <p class="font-display text-lg font-bold text-ink">{{ $title }}</p>
    @if ($slot->isNotEmpty())<div class="mt-1 text-sm text-mute">{{ $slot }}</div>@endif
</div>
