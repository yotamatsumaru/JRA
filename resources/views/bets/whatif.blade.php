@extends('layouts.app')
@section('title', 'What-if シミュレーション')

@section('content')
<div class="space-y-5">
    <x-page-header title="What-if シミュレーション" subtitle="もし〇〇していたら…の収支検証" icon="sparkles">
        <x-slot name="actions">
            <a href="{{ route('betting.dashboard') }}" class="inline-flex items-center space-x-1 bg-gray-200 hover:bg-gray-300 dark:bg-gray-700 dark:hover:bg-gray-600 text-gray-800 dark:text-gray-100 px-3 py-1.5 rounded text-sm">
                <x-icon name="chart" class="w-4 h-4" /><span>ダッシュボード</span>
            </a>
            <a href="{{ route('bankroll.index') }}" class="inline-flex items-center space-x-1 bg-turf-600 hover:bg-turf-700 text-white px-3 py-1.5 rounded text-sm">
                <x-icon name="cash" class="w-4 h-4" /><span>バンクロール</span>
            </a>
        </x-slot>
    </x-page-header>

    {{-- フィルタフォーム --}}
    <form method="GET" class="bg-white dark:bg-gray-800 rounded-lg shadow-sm ring-1 ring-gray-100 dark:ring-gray-700 p-4 grid grid-cols-2 md:grid-cols-6 gap-3 text-sm">
        <div>
            <label class="block text-xs text-gray-600 dark:text-gray-400 mb-1">From</label>
            <input type="date" name="from" value="{{ $from }}" class="w-full border rounded px-2 py-1.5 dark:bg-gray-700 dark:border-gray-600">
        </div>
        <div>
            <label class="block text-xs text-gray-600 dark:text-gray-400 mb-1">To</label>
            <input type="date" name="to" value="{{ $to }}" class="w-full border rounded px-2 py-1.5 dark:bg-gray-700 dark:border-gray-600">
        </div>
        <div>
            <label class="block text-xs text-gray-600 dark:text-gray-400 mb-1">券種</label>
            <select name="kind" class="w-full border rounded px-2 py-1.5 dark:bg-gray-700 dark:border-gray-600">
                <option value="">全て</option>
                @foreach ($kinds as $k => $label)
                    <option value="{{ $k }}" @selected($kind === $k)>{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="block text-xs text-gray-600 dark:text-gray-400 mb-1">賭け金倍率</label>
            <input type="number" name="multiplier" value="{{ $multiplier }}" step="0.1" min="0.1" max="10"
                   class="w-full border rounded px-2 py-1.5 dark:bg-gray-700 dark:border-gray-600 tabular-nums">
        </div>
        <div>
            <label class="block text-xs text-gray-600 dark:text-gray-400 mb-1">券種ROI下限 (%)</label>
            <input type="number" name="min_roi_kind" value="{{ $minRoiKind }}" step="1"
                   placeholder="例: 100"
                   class="w-full border rounded px-2 py-1.5 dark:bg-gray-700 dark:border-gray-600 tabular-nums">
        </div>
        <div class="flex items-end">
            <button class="bg-turf-600 hover:bg-turf-700 text-white px-3 py-1.5 rounded inline-flex items-center space-x-1 w-full justify-center">
                <x-icon name="filter" class="w-4 h-4" /><span>シミュレート</span>
            </button>
        </div>
    </form>

    @if ($bets->isEmpty())
        <x-empty-state
            icon="sparkles"
            title="精算済みの馬券がありません"
            message="What-if シミュレーションには、結果が出た馬券データが必要です。"
            actionLabel="馬券一覧へ"
            :actionHref="route('bets.index')"
            actionIcon="list" />
    @else

    {{-- ベースライン（実績） --}}
    <div class="bg-white dark:bg-gray-800 rounded-lg shadow p-5">
        <h2 class="text-sm font-semibold text-gray-700 dark:text-gray-200 mb-3 flex items-center space-x-1">
            <x-icon name="document" class="w-4 h-4" /><span>実績ベースライン (フィルタ後 {{ $bets->count() }} 件)</span>
        </h2>
        <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
            <x-kpi-card label="投資" :value="'¥'.number_format($actualStake)" icon="cash" color="sand" />
            <x-kpi-card label="払戻" :value="'¥'.number_format($actualReturn)" icon="cash" color="gold" />
            <x-kpi-card
                label="収支"
                :value="($actualProfit >= 0 ? '+' : '').'¥'.number_format($actualProfit)"
                icon="chart"
                :color="$actualProfit >= 0 ? 'turf' : 'rose'" />
            <x-kpi-card label="ROI" :value="$actualRoi !== null ? $actualRoi.'%' : '-'" icon="bolt" color="purple" />
        </div>
    </div>

    {{-- シナリオ1: 倍率 --}}
    <div class="bg-white dark:bg-gray-800 rounded-lg shadow p-5">
        <h2 class="text-sm font-semibold text-gray-700 dark:text-gray-200 mb-3 flex items-center space-x-1">
            <x-icon name="sparkles" class="w-4 h-4 text-purple-500" /><span>シナリオ1: {{ $scenarioMul['label'] }}</span>
        </h2>
        <p class="text-xs text-gray-500 dark:text-gray-400 mb-3">
            全買い目の賭け金を一律 {{ $multiplier }} 倍にした場合の収支を線形に再計算します。
        </p>
        @include('bets._whatif_card', ['s' => $scenarioMul, 'baseline' => $actualProfit])
    </div>

    {{-- シナリオ2: 的中だけ --}}
    <div class="bg-white dark:bg-gray-800 rounded-lg shadow p-5">
        <h2 class="text-sm font-semibold text-gray-700 dark:text-gray-200 mb-3 flex items-center space-x-1">
            <x-icon name="target" class="w-4 h-4 text-emerald-500" /><span>シナリオ2: {{ $scenarioHit['label'] }}</span>
        </h2>
        <p class="text-xs text-gray-500 dark:text-gray-400 mb-3">
            全 {{ $bets->count() }} 件中、的中した {{ $scenarioHit['count'] }} 件だけを購入していた場合の理想収支。
            不的中だった <strong class="text-rose-600">{{ $missCount }} 件 / ¥{{ number_format($missStake) }}</strong> をスキップ。
        </p>
        @include('bets._whatif_card', ['s' => $scenarioHit, 'baseline' => $actualProfit])
    </div>

    {{-- シナリオ3: 券種別ROIフィルタ --}}
    @if ($scenarioFilter)
    <div class="bg-white dark:bg-gray-800 rounded-lg shadow p-5">
        <h2 class="text-sm font-semibold text-gray-700 dark:text-gray-200 mb-3 flex items-center space-x-1">
            <x-icon name="filter" class="w-4 h-4 text-sky-500" /><span>シナリオ3: {{ $scenarioFilter['label'] }}</span>
        </h2>
        <p class="text-xs text-gray-500 dark:text-gray-400 mb-3">
            対象券種:
            @foreach ($scenarioFilter['kinds'] as $k)
                <span class="inline-block bg-sky-100 dark:bg-sky-900/30 text-sky-700 dark:text-sky-300 px-2 py-0.5 rounded text-xs mr-1">{{ \App\Models\Bet::KIND_LABELS[$k] ?? $k }}</span>
            @endforeach
            ({{ $scenarioFilter['count'] }} 件)
        </p>
        @include('bets._whatif_card', ['s' => $scenarioFilter, 'baseline' => $actualProfit])
    </div>
    @endif

    {{-- シナリオ4: ケリー基準 --}}
    @if ($scenarioKelly)
    <div class="bg-white dark:bg-gray-800 rounded-lg shadow p-5">
        <h2 class="text-sm font-semibold text-gray-700 dark:text-gray-200 mb-3 flex items-center space-x-1">
            <x-icon name="bolt" class="w-4 h-4 text-amber-500" /><span>{{ $scenarioKelly['label'] }}</span>
        </h2>
        <div class="grid grid-cols-2 md:grid-cols-4 gap-3 text-sm">
            <div class="bg-amber-50 dark:bg-amber-900/20 rounded p-3">
                <div class="text-xs text-amber-700 dark:text-amber-300">全体的中率</div>
                <div class="text-2xl font-bold tabular-nums">{{ $scenarioKelly['hit_rate'] }}%</div>
            </div>
            <div class="bg-amber-50 dark:bg-amber-900/20 rounded p-3">
                <div class="text-xs text-amber-700 dark:text-amber-300">的中時平均オッズ</div>
                <div class="text-2xl font-bold tabular-nums">{{ $scenarioKelly['avg_odds'] }}倍</div>
            </div>
            <div class="bg-amber-50 dark:bg-amber-900/20 rounded p-3">
                <div class="text-xs text-amber-700 dark:text-amber-300">推奨投資比率</div>
                <div class="text-2xl font-bold tabular-nums">{{ $scenarioKelly['fraction'] }}%</div>
            </div>
            <div class="bg-gray-50 dark:bg-gray-700/30 rounded p-3 text-xs text-gray-600 dark:text-gray-300">
                {{ $scenarioKelly['note'] }}
            </div>
        </div>
        <p class="text-xs text-gray-500 dark:text-gray-400 mt-3">
            ※ ケリー基準: f* = (b·p − q)/b （b=平均オッズ−1, p=的中率, q=1−p）。理論的な「破産しない最大投資比率」の目安。
        </p>
    </div>
    @endif

    {{-- 券種別ROI --}}
    <div class="bg-white dark:bg-gray-800 rounded-lg shadow p-5">
        <h2 class="text-sm font-semibold text-gray-700 dark:text-gray-200 mb-3">参考: 券種別 ROI (フィルタ後)</h2>
        <table class="w-full text-sm">
            <thead class="bg-gray-50 dark:bg-gray-700/50 text-xs text-gray-600 dark:text-gray-300">
                <tr>
                    <th class="px-3 py-2 text-left">券種</th>
                    <th class="px-3 py-2 text-right">件数</th>
                    <th class="px-3 py-2 text-right">投資</th>
                    <th class="px-3 py-2 text-right">払戻</th>
                    <th class="px-3 py-2 text-right">収支</th>
                    <th class="px-3 py-2 text-right">ROI</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                @foreach ($kindRoiTable as $r)
                <tr>
                    <td class="px-3 py-2 font-medium">{{ $r['kind_label'] }}</td>
                    <td class="px-3 py-2 text-right tabular-nums text-xs">{{ $r['cnt'] }}</td>
                    <td class="px-3 py-2 text-right tabular-nums text-xs">¥{{ number_format($r['stake']) }}</td>
                    <td class="px-3 py-2 text-right tabular-nums text-xs">¥{{ number_format($r['return']) }}</td>
                    <td class="px-3 py-2 text-right tabular-nums font-bold {{ $r['profit'] >= 0 ? 'text-emerald-600' : 'text-rose-600' }}">
                        {{ $r['profit'] >= 0 ? '+' : '' }}¥{{ number_format($r['profit']) }}
                    </td>
                    <td class="px-3 py-2 text-right tabular-nums">
                        <span class="font-bold {{ $r['roi'] >= 100 ? 'text-emerald-600' : 'text-rose-600' }}">{{ $r['roi'] }}%</span>
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    @endif {{-- bets is not empty --}}
</div>
@endsection
