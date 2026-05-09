@extends('layouts.app')
@section('title', '父系ヒートマップ')

@section('content')
<div class="space-y-4">
    <h1 class="inline-flex items-center gap-2 text-xl sm:text-2xl font-bold text-gray-800">
        <x-icon name="fire" class="w-6 h-6 text-orange-500" />
        <span>父系 × 条件 ヒートマップ</span>
    </h1>
    <p class="text-xs sm:text-sm text-gray-600">
        出走数上位の父系を縦軸に、距離区分・馬場状態・競馬場を横軸にした得意/不得意マップ。
        色が濃いほど高水準。出走数が {{ $minRuns }} 回未満のセルは灰色(参考値なし)で表示します。
    </p>

    @include('analytics._pedigree_nav', ['active' => 'heatmap'])

    {{-- フィルタ --}}
    <form method="GET" class="bg-white rounded-lg shadow p-3 grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 gap-3 items-end text-xs sm:text-sm">
        <div>
            <label class="block text-gray-600 text-xs mb-1">横軸</label>
            <select name="axis" class="border rounded px-2 py-1 w-full">
                <option value="distance"  @selected($axis === 'distance')>距離区分</option>
                <option value="condition" @selected($axis === 'condition')>馬場状態</option>
                <option value="venue"     @selected($axis === 'venue')>競馬場</option>
            </select>
        </div>
        <div>
            <label class="block text-gray-600 text-xs mb-1">トラック</label>
            <select name="track_type" class="border rounded px-2 py-1 w-full">
                @foreach (['芝','ダート','障害'] as $t)
                    <option value="{{ $t }}" @selected($trackType === $t)>{{ $t }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="block text-gray-600 text-xs mb-1">指標</label>
            <select name="metric" class="border rounded px-2 py-1 w-full">
                <option value="show_rate" @selected($metric === 'show_rate')>複勝率</option>
                <option value="win_rate"  @selected($metric === 'win_rate')>勝率</option>
                <option value="roi_win"   @selected($metric === 'roi_win')>単勝回収率</option>
                <option value="roi_place" @selected($metric === 'roi_place')>複勝回収率</option>
            </select>
        </div>
        <div>
            <label class="block text-gray-600 text-xs mb-1">セル最小出走数</label>
            <input type="number" min="1" name="min_runs" value="{{ $minRuns }}" class="border rounded px-2 py-1 w-full">
        </div>
        <div>
            <label class="block text-gray-600 text-xs mb-1">父TOP数</label>
            <input type="number" min="5" max="50" name="top" value="{{ $top }}" class="border rounded px-2 py-1 w-full">
        </div>
        <div class="col-span-2 sm:col-span-3 lg:col-span-5 flex gap-2">
            <button class="bg-purple-600 hover:bg-purple-700 text-white px-4 py-1.5 rounded">適用</button>
            <a href="{{ route('analytics.pedigree.heatmap') }}" class="text-gray-500 hover:text-gray-700 underline self-center text-xs">クリア</a>
        </div>
    </form>

    @php
        $metricLabels = [
            'win_rate'   => '勝率(%)',
            'show_rate'  => '複勝率(%)',
            'roi_win'    => '単勝回収率(%)',
            'roi_place'  => '複勝回収率(%)',
        ];
        // 色判定の閾値(指標ごと)
        $thresholds = [
            'win_rate'  => [5, 10, 15, 20],
            'show_rate' => [20, 30, 40, 50],
            'roi_win'   => [60, 80, 100, 130],
            'roi_place' => [60, 80, 100, 120],
        ];
        $cellColor = function ($v, $thr) {
            if ($v === null) return 'bg-gray-100 text-gray-300';
            if ($v >= $thr[3]) return 'bg-emerald-600 text-white font-bold';
            if ($v >= $thr[2]) return 'bg-emerald-400 text-white font-bold';
            if ($v >= $thr[1]) return 'bg-emerald-200 text-gray-800';
            if ($v >= $thr[0]) return 'bg-emerald-50 text-gray-700';
            return 'bg-rose-50 text-gray-500';
        };
        $thr = $thresholds[$metric] ?? [0,0,0,0];
    @endphp

    <div class="bg-white rounded-lg shadow p-4">
        <div class="mb-3 flex items-center justify-between flex-wrap gap-2">
            <h2 class="font-semibold text-gray-700">
                {{ $trackType }} × {{ $metricLabels[$metric] ?? $metric }} (父TOP{{ $top }} × {{
                    ['distance' => '距離区分', 'condition' => '馬場状態', 'venue' => '競馬場'][$axis] ?? '軸'
                }})
            </h2>
            <div class="text-xs text-gray-500 flex items-center gap-2">
                <span>低</span>
                <span class="inline-block w-4 h-4 bg-rose-50 border"></span>
                <span class="inline-block w-4 h-4 bg-emerald-50"></span>
                <span class="inline-block w-4 h-4 bg-emerald-200"></span>
                <span class="inline-block w-4 h-4 bg-emerald-400"></span>
                <span class="inline-block w-4 h-4 bg-emerald-600"></span>
                <span>高</span>
            </div>
        </div>

        @if (count($fathers) === 0)
            <p class="text-sm text-gray-500">条件に合致するデータがありません。</p>
        @else
        <div class="table-scroll">
            <table class="w-full text-xs sm:text-sm border-collapse min-w-[640px]">
                <thead>
                    <tr class="bg-gray-100 text-gray-600">
                        <th class="text-left px-2 py-2 border">父</th>
                        @foreach ($columns as $col)
                            <th class="px-2 py-2 border text-center">{{ $col }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @foreach ($fathers as $f)
                        <tr>
                            <td class="px-2 py-1.5 border font-medium text-purple-700 whitespace-nowrap">
                                <a href="{{ route('analytics.pedigree', ['father' => $f]) }}"
                                   class="hover:underline">{{ $f }}</a>
                            </td>
                            @foreach ($columns as $col)
                                @php
                                    $cell = $matrix[$f][$col] ?? null;
                                    $val  = $cell['v'] ?? null;
                                    $runs = $cell['runs'] ?? 0;
                                @endphp
                                <td class="px-2 py-1.5 border text-center {{ $cellColor($val, $thr) }}"
                                    title="出走 {{ $runs }} 回">
                                    @if ($val === null)
                                        @if ($runs > 0)
                                            <span class="text-gray-400">({{ $runs }})</span>
                                        @else
                                            <span class="text-gray-300">-</span>
                                        @endif
                                    @else
                                        {{ $val }}
                                        <div class="text-[10px] opacity-75">{{ $runs }}回</div>
                                    @endif
                                </td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @endif
    </div>

    <div class="text-xs text-gray-500 space-y-1">
        <p>※ セル内の上段は指標値、下段は出走数。出走 {{ $minRuns }} 回未満のセルは「(出走数)」だけ灰色で表示しています。</p>
        <p>※ 距離区分: 短距離(〜1399m) / マイル(1400-1799) / 中距離(1800-2199) / 中長距離(2200-2599) / 長距離(2600〜)</p>
        <p>※ 父名クリックで詳細(競馬場×トラック×距離別)へ。</p>
    </div>
</div>
@endsection
