@extends('layouts.app')
@section('title', '収支ダッシュボード')

@section('content')
<div class="space-y-5" x-data="{ darkMode: $root.darkMode || false }">
    <x-page-header title="収支ダッシュボード" subtitle="馬券の累計収支・回収率・傾向分析" icon="chart">
        <x-slot name="actions">
            <a href="{{ route('bets.create') }}" class="inline-flex items-center space-x-1 bg-turf-600 hover:bg-turf-700 text-white px-4 py-2 rounded text-sm">
                <x-icon name="plus" class="w-4 h-4" /><span>馬券を登録</span>
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
    // 月次収支
    new ApexCharts(document.querySelector('#chart-monthly'), {
        chart: { type: 'bar', height: 280, toolbar: { show: false }, foreColor: fg, animations: { enabled: false } },
        series: [
            { name: '投資', data: @json($monthly->pluck('stake')) },
            { name: '払戻', data: @json($monthly->pluck('return')) },
        ],
        plotOptions: { bar: { columnWidth: '60%' } },
        xaxis: { categories: @json($monthly->pluck('ym')), labels: { style: { colors: fg } } },
        yaxis: { labels: { style: { colors: fg }, formatter: v => '¥' + (v|0).toLocaleString() } },
        colors: ['#94a3b8', '#eab308'],
        grid: { borderColor: grid },
        legend: { labels: { colors: fg } },
        tooltip: { theme: isDark ? 'dark' : 'light', y: { formatter: v => '¥' + (v|0).toLocaleString() } },
    }).render();
    @endif
});
</script>
@endsection
