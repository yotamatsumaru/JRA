@extends('layouts.app')
@section('title', '競馬場別傾向分析')

@section('content')
<div class="space-y-6">
    <h1 class="text-xl sm:text-2xl font-bold text-gray-800">競馬場別傾向分析</h1>

    {{-- フィルタ --}}
    <form method="GET" class="bg-white rounded-lg shadow p-4 flex flex-wrap gap-3 text-sm items-end">
        <div>
            <label class="block text-xs text-gray-500 mb-1">競馬場</label>
            <select name="venue_id" class="border rounded px-2 py-1" onchange="this.form.submit()">
                @foreach ($venues as $v)
                    <option value="{{ $v->id }}" @selected($venueId == $v->id)>{{ $v->name }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="block text-xs text-gray-500 mb-1">トラック</label>
            <select name="track_type" class="border rounded px-2 py-1" onchange="this.form.submit()">
                @foreach (['芝','ダート','障害'] as $t)
                    <option value="{{ $t }}" @selected($trackType == $t)>{{ $t }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="block text-xs text-gray-500 mb-1">距離</label>
            <select name="distance" class="border rounded px-2 py-1" onchange="this.form.submit()">
                <option value="">すべて</option>
                @foreach ($availableDistances as $d)
                    <option value="{{ $d }}" @selected($distance == $d)>{{ $d }}m</option>
                @endforeach
            </select>
        </div>
        <noscript><button type="submit" class="bg-primary-600 text-white px-4 py-1 rounded">適用</button></noscript>
    </form>

    @if ($venue)
    <div class="bg-white dark:bg-gray-800 rounded-lg shadow dark:shadow-black/40 p-4">
        <h2 class="text-lg font-bold text-primary-700">{{ $venue->name }} / {{ $trackType }} @if ($distance) / {{ $distance }}m @endif</h2>
        @if ($venue->characteristics)
            <p class="mt-2 text-sm text-gray-600">{{ $venue->characteristics }}</p>
        @endif
    </div>
    @endif

    {{-- 枠順×着順ヒートマップ --}}
    <div class="bg-white dark:bg-gray-800 rounded-lg shadow dark:shadow-black/40 p-4">
        <h2 class="font-semibold text-gray-700 dark:text-gray-200 mb-3">枠番別 成績ヒートマップ</h2>
        @if ($frameStats->isEmpty())
            <p class="text-sm text-gray-500">データがありません。レース結果が蓄積されると表示されます。</p>
        @else
        <div class="table-scroll">
            <table class="w-full text-sm min-w-[760px]">
                <thead class="bg-gray-100 text-xs text-gray-600">
                    <tr>
                        <th class="px-2 py-2">枠</th>
                        <th class="px-2 py-2">出走</th>
                        <th class="px-2 py-2">勝</th>
                        <th class="px-2 py-2">連対</th>
                        <th class="px-2 py-2">複勝</th>
                        <th class="px-2 py-2">勝率</th>
                        <th class="px-2 py-2">連対率</th>
                        <th class="px-2 py-2">複勝率</th>
                        <th class="px-2 py-2 w-1/4">ヒートマップ（複勝率）</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($frameStats as $f)
                        @php
                            $winRate = $f->runs > 0 ? round($f->wins / $f->runs * 100, 1) : 0;
                            $placeRate = $f->runs > 0 ? round($f->places / $f->runs * 100, 1) : 0;
                            $showRate = $f->runs > 0 ? round($f->shows / $f->runs * 100, 1) : 0;
                            // 0-50%を強さに変換（25%超は赤系、12.5%以下は青系）
                            $intensity = min(100, $showRate * 2);
                        @endphp
                        <tr class="border-b">
                            <td class="px-2 py-2 text-center font-bold">{{ $f->frame_number }}枠</td>
                            <td class="px-2 py-2 text-center">{{ $f->runs }}</td>
                            <td class="px-2 py-2 text-center text-yellow-600 font-bold">{{ $f->wins }}</td>
                            <td class="px-2 py-2 text-center text-blue-600">{{ $f->places }}</td>
                            <td class="px-2 py-2 text-center text-emerald-600">{{ $f->shows }}</td>
                            <td class="px-2 py-2 text-center">{{ $winRate }}%</td>
                            <td class="px-2 py-2 text-center">{{ $placeRate }}%</td>
                            <td class="px-2 py-2 text-center font-bold">{{ $showRate }}%</td>
                            <td class="px-2 py-2">
                                <div class="h-6 rounded relative overflow-hidden bg-gray-100">
                                    <div class="absolute inset-y-0 left-0" style="width: {{ $intensity }}%; background: linear-gradient(to right, #93c5fd, #2563eb {{ $intensity * 0.5 }}%, #dc2626 {{ $intensity * 0.8 }}%);"></div>
                                    <div class="relative z-10 flex items-center justify-center h-full text-xs font-bold text-gray-700">{{ $showRate }}%</div>
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div class="mt-3 text-xs text-gray-500">※ ヒートマップは複勝率0〜50%を視覚化（青→赤に推移）。サンプル数が少ない枠は参考値です。</div>
        @endif
    </div>

    {{-- 脚質別 --}}
    <div class="bg-white dark:bg-gray-800 rounded-lg shadow dark:shadow-black/40 p-4">
        <h2 class="font-semibold text-gray-700 dark:text-gray-200 mb-3">脚質別成績</h2>
        @if ($styleStats->isEmpty())
            <p class="text-sm text-gray-500">データがありません</p>
        @else
        <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
            @foreach ($styleStats as $s)
                @php
                    $rate = $s->runs > 0 ? round($s->wins / $s->runs * 100, 1) : 0;
                    $showRate = $s->runs > 0 ? round($s->shows / $s->runs * 100, 1) : 0;
                @endphp
                <div class="border rounded-lg p-3 bg-gray-50">
                    <div class="text-xs text-gray-500">脚質</div>
                    <div class="text-2xl font-bold text-primary-700">{{ $s->running_style }}</div>
                    <div class="mt-2 text-xs text-gray-600 space-y-1">
                        <div>出走: <span class="font-bold">{{ $s->runs }}</span></div>
                        <div>勝率: <span class="font-bold text-yellow-600">{{ $rate }}%</span></div>
                        <div>複勝率: <span class="font-bold text-emerald-600">{{ $showRate }}%</span></div>
                    </div>
                </div>
            @endforeach
        </div>
        <div id="style-chart" class="mt-4"></div>
        @endif
    </div>
</div>

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', () => {
    const styleStats = @json($styleStats);
    if (styleStats.length > 0) {
        new ApexCharts(document.querySelector('#style-chart'), {
            chart: { type: 'bar', height: 240, toolbar: { show: false } },
            series: [
                { name: '勝率(%)', data: styleStats.map(s => s.runs > 0 ? Math.round(s.wins/s.runs*1000)/10 : 0) },
                { name: '複勝率(%)', data: styleStats.map(s => s.runs > 0 ? Math.round(s.shows/s.runs*1000)/10 : 0) },
            ],
            xaxis: { categories: styleStats.map(s => s.running_style) },
            colors: ['#f59e0b', '#10b981'],
            plotOptions: { bar: { borderRadius: 4, columnWidth: '60%' } },
            legend: { position: 'top' },
        }).render();
    }
});
</script>
@endpush
@endsection
