@extends('layouts.app')
@section('title', '払戻傾向')

@section('content')
<div class="space-y-5">
    <x-page-header
        title="払戻傾向"
        subtitle="netkeiba取込済の全レース母集団から、券種別の配当傾向を可視化"
        icon="cash">
        <x-slot name="actions">
            <a href="{{ route('betting.dashboard') }}" class="inline-flex items-center space-x-1 bg-gray-200 hover:bg-gray-300 dark:bg-gray-700 dark:hover:bg-gray-600 text-gray-800 dark:text-gray-100 px-4 py-2 rounded text-sm">
                <x-icon name="chart" class="w-4 h-4" /><span>収支ダッシュボード</span>
            </a>
        </x-slot>
    </x-page-header>

    @if ($kindStats->isEmpty())
        <x-empty-state
            icon="cash"
            title="払戻データがまだありません"
            message="netkeibaから払戻データつきでレースを取込むと、ここに券種別の傾向が表示されます"
            actionLabel="netkeibaから取込"
            :actionHref="route('import.netkeiba')"
            actionIcon="globe" />
    @else

    {{-- 券種別 平均/最高/最低 配当 --}}
    <div class="bg-white dark:bg-gray-800 rounded-lg shadow p-5">
        <h2 class="text-sm font-semibold text-gray-700 dark:text-gray-200 mb-3">券種別 配当統計</h2>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 dark:bg-gray-700/50 text-xs text-gray-600 dark:text-gray-300">
                    <tr>
                        <th class="px-3 py-2 text-left">券種</th>
                        <th class="px-3 py-2 text-right">サンプル数</th>
                        <th class="px-3 py-2 text-right">平均配当</th>
                        <th class="px-3 py-2 text-right">最高配当</th>
                        <th class="px-3 py-2 text-right">最低配当</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                    @foreach ($kindStats as $r)
                    <tr class="{{ $r['kind'] === $kind ? 'bg-turf-50 dark:bg-turf-900/20' : '' }}">
                        <td class="px-3 py-2 font-medium">
                            <a href="{{ route('betting.payouts', ['kind' => $r['kind']]) }}" class="hover:text-turf-600 hover:underline">{{ $r['kind_label'] }}</a>
                        </td>
                        <td class="px-3 py-2 text-right tabular-nums">{{ number_format($r['cnt']) }}</td>
                        <td class="px-3 py-2 text-right tabular-nums font-bold text-gold-600">¥{{ number_format($r['avg']) }}</td>
                        <td class="px-3 py-2 text-right tabular-nums">¥{{ number_format($r['max']) }}</td>
                        <td class="px-3 py-2 text-right tabular-nums text-gray-500">¥{{ number_format($r['min']) }}</td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>

    {{-- 券種選択 --}}
    <form method="GET" class="bg-white dark:bg-gray-800 rounded-lg shadow-sm ring-1 ring-gray-100 dark:ring-gray-700 p-4 flex items-center space-x-3 text-sm">
        <span class="font-medium text-gray-700 dark:text-gray-200">詳細を見る券種:</span>
        <select name="kind" onchange="this.form.submit()" class="border rounded px-2 py-1.5 dark:bg-gray-700 dark:border-gray-600">
            @foreach (\App\Models\Bet::KIND_LABELS as $k => $label)
                <option value="{{ $k }}" @selected($kind === $k)>{{ $label }}</option>
            @endforeach
        </select>
    </form>

    {{-- 人気別払戻 + 配当帯分布 --}}
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
        <div class="bg-white dark:bg-gray-800 rounded-lg shadow p-5">
            <h2 class="text-sm font-semibold text-gray-700 dark:text-gray-200 mb-3">人気別 平均払戻 ({{ \App\Models\Bet::KIND_LABELS[$kind] ?? $kind }})</h2>
            @if ($popDist->isEmpty())
                <p class="text-sm text-gray-400">人気データなし</p>
            @else
                <div id="chart-pop" style="height:280px"></div>
            @endif
        </div>
        <div class="bg-white dark:bg-gray-800 rounded-lg shadow p-5">
            <h2 class="text-sm font-semibold text-gray-700 dark:text-gray-200 mb-3">配当帯別 レース数</h2>
            <div id="chart-band" style="height:280px"></div>
        </div>
    </div>

    {{-- 高額配当TOP --}}
    <div class="bg-white dark:bg-gray-800 rounded-lg shadow p-5">
        <h2 class="text-sm font-semibold text-gray-700 dark:text-gray-200 mb-3 flex items-center space-x-1">
            <x-icon name="trophy" class="w-4 h-4 text-gold-500" /><span>高額配当TOP20 ({{ \App\Models\Bet::KIND_LABELS[$kind] ?? $kind }})</span>
        </h2>
        @if ($highest->isEmpty())
            <p class="text-sm text-gray-400">データなし</p>
        @else
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 dark:bg-gray-700/50 text-xs text-gray-600 dark:text-gray-300">
                    <tr>
                        <th class="px-3 py-2 text-left">日付</th>
                        <th class="px-3 py-2 text-left">レース</th>
                        <th class="px-3 py-2 text-left">組合せ</th>
                        <th class="px-3 py-2 text-right">払戻</th>
                        <th class="px-3 py-2 text-right">人気</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                    @foreach ($highest as $p)
                    <tr>
                        <td class="px-3 py-2 text-xs text-gray-500">{{ $p->race?->race_date?->format('Y/m/d') }}</td>
                        <td class="px-3 py-2"><a href="{{ route('races.show', $p->race) }}" class="text-turf-600 hover:underline">{{ $p->race?->venue?->name }} {{ $p->race?->race_number }}R</a></td>
                        <td class="px-3 py-2 font-mono">{{ $p->combination }}</td>
                        <td class="px-3 py-2 text-right tabular-nums text-gold-600 font-bold">¥{{ number_format($p->amount) }}</td>
                        <td class="px-3 py-2 text-right tabular-nums">{{ $p->popularity ? $p->popularity.'番人気' : '-' }}</td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @endif
    </div>

    @endif
