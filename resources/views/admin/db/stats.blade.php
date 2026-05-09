@extends('layouts.app')
@section('title', 'DB 統計ダッシュボード')

@section('content')
<div class="flex flex-col lg:flex-row gap-4">
    @include('admin.db._sidebar')

    <div class="flex-1 min-w-0 space-y-4">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <div>
                <div class="text-xs text-gray-500">
                    <a href="{{ route('admin.db.index') }}" class="hover:underline">DBビューア</a>
                </div>
                <h1 class="inline-flex items-center gap-2 text-xl sm:text-2xl font-bold text-gray-800 dark:text-gray-100 mt-1">
                    <x-icon name="chart" class="w-6 h-6 text-primary-600" />
                    <span>統計ダッシュボード</span>
                </h1>
            </div>
        </div>

        {{-- 行数バーチャート --}}
        <div class="bg-white dark:bg-gray-800 rounded-lg shadow p-4">
            <h2 class="font-semibold text-gray-700 dark:text-gray-200 mb-3">テーブル別 行数</h2>
            <div id="chart-rows"></div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
            {{-- グループ別 --}}
            <div class="bg-white dark:bg-gray-800 rounded-lg shadow p-4">
                <h2 class="font-semibold text-gray-700 dark:text-gray-200 mb-3">グループ別 行数</h2>
                <div id="chart-group"></div>
            </div>

            {{-- トラック別 races --}}
            <div class="bg-white dark:bg-gray-800 rounded-lg shadow p-4">
                <h2 class="font-semibold text-gray-700 dark:text-gray-200 mb-3">races: トラック別</h2>
                @if (count($racesByTrack) > 0)
                    <div id="chart-track"></div>
                @else
                    <p class="text-sm text-gray-500 p-4 text-center">races テーブルが空です</p>
                @endif
            </div>
        </div>

        {{-- 月別 races --}}
        <div class="bg-white dark:bg-gray-800 rounded-lg shadow p-4">
            <h2 class="font-semibold text-gray-700 dark:text-gray-200 mb-3">races: 月別レース数推移</h2>
            @if (count($racesByMonth) > 0)
                <div id="chart-month"></div>
            @else
                <p class="text-sm text-gray-500 p-4 text-center">データがありません</p>
            @endif
        </div>

        {{-- 競馬場別 results --}}
        <div class="bg-white dark:bg-gray-800 rounded-lg shadow p-4">
            <h2 class="font-semibold text-gray-700 dark:text-gray-200 mb-3">race_results: 競馬場別</h2>
            @if (count($resultsByVenue) > 0)
                <div id="chart-venue"></div>
            @else
                <p class="text-sm text-gray-500 p-4 text-center">データがありません</p>
            @endif
        </div>

        {{-- 直近90日 results --}}
        <div class="bg-white dark:bg-gray-800 rounded-lg shadow p-4">
            <h2 class="font-semibold text-gray-700 dark:text-gray-200 mb-3">race_results: 直近90日推移</h2>
            @if (count($resultsByDay) > 0)
                <div id="chart-day"></div>
            @else
                <p class="text-sm text-gray-500 p-4 text-center">データがありません</p>
            @endif
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/apexcharts@3.45.2/dist/apexcharts.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', () => {
    const isDark = () => document.documentElement.classList.contains('dark');
    const themeMode = () => isDark() ? 'dark' : 'light';
    const grid = () => ({ borderColor: isDark() ? '#374151' : '#e5e7eb' });
    const palette = ['#16a34a','#dc8330','#6366f1','#f59e0b','#ec4899','#0ea5e9','#84cc16','#a855f7','#f43f5e','#14b8a6','#eab308','#3b82f6','#8b5cf6','#10b981'];

    new ApexCharts(document.querySelector('#chart-rows'), {
        chart: { type: 'bar', height: 380, toolbar: { show: false }, foreColor: isDark() ? '#9ca3af' : '#6b7280' },
        series: [{ name: '行数', data: @json(collect($rowCounts)->pluck('rows')->map(fn($v) => (int)$v)) }],
        xaxis: { categories: @json(collect($rowCounts)->map(fn($r) => $r['label'].' ('.$r['name'].')')) },
        plotOptions: { bar: { borderRadius: 4, horizontal: true, distributed: true } },
        legend: { show: false },
        colors: palette,
        grid: grid(),
        tooltip: { theme: themeMode() },
        dataLabels: { enabled: true, style: { fontSize: '11px' }, formatter: (v) => Number(v).toLocaleString() },
    }).render();

    new ApexCharts(document.querySelector('#chart-group'), {
        chart: { type: 'donut', height: 280, foreColor: isDark() ? '#9ca3af' : '#6b7280' },
        series: @json(array_values($byGroup)),
        labels: @json(array_keys($byGroup)),
        colors: ['#16a34a','#6366f1','#f59e0b','#ec4899'],
        legend: { position: 'bottom' },
        dataLabels: { enabled: true, formatter: (val) => val.toFixed(1) + '%' },
        tooltip: { theme: themeMode() },
    }).render();

    @if (count($racesByTrack) > 0)
    new ApexCharts(document.querySelector('#chart-track'), {
        chart: { type: 'donut', height: 280, foreColor: isDark() ? '#9ca3af' : '#6b7280' },
        series: @json(collect($racesByTrack)->pluck('cnt')->map(fn($v) => (int)$v)),
        labels: @json(collect($racesByTrack)->pluck('track')),
        colors: ['#16a34a','#dc8330','#9ca3af'],
        legend: { position: 'bottom' },
        dataLabels: { enabled: true, formatter: (val) => val.toFixed(1) + '%' },
        tooltip: { theme: themeMode() },
    }).render();
    @endif

    @if (count($racesByMonth) > 0)
    new ApexCharts(document.querySelector('#chart-month'), {
        chart: { type: 'area', height: 300, toolbar: { show: false }, foreColor: isDark() ? '#9ca3af' : '#6b7280' },
        series: [{ name: 'レース数', data: @json(collect($racesByMonth)->pluck('cnt')->map(fn($v) => (int)$v)) }],
        xaxis: { categories: @json(collect($racesByMonth)->pluck('ym')) },
        colors: ['#16a34a'],
        stroke: { curve: 'smooth', width: 3 },
        fill: { type: 'gradient', gradient: { opacityFrom: 0.6, opacityTo: 0.1 } },
        dataLabels: { enabled: false },
        grid: grid(),
        tooltip: { theme: themeMode() },
    }).render();
    @endif

    @if (count($resultsByVenue) > 0)
    new ApexCharts(document.querySelector('#chart-venue'), {
        chart: { type: 'bar', height: 320, toolbar: { show: false }, foreColor: isDark() ? '#9ca3af' : '#6b7280' },
        series: [{ name: 'レコード数', data: @json(collect($resultsByVenue)->pluck('cnt')->map(fn($v) => (int)$v)) }],
        xaxis: { categories: @json(collect($resultsByVenue)->pluck('venue')) },
        colors: ['#dc8330'],
        plotOptions: { bar: { borderRadius: 4, distributed: true } },
        legend: { show: false },
        grid: grid(),
        tooltip: { theme: themeMode() },
        dataLabels: { enabled: true, style: { fontSize: '11px' }, formatter: (v) => Number(v).toLocaleString() },
    }).render();
    @endif

    @if (count($resultsByDay) > 0)
    new ApexCharts(document.querySelector('#chart-day'), {
        chart: { type: 'line', height: 280, toolbar: { show: false }, foreColor: isDark() ? '#9ca3af' : '#6b7280' },
        series: [{ name: 'レコード数', data: @json(collect($resultsByDay)->pluck('cnt')->map(fn($v) => (int)$v)) }],
        xaxis: { categories: @json(collect($resultsByDay)->pluck('d')), labels: { rotate: -45, style: { fontSize: '10px' } } },
        colors: ['#6366f1'],
        stroke: { curve: 'smooth', width: 2 },
        dataLabels: { enabled: false },
        grid: grid(),
        tooltip: { theme: themeMode() },
        markers: { size: 3 },
    }).render();
    @endif
});
</script>
@endsection
