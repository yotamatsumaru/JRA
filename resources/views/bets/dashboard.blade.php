@extends('layouts.app')
@section('title', '収支ダッシュボード')

@section('content')
<div class="space-y-5" x-data="{ darkMode: $root.darkMode || false }">
    <x-page-header title="収支ダッシュボード" subtitle="馬券の累計収支・回収率・傾向分析" icon="chart">
        <x-slot name="actions">
            <a href="{{ route('bets.create') }}" class="inline-flex items-center space-x-1 bg-turf-600 hover:bg-turf-700 text-white px-4 py-2 rounded text-sm">
                <x-icon name="plus" class="w-4 h-4" /><span>馬券を登録</span>
            </a>
            <a href="{{ route('bankroll.index') }}" class="inline-flex items-center space-x-1 bg-amber-600 hover:bg-amber-700 text-white px-3 py-2 rounded text-sm">
                <x-icon name="cash" class="w-4 h-4" /><span>バンクロール</span>
            </a>
            <a href="{{ route('bets.whatif') }}" class="inline-flex items-center space-x-1 bg-purple-600 hover:bg-purple-700 text-white px-3 py-2 rounded text-sm">
                <x-icon name="sparkles" class="w-4 h-4" /><span>What-if</span>
            </a>
            <a href="{{ route('bets.index') }}" class="inline-flex items-center space-x-1 bg-gray-200 hover:bg-gray-300 dark:bg-gray-700 dark:hover:bg-gray-600 text-gray-800 dark:text-gray-100 px-4 py-2 rounded text-sm">
                <x-icon name="list" class="w-4 h-4" /><span>買い目一覧</span>
            </a>
        </x-slot>
    </x-page-header>

    {{-- 期間フィルタ --}}
    <form method="GET" class="bg-white dark:bg-gray-800 rounded-lg shadow-sm ring-1 ring-gray-100 dark:ring-gray-700 p-4 flex flex-wrap items-end gap-3 text-sm">
        <div>
            <label class="block text-xs text-gray-600 dark:text-gray-400 mb-1">From</label>
            <input type="date" name="from" value="{{ $from }}" class="border rounded px-2 py-1.5 dark:bg-gray-700 dark:border-gray-600">
        </div>
        <div>
            <label class="block text-xs text-gray-600 dark:text-gray-400 mb-1">To</label>
            <input type="date" name="to" value="{{ $to }}" class="border rounded px-2 py-1.5 dark:bg-gray-700 dark:border-gray-600">
        </div>
        <button class="bg-turf-600 hover:bg-turf-700 text-white px-3 py-1.5 rounded inline-flex items-center space-x-1">
            <x-icon name="filter" class="w-4 h-4" /><span>適用</span>
        </button>
        <a href="{{ route('betting.dashboard') }}" class="text-gray-500 hover:text-gray-700 px-2">クリア</a>
    </form>

    {{-- 累計KPI --}}
    <div class="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-6 gap-3">
        <x-kpi-card label="購入件数" :value="number_format($kpi['count'])" subtext="件" icon="list" color="turf" />
        <x-kpi-card label="投資総額" :value="'¥'.number_format($kpi['stake'])" icon="cash" color="sand" />
        <x-kpi-card label="払戻総額" :value="'¥'.number_format($kpi['return'])" icon="cash" color="gold" />
        <x-kpi-card
            label="累計収支"
            :value="($kpi['profit'] >= 0 ? '+' : '').'¥'.number_format($kpi['profit'])"
            icon="chart"
            :color="$kpi['profit'] >= 0 ? 'turf' : 'rose'" />
        <x-kpi-card label="回収率" :value="$kpi['roi'] !== null ? $kpi['roi'].'%' : '-'" subtext="ROI" icon="bolt" color="purple" />
        <x-kpi-card label="的中率" :value="$kpi['hit_rate'] !== null ? $kpi['hit_rate'].'%' : '-'" :subtext="$kpi['hits'].'/'.$kpi['count'].' 件'" icon="trophy" color="sky" />
    </div>

    @if ($kpi['count'] === 0)
        <x-empty-state
            icon="cash"
            title="まだ馬券データがありません"
            message="馬券を登録すると、ここに収支推移と各種傾向が表示されます"
            actionLabel="最初の馬券を登録"
            :actionHref="route('bets.create')"
            actionIcon="plus" />
    @else

    {{-- 連勝・連敗 --}}
    <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
        <div class="bg-emerald-50 dark:bg-emerald-900/20 rounded-lg p-4 ring-1 ring-emerald-200 dark:ring-emerald-700">
            <div class="text-xs text-emerald-700 dark:text-emerald-300 font-bold">最大連勝</div>
            <div class="text-3xl font-bold text-emerald-700 dark:text-emerald-300">{{ $streaks['max_win'] }}<span class="text-sm font-normal">連勝</span></div>
        </div>
        <div class="bg-rose-50 dark:bg-rose-900/20 rounded-lg p-4 ring-1 ring-rose-200 dark:ring-rose-700">
            <div class="text-xs text-rose-700 dark:text-rose-300 font-bold">最大連敗</div>
            <div class="text-3xl font-bold text-rose-700 dark:text-rose-300">{{ $streaks['max_lose'] }}<span class="text-sm font-normal">連敗</span></div>
        </div>
        <div class="bg-sky-50 dark:bg-sky-900/20 rounded-lg p-4 ring-1 ring-sky-200 dark:ring-sky-700">
            <div class="text-xs text-sky-700 dark:text-sky-300 font-bold">直近の状態</div>
            <div class="text-3xl font-bold text-sky-700 dark:text-sky-300">
                @if ($streaks['cur_win'] > 0) {{ $streaks['cur_win'] }}<span class="text-sm font-normal">連勝中</span>
                @elseif ($streaks['cur_lose'] > 0) {{ $streaks['cur_lose'] }}<span class="text-sm font-normal">連敗中</span>
                @else <span class="text-sm">-</span>
                @endif
            </div>
        </div>
        <div class="bg-gold-50 dark:bg-gold-900/20 rounded-lg p-4 ring-1 ring-gold-200 dark:ring-gold-700">
            <div class="text-xs text-gold-700 dark:text-gold-300 font-bold">過去6ヶ月平均ROI</div>
            <div class="text-3xl font-bold text-gold-700 dark:text-gold-300">{{ $monthlyTarget['avg_roi'] ?? '-' }}<span class="text-sm font-normal">%</span></div>
        </div>
    </div>

    {{-- グラフ: 累積回収率 + 月次収支 --}}
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
        <div class="bg-white dark:bg-gray-800 rounded-lg shadow p-5">
            <h2 class="text-sm font-semibold text-gray-700 dark:text-gray-200 mb-3">累積回収率推移</h2>
            <div id="chart-cum" style="height:280px"></div>
        </div>
        <div class="bg-white dark:bg-gray-800 rounded-lg shadow p-5">
            <h2 class="text-sm font-semibold text-gray-700 dark:text-gray-200 mb-3">月次収支</h2>
            <div id="chart-monthly" style="height:280px"></div>
        </div>
    </div>

    {{-- 年次推移 (Phase 2-E) --}}
    @if ($yearly->isNotEmpty())
    <div class="bg-white dark:bg-gray-800 rounded-lg shadow p-5">
        <h2 class="text-sm font-semibold text-gray-700 dark:text-gray-200 mb-3 flex items-center space-x-1">
            <x-icon name="chart" class="w-4 h-4 text-turf-600" /><span>年次推移</span>
        </h2>
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
            <div id="chart-yearly" style="height:260px"></div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-gray-50 dark:bg-gray-700/50 text-xs text-gray-600 dark:text-gray-300">
                        <tr>
                            <th class="px-3 py-2 text-left">年</th>
                            <th class="px-3 py-2 text-right">件数</th>
                            <th class="px-3 py-2 text-right">投資</th>
                            <th class="px-3 py-2 text-right">払戻</th>
                            <th class="px-3 py-2 text-right">収支</th>
                            <th class="px-3 py-2 text-right">ROI</th>
                            <th class="px-3 py-2 text-right">的中率</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                        @foreach ($yearly as $r)
                        <tr>
                            <td class="px-3 py-2 font-medium">{{ $r['y'] }}</td>
                            <td class="px-3 py-2 text-right tabular-nums text-xs">{{ $r['cnt'] }}</td>
                            <td class="px-3 py-2 text-right tabular-nums text-xs">¥{{ number_format($r['stake']) }}</td>
                            <td class="px-3 py-2 text-right tabular-nums text-xs">¥{{ number_format($r['return']) }}</td>
                            <td class="px-3 py-2 text-right tabular-nums font-bold {{ $r['profit'] >= 0 ? 'text-emerald-600' : 'text-rose-600' }}">
                                {{ $r['profit'] >= 0 ? '+' : '' }}¥{{ number_format($r['profit']) }}
                            </td>
                            <td class="px-3 py-2 text-right tabular-nums">
                                <span class="font-bold {{ $r['roi'] >= 100 ? 'text-emerald-600' : 'text-rose-600' }}">{{ $r['roi'] }}%</span>
                            </td>
                            <td class="px-3 py-2 text-right tabular-nums text-xs">{{ $r['hit_rate'] }}%</td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    @endif

    {{-- 券種別ROI --}}
    <div class="bg-white dark:bg-gray-800 rounded-lg shadow p-5">
        <h2 class="text-sm font-semibold text-gray-700 dark:text-gray-200 mb-3">券種別 ROI</h2>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 dark:bg-gray-700/50 text-xs text-gray-600 dark:text-gray-300">
                    <tr>
                        <th class="px-3 py-2 text-left">券種</th>
                        <th class="px-3 py-2 text-right">購入数</th>
                        <th class="px-3 py-2 text-right">投資</th>
                        <th class="px-3 py-2 text-right">払戻</th>
                        <th class="px-3 py-2 text-right">収支</th>
                        <th class="px-3 py-2 text-right">回収率</th>
                        <th class="px-3 py-2 text-right">的中率</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                    @foreach ($byKind as $r)
                    <tr>
                        <td class="px-3 py-2 font-medium">{{ $r['kind_label'] }}</td>
                        <td class="px-3 py-2 text-right tabular-nums">{{ $r['cnt'] }}</td>
                        <td class="px-3 py-2 text-right tabular-nums">¥{{ number_format($r['stake']) }}</td>
                        <td class="px-3 py-2 text-right tabular-nums">¥{{ number_format($r['return']) }}</td>
                        <td class="px-3 py-2 text-right tabular-nums font-bold {{ $r['profit'] >= 0 ? 'text-emerald-600' : 'text-rose-600' }}">
                            {{ $r['profit'] >= 0 ? '+' : '' }}¥{{ number_format($r['profit']) }}
                        </td>
                        <td class="px-3 py-2 text-right tabular-nums">
                            <span class="font-bold {{ $r['roi'] >= 100 ? 'text-emerald-600' : 'text-rose-600' }}">{{ $r['roi'] }}%</span>
                        </td>
                        <td class="px-3 py-2 text-right tabular-nums">{{ $r['hit_rate'] }}%</td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>

    {{-- 競馬場別 + トラック別 --}}
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
        <div class="bg-white dark:bg-gray-800 rounded-lg shadow p-5">
            <h2 class="text-sm font-semibold text-gray-700 dark:text-gray-200 mb-3">競馬場別 ROI</h2>
            @if ($byVenue->isEmpty())
                <p class="text-sm text-gray-400">データなし</p>
            @else
            <table class="w-full text-sm">
                <thead class="text-xs text-gray-500"><tr><th class="text-left">競馬場</th><th class="text-right">投資</th><th class="text-right">収支</th><th class="text-right">ROI</th></tr></thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                @foreach ($byVenue as $r)
                <tr>
                    <td class="py-1.5">{{ $r['name'] }} <span class="text-xs text-gray-400">({{ $r['cnt'] }})</span></td>
                    <td class="py-1.5 text-right tabular-nums text-xs">¥{{ number_format($r['stake']) }}</td>
                    <td class="py-1.5 text-right tabular-nums text-xs font-bold {{ $r['profit'] >= 0 ? 'text-emerald-600' : 'text-rose-600' }}">{{ $r['profit'] >= 0 ? '+' : '' }}¥{{ number_format($r['profit']) }}</td>
                    <td class="py-1.5 text-right tabular-nums font-bold {{ $r['roi'] >= 100 ? 'text-emerald-600' : 'text-rose-600' }}">{{ $r['roi'] }}%</td>
                </tr>
                @endforeach
                </tbody>
            </table>
            @endif
        </div>

        <div class="bg-white dark:bg-gray-800 rounded-lg shadow p-5">
            <h2 class="text-sm font-semibold text-gray-700 dark:text-gray-200 mb-3">距離・トラック別 ROI</h2>
            @if ($byTrack->isEmpty())
                <p class="text-sm text-gray-400">データなし</p>
            @else
            <table class="w-full text-sm">
                <thead class="text-xs text-gray-500"><tr><th class="text-left">区分</th><th class="text-right">投資</th><th class="text-right">収支</th><th class="text-right">ROI</th></tr></thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                @foreach ($byTrack as $r)
                <tr>
                    <td class="py-1.5">{{ $r['label'] }} <span class="text-xs text-gray-400">({{ $r['cnt'] }})</span></td>
                    <td class="py-1.5 text-right tabular-nums text-xs">¥{{ number_format($r['stake']) }}</td>
                    <td class="py-1.5 text-right tabular-nums text-xs font-bold {{ $r['profit'] >= 0 ? 'text-emerald-600' : 'text-rose-600' }}">{{ $r['profit'] >= 0 ? '+' : '' }}¥{{ number_format($r['profit']) }}</td>
                    <td class="py-1.5 text-right tabular-nums font-bold {{ $r['roi'] >= 100 ? 'text-emerald-600' : 'text-rose-600' }}">{{ $r['roi'] }}%</td>
                </tr>
                @endforeach
                </tbody>
            </table>
            @endif
        </div>
    </div>

    {{-- 騎手別 + 馬別 --}}
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
        <div class="bg-white dark:bg-gray-800 rounded-lg shadow p-5">
            <h2 class="text-sm font-semibold text-gray-700 dark:text-gray-200 mb-3">騎手別 収支 (1着騎手・3R以上)</h2>
            @if ($byJockey->isEmpty())
                <p class="text-sm text-gray-400">データなし（3レース以上の購入が必要）</p>
            @else
            <table class="w-full text-sm">
                <thead class="text-xs text-gray-500"><tr><th class="text-left">騎手</th><th class="text-right">投資</th><th class="text-right">収支</th><th class="text-right">ROI</th></tr></thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                @foreach ($byJockey as $r)
                <tr>
                    <td class="py-1.5">{{ $r['name'] }} <span class="text-xs text-gray-400">({{ $r['cnt'] }})</span></td>
                    <td class="py-1.5 text-right tabular-nums text-xs">¥{{ number_format($r['stake']) }}</td>
                    <td class="py-1.5 text-right tabular-nums text-xs font-bold {{ $r['profit'] >= 0 ? 'text-emerald-600' : 'text-rose-600' }}">{{ $r['profit'] >= 0 ? '+' : '' }}¥{{ number_format($r['profit']) }}</td>
                    <td class="py-1.5 text-right tabular-nums font-bold {{ $r['roi'] >= 100 ? 'text-emerald-600' : 'text-rose-600' }}">{{ $r['roi'] }}%</td>
                </tr>
                @endforeach
                </tbody>
            </table>
            @endif
        </div>

        <div class="bg-white dark:bg-gray-800 rounded-lg shadow p-5">
            <h2 class="text-sm font-semibold text-gray-700 dark:text-gray-200 mb-3">馬別 収支 (買い目1頭目・2R以上)</h2>
            @if ($byHorse->isEmpty())
                <p class="text-sm text-gray-400">データなし（2レース以上の買い目が必要）</p>
            @else
            <table class="w-full text-sm">
                <thead class="text-xs text-gray-500"><tr><th class="text-left">馬名</th><th class="text-right">投資</th><th class="text-right">収支</th><th class="text-right">ROI</th></tr></thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                @foreach ($byHorse as $r)
                <tr>
                    <td class="py-1.5">{{ $r['name'] }} <span class="text-xs text-gray-400">({{ $r['cnt'] }})</span></td>
                    <td class="py-1.5 text-right tabular-nums text-xs">¥{{ number_format($r['stake']) }}</td>
                    <td class="py-1.5 text-right tabular-nums text-xs font-bold {{ $r['profit'] >= 0 ? 'text-emerald-600' : 'text-rose-600' }}">{{ $r['profit'] >= 0 ? '+' : '' }}¥{{ number_format($r['profit']) }}</td>
                    <td class="py-1.5 text-right tabular-nums font-bold {{ $r['roi'] >= 100 ? 'text-emerald-600' : 'text-rose-600' }}">{{ $r['roi'] }}%</td>
                </tr>
                @endforeach
                </tbody>
            </table>
            @endif
        </div>
    </div>

    {{-- 配当ベスト10 --}}
    <div class="bg-white dark:bg-gray-800 rounded-lg shadow p-5">
        <h2 class="text-sm font-semibold text-gray-700 dark:text-gray-200 mb-3 flex items-center space-x-1">
            <x-icon name="trophy" class="w-4 h-4 text-gold-500" /><span>配当ベスト10（自分の的中）</span>
        </h2>
        @if ($bestPayouts->isEmpty())
            <p class="text-sm text-gray-400">的中履歴なし</p>
        @else
        <table class="w-full text-sm">
            <thead class="text-xs text-gray-500"><tr><th class="text-left">日付</th><th class="text-left">レース</th><th class="text-left">券種 / 組合せ</th><th class="text-right">投資</th><th class="text-right">払戻</th><th class="text-right">倍率</th></tr></thead>
            <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
            @foreach ($bestPayouts as $leg)
            <tr>
                <td class="py-1.5 text-xs text-gray-500">{{ $leg->bet?->race?->race_date?->format('Y/m/d') }}</td>
                <td class="py-1.5"><a href="{{ route('races.show', $leg->bet?->race) }}" class="text-turf-600 hover:underline">{{ $leg->bet?->race?->venue?->name }} {{ $leg->bet?->race?->race_number }}R</a></td>
                <td class="py-1.5 text-xs"><span class="font-medium">{{ \App\Models\Bet::KIND_LABELS[$leg->bet?->kind] ?? '' }}</span> <span class="font-mono text-gray-600">{{ $leg->combination }}</span></td>
                <td class="py-1.5 text-right tabular-nums text-xs">¥{{ number_format($leg->stake) }}</td>
                <td class="py-1.5 text-right tabular-nums text-gold-600 font-bold">¥{{ number_format($leg->payout) }}</td>
                <td class="py-1.5 text-right tabular-nums font-bold">{{ $leg->stake > 0 ? round($leg->payout / $leg->stake, 1) : 0 }}倍</td>
            </tr>
            @endforeach
            </tbody>
        </table>
        @endif
    </div>

    @endif {{-- count > 0 --}}

    {{-- ============================================================ --}}
    {{-- 払戻データ概況（自分の馬券に関係なく、取込済の全レース母集団から） --}}
    {{-- ============================================================ --}}
    <div class="mt-8">
        <div class="flex items-center justify-between mb-3">
            <h2 class="text-base font-semibold text-gray-800 dark:text-gray-100 flex items-center space-x-2">
                <x-icon name="cash" class="w-5 h-5 text-gold-500" />
                <span>払戻データ概況</span>
                <span class="text-xs font-normal text-gray-400">取込済全レースの公式払戻</span>
            </h2>
            <div class="flex items-center space-x-2">
                <a href="{{ route('betting.payouts.list', ['from' => $from, 'to' => $to]) }}"
                   class="inline-flex items-center space-x-1 bg-gray-100 hover:bg-gray-200 dark:bg-gray-700 dark:hover:bg-gray-600 text-gray-700 dark:text-gray-200 px-3 py-1.5 rounded text-xs">
                    <x-icon name="list" class="w-4 h-4" /><span>払戻金一覧</span>
                </a>
                <a href="{{ route('betting.payouts') }}"
                   class="inline-flex items-center space-x-1 bg-gray-100 hover:bg-gray-200 dark:bg-gray-700 dark:hover:bg-gray-600 text-gray-700 dark:text-gray-200 px-3 py-1.5 rounded text-xs">
                    <x-icon name="chart" class="w-4 h-4" /><span>傾向分析</span>
                </a>
            </div>
        </div>

        @if ($payoutOverview['total_payouts'] === 0)
            <div class="bg-white dark:bg-gray-800 rounded-lg shadow p-6 text-center text-sm text-gray-500">
                払戻データがまだ取り込まれていません。<br>
                <code class="text-xs bg-gray-100 dark:bg-gray-700 px-2 py-0.5 rounded">php artisan netkeiba:year</code> でレース＋払戻を一括取込できます。
            </div>
        @else
        <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-4">
            <x-kpi-card
                label="取込レース数"
                :value="number_format($payoutOverview['total_races'])"
                subtext="レース"
                icon="list" color="turf" />
            <x-kpi-card
                label="払戻レコード数"
                :value="number_format($payoutOverview['total_payouts'])"
                subtext="件"
                icon="cash" color="gold" />
            <x-kpi-card
                label="平均配当"
                :value="'¥'.number_format($payoutOverview['avg_amount'])"
                subtext="100円あたり"
                icon="chart" color="sand" />
            <x-kpi-card
                label="最高配当（直近）"
                :value="$payoutOverview['top_recent']->isNotEmpty() ? '¥'.number_format($payoutOverview['top_recent']->first()->amount) : '-'"
                :subtext="$payoutOverview['top_recent']->isNotEmpty() ? (\App\Models\Bet::KIND_LABELS[$payoutOverview['top_recent']->first()->kind] ?? '') : ''"
                icon="trophy" color="purple" />
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
            {{-- 券種別 取込状況 --}}
            <div class="bg-white dark:bg-gray-800 rounded-lg shadow p-5">
                <h3 class="text-sm font-semibold text-gray-700 dark:text-gray-200 mb-3">券種別 取込状況</h3>
                @if ($payoutOverview['by_kind']->isEmpty())
                    <p class="text-sm text-gray-400">データなし</p>
                @else
                <table class="w-full text-sm">
                    <thead class="text-xs text-gray-500">
                        <tr>
                            <th class="text-left py-1.5">券種</th>
                            <th class="text-right">件数</th>
                            <th class="text-right">平均配当</th>
                            <th class="text-right">最高配当</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                    @foreach ($payoutOverview['by_kind'] as $r)
                    <tr>
                        <td class="py-1.5">
                            <a href="{{ route('betting.payouts.list', ['kind' => $r['kind'], 'from' => $from, 'to' => $to]) }}"
                               class="text-turf-600 hover:underline font-medium">{{ $r['kind_label'] }}</a>
                        </td>
                        <td class="py-1.5 text-right tabular-nums text-xs">{{ number_format($r['cnt']) }}</td>
                        <td class="py-1.5 text-right tabular-nums">¥{{ number_format($r['avg']) }}</td>
                        <td class="py-1.5 text-right tabular-nums text-gold-600 font-bold">¥{{ number_format($r['max']) }}</td>
                    </tr>
                    @endforeach
                    </tbody>
                </table>
                @endif
            </div>

            {{-- 直近高額配当TOP5 --}}
            <div class="bg-white dark:bg-gray-800 rounded-lg shadow p-5">
                <h3 class="text-sm font-semibold text-gray-700 dark:text-gray-200 mb-3 flex items-center space-x-1">
                    <x-icon name="trophy" class="w-4 h-4 text-gold-500" /><span>高額配当 TOP5</span>
                </h3>
                @if ($payoutOverview['top_recent']->isEmpty())
                    <p class="text-sm text-gray-400">データなし</p>
                @else
                <table class="w-full text-sm">
                    <thead class="text-xs text-gray-500">
                        <tr>
                            <th class="text-left py-1.5">日付 / レース</th>
                            <th class="text-left">券種</th>
                            <th class="text-left">組合せ</th>
                            <th class="text-right">配当</th>
                            <th class="text-right">人気</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                    @foreach ($payoutOverview['top_recent'] as $p)
                    <tr>
                        <td class="py-1.5 text-xs">
                            <div class="text-gray-500">{{ $p->race?->race_date?->format('Y/m/d') }}</div>
                            <a href="{{ route('races.show', $p->race) }}" class="text-turf-600 hover:underline">
                                {{ $p->race?->venue?->name }} {{ $p->race?->race_number }}R
                            </a>
                        </td>
                        <td class="py-1.5 text-xs font-medium">{{ \App\Models\Bet::KIND_LABELS[$p->kind] ?? $p->kind }}</td>
                        <td class="py-1.5 font-mono text-xs text-gray-700 dark:text-gray-300">{{ $p->combination }}</td>
                        <td class="py-1.5 text-right tabular-nums text-gold-600 font-bold">¥{{ number_format($p->amount) }}</td>
                        <td class="py-1.5 text-right tabular-nums text-xs text-gray-500">{{ $p->popularity ? $p->popularity.'番人気' : '-' }}</td>
                    </tr>
                    @endforeach
                    </tbody>
                </table>
                @endif
            </div>
        </div>
        @endif
    </div>

    {{-- ============================================================ --}}
    {{-- 拡張: 払戻データの多次元分析                                --}}
    {{-- ============================================================ --}}
    @if ($payoutOverview['total_payouts'] > 0)
    <div class="mt-8">
        <div class="flex items-center justify-between mb-3">
            <h2 class="text-base font-semibold text-gray-800 dark:text-gray-100 flex items-center space-x-2">
                <x-icon name="chart" class="w-5 h-5 text-purple-500" />
                <span>払戻データ詳細分析</span>
                <span class="text-xs font-normal text-gray-400">配当帯・人気・曜日・競馬場別</span>
            </h2>
        </div>

        {{-- 万馬券系KPI --}}
        <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-4">
            <div class="bg-gradient-to-br from-amber-50 to-yellow-100 dark:from-amber-900/20 dark:to-yellow-900/30 rounded-lg p-4 ring-1 ring-amber-200 dark:ring-amber-700">
                <div class="text-xs text-amber-700 dark:text-amber-300 font-bold flex items-center gap-1"><x-icon name="sparkles" class="w-3 h-3" />万馬券</div>
                <div class="text-3xl font-bold text-amber-700 dark:text-amber-300">{{ number_format($payoutAnalytics['manbaken_count']) }}<span class="text-sm font-normal">件</span></div>
                <div class="text-xs text-gray-500">¥10,000以上の払戻</div>
            </div>
            <div class="bg-gradient-to-br from-orange-50 to-red-100 dark:from-orange-900/20 dark:to-red-900/30 rounded-lg p-4 ring-1 ring-orange-200 dark:ring-orange-700">
                <div class="text-xs text-orange-700 dark:text-orange-300 font-bold flex items-center gap-1"><x-icon name="bolt" class="w-3 h-3" />十万馬券</div>
                <div class="text-3xl font-bold text-orange-700 dark:text-orange-300">{{ number_format($payoutAnalytics['hyaku_count']) }}<span class="text-sm font-normal">件</span></div>
                <div class="text-xs text-gray-500">¥100,000以上の払戻</div>
            </div>
            <div class="bg-gradient-to-br from-rose-50 to-pink-100 dark:from-rose-900/20 dark:to-pink-900/30 rounded-lg p-4 ring-1 ring-rose-200 dark:ring-rose-700">
                <div class="text-xs text-rose-700 dark:text-rose-300 font-bold flex items-center gap-1"><x-icon name="trophy" class="w-3 h-3" />百万馬券</div>
                <div class="text-3xl font-bold text-rose-700 dark:text-rose-300">{{ number_format($payoutAnalytics['million_count']) }}<span class="text-sm font-normal">件</span></div>
                <div class="text-xs text-gray-500">¥1,000,000以上の払戻</div>
            </div>
            <div class="bg-gradient-to-br from-purple-50 to-indigo-100 dark:from-purple-900/20 dark:to-indigo-900/30 rounded-lg p-4 ring-1 ring-purple-200 dark:ring-purple-700">
                <div class="text-xs text-purple-700 dark:text-purple-300 font-bold">期間中最高配当</div>
                <div class="text-2xl font-bold text-purple-700 dark:text-purple-300">¥{{ $payoutAnalytics['top10']->isNotEmpty() ? number_format($payoutAnalytics['top10']->first()->amount) : '-' }}</div>
                <div class="text-xs text-gray-500">{{ $payoutAnalytics['top10']->isNotEmpty() ? (\App\Models\Bet::KIND_LABELS[$payoutAnalytics['top10']->first()->kind] ?? '') : '' }}</div>
            </div>
        </div>

        {{-- 配当帯ヒストグラム + 券種別平均配当 --}}
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 mb-4">
            <div class="bg-white dark:bg-gray-800 rounded-lg shadow p-5">
                <h3 class="text-sm font-semibold text-gray-700 dark:text-gray-200 mb-3">配当帯別 件数分布（券種別積み上げ）</h3>
                <div id="chart-payout-band" style="height: 320px"></div>
            </div>
            <div class="bg-white dark:bg-gray-800 rounded-lg shadow p-5">
                <h3 class="text-sm font-semibold text-gray-700 dark:text-gray-200 mb-3">券種別 平均配当</h3>
                <div id="chart-kind-avg" style="height: 320px"></div>
            </div>
        </div>

        {{-- 人気別 + 月別万馬券 --}}
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 mb-4">
            <div class="bg-white dark:bg-gray-800 rounded-lg shadow p-5">
                <h3 class="text-sm font-semibold text-gray-700 dark:text-gray-200 mb-3">単勝人気別 平均配当</h3>
                <div id="chart-popularity" style="height: 320px"></div>
            </div>
            <div class="bg-white dark:bg-gray-800 rounded-lg shadow p-5">
                <h3 class="text-sm font-semibold text-gray-700 dark:text-gray-200 mb-3">月別 万馬券発生件数</h3>
                <div id="chart-manbaken-monthly" style="height: 320px"></div>
            </div>
        </div>

        {{-- 曜日別 + 競馬場別 --}}
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 mb-4">
            <div class="bg-white dark:bg-gray-800 rounded-lg shadow p-5">
                <h3 class="text-sm font-semibold text-gray-700 dark:text-gray-200 mb-3">曜日別 平均配当 / 件数</h3>
                <div id="chart-weekday" style="height: 320px"></div>
            </div>
            <div class="bg-white dark:bg-gray-800 rounded-lg shadow p-5">
                <h3 class="text-sm font-semibold text-gray-700 dark:text-gray-200 mb-3">競馬場別 平均配当 / 万馬券回数</h3>
                <div id="chart-venue-payout" style="height: 320px"></div>
            </div>
        </div>

        {{-- 期間中の歴代TOP10高額配当 --}}
        <div class="bg-white dark:bg-gray-800 rounded-lg shadow p-5">
            <h3 class="text-sm font-semibold text-gray-700 dark:text-gray-200 mb-3 flex items-center gap-1">
                <x-icon name="trophy" class="w-4 h-4 text-gold-500" /><span>期間中の歴代TOP10高額配当</span>
            </h3>
            @if ($payoutAnalytics['top10']->isEmpty())
                <p class="text-sm text-gray-400">データなし</p>
            @else
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="text-xs text-gray-500 bg-gray-50 dark:bg-gray-700/50">
                        <tr>
                            <th class="text-right py-2 px-2">#</th>
                            <th class="text-left px-2">日付</th>
                            <th class="text-left px-2">レース</th>
                            <th class="text-left px-2">券種</th>
                            <th class="text-left px-2">組合せ</th>
                            <th class="text-right px-2">配当</th>
                            <th class="text-right px-2">倍率</th>
                            <th class="text-right px-2">人気</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                    @foreach ($payoutAnalytics['top10'] as $i => $p)
                    <tr class="hover:bg-gray-50 dark:hover:bg-gray-700/30">
                        <td class="px-2 py-1.5 text-right text-xs text-gray-400 tabular-nums">{{ $i+1 }}</td>
                        <td class="px-2 py-1.5 text-xs text-gray-500 whitespace-nowrap">{{ $p->race?->race_date?->format('Y/m/d') }}</td>
                        <td class="px-2 py-1.5 whitespace-nowrap">
                            <a href="{{ route('races.show', $p->race) }}" class="text-turf-600 hover:underline">
                                {{ $p->race?->venue?->name }} {{ $p->race?->race_number }}R {{ $p->race?->name }}
                            </a>
                        </td>
                        <td class="px-2 py-1.5 text-xs font-medium">{{ \App\Models\Bet::KIND_LABELS[$p->kind] ?? $p->kind }}</td>
                        <td class="px-2 py-1.5 font-mono text-xs text-gray-700 dark:text-gray-300">{{ $p->combination }}</td>
                        <td class="px-2 py-1.5 text-right tabular-nums font-bold {{ $p->amount >= 100000 ? 'text-rose-600' : ($p->amount >= 10000 ? 'text-gold-600' : 'text-gray-700 dark:text-gray-200') }}">
                            ¥{{ number_format($p->amount) }}
                        </td>
                        <td class="px-2 py-1.5 text-right tabular-nums text-xs text-gray-500">{{ number_format($p->amount/100, 1) }}倍</td>
                        <td class="px-2 py-1.5 text-right tabular-nums text-xs text-gray-500">{{ $p->popularity ? $p->popularity.'番' : '-' }}</td>
                    </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            @endif
        </div>
    </div>
    @endif

    {{-- ============================================================ --}}
    {{-- 拡張: 自分の馬券のさらなる分析                              --}}
    {{-- ============================================================ --}}
    @if ($kpi['count'] > 0)
    <div class="mt-8">
        <div class="flex items-center justify-between mb-3">
            <h2 class="text-base font-semibold text-gray-800 dark:text-gray-100 flex items-center space-x-2">
                <x-icon name="user" class="w-5 h-5 text-turf-500" />
                <span>自分の馬券 詳細分析</span>
                <span class="text-xs font-normal text-gray-400">曜日・投資額・グレード別</span>
            </h2>
        </div>

        {{-- 曜日別収支 + 投資額帯分布 --}}
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 mb-4">
            <div class="bg-white dark:bg-gray-800 rounded-lg shadow p-5">
                <h3 class="text-sm font-semibold text-gray-700 dark:text-gray-200 mb-3">曜日別 自分の収支・的中率</h3>
                <div id="chart-my-weekday" style="height: 320px"></div>
            </div>
            <div class="bg-white dark:bg-gray-800 rounded-lg shadow p-5">
                <h3 class="text-sm font-semibold text-gray-700 dark:text-gray-200 mb-3">投資額帯別 購入分布</h3>
                <div id="chart-stake-dist" style="height: 320px"></div>
            </div>
        </div>

        {{-- 直近30日推移 + 投資vs払戻散布 --}}
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 mb-4">
            <div class="bg-white dark:bg-gray-800 rounded-lg shadow p-5">
                <h3 class="text-sm font-semibold text-gray-700 dark:text-gray-200 mb-3">直近30日 日次収支</h3>
                <div id="chart-recent30" style="height: 320px"></div>
            </div>
            <div class="bg-white dark:bg-gray-800 rounded-lg shadow p-5">
                <h3 class="text-sm font-semibold text-gray-700 dark:text-gray-200 mb-3">投資 vs 払戻 散布図（直近200件）</h3>
                <div id="chart-scatter" style="height: 320px"></div>
            </div>
        </div>

        {{-- グレード別 --}}
        <div class="bg-white dark:bg-gray-800 rounded-lg shadow p-5">
            <h3 class="text-sm font-semibold text-gray-700 dark:text-gray-200 mb-3">グレード別 収支</h3>
            @if ($myAnalytics['by_grade']->isEmpty())
                <p class="text-sm text-gray-400">データなし</p>
            @else
            <table class="w-full text-sm">
                <thead class="text-xs text-gray-500 bg-gray-50 dark:bg-gray-700/50">
                    <tr>
                        <th class="text-left px-2 py-2">グレード</th>
                        <th class="text-right px-2">購入数</th>
                        <th class="text-right px-2">投資</th>
                        <th class="text-right px-2">払戻</th>
                        <th class="text-right px-2">収支</th>
                        <th class="text-right px-2">回収率</th>
                        <th class="text-right px-2">的中率</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                @foreach ($myAnalytics['by_grade'] as $r)
                <tr>
                    <td class="px-2 py-1.5 font-medium">
                        @php
                            $gradeColors = [
                                'GI'   => 'bg-rose-100 text-rose-800 dark:bg-rose-900/40 dark:text-rose-200',
                                'GII'  => 'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-200',
                                'GIII' => 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-200',
                                'OP'   => 'bg-sky-100 text-sky-800 dark:bg-sky-900/40 dark:text-sky-200',
                            ];
                            $gc = $gradeColors[$r['grade']] ?? 'bg-gray-100 text-gray-700 dark:bg-gray-700 dark:text-gray-200';
                        @endphp
                        <span class="inline-block px-2 py-0.5 rounded text-xs font-bold {{ $gc }}">{{ $r['grade'] }}</span>
                    </td>
                    <td class="px-2 py-1.5 text-right tabular-nums">{{ number_format($r['cnt']) }}</td>
                    <td class="px-2 py-1.5 text-right tabular-nums">¥{{ number_format($r['stake']) }}</td>
                    <td class="px-2 py-1.5 text-right tabular-nums">¥{{ number_format($r['return']) }}</td>
                    <td class="px-2 py-1.5 text-right tabular-nums font-bold {{ $r['profit'] >= 0 ? 'text-emerald-600' : 'text-rose-600' }}">
                        {{ $r['profit'] >= 0 ? '+' : '' }}¥{{ number_format($r['profit']) }}
                    </td>
                    <td class="px-2 py-1.5 text-right tabular-nums font-bold {{ $r['roi'] >= 100 ? 'text-emerald-600' : 'text-rose-600' }}">{{ $r['roi'] }}%</td>
                    <td class="px-2 py-1.5 text-right tabular-nums">{{ $r['hit_rate'] }}%</td>
                </tr>
                @endforeach
                </tbody>
            </table>
            @endif
        </div>
    </div>
    @endif