</div>

<script src="https://cdn.jsdelivr.net/npm/apexcharts"></script>
<script>
document.addEventListener('DOMContentLoaded', () => {
    const isDark = document.documentElement.classList.contains('dark');
    const fg = isDark ? '#cbd5e1' : '#475569';
    const grid = isDark ? '#334155' : '#e2e8f0';

    @if ($popDist->isNotEmpty())
    new ApexCharts(document.querySelector('#chart-pop'), {
        chart: { type: 'bar', height: 280, toolbar: { show: false }, foreColor: fg, animations: { enabled: false } },
        series: [
            { name: '平均払戻', data: @json($popDist->pluck('avg')) },
            { name: '最高払戻', data: @json($popDist->pluck('max')) },
        ],
        plotOptions: { bar: { horizontal: false, columnWidth: '60%' } },
        xaxis: { categories: @json($popDist->map(fn($r) => $r['popularity'].'番人気')->values()), labels: { style: { colors: fg } } },
        yaxis: { labels: { style: { colors: fg }, formatter: v => '¥' + (v|0).toLocaleString() } },
        colors: ['#eab308', '#dc2626'],
        grid: { borderColor: grid },
        legend: { labels: { colors: fg } },
        tooltip: { theme: isDark ? 'dark' : 'light', y: { formatter: v => '¥' + (v|0).toLocaleString() } },
    }).render();
    @endif

    new ApexCharts(document.querySelector('#chart-band'), {
        chart: { type: 'bar', height: 280, toolbar: { show: false }, foreColor: fg, animations: { enabled: false } },
        series: [{ name: 'レース数', data: @json(collect($bandCounts)->pluck('cnt')) }],
        plotOptions: { bar: { horizontal: true, distributed: true } },
        xaxis: { categories: @json(collect($bandCounts)->pluck('label')), labels: { style: { colors: fg } } },
        yaxis: { labels: { style: { colors: fg } } },
        colors: ['#16a34a', '#0ea5e9', '#eab308', '#f97316', '#dc2626', '#9333ea'],
        grid: { borderColor: grid },
        legend: { show: false },
        tooltip: { theme: isDark ? 'dark' : 'light' },
    }).render();
});
</script>
@endsection
