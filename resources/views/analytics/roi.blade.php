@extends('layouts.app')
@section('title', '回収率シミュレーション')

@section('content')
<div class="space-y-6">

    <div>
        <h1 class="text-2xl font-bold text-gray-800">回収率シミュレーション</h1>
        <p class="text-sm text-gray-600 mt-1">
            過去データを元に、指定条件で買い続けた場合の回収率をシミュレートします。
            100%超で利益が出る組合せです。
        </p>
    </div>

    {{-- 券種タブ --}}
    <div class="bg-white rounded-lg shadow p-2 flex flex-wrap gap-2">
        @foreach ($kindLabels as $k => $label)
            @php
                $params = array_filter([
                    'kind'        => $k,
                    'popularity'  => $popularity,
                    'venue_id'    => $venueId,
                    'track_type'  => $trackType,
                    'from'        => $from,
                    'to'          => $to,
                ], fn($v) => $v !== null && $v !== '');
            @endphp
            <a href="{{ route('analytics.roi', $params) }}"
               class="px-4 py-2 rounded text-sm font-semibold transition
                      {{ $kind === $k
                         ? 'bg-primary-600 text-white shadow'
                         : 'bg-gray-100 text-gray-700 hover:bg-gray-200' }}">
                {{ $label }}
            </a>
        @endforeach
    </div>

    {{-- フィルタ --}}
    <form method="GET" class="bg-white rounded-lg shadow p-4 grid grid-cols-2 md:grid-cols-6 gap-3 text-sm items-end">
        <input type="hidden" name="kind" value="{{ $kind }}">
        <div>
            <label class="block text-xs text-gray-500 mb-1">人気
                @if (in_array($kind, ['uma-ren','wide','san-fuku']))
                    <span class="text-gray-400">(上位N人気)</span>
                @endif
            </label>
            <select name="popularity" class="w-full border rounded px-2 py-1">
                <option value="">指定なし</option>
                @for ($i = 1; $i <= 18; $i++)
                    <option value="{{ $i }}" @selected($popularity == $i)>
                        @if (in_array($kind, ['uma-ren','wide','san-fuku']))
                            上位{{ $i }}人気で買い
                        @else
                            {{ $i }}番人気
                        @endif
                    </option>
                @endfor
            </select>
        </div>
        <div>
            <label class="block text-xs text-gray-500 mb-1">競馬場</label>
            <select name="venue_id" class="w-full border rounded px-2 py-1">
                <option value="">すべて</option>
                @foreach ($venues as $v)
                    <option value="{{ $v->id }}" @selected($venueId == $v->id)>{{ $v->name }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="block text-xs text-gray-500 mb-1">トラック</label>
            <select name="track_type" class="w-full border rounded px-2 py-1">
                <option value="">すべて</option>
                @foreach (['芝','ダート','障害'] as $t)
                    <option value="{{ $t }}" @selected($trackType == $t)>{{ $t }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="block text-xs text-gray-500 mb-1">From</label>
            <input type="date" name="from" value="{{ $from }}" class="w-full border rounded px-2 py-1">
        </div>
        <div>
            <label class="block text-xs text-gray-500 mb-1">To</label>
            <input type="date" name="to" value="{{ $to }}" class="w-full border rounded px-2 py-1">
        </div>
        <div>
            <button type="submit" class="bg-primary-600 hover:bg-primary-700 text-white px-4 py-2 rounded w-full">計算</button>
        </div>
    </form>

    {{-- KPIサマリ --}}
    <div class="grid grid-cols-2 md:grid-cols-5 gap-4">
        <div class="bg-white rounded-lg shadow p-4">
            <div class="text-xs text-gray-500">対象レース</div>
            <div class="text-2xl font-bold text-gray-800 mt-1">{{ number_format($simulation['races']) }}</div>
            <div class="text-xs text-gray-500 mt-1">{{ $kindLabel }}</div>
        </div>
        <div class="bg-white rounded-lg shadow p-4">
            <div class="text-xs text-gray-500">購入点数</div>
            <div class="text-2xl font-bold text-gray-800 mt-1">{{ number_format($simulation['bets']) }}</div>
            <div class="text-xs text-gray-500 mt-1">1点 100円換算</div>
        </div>
        <div class="bg-white rounded-lg shadow p-4">
            <div class="text-xs text-gray-500">投資総額</div>
            <div class="text-2xl font-bold text-gray-700 mt-1">¥{{ number_format($simulation['stake']) }}</div>
        </div>
        <div class="bg-white rounded-lg shadow p-4">
            <div class="text-xs text-gray-500">払戻総額</div>
            <div class="text-2xl font-bold text-emerald-700 mt-1">¥{{ number_format($simulation['winnings']) }}</div>
            <div class="text-xs text-gray-500 mt-1">的中: {{ number_format($simulation['hits']) }}件 ({{ $simulation['hit_rate'] }}%)</div>
        </div>
        <div class="bg-white rounded-lg shadow p-4 {{ $simulation['roi'] >= 100 ? 'ring-2 ring-emerald-400' : '' }}">
            <div class="text-xs text-gray-500">回収率</div>
            <div class="text-3xl font-bold {{ $simulation['roi'] >= 100 ? 'text-emerald-600' : ($simulation['roi'] >= 80 ? 'text-amber-600' : 'text-rose-600') }} mt-1">
                {{ $simulation['roi'] }}%
            </div>
            <div class="text-xs {{ $simulation['profit'] >= 0 ? 'text-emerald-600' : 'text-rose-600' }} mt-1">
                収支 {{ $simulation['profit'] >= 0 ? '+' : '' }}¥{{ number_format($simulation['profit']) }}
            </div>
        </div>
    </div>

    {{-- グラフ群 --}}
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">

        {{-- 人気別 (単勝/複勝のみ表示) --}}
        @if (!empty($charts['by_popularity']))
        <div class="bg-white rounded-lg shadow p-4">
            <h3 class="font-semibold text-gray-700 mb-3">人気別 回収率</h3>
            <div id="chart-roi-popularity" style="min-height: 300px;"></div>
        </div>
        @endif

        {{-- オッズ帯別 (単勝のみ) --}}
        @if (!empty($charts['by_odds_band']))
        <div class="bg-white rounded-lg shadow p-4">
            <h3 class="font-semibold text-gray-700 mb-3">オッズ帯別 回収率</h3>
            <div id="chart-roi-odds-band" style="min-height: 300px;"></div>
        </div>
        @endif

        {{-- 競馬場別 --}}
        @if (!empty($charts['by_venue']))
        <div class="bg-white rounded-lg shadow p-4">
            <h3 class="font-semibold text-gray-700 mb-3">競馬場別 回収率</h3>
            <div id="chart-roi-venue" style="min-height: 300px;"></div>
        </div>
        @endif

        {{-- トラック別 --}}
        @if (!empty($charts['by_track']))
        <div class="bg-white rounded-lg shadow p-4">
            <h3 class="font-semibold text-gray-700 mb-3">トラック別 回収率</h3>
            <div id="chart-roi-track" style="min-height: 300px;"></div>
        </div>
        @endif

        {{-- 距離帯別 --}}
        @if (!empty($charts['by_distance']))
        <div class="bg-white rounded-lg shadow p-4 lg:col-span-2">
            <h3 class="font-semibold text-gray-700 mb-3">距離帯別 回収率</h3>
            <div id="chart-roi-distance" style="min-height: 300px;"></div>
        </div>
        @endif
    </div>

    {{-- 明細テーブル(人気別が無いとき=馬連等は競馬場別を主軸に) --}}
    @php
        $detailRows = $charts['by_popularity'] ?: $charts['by_venue'];
        $detailTitle = $charts['by_popularity'] ? '人気別 内訳' : '競馬場別 内訳';
    @endphp
    @if (!empty($detailRows))
    <div class="bg-white rounded-lg shadow p-4">
        <h3 class="font-semibold text-gray-700 mb-3">{{ $detailTitle }}</h3>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-gray-100 text-xs text-gray-600 uppercase">
                    <tr>
                        <th class="text-left px-3 py-2">区分</th>
                        <th class="text-right px-3 py-2">点数</th>
                        <th class="text-right px-3 py-2">的中</th>
                        <th class="text-right px-3 py-2">的中率</th>
                        <th class="text-right px-3 py-2">投資</th>
                        <th class="text-right px-3 py-2">払戻</th>
                        <th class="text-right px-3 py-2">回収率</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($detailRows as $row)
                        <tr class="border-b hover:bg-gray-50">
                            <td class="px-3 py-2 font-semibold">{{ $row['label'] }}</td>
                            <td class="px-3 py-2 text-right">{{ number_format($row['bets']) }}</td>
                            <td class="px-3 py-2 text-right">{{ number_format($row['hits']) }}</td>
                            <td class="px-3 py-2 text-right">{{ $row['hit_rate'] }}%</td>
                            <td class="px-3 py-2 text-right text-gray-600">¥{{ number_format($row['stake']) }}</td>
                            <td class="px-3 py-2 text-right text-emerald-600">¥{{ number_format($row['winnings']) }}</td>
                            <td class="px-3 py-2 text-right">
                                <span class="font-bold
                                    {{ $row['roi'] >= 100 ? 'text-emerald-600' : ($row['roi'] >= 80 ? 'text-amber-600' : 'text-rose-600') }}">
                                    {{ $row['roi'] }}%
                                </span>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
    @endif

    {{-- 注意 --}}
    <div class="bg-amber-50 border-l-4 border-amber-400 p-4 rounded">
        <h3 class="font-semibold text-amber-800 mb-2">読み方のヒント</h3>
        <ul class="text-sm text-amber-700 list-disc list-inside space-y-1">
            <li>JRAの控除率は単勝/複勝 約20%・3連複/3連単 約27.5%。理論上の平均回収率は控除率を引いた値です。</li>
            <li>単勝・複勝はオッズベース計算。馬連/ワイド/3連複は <b>公式払戻×全買い想定</b>(1点100円)で計算しています。</li>
            <li>「上位N人気で買い」は、各レースで人気上位N頭の組合せ全て(馬連/ワイドなら C(N,2)、3連複なら C(N,3) 点)を買う想定です。</li>
            <li>サンプル数(点数)が少ないとブレが大きいので、数百〜数千以上で参考に。</li>
            <li>未来の成績は保証されないので、自己責任で。</li>
        </ul>
    </div>
</div>

@php
    $datasetsJs = [
        'by_popularity' => $charts['by_popularity'] ?? [],
        'by_odds_band'  => $charts['by_odds_band']  ?? [],
        'by_venue'      => $charts['by_venue']      ?? [],
        'by_track'      => $charts['by_track']      ?? [],
        'by_distance'   => $charts['by_distance']   ?? [],
    ];
@endphp
<script>
document.addEventListener('DOMContentLoaded', function () {
    const datasets = @json($datasetsJs);

    function colorFor(v) {
        if (v >= 100) return '#10b981';     // emerald
        if (v >= 80)  return '#f59e0b';     // amber
        return '#ef4444';                    // rose
    }

    function renderRoiChart(elId, rows, opts = {}) {
        const el = document.getElementById(elId);
        if (!el || !rows || rows.length === 0) return;

        const labels = rows.map(r => r.label);
        const rois   = rows.map(r => r.roi);
        const hits   = rows.map(r => r.hit_rate);
        const colors = rois.map(colorFor);

        const options = {
            chart: {
                type: 'bar',
                height: opts.height || 320,
                toolbar: { show: false },
                fontFamily: 'inherit',
            },
            series: [
                { name: '回収率(%)', type: 'bar', data: rois },
                { name: '的中率(%)', type: 'line', data: hits },
            ],
            stroke: { width: [0, 3], curve: 'smooth' },
            plotOptions: {
                bar: {
                    borderRadius: 4,
                    columnWidth: '60%',
                    distributed: true,
                    dataLabels: { position: 'top' },
                },
            },
            colors: colors,
            dataLabels: {
                enabled: true,
                enabledOnSeries: [0],
                formatter: (val) => val + '%',
                offsetY: -18,
                style: { fontSize: '11px', colors: ['#374151'] },
            },
            xaxis: {
                categories: labels,
                labels: { style: { fontSize: '11px' } },
            },
            yaxis: [
                {
                    title: { text: '回収率(%)' },
                    labels: { formatter: (v) => v + '%' },
                },
                {
                    opposite: true,
                    title: { text: '的中率(%)' },
                    labels: { formatter: (v) => v + '%' },
                },
            ],
            annotations: {
                yaxis: [{
                    y: 100,
                    borderColor: '#10b981',
                    strokeDashArray: 5,
                    label: {
                        borderColor: '#10b981',
                        style: { color: '#fff', background: '#10b981', fontSize: '10px' },
                        text: '損益分岐 100%',
                    },
                }],
            },
            tooltip: {
                shared: true,
                intersect: false,
                y: [
                    { formatter: (v) => v + '%' },
                    { formatter: (v) => v + '%' },
                ],
            },
            legend: { show: true, position: 'top' },
        };

        new ApexCharts(el, options).render();
    }

    renderRoiChart('chart-roi-popularity', datasets.by_popularity);
    renderRoiChart('chart-roi-odds-band',  datasets.by_odds_band);
    renderRoiChart('chart-roi-venue',      datasets.by_venue);
    renderRoiChart('chart-roi-track',      datasets.by_track);
    renderRoiChart('chart-roi-distance',   datasets.by_distance);
});
</script>
@endsection
