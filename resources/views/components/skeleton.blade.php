@props([
    'type' => 'box',   // box | line | chart | table | card
    'lines' => 3,
    'rows' => 5,
    'height' => 'h-32',
])

@if ($type === 'box')
    <div {{ $attributes->merge(['class' => "skeleton {$height} w-full"]) }}></div>

@elseif ($type === 'line')
    <div class="space-y-2">
        @for ($i = 0; $i < $lines; $i++)
            <div class="skeleton h-4" style="width: {{ rand(60, 100) }}%"></div>
        @endfor
    </div>

@elseif ($type === 'chart')
    <div class="bg-white dark:bg-gray-800 rounded-lg shadow p-4">
        <div class="skeleton h-5 w-1/3 mb-4"></div>
        <div class="skeleton h-64 w-full"></div>
    </div>

@elseif ($type === 'card')
    <div class="bg-white dark:bg-gray-800 rounded-lg shadow p-4 space-y-2">
        <div class="skeleton h-3 w-1/4"></div>
        <div class="skeleton h-8 w-1/2"></div>
    </div>

@elseif ($type === 'table')
    <div class="bg-white dark:bg-gray-800 rounded-lg shadow p-4 space-y-3">
        <div class="skeleton h-5 w-1/4"></div>
        @for ($i = 0; $i < $rows; $i++)
            <div class="flex space-x-3">
                <div class="skeleton h-4 w-16"></div>
                <div class="skeleton h-4 flex-1"></div>
                <div class="skeleton h-4 w-20"></div>
            </div>
        @endfor
    </div>
@endif
