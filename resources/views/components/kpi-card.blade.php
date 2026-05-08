@props([
    'label' => '',
    'value' => 0,
    'icon' => 'chart',
    'color' => 'turf',  // turf | sand | gold | sky | rose | purple
    'href' => null,
    'subtext' => null,
])

@php
    $palette = [
        'turf'   => ['bg' => 'bg-turf-50 dark:bg-turf-900/30',     'text' => 'text-turf-700 dark:text-turf-300',     'icon' => 'text-turf-600 dark:text-turf-400',     'ring' => 'ring-turf-200 dark:ring-turf-800'],
        'sand'   => ['bg' => 'bg-sand-50 dark:bg-sand-900/30',     'text' => 'text-sand-700 dark:text-sand-300',     'icon' => 'text-sand-600 dark:text-sand-400',     'ring' => 'ring-sand-200 dark:ring-sand-800'],
        'gold'   => ['bg' => 'bg-gold-50 dark:bg-gold-900/30',     'text' => 'text-gold-700 dark:text-gold-300',     'icon' => 'text-gold-600 dark:text-gold-400',     'ring' => 'ring-gold-200 dark:ring-gold-800'],
        'sky'    => ['bg' => 'bg-sky-50 dark:bg-sky-900/30',       'text' => 'text-sky-700 dark:text-sky-300',       'icon' => 'text-sky-600 dark:text-sky-400',       'ring' => 'ring-sky-200 dark:ring-sky-800'],
        'rose'   => ['bg' => 'bg-rose-50 dark:bg-rose-900/30',     'text' => 'text-rose-700 dark:text-rose-300',     'icon' => 'text-rose-600 dark:text-rose-400',     'ring' => 'ring-rose-200 dark:ring-rose-800'],
        'purple' => ['bg' => 'bg-purple-50 dark:bg-purple-900/30', 'text' => 'text-purple-700 dark:text-purple-300', 'icon' => 'text-purple-600 dark:text-purple-400', 'ring' => 'ring-purple-200 dark:ring-purple-800'],
    ];
    $c = $palette[$color] ?? $palette['turf'];
    $tag = $href ? 'a' : 'div';
    $extraClass = $href ? 'hover:shadow-md transition-shadow cursor-pointer' : '';
@endphp

<{{ $tag }}
    @if ($href) href="{{ $href }}" @endif
    class="bg-white dark:bg-gray-800 rounded-lg shadow-sm ring-1 ring-gray-100 dark:ring-gray-700 p-4 flex items-start justify-between {{ $extraClass }}"
>
    <div class="flex-1 min-w-0">
        <div class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400 font-medium">{{ $label }}</div>
        <div class="mt-1 text-3xl font-bold {{ $c['text'] }} truncate">{{ is_numeric($value) ? number_format($value) : $value }}</div>
        @if ($subtext)
            <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ $subtext }}</div>
        @endif
    </div>
    <div class="ml-3 flex-shrink-0 w-10 h-10 rounded-lg {{ $c['bg'] }} ring-1 {{ $c['ring'] }} flex items-center justify-center">
        <x-icon :name="$icon" class="w-5 h-5 {{ $c['icon'] }}" />
    </div>
</{{ $tag }}>
