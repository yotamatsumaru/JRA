@extends('layouts.app')
@section('title', 'コース×ペース×脚質 3D 分析')

@php
    /**
     * ヒートマップ用の色クラスを決定
     *  指標 (win_rate / place_rate / win_roi) と値から bg と text を返す
     */
    $heatColor = function (string $metric, float $value): array {
        // win_rate: 0-30+, place_rate: 0-60+, win_roi: 0-200+
        if ($metric === 'win_rate') {
            if ($value <= 0)   return ['bg-gray-100 dark:bg-gray-800', 'text-gray-400'];
            if ($value < 6)    return ['bg-emerald-50 dark:bg-emerald-900/20', 'text-emerald-700 dark:text-emerald-300'];
            if ($value < 12)   return ['bg-emerald-100 dark:bg-emerald-900/30', 'text-emerald-700 dark:text-emerald-200'];
            if ($value < 18)   return ['bg-emerald-200 dark:bg-emerald-800/40', 'text-emerald-800 dark:text-emerald-100'];
            if ($value < 24)   return ['bg-emerald-300 dark:bg-emerald-700/50', 'text-emerald-900 dark:text-white'];
            return ['bg-emerald-500 dark:bg-emerald-600', 'text-white'];
        }
        if ($metric === 'place_rate') {
            if ($value <= 0)   return ['bg-gray-100 dark:bg-gray-800', 'text-gray-400'];
            if ($value < 15)   return ['bg-sky-50 dark:bg-sky-900/20', 'text-sky-700 dark:text-sky-300'];
            if ($value < 30)   return ['bg-sky-100 dark:bg-sky-900/30', 'text-sky-700 dark:text-sky-200'];
            if ($value < 45)   return ['bg-sky-200 dark:bg-sky-800/40', 'text-sky-800 dark:text-sky-100'];
            if ($value < 60)   return ['bg-sky-300 dark:bg-sky-700/50', 'text-sky-900 dark:text-white'];
            return ['bg-sky-500 dark:bg-sky-600', 'text-white'];
        }
        // win_roi
        if ($value <= 0)   return ['bg-gray-100 dark:bg-gray-800', 'text-gray-400'];
        if ($value < 60)   return ['bg-rose-50 dark:bg-rose-900/20', 'text-rose-700 dark:text-rose-300'];
        if ($value < 90)   return ['bg-amber-50 dark:bg-amber-900/20', 'text-amber-700 dark:text-amber-300'];
        if ($value < 100)  return ['bg-amber-100 dark:bg-amber-900/30', 'text-amber-800 dark:text-amber-200'];
        if ($value < 130)  return ['bg-emerald-200 dark:bg-emerald-800/40', 'text-emerald-800 dark:text-emerald-100'];
        if ($value < 160)  return ['bg-emerald-300 dark:bg-emerald-700/50', 'text-emerald-900 dark:text-white'];
        return ['bg-emerald-500 dark:bg-emerald-600', 'text-white'];
    };

    $paceLabels = ['H' => 'ハイ (H)', 'M' => 'ミドル (M)', 'S' => 'スロー (S)'];
    $styleLabels = ['逃' => '逃げ', '先' => '先行', '差' => '差し', '追' => '追込'];
@endphp

