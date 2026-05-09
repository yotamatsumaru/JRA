@extends('layouts.app')
@section('title', '買い目一覧')

@section('content')
<div class="space-y-4">
    <x-page-header title="買い目一覧" subtitle="登録した馬券と収支" icon="cash">
        <x-slot name="actions">
            <form method="POST" action="{{ route('bets.settle-all') }}" class="inline"
                  onsubmit="return confirm('結果が確定しているのに未精算の馬券を一括精算します。よろしいですか?');">
                @csrf
                <button type="submit" class="inline-flex items-center space-x-1 bg-emerald-600 hover:bg-emerald-700 text-white px-3 py-2 rounded text-sm">
                    <x-icon name="check" class="w-4 h-4" /><span>一括精算</span>
                </button>
            </form>
            <a href="{{ route('bets.whatif', request()->only(['kind', 'from', 'to'])) }}" class="inline-flex items-center space-x-1 bg-purple-600 hover:bg-purple-700 text-white px-3 py-2 rounded text-sm">
                <x-icon name="sparkles" class="w-4 h-4" /><span>What-if</span>
            </a>
            <a href="{{ route('bankroll.index') }}" class="inline-flex items-center space-x-1 bg-amber-600 hover:bg-amber-700 text-white px-3 py-2 rounded text-sm">
                <x-icon name="cash" class="w-4 h-4" /><span>バンクロール</span>
            </a>
            <a href="{{ route('bets.export-csv', request()->query()) }}" class="inline-flex items-center space-x-1 bg-sky-600 hover:bg-sky-700 text-white px-3 py-2 rounded text-sm">
                <x-icon name="download" class="w-4 h-4" /><span>CSV</span>
            </a>
            <a href="{{ route('bets.print', request()->query()) }}" target="_blank" class="inline-flex items-center space-x-1 bg-gray-600 hover:bg-gray-700 text-white px-3 py-2 rounded text-sm">
                <x-icon name="document" class="w-4 h-4" /><span>印刷</span>
            </a>
            <a href="{{ route('betting.dashboard') }}" class="inline-flex items-center space-x-1 bg-gold-500 hover:bg-gold-600 text-white px-3 py-2 rounded text-sm">
                <x-icon name="chart" class="w-4 h-4" /><span>ダッシュボード</span>
            </a>
            <a href="{{ route('bets.create') }}" class="inline-flex items-center space-x-1 bg-turf-600 hover:bg-turf-700 text-white px-3 py-2 rounded text-sm">
                <x-icon name="plus" class="w-4 h-4" /><span>馬券を登録</span>
            </a>
        </x-slot>
    </x-page-header>

    {{-- KPIカード --}}
    <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
        <x-kpi-card label="購入件数" :value="number_format($summary['count'])" subtext="件" icon="list" color="turf" />
        <x-kpi-card label="投資額" :value="'¥'.number_format($summary['stake'])" icon="cash" color="sand" />
        <x-kpi-card label="払戻額" :value="'¥'.number_format($summary['return'])" icon="cash" color="gold" />
        <x-kpi-card
            label="収支"
            :value="($summary['profit'] >= 0 ? '+' : '').'¥'.number_format($summary['profit'])"
            :subtext="$summary['roi'] !== null ? '回収率 '.$summary['roi'].'%' : '-'"
            icon="chart"
            :color="$summary['profit'] >= 0 ? 'turf' : 'rose'" />
    </div>

    {{-- フィルタ --}}
    <form method="GET" class="bg-white dark:bg-gray-800 rounded-lg shadow-sm ring-1 ring-gray-100 dark:ring-gray-700 p-4">
        <div class="flex items-center space-x-2 mb-3 text-sm font-medium text-gray-700 dark:text-gray-200">
            <x-icon name="filter" class="w-4 h-4" /><span>絞り込み</span>
        </div>
        <div class="grid grid-cols-2 md:grid-cols-6 gap-3 text-sm">
            <div>
                <label class="block text-xs text-gray-600 dark:text-gray-400 mb-1">券種</label>
                <select name="kind" class="w-full border rounded px-2 py-1.5 dark:bg-gray-700 dark:border-gray-600">
                    <option value="">すべて</option>
                    @foreach ($kinds as $k => $label)
                        <option value="{{ $k }}" @selected(request('kind') === $k)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="block text-xs text-gray-600 dark:text-gray-400 mb-1">状態</label>
                <select name="status" class="w-full border rounded px-2 py-1.5 dark:bg-gray-700 dark:border-gray-600">
                    <option value="">すべて</option>
                    <option value="hit" @selected(request('status') === 'hit')>的中</option>
                    <option value="miss" @selected(request('status') === 'miss')>不的中</option>
                    <option value="open" @selected(request('status') === 'open')>未確定</option>
                </select>
            </div>
            <div>
                <label class="block text-xs text-gray-600 dark:text-gray-400 mb-1">競馬場</label>
                <select name="venue_id" class="w-full border rounded px-2 py-1.5 dark:bg-gray-700 dark:border-gray-600">
                    <option value="">すべて</option>
                    @foreach ($venues as $v)
                        <option value="{{ $v->id }}" @selected(request('venue_id') == $v->id)>{{ $v->name }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="block text-xs text-gray-600 dark:text-gray-400 mb-1">From</label>
                <input type="date" name="from" value="{{ request('from') }}" class="w-full border rounded px-2 py-1.5 dark:bg-gray-700 dark:border-gray-600">
            </div>
            <div>
                <label class="block text-xs text-gray-600 dark:text-gray-400 mb-1">To</label>
                <input type="date" name="to" value="{{ request('to') }}" class="w-full border rounded px-2 py-1.5 dark:bg-gray-700 dark:border-gray-600">
            </div>
            <div class="flex items-end space-x-1">
                <button class="bg-turf-600 hover:bg-turf-700 text-white px-3 py-1.5 rounded text-sm inline-flex items-center space-x-1">
                    <x-icon name="search" class="w-4 h-4" /><span>絞込</span>
                </button>
                <a href="{{ route('bets.index') }}" class="text-gray-500 hover:text-gray-700 px-2 text-sm">クリア</a>
            </div>
        </div>
    </form>

    {{-- 一覧 --}}
    @if ($bets->isEmpty())
        <x-empty-state icon="cash" title="馬券がありません" message="買った馬券を登録して収支を可視化しましょう" actionLabel="馬券を登録" :actionHref="route('bets.create')" actionIcon="plus" />
    @else
    <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm ring-1 ring-gray-100 dark:ring-gray-700 overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="bg-gray-50 dark:bg-gray-700/50 text-gray-600 dark:text-gray-300 text-xs uppercase">
                <tr>
                    <th class="px-3 py-2 text-left">日付 / レース</th>
                    <th class="px-3 py-2 text-left">券種 / 買い方</th>
                    <th class="px-3 py-2 text-right">投資</th>
                    <th class="px-3 py-2 text-right">払戻</th>
                    <th class="px-3 py-2 text-right">収支</th>
                    <th class="px-3 py-2 text-center">状態</th>
                    <th class="px-3 py-2"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                @foreach ($bets as $bet)
                <tr class="hover:bg-turf-50/50 dark:hover:bg-gray-700/40">
                    <td class="px-3 py-2">
                        <div class="text-xs text-gray-500">{{ $bet->race?->race_date?->format('Y/m/d') }} {{ $bet->race?->venue?->name }} {{ $bet->race?->race_number }}R</div>
                        <a href="{{ route('races.show', $bet->race) }}" class="font-medium text-turf-700 dark:text-turf-300 hover:underline">{{ $bet->race?->name }}</a>
                    </td>
                    <td class="px-3 py-2">
                        <div class="font-medium">{{ $bet->kind_label }}</div>
                        <div class="text-xs text-gray-500">{{ $bet->method_label }} / {{ $bet->points }}点</div>
                    </td>
                    <td class="px-3 py-2 text-right tabular-nums">¥{{ number_format($bet->total_stake) }}</td>
                    <td class="px-3 py-2 text-right tabular-nums {{ $bet->total_return > 0 ? 'text-gold-600 font-bold' : 'text-gray-400' }}">¥{{ number_format($bet->total_return) }}</td>
                    <td class="px-3 py-2 text-right tabular-nums font-bold {{ $bet->profit >= 0 ? 'text-emerald-600' : 'text-rose-600' }}">
                        {{ $bet->profit >= 0 ? '+' : '' }}¥{{ number_format($bet->profit) }}
                    </td>
                    <td class="px-3 py-2 text-center">
                        @if (!$bet->is_settled)
                            <span class="inline-block bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-300 text-xs px-2 py-0.5 rounded">未確定</span>
                        @elseif ($bet->hit_count > 0)
                            <span class="inline-block bg-emerald-100 dark:bg-emerald-900/40 text-emerald-700 dark:text-emerald-300 text-xs px-2 py-0.5 rounded font-bold">的中 {{ $bet->hit_count }}/{{ $bet->points }}</span>
                        @else
                            <span class="inline-block bg-rose-100 dark:bg-rose-900/40 text-rose-700 dark:text-rose-300 text-xs px-2 py-0.5 rounded">不的中</span>
                        @endif
                    </td>
                    <td class="px-3 py-2 text-right">
                        <a href="{{ route('bets.show', $bet) }}" class="text-xs text-turf-600 hover:underline mr-2">詳細</a>
                        <a href="{{ route('bets.edit', $bet) }}" class="text-xs text-gray-500 hover:underline">編集</a>
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    <div class="mt-3">{{ $bets->links() }}</div>
    @endif
</div>
@endsection
