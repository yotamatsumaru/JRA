@extends('layouts.app')
@section('title', 'ダッシュボード')

@section('content')
<div class="space-y-6">

    <x-page-header title="ダッシュボード" subtitle="JRA 中央競馬データ分析" icon="home">
        <x-slot name="actions">
            <a href="{{ route('races.create') }}" class="inline-flex items-center space-x-1.5 bg-turf-600 hover:bg-turf-700 text-white px-4 py-2 rounded-md text-sm font-medium shadow-sm">
                <x-icon name="plus" class="w-4 h-4" />
                <span>レース登録</span>
            </a>
            <a href="{{ route('import.netkeiba') }}" class="inline-flex items-center space-x-1.5 bg-sand-600 hover:bg-sand-700 text-white px-4 py-2 rounded-md text-sm font-medium shadow-sm">
                <x-icon name="globe" class="w-4 h-4" />
                <span>netkeiba取込</span>
            </a>
        </x-slot>
    </x-page-header>

    {{-- KPI カード --}}
    <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
        <x-kpi-card label="登録レース" :value="$stats['races_total']" icon="flag" color="turf" :href="route('races.index')" />
        <x-kpi-card label="登録馬" :value="$stats['horses_total']" icon="sparkles" color="sand" :href="route('horses.index')" />
        <x-kpi-card label="現役騎手" :value="$stats['jockeys_total']" icon="user" color="gold" :href="route('jockeys.index')" />
        <x-kpi-card label="競馬場" :value="$stats['venues_total']" icon="map" color="rose" :href="route('venues.index')" />
    </div>

    {{-- グラフ2列 --}}
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">

        <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm ring-1 ring-gray-100 dark:ring-gray-700 p-4">
            <div class="flex items-center space-x-2 mb-3">
                <x-icon name="calendar" class="w-5 h-5 text-turf-600 dark:text-turf-400" />
                <h2 class="font-semibold text-gray-700 dark:text-gray-200">月別レース数（直近12か月）</h2>
            </div>
            <div id="chart-monthly"></div>
        </div>

        <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm ring-1 ring-gray-100 dark:ring-gray-700 p-4">
            <div class="flex items-center space-x-2 mb-3">
                <x-icon name="trophy" class="w-5 h-5 text-gold-500" />
                <h2 class="font-semibold text-gray-700 dark:text-gray-200">グレード別レース数</h2>
            </div>
            <div id="chart-grade"></div>
        </div>

        <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm ring-1 ring-gray-100 dark:ring-gray-700 p-4">
            <div class="flex items-center space-x-2 mb-3">
                <x-icon name="map" class="w-5 h-5 text-rose-500" />
                <h2 class="font-semibold text-gray-700 dark:text-gray-200">競馬場別レース数</h2>
            </div>
            <div id="chart-venue"></div>
        </div>

        <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm ring-1 ring-gray-100 dark:ring-gray-700 p-4">
            <div class="flex items-center space-x-2 mb-3">
                <x-icon name="user" class="w-5 h-5 text-sky-500" />
                <h2 class="font-semibold text-gray-700 dark:text-gray-200">トップ騎手（勝利数）</h2>
            </div>
            @if ($topJockeys->isEmpty())
                <x-empty-state icon="user" title="データがまだありません" message="レース結果を取り込むと表示されます" />
            @else
                <ol class="space-y-1 text-sm">
                    @foreach ($topJockeys as $i => $tj)
                        <li class="flex items-center justify-between border-b dark:border-gray-700 py-2">
                            <div class="flex items-center space-x-3">
                                <div class="w-7 h-7 rounded-full flex items-center justify-center font-bold text-xs
                                    @if($i === 0) bg-gold-100 dark:bg-gold-900/40 text-gold-700 dark:text-gold-300
                                    @elseif($i === 1) bg-gray-200 dark:bg-gray-600 text-gray-700 dark:text-gray-200
                                    @elseif($i === 2) bg-sand-100 dark:bg-sand-900/40 text-sand-700 dark:text-sand-300
                                    @else bg-gray-50 dark:bg-gray-700 text-gray-500 dark:text-gray-400 @endif">
                                    {{ $i + 1 }}
                                </div>
                                @if ($tj->jockey)
                                    <a href="{{ route('jockeys.show', $tj->jockey) }}" class="hover:text-turf-600 dark:hover:text-turf-400 dark:text-gray-200">{{ $tj->jockey->name }}</a>
                                @else
                                    <span class="text-gray-400">不明</span>
                                @endif
                            </div>
                            <span class="font-bold text-turf-700 dark:text-turf-400">{{ $tj->wins }}勝</span>
                        </li>
                    @endforeach
                </ol>
            @endif
        </div>
    </div>

    {{-- 最近のレース --}}
    <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm ring-1 ring-gray-100 dark:ring-gray-700 p-4">
        <div class="flex justify-between items-center mb-3">
            <div class="flex items-center space-x-2">
                <x-icon name="clock" class="w-5 h-5 text-turf-600 dark:text-turf-400" />
                <h2 class="font-semibold text-gray-700 dark:text-gray-200">最近登録したレース</h2>
            </div>
            <a href="{{ route('races.index') }}" class="text-sm text-turf-600 dark:text-turf-400 hover:underline flex items-center space-x-1">
                <span>すべて表示</span>
                <x-icon name="arrow-right" class="w-3 h-3" />
            </a>
        </div>
        @if ($stats['recent_races']->isEmpty())
            <x-empty-state
                icon="flag"
                title="まだレースが登録されていません"
                message="最初のレースを登録して分析を始めましょう"
                actionLabel="レースを登録"
                actionHref="{{ route('races.create') }}"
            />
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="text-xs text-gray-500 dark:text-gray-400 uppercase border-b dark:border-gray-700">
                        <tr>
                            <th class="text-left py-2 px-2">日付</th>
                            <th class="text-left py-2 px-2">場</th>
                            <th class="text-left py-2 px-2">R</th>
                            <th class="text-left py-2 px-2">レース名</th>
                            <th class="text-left py-2 px-2">距離</th>
                            <th class="text-right py-2 px-2">頭数</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($stats['recent_races'] as $r)
                        <tr class="border-b dark:border-gray-700 hover:bg-turf-50/50 dark:hover:bg-gray-700/50 transition-colors">
                            <td class="py-2 px-2 text-gray-700 dark:text-gray-300">{{ $r->race_date?->format('Y/m/d') }}</td>
                            <td class="py-2 px-2 dark:text-gray-300">{{ $r->venue?->name }}</td>
                            <td class="py-2 px-2 text-gray-600 dark:text-gray-400">{{ $r->race_number }}R</td>
                            <td class="py-2 px-2">
                                <a href="{{ route('races.show', $r) }}" class="text-turf-700 dark:text-turf-400 hover:underline font-medium">{{ $r->name }}</a>
                                @if ($r->grade)
                                    <span class="ml-1 text-xs bg-gold-100 dark:bg-gold-900/40 text-gold-700 dark:text-gold-300 px-1.5 py-0.5 rounded font-medium">{{ $r->grade }}</span>
                                @endif
                            </td>
                            <td class="py-2 px-2 text-gray-600 dark:text-gray-400">
                                <span class="@if($r->track_type === '芝') text-turf-700 dark:text-turf-400 @elseif($r->track_type === 'ダート') text-sand-700 dark:text-sand-400 @endif">
                                    {{ $r->track_type }}
                                </span>
                                {{ $r->distance }}m
                            </td>
                            <td class="py-2 px-2 text-right text-xs text-gray-500 dark:text-gray-400">{{ $r->results_count ?? 0 }}頭</td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
