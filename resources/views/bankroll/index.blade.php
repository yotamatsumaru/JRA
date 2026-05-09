@extends('layouts.app')
@section('title', 'バンクロール管理')

@section('content')
<div class="space-y-5">
    <x-page-header title="バンクロール管理" subtitle="月次予算と実績の追跡" icon="cash">
        <x-slot name="actions">
            <a href="{{ route('betting.dashboard') }}" class="inline-flex items-center space-x-1 bg-gray-200 hover:bg-gray-300 dark:bg-gray-700 dark:hover:bg-gray-600 text-gray-800 dark:text-gray-100 px-3 py-1.5 rounded text-sm">
                <x-icon name="chart" class="w-4 h-4" /><span>ダッシュボード</span>
            </a>
            <a href="{{ route('bets.whatif') }}" class="inline-flex items-center space-x-1 bg-purple-600 hover:bg-purple-700 text-white px-3 py-1.5 rounded text-sm">
                <x-icon name="sparkles" class="w-4 h-4" /><span>What-if</span>
            </a>
        </x-slot>
    </x-page-header>

    @if (session('status'))
        <div class="bg-emerald-50 dark:bg-emerald-900/20 border border-emerald-200 dark:border-emerald-700 text-emerald-800 dark:text-emerald-200 rounded px-4 py-2 text-sm">{{ session('status') }}</div>
    @endif

    {{-- 累計サマリ --}}
    <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
        <x-kpi-card label="直近12ヶ月 投資" :value="'¥'.number_format($totalStake)" icon="cash" color="sand" />
        <x-kpi-card label="直近12ヶ月 払戻" :value="'¥'.number_format($totalReturn)" icon="cash" color="gold" />
        <x-kpi-card
            label="直近12ヶ月 収支"
            :value="($totalProfit >= 0 ? '+' : '').'¥'.number_format($totalProfit)"
            icon="chart"
            :color="$totalProfit >= 0 ? 'turf' : 'rose'" />
        <x-kpi-card
            label="平均ROI"
            :value="$totalStake > 0 ? round($totalReturn / $totalStake * 100, 1).'%' : '-'"
            icon="bolt"
            color="purple" />
    </div>

    {{-- 当月の予算編集 --}}
    <div class="bg-white dark:bg-gray-800 rounded-lg shadow p-5">
        <h2 class="text-sm font-semibold text-gray-700 dark:text-gray-200 mb-3 flex items-center space-x-1">
            <x-icon name="target" class="w-4 h-4 text-turf-600" /><span>{{ $thisYm }} の予算設定</span>
        </h2>
        <form method="POST" action="{{ route('bankroll.update') }}" class="grid grid-cols-1 md:grid-cols-4 gap-3 items-end">
            @csrf
            <input type="hidden" name="ym" value="{{ $thisYm }}">
            <div>
                <label class="block text-xs text-gray-600 dark:text-gray-400 mb-1">投資予算 (円)</label>
                <input type="number" name="target_stake" value="{{ old('target_stake', $current->target_stake) }}"
                       min="0" step="100"
                       class="w-full border rounded px-2 py-1.5 dark:bg-gray-700 dark:border-gray-600 tabular-nums">
            </div>
            <div>
                <label class="block text-xs text-gray-600 dark:text-gray-400 mb-1">収支目標 (円, +で利益)</label>
                <input type="number" name="target_profit" value="{{ old('target_profit', $current->target_profit) }}"
                       step="100"
                       class="w-full border rounded px-2 py-1.5 dark:bg-gray-700 dark:border-gray-600 tabular-nums">
            </div>
            <div class="md:col-span-2">
                <label class="block text-xs text-gray-600 dark:text-gray-400 mb-1">メモ</label>
                <input type="text" name="notes" value="{{ old('notes', $current->notes) }}"
                       maxlength="2000"
                       class="w-full border rounded px-2 py-1.5 dark:bg-gray-700 dark:border-gray-600">
            </div>
            <div class="md:col-span-4 flex justify-end">
                <button class="bg-turf-600 hover:bg-turf-700 text-white px-4 py-1.5 rounded text-sm">予算を保存</button>
            </div>
        </form>
        @error('target_stake')<p class="text-rose-600 text-xs mt-1">{{ $message }}</p>@enderror
        @error('target_profit')<p class="text-rose-600 text-xs mt-1">{{ $message }}</p>@enderror
    </div>

    {{-- 月次推移テーブル --}}
    <div class="bg-white dark:bg-gray-800 rounded-lg shadow p-5">
        <h2 class="text-sm font-semibold text-gray-700 dark:text-gray-200 mb-3">直近12ヶ月 予算 vs 実績</h2>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 dark:bg-gray-700/50 text-xs text-gray-600 dark:text-gray-300">
                    <tr>
                        <th class="px-3 py-2 text-left">月</th>
                        <th class="px-3 py-2 text-right">予算</th>
                        <th class="px-3 py-2 text-right">投資実績</th>
                        <th class="px-3 py-2 text-right">消化率</th>
                        <th class="px-3 py-2 text-right">払戻</th>
                        <th class="px-3 py-2 text-right">収支</th>
                        <th class="px-3 py-2 text-right">目標差</th>
                        <th class="px-3 py-2 text-right">ROI</th>
                        <th class="px-3 py-2 text-right">件数</th>
                        <th class="px-3 py-2 text-center">操作</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                    @foreach ($rows as $r)
                    <tr class="{{ $r['is_current'] ? 'bg-turf-50/40 dark:bg-turf-900/10' : '' }}">
                        <td class="px-3 py-2 font-medium">
                            {{ $r['ym'] }}
                            @if ($r['is_current']) <span class="text-xs text-turf-600 dark:text-turf-400">(当月)</span> @endif
                        </td>
                        <td class="px-3 py-2 text-right tabular-nums text-xs">
                            @if ($r['target_stake'] > 0)
                                ¥{{ number_format($r['target_stake']) }}
                            @else
                                <span class="text-gray-400">未設定</span>
                            @endif
                        </td>
                        <td class="px-3 py-2 text-right tabular-nums">¥{{ number_format($r['stake']) }}</td>
                        <td class="px-3 py-2 text-right tabular-nums text-xs">
                            @if ($r['stake_pct'] !== null)
                                <span class="{{ $r['stake_pct'] > 100 ? 'text-rose-600 font-bold' : ($r['stake_pct'] >= 80 ? 'text-amber-600' : 'text-gray-600') }}">{{ $r['stake_pct'] }}%</span>
                            @else
                                -
                            @endif
                        </td>
                        <td class="px-3 py-2 text-right tabular-nums text-xs">¥{{ number_format($r['return']) }}</td>
                        <td class="px-3 py-2 text-right tabular-nums font-bold {{ $r['profit'] >= 0 ? 'text-emerald-600' : 'text-rose-600' }}">
                            {{ $r['profit'] >= 0 ? '+' : '' }}¥{{ number_format($r['profit']) }}
                        </td>
                        <td class="px-3 py-2 text-right tabular-nums text-xs">
                            @if ($r['target_profit'] != 0 || $r['target_stake'] > 0)
                                <span class="{{ $r['profit_diff'] >= 0 ? 'text-emerald-600' : 'text-rose-600' }}">
                                    {{ $r['profit_diff'] >= 0 ? '+' : '' }}¥{{ number_format($r['profit_diff']) }}
                                </span>
                            @else
                                -
                            @endif
                        </td>
                        <td class="px-3 py-2 text-right tabular-nums">
                            @if ($r['roi'] !== null)
                                <span class="font-bold {{ $r['roi'] >= 100 ? 'text-emerald-600' : 'text-rose-600' }}">{{ $r['roi'] }}%</span>
                            @else
                                <span class="text-gray-400">-</span>
                            @endif
                        </td>
                        <td class="px-3 py-2 text-right tabular-nums text-xs text-gray-500">{{ $r['cnt'] }}</td>
                        <td class="px-3 py-2 text-center">
                            <details class="inline-block">
                                <summary class="cursor-pointer text-xs text-turf-600 hover:underline">編集</summary>
                                <form method="POST" action="{{ route('bankroll.update') }}" class="absolute z-10 mt-2 bg-white dark:bg-gray-800 border dark:border-gray-700 rounded shadow-lg p-3 w-72 text-left space-y-2">
                                    @csrf
                                    <input type="hidden" name="ym" value="{{ $r['ym'] }}">
                                    <div>
                                        <label class="block text-xs text-gray-500 mb-1">投資予算</label>
                                        <input type="number" name="target_stake" value="{{ $r['target_stake'] }}" min="0" step="100" class="w-full border rounded px-2 py-1 text-sm dark:bg-gray-700 dark:border-gray-600">
                                    </div>
                                    <div>
                                        <label class="block text-xs text-gray-500 mb-1">収支目標</label>
                                        <input type="number" name="target_profit" value="{{ $r['target_profit'] }}" step="100" class="w-full border rounded px-2 py-1 text-sm dark:bg-gray-700 dark:border-gray-600">
                                    </div>
                                    <div>
                                        <label class="block text-xs text-gray-500 mb-1">メモ</label>
                                        <input type="text" name="notes" value="{{ $r['notes'] }}" class="w-full border rounded px-2 py-1 text-sm dark:bg-gray-700 dark:border-gray-600">
                                    </div>
                                    <div class="flex justify-between">
                                        <button type="submit" class="bg-turf-600 hover:bg-turf-700 text-white px-3 py-1 rounded text-xs">保存</button>
                                    </div>
                                </form>
                            </details>
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <p class="text-xs text-gray-500 dark:text-gray-400 mt-3">
            ※ 「消化率」は実投資額 / 予算。100%超で予算オーバー。「目標差」は実収支 − 目標収支。
        </p>
    </div>
</div>
@endsection
