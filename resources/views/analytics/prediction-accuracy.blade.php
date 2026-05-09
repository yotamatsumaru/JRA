@extends('layouts.app')
@section('title', '予想精度トラッキング')

@section('content')
<div class="space-y-5">
    <x-page-header title="予想精度トラッキング" subtitle="印 (◎○▲△☆✕) と着順を突き合わせて的中率・回収率を分析" icon="target" />

    {{-- フィルタ --}}
    <form method="GET" class="bg-white dark:bg-gray-800 rounded-lg shadow-sm ring-1 ring-gray-100 dark:ring-gray-700 p-4">
        <div class="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-7 gap-3 text-xs">
            <label>
                <span class="text-gray-500">期間From</span>
                <input type="date" name="from" value="{{ $filters['from'] ?? '' }}" class="mt-1 w-full rounded border-gray-300 dark:bg-gray-900 dark:border-gray-600">
            </label>
            <label>
                <span class="text-gray-500">期間To</span>
                <input type="date" name="to" value="{{ $filters['to'] ?? '' }}" class="mt-1 w-full rounded border-gray-300 dark:bg-gray-900 dark:border-gray-600">
            </label>
            <label>
                <span class="text-gray-500">競馬場</span>
                <select name="venue_id" class="mt-1 w-full rounded border-gray-300 dark:bg-gray-900 dark:border-gray-600">
                    <option value="">--</option>
                    @foreach ($venues as $v)
                        <option value="{{ $v->id }}" @selected(($filters['venue_id'] ?? '') == $v->id)>{{ $v->name }}</option>
                    @endforeach
                </select>
            </label>
            <label>
                <span class="text-gray-500">トラック</span>
                <select name="track_type" class="mt-1 w-full rounded border-gray-300 dark:bg-gray-900 dark:border-gray-600">
                    <option value="">--</option>
                    @foreach (['芝', 'ダート', '障害'] as $t)
                        <option value="{{ $t }}" @selected(($filters['track_type'] ?? '') === $t)>{{ $t }}</option>
                    @endforeach
                </select>
            </label>
            <label>
                <span class="text-gray-500">グレード</span>
                <select name="grade" class="mt-1 w-full rounded border-gray-300 dark:bg-gray-900 dark:border-gray-600">
                    <option value="">--</option>
                    @foreach (['G1', 'G2', 'G3', 'L', 'OP'] as $g)
                        <option value="{{ $g }}" @selected(($filters['grade'] ?? '') === $g)>{{ $g }}</option>
                    @endforeach
                </select>
            </label>
            <label>
                <span class="text-gray-500">距離≧</span>
                <input type="number" min="800" max="3600" step="100" name="distance_min"
                    value="{{ $filters['distance_min'] ?? '' }}"
                    class="mt-1 w-full rounded border-gray-300 dark:bg-gray-900 dark:border-gray-600">
            </label>
            <label>
                <span class="text-gray-500">距離≦</span>
                <input type="number" min="800" max="3600" step="100" name="distance_max"
                    value="{{ $filters['distance_max'] ?? '' }}"
                    class="mt-1 w-full rounded border-gray-300 dark:bg-gray-900 dark:border-gray-600">
            </label>
        </div>
        <div class="flex items-center gap-2 mt-3 flex-wrap">
            <button type="submit" class="bg-turf-600 hover:bg-turf-700 text-white px-4 py-1.5 rounded text-sm">適用</button>
            <a href="{{ route('analytics.prediction-accuracy') }}" class="text-xs text-gray-500 hover:underline">クリア</a>

            {{-- Phase 5-E: CSV エクスポート --}}
            <span class="ml-auto flex items-center gap-1.5 text-xs">
                <span class="text-gray-400">CSVエクスポート:</span>
                <a href="{{ route('analytics.prediction-accuracy.export-csv', array_merge(request()->query(), ['type' => 'summary'])) }}"
                   class="inline-flex items-center gap-1 px-2.5 py-1 rounded bg-emerald-50 hover:bg-emerald-100 dark:bg-emerald-900/30 dark:hover:bg-emerald-900/50 text-emerald-700 dark:text-emerald-300">
                    <x-icon name="download" class="w-3.5 h-3.5" />
                    <span>印別</span>
                </a>
                <a href="{{ route('analytics.prediction-accuracy.export-csv', array_merge(request()->query(), ['type' => 'monthly'])) }}"
                   class="inline-flex items-center gap-1 px-2.5 py-1 rounded bg-emerald-50 hover:bg-emerald-100 dark:bg-emerald-900/30 dark:hover:bg-emerald-900/50 text-emerald-700 dark:text-emerald-300">
                    <x-icon name="download" class="w-3.5 h-3.5" />
                    <span>月別</span>
                </a>
                <a href="{{ route('analytics.prediction-accuracy.export-csv', array_merge(request()->query(), ['type' => 'courses'])) }}"
                   class="inline-flex items-center gap-1 px-2.5 py-1 rounded bg-emerald-50 hover:bg-emerald-100 dark:bg-emerald-900/30 dark:hover:bg-emerald-900/50 text-emerald-700 dark:text-emerald-300">
                    <x-icon name="download" class="w-3.5 h-3.5" />
                    <span>コース別</span>
                </a>
            </span>
        </div>
    </form>

    {{-- 印別精度サマリ --}}
    <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm ring-1 ring-gray-100 dark:ring-gray-700 p-4">
        <h2 class="text-sm font-semibold text-gray-700 dark:text-gray-200 mb-3">印別の精度</h2>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="text-xs text-gray-500 dark:text-gray-400 bg-gray-50 dark:bg-gray-900/30">
                    <tr>
                        <th class="px-3 py-2 text-left">印</th>
                        <th class="px-3 py-2 text-right">対象</th>
                        <th class="px-3 py-2 text-right">勝</th>
                        <th class="px-3 py-2 text-right">2着内</th>
                        <th class="px-3 py-2 text-right">3着内</th>
                        <th class="px-3 py-2 text-right">勝率</th>
                        <th class="px-3 py-2 text-right">複勝率</th>
                        <th class="px-3 py-2 text-right">単勝ROI</th>
                        <th class="px-3 py-2 text-right">複勝ROI</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                    @foreach ($summary as $row)
                    <tr>
                        <td class="px-3 py-2 font-bold text-base">{{ $row['mark'] }}</td>
                        <td class="px-3 py-2 text-right tabular-nums">{{ number_format($row['runs']) }}</td>
                        <td class="px-3 py-2 text-right tabular-nums">{{ number_format($row['wins']) }}</td>
                        <td class="px-3 py-2 text-right tabular-nums">{{ number_format($row['top2']) }}</td>
                        <td class="px-3 py-2 text-right tabular-nums">{{ number_format($row['top3']) }}</td>
                        <td class="px-3 py-2 text-right tabular-nums">{{ $row['win_rate'] !== null ? $row['win_rate'].'%' : '-' }}</td>
                        <td class="px-3 py-2 text-right tabular-nums">{{ $row['place_rate'] !== null ? $row['place_rate'].'%' : '-' }}</td>
                        <td class="px-3 py-2 text-right tabular-nums {{ ($row['win_roi'] ?? 0) >= 100 ? 'text-emerald-600 font-semibold' : (($row['win_roi'] ?? 0) > 0 ? '' : 'text-gray-400') }}">
                            {{ $row['win_roi'] !== null ? $row['win_roi'].'%' : '-' }}
                        </td>
                        <td class="px-3 py-2 text-right tabular-nums {{ ($row['place_roi'] ?? 0) >= 100 ? 'text-emerald-600 font-semibold' : (($row['place_roi'] ?? 0) > 0 ? '' : 'text-gray-400') }}">
                            {{ $row['place_roi'] !== null ? $row['place_roi'].'%' : '-' }}
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <p class="text-[11px] text-gray-400 mt-2">※ ROI は 1点 100 円ベースの理論値。複勝は place_odds_min(欠損時 win_odds/3)で保守側に算出。</p>
    </div>

    {{-- 月別推移チャート (◎本命のみ) --}}
    <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm ring-1 ring-gray-100 dark:ring-gray-700 p-4">
        <h2 class="text-sm font-semibold text-gray-700 dark:text-gray-200 mb-3">◎本命の月別推移</h2>
        @if (count($chartLabels) > 0)
            <div id="honmei-chart"></div>
        @else
            <p class="text-xs text-gray-500">データがありません。フィルタを調整してください。</p>
        @endif
    </div>

    {{-- コース別本命精度 --}}
    <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm ring-1 ring-gray-100 dark:ring-gray-700 p-4">
        <h2 class="text-sm font-semibold text-gray-700 dark:text-gray-200 mb-3">◎本命のコース別精度</h2>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="text-xs text-gray-500 dark:text-gray-400 bg-gray-50 dark:bg-gray-900/30">
                    <tr>
                        <th class="px-3 py-2 text-left">競馬場</th>
                        <th class="px-3 py-2 text-left">トラック</th>
                        <th class="px-3 py-2 text-right">対象</th>
                        <th class="px-3 py-2 text-right">勝</th>
                        <th class="px-3 py-2 text-right">3着内</th>
                        <th class="px-3 py-2 text-right">勝率</th>
                        <th class="px-3 py-2 text-right">複勝率</th>
                        <th class="px-3 py-2 text-right">単勝ROI</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                    @forelse ($courses as $c)
                    <tr>
                        <td class="px-3 py-2">{{ $c['venue'] ?? '-' }}</td>
                        <td class="px-3 py-2">{{ $c['track_type'] ?? '-' }}</td>
                        <td class="px-3 py-2 text-right tabular-nums">{{ number_format($c['runs']) }}</td>
                        <td class="px-3 py-2 text-right tabular-nums">{{ number_format($c['wins']) }}</td>
                        <td class="px-3 py-2 text-right tabular-nums">{{ number_format($c['top3']) }}</td>
                        <td class="px-3 py-2 text-right tabular-nums">{{ $c['win_rate'] }}%</td>
                        <td class="px-3 py-2 text-right tabular-nums">{{ $c['place_rate'] }}%</td>
                        <td class="px-3 py-2 text-right tabular-nums {{ $c['win_roi'] >= 100 ? 'text-emerald-600 font-semibold' : '' }}">{{ $c['win_roi'] }}%</td>
                    </tr>
                    @empty
                    <tr><td colspan="8" class="px-3 py-6 text-center text-gray-400 text-xs">データがありません</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>

@if (count($chartLabels) > 0)
<script>
document.addEventListener('DOMContentLoaded', () => {
    if (typeof ApexCharts === 'undefined') return;
    const opt = {
        chart: { type: 'line', height: 280, toolbar: { show: false } },
        series: [
            { name: '対象数',   type: 'column', data: @json($chartHonmei['runs']) },
            { name: '複勝率(%)', type: 'line',   data: @json($chartHonmei['place_rate']) },
            { name: '単勝ROI(%)', type: 'line',  data: @json($chartHonmei['win_roi']) },
        ],
        stroke: { width: [0, 3, 3], curve: 'smooth' },
        colors: ['#94a3b8', '#10b981', '#f59e0b'],
        xaxis: { categories: @json($chartLabels) },
        yaxis: [
            { title: { text: '対象数' } },
            { opposite: true, title: { text: '%' } },
            { opposite: true, show: false },
        ],
        legend: { position: 'top' },
    };
    new ApexCharts(document.getElementById('honmei-chart'), opt).render();
});
</script>
@endif
@endsection