</div>

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', () => {

    // ダークモード判定（チャート色調整用）
    const isDark = () => document.documentElement.classList.contains('dark');
    const themeMode = () => isDark() ? 'dark' : 'light';

    // 月別レース数
    new ApexCharts(document.querySelector('#chart-monthly'), {
        chart: { type: 'area', height: 250, toolbar: { show: false }, foreColor: isDark() ? '#9ca3af' : '#6b7280' },
        series: [{
            name: 'レース数',
            data: @json($byMonth->pluck('cnt'))
        }],
        xaxis: { categories: @json($byMonth->pluck('ym')) },
        colors: ['#16a34a'],
        stroke: { curve: 'smooth', width: 3 },
        fill: { type: 'gradient', gradient: { opacityFrom: 0.6, opacityTo: 0.1 } },
        dataLabels: { enabled: false },
        grid: { borderColor: isDark() ? '#374151' : '#e5e7eb' },
        tooltip: { theme: themeMode() },
    }).render();

    // グレード別
    new ApexCharts(document.querySelector('#chart-grade'), {
        chart: { type: 'bar', height: 250, toolbar: { show: false }, foreColor: isDark() ? '#9ca3af' : '#6b7280' },
        series: [{
            name: 'レース数',
            data: @json($byGrade->pluck('cnt'))
        }],
        xaxis: { categories: @json($byGrade->pluck('grade')) },
        colors: ['#f59e0b'],
        plotOptions: { bar: { borderRadius: 4, horizontal: false, distributed: false } },
        grid: { borderColor: isDark() ? '#374151' : '#e5e7eb' },
        tooltip: { theme: themeMode() },
        dataLabels: { enabled: false },
    }).render();

    // 競馬場別
    new ApexCharts(document.querySelector('#chart-venue'), {
        chart: { type: 'bar', height: 250, toolbar: { show: false }, foreColor: isDark() ? '#9ca3af' : '#6b7280' },
        series: [{
            name: 'レース数',
            data: @json($byVenue->pluck('cnt'))
        }],
        xaxis: { categories: @json($byVenue->pluck('name')) },
        colors: ['#dc8330'],
        plotOptions: { bar: { borderRadius: 4, horizontal: true } },
        grid: { borderColor: isDark() ? '#374151' : '#e5e7eb' },
        tooltip: { theme: themeMode() },
        dataLabels: { enabled: false },
    }).render();

});
</script>
@endpush

@endsection