@section('content')
<div class="space-y-5">
    <x-page-header
        title="コース×ペース×脚質 3D 分析"
        subtitle="競馬場・距離帯・ペース・脚質の組み合わせで勝率/複勝率/単勝ROIを多次元集計"
        icon="grid" />

    {{-- フィルタ --}}
    <form method="GET" class="bg-white dark:bg-gray-800 rounded-lg shadow-sm ring-1 ring-gray-100 dark:ring-gray-700 p-4">
        <div class="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-8 gap-3 text-xs">
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
                <span class="text-gray-500">距離帯</span>
                <select name="distance_band" class="mt-1 w-full rounded border-gray-300 dark:bg-gray-900 dark:border-gray-600">
                    <option value="">--</option>
                    @foreach ($bands as $b)
                        <option value="{{ $b }}" @selected(($filters['distance_band'] ?? '') === $b)>{{ $b }}</option>
                    @endforeach
                </select>
            </label>
            <label>
                <span class="text-gray-500">ペース</span>
                <select name="pace" class="mt-1 w-full rounded border-gray-300 dark:bg-gray-900 dark:border-gray-600">
                    <option value="">--</option>
                    @foreach (['H' => 'ハイ', 'M' => 'ミドル', 'S' => 'スロー'] as $code => $lbl)
                        <option value="{{ $code }}" @selected(($filters['pace'] ?? '') === $code)>{{ $lbl }}</option>
                    @endforeach
                </select>
            </label>
            <label>
                <span class="text-gray-500">脚質</span>
                <select name="style" class="mt-1 w-full rounded border-gray-300 dark:bg-gray-900 dark:border-gray-600">
                    <option value="">--</option>
                    @foreach (['逃', '先', '差', '追'] as $s)
                        <option value="{{ $s }}" @selected(($filters['style'] ?? '') === $s)>{{ $s }}</option>
                    @endforeach
                </select>
            </label>
        </div>
        <div class="flex items-center gap-2 mt-3">
            <button type="submit" class="bg-turf-600 hover:bg-turf-700 text-white px-4 py-1.5 rounded text-sm">適用</button>
            <a href="{{ route('analytics.pace-style') }}" class="text-xs text-gray-500 hover:underline">クリア</a>
        </div>
    </form>

    {{-- 全体サマリ --}}
    <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
        <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm ring-1 ring-gray-100 dark:ring-gray-700 p-4">
            <div class="text-xs text-gray-500">対象レコード</div>
            <div class="text-2xl font-semibold text-gray-800 dark:text-gray-100 mt-1">{{ number_format($total['runs']) }}</div>
            <div class="text-[10px] text-gray-400 mt-1">勝 {{ number_format($total['wins']) }} / 3着内 {{ number_format($total['top3']) }}</div>
        </div>
        <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm ring-1 ring-gray-100 dark:ring-gray-700 p-4">
            <div class="text-xs text-gray-500">勝率</div>
            <div class="text-2xl font-semibold text-emerald-600 dark:text-emerald-400 mt-1">{{ $total['win_rate'] }}%</div>
        </div>
        <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm ring-1 ring-gray-100 dark:ring-gray-700 p-4">
            <div class="text-xs text-gray-500">複勝率(3着内)</div>
            <div class="text-2xl font-semibold text-sky-600 dark:text-sky-400 mt-1">{{ $total['place_rate'] }}%</div>
        </div>
        <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm ring-1 ring-gray-100 dark:ring-gray-700 p-4">
            <div class="text-xs text-gray-500">単勝ROI</div>
            <div class="text-2xl font-semibold {{ $total['win_roi'] >= 100 ? 'text-emerald-600 dark:text-emerald-400' : 'text-rose-600 dark:text-rose-400' }} mt-1">{{ $total['win_roi'] }}%</div>
        </div>
    </div>

    {{-- ペース×脚質 ヒートマップ --}}
    <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm ring-1 ring-gray-100 dark:ring-gray-700 p-4">
        <div class="flex items-center justify-between mb-3">
            <h2 class="text-sm font-semibold text-gray-700 dark:text-gray-200">ペース × 脚質 ヒートマップ</h2>
            <div class="text-[10px] text-gray-400">セル内: 試行数 / 勝率 / 複勝率 / ROI</div>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-xs border-collapse">
                <thead>
                    <tr>
                        <th class="px-2 py-2 text-left text-gray-500 bg-gray-50 dark:bg-gray-900/30 border border-gray-200 dark:border-gray-700">ペース \ 脚質</th>
                        @foreach ($styleLabels as $sCode => $sLbl)
                            <th class="px-2 py-2 text-center text-gray-500 bg-gray-50 dark:bg-gray-900/30 border border-gray-200 dark:border-gray-700">{{ $sLbl }} ({{ $sCode }})</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @foreach ($paceLabels as $pCode => $pLbl)
                        <tr>
                            <th class="px-2 py-2 text-left text-gray-700 dark:text-gray-200 bg-gray-50 dark:bg-gray-900/30 border border-gray-200 dark:border-gray-700 whitespace-nowrap">{{ $pLbl }}</th>
                            @foreach ($styleLabels as $sCode => $sLbl)
                                @php
                                    $cell = $matrix[$pCode][$sCode] ?? ['runs'=>0,'wins'=>0,'top3'=>0,'win_rate'=>0,'place_rate'=>0,'win_roi'=>0];
                                    [$bgRoi, $textRoi] = $heatColor('win_roi', (float)$cell['win_roi']);
                                @endphp
                                <td class="border border-gray-200 dark:border-gray-700 align-top p-0">
                                    <div class="{{ $bgRoi }} {{ $textRoi }} px-2 py-2 text-center">
                                        <div class="text-[10px] opacity-70">N={{ number_format($cell['runs']) }}</div>
                                        <div class="font-semibold text-sm mt-0.5">勝 {{ $cell['win_rate'] }}%</div>
                                        <div class="text-[11px] opacity-90">複 {{ $cell['place_rate'] }}%</div>
                                        <div class="text-[11px] font-mono mt-0.5">ROI {{ $cell['win_roi'] }}%</div>
                                    </div>
                                </td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div class="mt-3 flex items-center gap-3 text-[10px] text-gray-500">
            <span>背景色: 単勝ROI</span>
            <span class="inline-block w-4 h-3 bg-rose-50 border border-gray-200"></span>
            <span>&lt;60</span>
            <span class="inline-block w-4 h-3 bg-amber-100 border border-gray-200"></span>
            <span>60–100</span>
            <span class="inline-block w-4 h-3 bg-emerald-300 border border-gray-200"></span>
            <span>100–160</span>
            <span class="inline-block w-4 h-3 bg-emerald-500 border border-gray-200"></span>
            <span>≥160</span>
        </div>
    </div>

    {{-- 距離帯別 --}}
    <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm ring-1 ring-gray-100 dark:ring-gray-700 p-4">
        <h2 class="text-sm font-semibold text-gray-700 dark:text-gray-200 mb-3">距離帯別の集計</h2>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="text-xs text-gray-500 dark:text-gray-400 bg-gray-50 dark:bg-gray-900/30">
                    <tr>
                        <th class="px-3 py-2 text-left">距離帯</th>
                        <th class="px-3 py-2 text-right">範囲(m)</th>
                        <th class="px-3 py-2 text-right">対象</th>
                        <th class="px-3 py-2 text-right">勝</th>
                        <th class="px-3 py-2 text-right">3着内</th>
                        <th class="px-3 py-2 text-right">勝率</th>
                        <th class="px-3 py-2 text-right">複勝率</th>
                        <th class="px-3 py-2 text-right">単勝ROI</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                    @forelse ($bandsAgg as $b)
                        @php
                            [$wrBg, $wrTx] = $heatColor('win_rate', (float)$b['win_rate']);
                            [$prBg, $prTx] = $heatColor('place_rate', (float)$b['place_rate']);
                            [$roiBg, $roiTx] = $heatColor('win_roi', (float)$b['win_roi']);
                        @endphp
                        <tr>
                            <td class="px-3 py-2 font-medium text-gray-700 dark:text-gray-200">{{ $b['band'] }}</td>
                            <td class="px-3 py-2 text-right text-xs text-gray-500">{{ $b['min'] }}–{{ $b['max'] === 9999 ? '∞' : $b['max'] }}</td>
                            <td class="px-3 py-2 text-right tabular-nums">{{ number_format($b['runs']) }}</td>
                            <td class="px-3 py-2 text-right tabular-nums">{{ number_format($b['wins']) }}</td>
                            <td class="px-3 py-2 text-right tabular-nums">{{ number_format($b['top3']) }}</td>
                            <td class="px-3 py-2 text-right tabular-nums"><span class="px-1.5 rounded {{ $wrBg }} {{ $wrTx }}">{{ $b['win_rate'] }}%</span></td>
                            <td class="px-3 py-2 text-right tabular-nums"><span class="px-1.5 rounded {{ $prBg }} {{ $prTx }}">{{ $b['place_rate'] }}%</span></td>
                            <td class="px-3 py-2 text-right tabular-nums font-mono"><span class="px-1.5 rounded {{ $roiBg }} {{ $roiTx }}">{{ $b['win_roi'] }}%</span></td>
                        </tr>
                    @empty
                        <tr><td colspan="8" class="px-3 py-6 text-center text-gray-400">データがありません</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    {{-- 競馬場×トラック --}}
    <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm ring-1 ring-gray-100 dark:ring-gray-700 p-4">
        <h2 class="text-sm font-semibold text-gray-700 dark:text-gray-200 mb-3">競馬場 × トラック種別</h2>
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
                    @forelse ($venuesAgg as $v)
                        @php
                            [$wrBg, $wrTx] = $heatColor('win_rate', (float)$v['win_rate']);
                            [$prBg, $prTx] = $heatColor('place_rate', (float)$v['place_rate']);
                            [$roiBg, $roiTx] = $heatColor('win_roi', (float)$v['win_roi']);
                        @endphp
                        <tr>
                            <td class="px-3 py-2 text-gray-700 dark:text-gray-200">{{ $v['name'] ?? '-' }}</td>
                            <td class="px-3 py-2 text-xs text-gray-500">{{ $v['track_type'] ?? '-' }}</td>
                            <td class="px-3 py-2 text-right tabular-nums">{{ number_format($v['runs']) }}</td>
                            <td class="px-3 py-2 text-right tabular-nums">{{ number_format($v['wins']) }}</td>
                            <td class="px-3 py-2 text-right tabular-nums">{{ number_format($v['top3']) }}</td>
                            <td class="px-3 py-2 text-right tabular-nums"><span class="px-1.5 rounded {{ $wrBg }} {{ $wrTx }}">{{ $v['win_rate'] }}%</span></td>
                            <td class="px-3 py-2 text-right tabular-nums"><span class="px-1.5 rounded {{ $prBg }} {{ $prTx }}">{{ $v['place_rate'] }}%</span></td>
                            <td class="px-3 py-2 text-right tabular-nums font-mono"><span class="px-1.5 rounded {{ $roiBg }} {{ $roiTx }}">{{ $v['win_roi'] }}%</span></td>
                        </tr>
                    @empty
                        <tr><td colspan="8" class="px-3 py-6 text-center text-gray-400">データがありません</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    {{-- ペース×脚質 詳細リスト --}}
    @if (!empty($pivot))
    <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm ring-1 ring-gray-100 dark:ring-gray-700 p-4">
        <h2 class="text-sm font-semibold text-gray-700 dark:text-gray-200 mb-3">ペース × 脚質 詳細</h2>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="text-xs text-gray-500 dark:text-gray-400 bg-gray-50 dark:bg-gray-900/30">
                    <tr>
                        <th class="px-3 py-2 text-left">ペース</th>
                        <th class="px-3 py-2 text-left">脚質</th>
                        <th class="px-3 py-2 text-right">対象</th>
                        <th class="px-3 py-2 text-right">勝率</th>
                        <th class="px-3 py-2 text-right">複勝率</th>
                        <th class="px-3 py-2 text-right">単勝ROI</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                    @foreach ($pivot as $row)
                        <tr>
                            <td class="px-3 py-2 text-gray-700 dark:text-gray-200">{{ $row['pace'] ?? '-' }}</td>
                            <td class="px-3 py-2 text-gray-700 dark:text-gray-200">{{ $row['running_style'] ?? '-' }}</td>
                            <td class="px-3 py-2 text-right tabular-nums">{{ number_format($row['runs']) }}</td>
                            <td class="px-3 py-2 text-right tabular-nums">{{ $row['win_rate'] }}%</td>
                            <td class="px-3 py-2 text-right tabular-nums">{{ $row['place_rate'] }}%</td>
                            <td class="px-3 py-2 text-right tabular-nums font-mono">{{ $row['win_roi'] }}%</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
    @endif
</div>
@endsection
