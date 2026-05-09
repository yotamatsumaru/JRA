{{-- DBビューア共通サイドバー --}}
@php
    $currentTable = $tableMeta['name'] ?? null;
    $currentRoute = request()->route()->getName();
    $grouped = collect($tables ?? [])->groupBy('group');
@endphp
<aside class="lg:w-60 lg:flex-shrink-0">
    <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm ring-1 ring-gray-100 dark:ring-gray-700 p-3 space-y-3 lg:sticky lg:top-20">
        <div class="space-y-1">
            <a href="{{ route('admin.db.index') }}"
               class="flex items-center gap-2 px-3 py-2 rounded text-sm {{ $currentRoute === 'admin.db.index' ? 'bg-primary-100 text-primary-800 font-semibold' : 'hover:bg-gray-100 dark:hover:bg-gray-700' }}">
                <x-icon name="home" class="w-4 h-4" /><span>トップ</span>
            </a>
            <a href="{{ route('admin.db.stats') }}"
               class="flex items-center gap-2 px-3 py-2 rounded text-sm {{ $currentRoute === 'admin.db.stats' ? 'bg-primary-100 text-primary-800 font-semibold' : 'hover:bg-gray-100 dark:hover:bg-gray-700' }}">
                <x-icon name="chart" class="w-4 h-4" /><span>統計</span>
            </a>
            <a href="{{ route('admin.db.schema') }}"
               class="flex items-center gap-2 px-3 py-2 rounded text-sm {{ $currentRoute === 'admin.db.schema' ? 'bg-primary-100 text-primary-800 font-semibold' : 'hover:bg-gray-100 dark:hover:bg-gray-700' }}">
                <x-icon name="map" class="w-4 h-4" /><span>ER図</span>
            </a>
        </div>

        <hr class="border-gray-200 dark:border-gray-700">

        @foreach ($grouped as $group => $items)
            <div>
                <div class="text-[10px] uppercase tracking-wide text-gray-400 dark:text-gray-500 px-2 mb-1">{{ $group }}</div>
                <div class="space-y-0.5">
                    @foreach ($items as $t)
                        <a href="{{ route('admin.db.table', $t->name) }}"
                           class="block px-3 py-1.5 rounded text-sm font-mono {{ $currentTable === $t->name ? 'bg-primary-100 text-primary-800 font-semibold' : 'hover:bg-gray-100 dark:hover:bg-gray-700 text-gray-700 dark:text-gray-300' }}">
                            <span class="text-xs text-gray-400">{{ $t->label }}</span><br>
                            <span class="text-[11px]">{{ $t->name }}</span>
                        </a>
                    @endforeach
                </div>
            </div>
        @endforeach
    </div>
</aside>