</div>

<script src="https://cdn.jsdelivr.net/npm/apexcharts"></script>
<script>
document.addEventListener('DOMContentLoaded', () => {
    const isDark = document.documentElement.classList.contains('dark');
    const fg = isDark ? '#cbd5e1' : '#475569';
    const grid = isDark ? '#334155' : '#e2e8f0';

    @if (!empty($cumulative) && count($cumulative) > 0)
    // 累積回収率推移
    new ApexCharts(document.querySelector('#chart-cum'), {
        chart: { type: 'area', height: 280, toolbar: { show: false }, foreColor: fg, animations: { enabled: false } },
        series: [
            { name: '回収率(%)', type: 'line', data: @json($cumulative->map(fn($r) => ['x' => $r['d'], 'y' => $r['roi']])) },
            { name: '累積収支(円)', type: 'area', data: @json($cumulative->map(fn($r) => ['x' => $r['d'], 'y' => $r['profit']])) },
        ],
        stroke: { curve: 'smooth', width: [2, 2] },
        fill: { type: ['solid', 'gradient'], gradient: { shadeIntensity: 0.5, opacityFrom: 0.4, opacityTo: 0 } },
        xaxis: { type: 'datetime', labels: { style: { colors: fg } } },
        yaxis: [
            { title: { text: 'ROI(%)', style: { color: fg } }, labels: { style: { colors: fg } } },
            { opposite: true, title: { text: '収支(円)', style: { color: fg } }, labels: { style: { colors: fg }, formatter: v => '¥' + (v|0).toLocaleString() } },
        ],
        colors: ['#16a34a', '#eab308'],
        grid: { borderColor: grid },
        annotations: { yaxis: [{ y: 100, borderColor: '#94a3b8', label: { text: '回収率100%', style: { color: '#fff', background: '#94a3b8' } } }] },
        tooltip: { theme: isDark ? 'dark' : 'light' },
    }).render();
    @endif

    @if ($monthly->isNotEmpty())
    // 月次収支 (投資/払戻/ROI 複合チャート)
    new ApexCharts(document.querySelector('#chart-monthly'), {
        chart: { type: 'line', height: 280, toolbar: { show: false }, foreColor: fg, animations: { enabled: false } },
        series: [
            { name: '投資',   type: 'column', data: @json($monthly->pluck('stake')) },
            { name: '払戻',   type: 'column', data: @json($monthly->pluck('return')) },
            { name: 'ROI(%)', type: 'line',   data: @json($monthly->pluck('roi')) },
        ],
        stroke: { curve: 'smooth', width: [0, 0, 3] },
        plotOptions: { bar: { columnWidth: '60%' } },
        xaxis: { categories: @json($monthly->pluck('ym')), labels: { style: { colors: fg } } },
        yaxis: [
            { title: { text: '金額(円)', style: { color: fg } }, labels: { style: { colors: fg }, formatter: v => '¥' + (v|0).toLocaleString() } },
            { show: false },
            { opposite: true, title: { text: 'ROI(%)', style: { color: fg } }, labels: { style: { colors: fg } } },
        ],
        colors: ['#94a3b8', '#eab308', '#16a34a'],
        grid: { borderColor: grid },
        legend: { labels: { colors: fg } },
        tooltip: { theme: isDark ? 'dark' : 'light' },
    }).render();
    @endif

    @if ($yearly->isNotEmpty())
    // 年次推移 (投資/払戻/ROI)
    new ApexCharts(document.querySelector('#chart-yearly'), {
        chart: { type: 'line', height: 260, toolbar: { show: false }, foreColor: fg, animations: { enabled: false } },
        series: [
            { name: '投資',   type: 'column', data: @json($yearly->pluck('stake')) },
            { name: '払戻',   type: 'column', data: @json($yearly->pluck('return')) },
            { name: 'ROI(%)', type: 'line',   data: @json($yearly->pluck('roi')) },
        ],
        stroke: { curve: 'smooth', width: [0, 0, 3] },
        plotOptions: { bar: { columnWidth: '50%', borderRadius: 4 } },
        xaxis: { categories: @json($yearly->pluck('y')), labels: { style: { colors: fg } } },
        yaxis: [
            { title: { text: '金額(円)', style: { color: fg } }, labels: { style: { colors: fg }, formatter: v => '¥' + (v|0).toLocaleString() } },
            { show: false },
            { opposite: true, title: { text: 'ROI(%)', style: { color: fg } }, labels: { style: { colors: fg } } },
        ],
        colors: ['#94a3b8', '#eab308', '#16a34a'],
        grid: { borderColor: grid },
        legend: { labels: { colors: fg } },
        tooltip: { theme: isDark ? 'dark' : 'light' },
        annotations: { yaxis: [{ y: 100, yAxisIndex: 2, borderColor: '#94a3b8', strokeDashArray: 4 }] },
    }).render();
    @endif

    // ==================================================
    // 拡張: 払戻データ詳細分析チャート
    // ==================================================
    @if ($payoutOverview['total_payouts'] > 0)

    // ----- 配当帯別 件数分布（券種別積み上げ） -----
    new ApexCharts(document.querySelector('#chart-payout-band'), {
        chart: { type: 'bar', stacked: true, height: 320, toolbar: { show: false }, foreColor: fg, animations: { enabled: false } },
        series: @json(collect($payoutAnalytics['band_counts_by_kind'])->map(fn($r) => [
            'name' => $r['kind_label'],
            'data' => collect($r['bands'])->pluck('cnt')->all(),
        ])->all()),
        plotOptions: { bar: { columnWidth: '60%' } },
        xaxis: { categories: @json($payoutAnalytics['band_labels']), labels: { style: { colors: fg }, rotate: -25 } },
        yaxis: { labels: { style: { colors: fg } } },
        colors: ['#dc2626','#10b981','#f59e0b','#3b82f6','#6366f1','#06b6d4','#a855f7','#ec4899'],
        grid: { borderColor: grid },
        legend: { labels: { colors: fg } },
        tooltip: { theme: isDark ? 'dark' : 'light' },
    }).render();

    // ----- 券種別 平均配当 -----
    new ApexCharts(document.querySelector('#chart-kind-avg'), {
        chart: { type: 'bar', height: 320, toolbar: { show: false }, foreColor: fg, animations: { enabled: false } },
        series: [{ name: '平均配当', data: @json(collect($payoutAnalytics['kind_avg'])->pluck('avg')->all()) }],
        plotOptions: { bar: { columnWidth: '55%', borderRadius: 4, dataLabels: { position: 'top' } } },
        dataLabels: {
            enabled: true,
            formatter: v => '¥' + (v|0).toLocaleString(),
            offsetY: -18, style: { colors: [fg], fontSize: '10px' },
        },
        xaxis: { categories: @json(collect($payoutAnalytics['kind_avg'])->pluck('label')->all()), labels: { style: { colors: fg } } },
        yaxis: { labels: { style: { colors: fg }, formatter: v => '¥' + (v|0).toLocaleString() } },
        colors: ['#eab308'],
        grid: { borderColor: grid },
        tooltip: { theme: isDark ? 'dark' : 'light', y: { formatter: v => '¥' + (v|0).toLocaleString() } },
    }).render();

    @if ($payoutAnalytics['by_popularity']->isNotEmpty())
    // ----- 単勝人気別 平均配当 -----
    new ApexCharts(document.querySelector('#chart-popularity'), {
        chart: { type: 'line', height: 320, toolbar: { show: false }, foreColor: fg, animations: { enabled: false } },
        series: [
            { name: '平均配当(円)', type: 'column', data: @json($payoutAnalytics['by_popularity']->pluck('avg')) },
            { name: '件数', type: 'line', data: @json($payoutAnalytics['by_popularity']->pluck('cnt')) },
        ],
        stroke: { curve: 'smooth', width: [0, 3] },
        plotOptions: { bar: { columnWidth: '50%', borderRadius: 4 } },
        xaxis: { categories: @json($payoutAnalytics['by_popularity']->map(fn($r) => $r['pop'].'人気')), labels: { style: { colors: fg } } },
        yaxis: [
            { title: { text: '平均配当(円)', style: { color: fg } }, labels: { style: { colors: fg }, formatter: v => '¥' + (v|0).toLocaleString() } },
            { opposite: true, title: { text: '件数', style: { color: fg } }, labels: { style: { colors: fg } } },
        ],
        colors: ['#3b82f6', '#dc2626'],
        grid: { borderColor: grid },
        legend: { labels: { colors: fg } },
        tooltip: { theme: isDark ? 'dark' : 'light' },
    }).render();
    @endif

    @if ($payoutAnalytics['manbaken_monthly']->isNotEmpty())
    // ----- 月別 万馬券発生件数 -----
    new ApexCharts(document.querySelector('#chart-manbaken-monthly'), {
        chart: { type: 'area', height: 320, toolbar: { show: false }, foreColor: fg, animations: { enabled: false } },
        series: [{ name: '万馬券件数', data: @json($payoutAnalytics['manbaken_monthly']->pluck('cnt')) }],
        stroke: { curve: 'smooth', width: 2 },
        fill: { type: 'gradient', gradient: { shadeIntensity: 0.7, opacityFrom: 0.5, opacityTo: 0.05 } },
        xaxis: { categories: @json($payoutAnalytics['manbaken_monthly']->pluck('ym')), labels: { style: { colors: fg } } },
        yaxis: { labels: { style: { colors: fg } } },
        colors: ['#f59e0b'],
        grid: { borderColor: grid },
        markers: { size: 4 },
        tooltip: { theme: isDark ? 'dark' : 'light' },
    }).render();
    @endif

    @if ($payoutAnalytics['by_weekday']->isNotEmpty())
    // ----- 曜日別 平均配当 / 件数 -----
    new ApexCharts(document.querySelector('#chart-weekday'), {
        chart: { type: 'bar', height: 320, toolbar: { show: false }, foreColor: fg, animations: { enabled: false } },
        series: [
            { name: '平均配当(円)', type: 'column', data: @json($payoutAnalytics['by_weekday']->pluck('avg')) },
            { name: '件数',         type: 'line',   data: @json($payoutAnalytics['by_weekday']->pluck('cnt')) },
        ],
        stroke: { curve: 'smooth', width: [0, 3] },
        plotOptions: { bar: { columnWidth: '50%', borderRadius: 6 } },
        xaxis: { categories: @json($payoutAnalytics['by_weekday']->pluck('label')), labels: { style: { colors: fg } } },
        yaxis: [
            { title: { text: '平均配当(円)', style: { color: fg } }, labels: { style: { colors: fg }, formatter: v => '¥' + (v|0).toLocaleString() } },
            { opposite: true, title: { text: '件数', style: { color: fg } }, labels: { style: { colors: fg } } },
        ],
        colors: ['#06b6d4', '#a855f7'],
        grid: { borderColor: grid },
        legend: { labels: { colors: fg } },
        tooltip: { theme: isDark ? 'dark' : 'light' },
    }).render();
    @endif

    @if ($payoutAnalytics['by_venue']->isNotEmpty())
    // ----- 競馬場別 平均配当 / 万馬券回数 -----
    new ApexCharts(document.querySelector('#chart-venue-payout'), {
        chart: { type: 'bar', height: 320, toolbar: { show: false }, foreColor: fg, animations: { enabled: false } },
        series: [
            { name: '平均配当(円)', type: 'column', data: @json($payoutAnalytics['by_venue']->pluck('avg')) },
            { name: '万馬券回数',   type: 'line',   data: @json($payoutAnalytics['by_venue']->pluck('manbaken_cnt')) },
        ],
        stroke: { curve: 'straight', width: [0, 3] },
        plotOptions: { bar: { columnWidth: '60%', borderRadius: 4 } },
        xaxis: { categories: @json($payoutAnalytics['by_venue']->pluck('name')), labels: { style: { colors: fg }, rotate: -25 } },
        yaxis: [
            { title: { text: '平均配当(円)', style: { color: fg } }, labels: { style: { colors: fg }, formatter: v => '¥' + (v|0).toLocaleString() } },
            { opposite: true, title: { text: '万馬券回数', style: { color: fg } }, labels: { style: { colors: fg } } },
        ],
        colors: ['#10b981', '#f59e0b'],
        grid: { borderColor: grid },
        legend: { labels: { colors: fg } },
        tooltip: { theme: isDark ? 'dark' : 'light' },
    }).render();
    @endif
    @endif

    // ==================================================
    // 拡張: 自分の馬券詳細分析チャート
    // ==================================================
    @if ($kpi['count'] > 0)

    @if ($myAnalytics['by_weekday']->isNotEmpty())
    // ----- 曜日別 自分の収支・的中率 -----
    new ApexCharts(document.querySelector('#chart-my-weekday'), {
        chart: { type: 'bar', height: 320, toolbar: { show: false }, foreColor: fg, animations: { enabled: false } },
        series: [
            { name: '投資', type: 'column', data: @json($myAnalytics['by_weekday']->pluck('stake')) },
            { name: '払戻', type: 'column', data: @json($myAnalytics['by_weekday']->pluck('return')) },
            { name: '回収率(%)', type: 'line', data: @json($myAnalytics['by_weekday']->pluck('roi')) },
        ],
        stroke: { curve: 'smooth', width: [0, 0, 3] },
        plotOptions: { bar: { columnWidth: '60%', borderRadius: 4 } },
        xaxis: { categories: @json($myAnalytics['by_weekday']->pluck('label')), labels: { style: { colors: fg } } },
        yaxis: [
            { title: { text: '金額(円)', style: { color: fg } }, labels: { style: { colors: fg }, formatter: v => '¥' + (v|0).toLocaleString() } },
            { show: false },
            { opposite: true, title: { text: '回収率(%)', style: { color: fg } }, labels: { style: { colors: fg } } },
        ],
        colors: ['#94a3b8', '#eab308', '#16a34a'],
        grid: { borderColor: grid },
        legend: { labels: { colors: fg } },
        tooltip: { theme: isDark ? 'dark' : 'light' },
    }).render();
    @endif

    // ----- 投資額帯別 購入分布 -----
    new ApexCharts(document.querySelector('#chart-stake-dist'), {
        chart: { type: 'donut', height: 320, foreColor: fg, animations: { enabled: false } },
        series: @json(collect($myAnalytics['stake_dist'])->pluck('cnt')->all()),
        labels: @json(collect($myAnalytics['stake_dist'])->pluck('label')->all()),
        colors: ['#22d3ee','#3b82f6','#6366f1','#a855f7','#ec4899','#dc2626'],
        legend: { position: 'bottom', labels: { colors: fg } },
        plotOptions: { pie: { donut: { size: '60%', labels: { show: true, total: { show: true, label: '購入総数' } } } } },
        tooltip: { theme: isDark ? 'dark' : 'light' },
        dataLabels: { formatter: (v, o) => o.w.config.series[o.seriesIndex] + '件' },
    }).render();

    @if ($myAnalytics['recent30']->isNotEmpty())
    // ----- 直近30日 日次収支 -----
    new ApexCharts(document.querySelector('#chart-recent30'), {
        chart: { type: 'bar', height: 320, toolbar: { show: false }, foreColor: fg, animations: { enabled: false } },
        series: [
            { name: '収支', data: @json($myAnalytics['recent30']->map(fn($r) => ['x' => $r['d'], 'y' => $r['profit']])) },
        ],
        plotOptions: { bar: { columnWidth: '70%', colors: { ranges: [
            { from: -99999999, to: -1, color: '#dc2626' },
            { from: 0, to: 99999999, color: '#16a34a' },
        ] } } },
        xaxis: { type: 'datetime', labels: { style: { colors: fg } } },
        yaxis: { labels: { style: { colors: fg }, formatter: v => '¥' + (v|0).toLocaleString() } },
        grid: { borderColor: grid },
        tooltip: { theme: isDark ? 'dark' : 'light', y: { formatter: v => '¥' + (v|0).toLocaleString() } },
    }).render();
    @endif

    // ----- 投資 vs 払戻 散布図 -----
    new ApexCharts(document.querySelector('#chart-scatter'), {
        chart: { type: 'scatter', height: 320, toolbar: { show: false }, foreColor: fg, animations: { enabled: false }, zoom: { enabled: true, type: 'xy' } },
        series: [{ name: '馬券', data: @json($myAnalytics['scatter']) }],
        xaxis: { title: { text: '投資(円)', style: { color: fg } }, labels: { style: { colors: fg }, formatter: v => '¥' + (v|0).toLocaleString() }, tickAmount: 6 },
        yaxis: { title: { text: '払戻(円)', style: { color: fg } }, labels: { style: { colors: fg }, formatter: v => '¥' + (v|0).toLocaleString() } },
        colors: ['#a855f7'],
        markers: { size: 5, strokeWidth: 0, opacity: 0.55 },
        grid: { borderColor: grid },
        tooltip: { theme: isDark ? 'dark' : 'light' },
    }).render();
    @endif
});
</script>
@endsection
