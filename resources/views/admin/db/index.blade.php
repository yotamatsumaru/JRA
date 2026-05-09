@extends('layouts.app')
@section('title', 'DBビューア')

@section('content')
<div class="space-y-6">
    <div class="flex flex-wrap items-center justify-between gap-2">
        <h1 class="text-xl sm:text-2xl font-bold text-gray-800 dark:text-gray-100">🗄️ DBビューア</h1>
        <div class="text-xs text-gray-500">読み取り専用 / Schema: {{ config('database.connections.'.config('database.default').'.database') }}</div>
    </div>

    {{-- KPI --}}
    <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
        <div class="bg-white dark:bg-gray-800 rounded-lg shadow p-3">
            <div class="text-xs text-gray-500">テーブル数</div>
            <div class="text-2xl font-bold text-primary-700">{{ $totalTables }}</div>
        </div>
        <div class="bg-white dark:bg-gray-800 rounded-lg shadow p-3">
            <div class="text-xs text-gray-500">総行数</div>
            <div class="text-2xl font-bold text-emerald-600">{{ number_format($totalRows) }}</div>
        </div>
        <div class="bg-white dark:bg-gray-800 rounded-lg shadow p-3">
            <div class="text-xs text-gray-500">総カラム数</div>
            <div class="text-2xl font-bold text-amber-600">{{ $totalColumns }}</div>
        </div>
        <a href="{{ route('admin.db.stats') }}" class="bg-gradient-to-br from-primary-500 to-primary-700 text-white rounded-lg shadow p-3 hover:opacity-90">
            <div class="text-xs opacity-90">統計ダッシュボード</div>
            <div class="text-lg font-bold mt-1">📊 グラフで見る →</div>
        </a>
    </div>

    {{-- 行数バーチャート --}}
    <div class="bg-white dark:bg-gray-800 rounded-lg shadow p-4">
        <h2 class="font-semibold text-gray-700 dark:text-gray-200 mb-3">📊 テーブル別 行数</h2>
        <div id="chart-tables"></div>
    </div>

    {{-- グループ別カード一覧 --}}
    @foreach ($grouped as $group => $items)
    <div class="bg-white dark:bg-gray-800 rounded-lg shadow p-4">
        <h2 class="font-semibold text-gray-700 dark:text-gray-200 mb-3">📁 {{ $group }}</h2>
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-3">
            @foreach ($items as $t)
                <a href="{{ route('admin.db.table', $t->name) }}"
                   class="block bg-gray-50 dark:bg-gray-700/50 hover:bg-primary-50 dark:hover:bg-primary-900/30 ring-1 ring-gray-200 dark:ring-gray-600 rounded-lg p-3 transition">
                    <div class="flex items-baseline justify-between gap-2">
                        <div>
                            <div class="text-sm font-bold text-gray-800 dark:text-gray-100">{{ $t->label }}</div>
                            <div class="text-[11px] text-gray-500 font-mono">{{ $t->name }}</div>
                        </div>
                        <div class="text-right">
                            <div class="text-xl font-bold text-primary-700 dark:text-primary-300 font-mono">{{ number_format($t->rows) }}</div>
                            <div class="text-[10px] text-gray-500">行</div>
                        </div>
                    </div>
                    <div class="flex justify-between text-[10px] text-gray-500 mt-2 pt-2 border-t border-gray-200 dark:border-gray-600">
                        <span>{{ $t->columns }} 列</span>
                        <span>
                            @if ($t->latest_update)
                                {{ \Illuminate\Support\Carbon::parse($t->latest_update)->format('Y/m/d') }}
                            @else
                                -
                            @endif
                        </span>
                    </div>
                </a>
            @endforeach
        </div>
    </div>
    @endforeach
</div>

<script src="https://cdn.jsdelivr.net/npm/apexcharts@3.45.2/dist/apexcharts.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', () => {
    const isDark = () => document.documentElement.classList.contains('dark');
    const themeMode = () => isDark() ? 'dark' : 'light';

    new ApexCharts(document.querySelector('#chart-tables'), {
        chart: { type: 'bar', height: 380, toolbar: { show: false }, foreColor: isDark() ? '#9ca3af' : '#6b7280' },
        series: [{ name: '行数', data: @json(collect($rows)->pluck('rows')->map(fn($v) => (int)$v)) }],
        xaxis: { categories: @json(collect($rows)->map(fn($r) => $r->label.' ('.$r->name.')')) },
        plotOptions: { bar: { borderRadius: 4, horizontal: true, distributed: true } },
        legend: { show: false },
        colors: ['#16a34a','#dc8330','#6366f1','#f59e0b','#ec4899','#0ea5e9','#84cc16','#a855f7','#f43f5e','#14b8a6','#eab308','#3b82f6','#8b5cf6','#10b981'],
        grid: { borderColor: isDark() ? '#374151' : '#e5e7eb' },
        tooltip: { theme: themeMode() },
        dataLabels: { enabled: true, style: { fontSize: '11px' }, formatter: (v) => Number(v).toLocaleString() },
    }).render();
});
</script>
@endsection
