@extends('layouts.app')
@section('title', '払戻金一覧')

@section('content')
<div class="space-y-5">
    <x-page-header title="払戻金一覧" subtitle="取込済レースの公式払戻データ（フィルタ・ソート・CSV出力）" icon="cash">
        <x-slot name="actions">
            <a href="{{ route('betting.dashboard') }}"
               class="inline-flex items-center space-x-1 bg-gray-200 hover:bg-gray-300 dark:bg-gray-700 dark:hover:bg-gray-600 text-gray-800 dark:text-gray-100 px-3 py-2 rounded text-sm">
                <x-icon name="chart" class="w-4 h-4" /><span>ダッシュボード</span>
            </a>
            <a href="{{ route('betting.payouts') }}"
               class="inline-flex items-center space-x-1 bg-gray-200 hover:bg-gray-300 dark:bg-gray-700 dark:hover:bg-gray-600 text-gray-800 dark:text-gray-100 px-3 py-2 rounded text-sm">
                <x-icon name="chart" class="w-4 h-4" /><span>傾向分析</span>
            </a>
            <a href="{{ route('betting.payouts.list', array_merge(request()->query(), ['export' => 'csv'])) }}"
               class="inline-flex items-center space-x-1 bg-gold-500 hover:bg-gold-600 text-white px-3 py-2 rounded text-sm">
                <x-icon name="download" class="w-4 h-4" /><span>CSV出力</span>
            </a>
        </x-slot>
    </x-page-header>

    {{-- フィルタフォーム --}}
    <form method="GET" class="bg-white dark:bg-gray-800 rounded-lg shadow-sm ring-1 ring-gray-100 dark:ring-gray-700 p-4">
        <div class="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-7 gap-3 text-sm">
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
                    <option value="">すべて</option>
                    @foreach ($kinds as $k => $label)
                        <option value="{{ $k }}" @selected($kind === $k)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="block text-xs text-gray-600 dark:text-gray-400 mb-1">競馬場</label>
                <select name="venue_id" class="w-full border rounded px-2 py-1.5 dark:bg-gray-700 dark:border-gray-600">
                    <option value="">すべて</option>
                    @foreach ($venues as $v)
                        <option value="{{ $v->id }}" @selected((string) $venueId === (string) $v->id)>{{ $v->name }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="block text-xs text-gray-600 dark:text-gray-400 mb-1">最低配当(円)</label>
                <input type="number" name="min_amount" value="{{ $minAmount }}" min="0" step="100"
                       class="w-full border rounded px-2 py-1.5 dark:bg-gray-700 dark:border-gray-600" placeholder="例: 1000">
            </div>
            <div>
                <label class="block text-xs text-gray-600 dark:text-gray-400 mb-1">最高配当(円)</label>
                <input type="number" name="max_amount" value="{{ $maxAmount }}" min="0" step="100"
                       class="w-full border rounded px-2 py-1.5 dark:bg-gray-700 dark:border-gray-600" placeholder="例: 100000">
            </div>
            <div>
                <label class="block text-xs text-gray-600 dark:text-gray-400 mb-1">人気</label>
                <input type="number" name="popularity" value="{{ $popularity }}" min="1" max="18"
                       class="w-full border rounded px-2 py-1.5 dark:bg-gray-700 dark:border-gray-600" placeholder="例: 1">
            </div>
        </div>

        <div class="flex flex-wrap items-end gap-3 mt-3">
            <div>
                <label class="block text-xs text-gray-600 dark:text-gray-400 mb-1">並び順</label>
                <select name="sort" class="border rounded px-2 py-1.5 dark:bg-gray-700 dark:border-gray-600 text-sm">
                    <option value="date_desc"   @selected($sort === 'date_desc')>日付（新しい順）</option>
                    <option value="date_asc"    @selected($sort === 'date_asc')>日付（古い順）</option>
                    <option value="amount_desc" @selected($sort === 'amount_desc')>配当（高い順）</option>
                    <option value="amount_asc"  @selected($sort === 'amount_asc')>配当（安い順）</option>
                    <option value="pop_desc"    @selected($sort === 'pop_desc')>人気（薄い順）</option>
                    <option value="pop_asc"     @selected($sort === 'pop_asc')>人気（濃い順）</option>
                </select>
            </div>
            <button class="bg-turf-600 hover:bg-turf-700 text-white px-4 py-1.5 rounded text-sm inline-flex items-center space-x-1">
                <x-icon name="filter" class="w-4 h-4" /><span>適用</span>
            </button>
            <a href="{{ route('betting.payouts.list') }}" class="text-gray-500 hover:text-gray-700 dark:hover:text-gray-300 px-2 text-sm">クリア</a>
        </div>
    </form>

    {{-- サマリ --}}
    <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
        <x-kpi-card label="該当件数" :value="number_format($summary['cnt'])" subtext="件" icon="list" color="turf" />
        <x-kpi-card label="平均配当" :value="'¥'.number_format($summary['avg'])" subtext="100円あたり" icon="chart" color="sand" />
        <x-kpi-card label="最高配当" :value="'¥'.number_format($summary['max'])" icon="trophy" color="gold" />
        <x-kpi-card label="最低配当" :value="'¥'.number_format($summary['min'])" icon="cash" color="sky" />
    </div>

    {{-- 一覧テーブル --}}
    <div class="bg-white dark:bg-gray-800 rounded-lg shadow overflow-hidden">
        @if ($payouts->isEmpty())
            <div class="p-10 text-center text-sm text-gray-400">
                条件に該当する払戻データがありません
            </div>
        @else
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 dark:bg-gray-700/50 text-xs text-gray-600 dark:text-gray-300">
                    <tr>
                        <th class="px-3 py-2 text-left">日付</th>
                        <th class="px-3 py-2 text-left">競馬場 / R</th>
                        <th class="px-3 py-2 text-left">レース名</th>
                        <th class="px-3 py-2 text-left">券種</th>
                        <th class="px-3 py-2 text-left">組合せ</th>
                        <th class="px-3 py-2 text-right">配当</th>
                        <th class="px-3 py-2 text-right">倍率</th>
                        <th class="px-3 py-2 text-right">人気</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                    @foreach ($payouts as $p)
                    <tr class="hover:bg-gray-50 dark:hover:bg-gray-700/30">
                        <td class="px-3 py-2 text-xs text-gray-500 whitespace-nowrap">
                            {{ \Carbon\Carbon::parse($p->race_date)->format('Y/m/d') }}
                        </td>
                        <td class="px-3 py-2 whitespace-nowrap">
                            <span class="text-gray-700 dark:text-gray-300">{{ $p->venue_name }}</span>
                            <span class="text-xs text-gray-400">{{ $p->race_number }}R</span>
                        </td>
                        <td class="px-3 py-2">
                            <a href="{{ route('races.show', $p->race_id) }}" class="text-turf-600 hover:underline">
                                {{ $p->race_name }}
                            </a>
                        </td>
                        <td class="px-3 py-2">
                            @php
                                $kindColors = [
                                    'tan' => 'bg-rose-100 text-rose-700 dark:bg-rose-900/30 dark:text-rose-300',
                                    'fuku' => 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-300',
                                    'waku-ren' => 'bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-300',
                                    'uma-ren' => 'bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-300',
                                    'uma-tan' => 'bg-indigo-100 text-indigo-700 dark:bg-indigo-900/30 dark:text-indigo-300',
                                    'wide' => 'bg-cyan-100 text-cyan-700 dark:bg-cyan-900/30 dark:text-cyan-300',
                                    'san-fuku' => 'bg-purple-100 text-purple-700 dark:bg-purple-900/30 dark:text-purple-300',
                                    'san-tan' => 'bg-pink-100 text-pink-700 dark:bg-pink-900/30 dark:text-pink-300',
                                ];
                                $cls = $kindColors[$p->kind] ?? 'bg-gray-100 text-gray-700 dark:bg-gray-700 dark:text-gray-300';
                            @endphp
                            <span class="inline-block px-2 py-0.5 rounded text-xs font-medium {{ $cls }}">
                                {{ \App\Models\Bet::KIND_LABELS[$p->kind] ?? $p->kind }}
                            </span>
                        </td>
                        <td class="px-3 py-2 font-mono text-sm text-gray-700 dark:text-gray-200">{{ $p->combination }}</td>
                        <td class="px-3 py-2 text-right tabular-nums font-bold {{ $p->amount >= 10000 ? 'text-gold-600' : 'text-gray-700 dark:text-gray-200' }}">
                            ¥{{ number_format($p->amount) }}
                        </td>
                        <td class="px-3 py-2 text-right tabular-nums text-xs text-gray-500">
                            {{ number_format($p->amount / 100, 1) }}倍
                        </td>
                        <td class="px-3 py-2 text-right tabular-nums text-xs">
                            @if ($p->popularity)
                                <span class="text-gray-700 dark:text-gray-300">{{ $p->popularity }}</span>
                                <span class="text-gray-400">番人気</span>
                            @else
                                <span class="text-gray-400">-</span>
                            @endif
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="px-3 py-3 border-t border-gray-100 dark:border-gray-700">
            {{ $payouts->links() }}
        </div>
        @endif
    </div>
</div>
@endsection
