@props([
    'title',
    'subtitle' => null,
    'icon' => null,
    'breadcrumbs' => [],
])

<div {{ $attributes->merge(['class' => 'mb-6']) }}>
    @if (count($breadcrumbs) > 0)
        <nav class="flex items-center text-xs text-gray-500 dark:text-gray-400 mb-2 space-x-1">
            <a href="{{ route('dashboard') }}" class="hover:text-turf-600 dark:hover:text-turf-400 flex items-center">
                <x-icon name="home" class="w-3 h-3" />
            </a>
            @foreach ($breadcrumbs as $crumb)
                <x-icon name="chevron-right" class="w-3 h-3" />
                @if (!empty($crumb['href']))
                    <a href="{{ $crumb['href'] }}" class="hover:text-turf-600 dark:hover:text-turf-400">{{ $crumb['label'] }}</a>
                @else
                    <span class="text-gray-700 dark:text-gray-300">{{ $crumb['label'] }}</span>
                @endif
            @endforeach
        </nav>
    @endif

    <div class="flex items-center justify-between flex-wrap gap-3">
        <div class="flex items-center space-x-3">
            @if ($icon)
                <div class="w-10 h-10 rounded-lg bg-turf-100 dark:bg-turf-900/40 ring-1 ring-turf-200 dark:ring-turf-800 flex items-center justify-center">
                    <x-icon :name="$icon" class="w-5 h-5 text-turf-700 dark:text-turf-300" />
                </div>
            @endif
            <div>
                <h1 class="text-2xl font-bold text-gray-800 dark:text-gray-100">{{ $title }}</h1>
                @if ($subtitle)
                    <p class="text-sm text-gray-500 dark:text-gray-400">{{ $subtitle }}</p>
                @endif
            </div>
        </div>

        @if (isset($actions))
            <div class="flex items-center space-x-2">
                {{ $actions }}
            </div>
        @endif
    </div>
</div>
