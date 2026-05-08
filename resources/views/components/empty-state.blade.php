@props([
    'icon' => 'info',
    'title' => 'データがありません',
    'message' => null,
    'actionLabel' => null,
    'actionHref' => null,
    'actionIcon' => 'plus',
])

<div {{ $attributes->merge(['class' => 'text-center py-12 px-4']) }}>
    <div class="mx-auto w-16 h-16 rounded-full bg-gray-100 dark:bg-gray-800 flex items-center justify-center">
        <x-icon :name="$icon" class="w-8 h-8 text-gray-400 dark:text-gray-500" />
    </div>
    <h3 class="mt-4 text-base font-semibold text-gray-700 dark:text-gray-200">{{ $title }}</h3>
    @if ($message)
        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ $message }}</p>
    @endif
    @if ($actionLabel && $actionHref)
        <div class="mt-4">
            <a href="{{ $actionHref }}" class="inline-flex items-center space-x-1.5 px-4 py-2 rounded-md bg-turf-600 hover:bg-turf-700 text-white text-sm font-medium shadow-sm">
                <x-icon :name="$actionIcon" class="w-4 h-4" />
                <span>{{ $actionLabel }}</span>
            </a>
        </div>
    @endif
</div>
