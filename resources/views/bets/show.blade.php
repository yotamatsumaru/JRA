@extends('layouts.app')
@section('title', '馬券詳細')

@section('content')
<div class="max-w-4xl mx-auto space-y-4">
    <x-page-header
        title="{{ $bet->kind_label }} {{ $bet->method_label }}"
        subtitle="{{ $bet->race?->race_date?->format('Y/m/d') }} {{ $bet->race?->venue?->name }} {{ $bet->race?->race_number }}R - {{ $bet->race?->name }}"
        icon="cash">
        <x-slot name="actions">
            <form method="POST" action="{{ route('bets.settle', $bet) }}" class="inline">
                @csrf
                <button type="submit" class="inline-flex items-center space-x-1 bg-sky-500 hover:bg-sky-600 text-white px-3 py-1.5 rounded text-sm">
                    <x-icon name="bolt" class="w-4 h-4" /><span>再精算</span>
                </button>
            </form>
            <a href="{{ route('bets.edit', $bet) }}" class="inline-flex items-center space-x-1 bg-gray-200 hover:bg-gray-300 dark:bg-gray-700 dark:hover:bg-gray-600 text-gray-800 dark:text-gray-100 px-3 py-1.5 rounded text-sm">
                <x-icon name="edit" class="w-4 h-4" /><span>編集</span>
            </a>
        </x-slot>
    </x-page-header>

    {{-- KPI --}}
    <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
        <x-kpi-card label="点数" :value="$bet->points" subtext="点 ({{ $bet->method_label }})" icon="list" color="turf" />
        <x-kpi-card label="投資額" :value="'¥'.number_format($bet->total_stake)" :subtext="'@ ¥'.number_format($bet->unit_stake).'/点'" icon="cash" color="sand" />
        <x-kpi-card label="払戻額" :value="'¥'.number_format($bet->total_return)" :subtext="$bet->hit_count.' 点的中'" icon="cash" color="gold" />
        <x-kpi-card
            label="収支"
            :value="($bet->profit >= 0 ? '+' : '').'¥'.number_format($bet->profit)"
            :subtext="$bet->roi !== null ? '回収率 '.$bet->roi.'%' : '-'"
            icon="chart"
            :color="$bet->profit >= 0 ? 'turf' : 'rose'" />
    </div>

    {{-- 状態バッジ --}}
    <div class="flex items-center space-x-2">
        @if (!$bet->is_settled)
            <span class="bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-200 px-3 py-1 rounded-full text-sm">未確定 (レース結果未登録)</span>
        @elseif ($bet->hit_count > 0)
            <span class="bg-emerald-100 dark:bg-emerald-900/40 text-emerald-700 dark:text-emerald-300 px-3 py-1 rounded-full text-sm font-bold">
                的中 {{ $bet->hit_count }} / {{ $bet->points }} 点
            </span>
        @else
            <span class="bg-rose-100 dark:bg-rose-900/40 text-rose-700 dark:text-rose-300 px-3 py-1 rounded-full text-sm">不的中</span>
        @endif
        @if ($bet->purchased_at)
            <span class="text-xs text-gray-500">購入: {{ $bet->purchased_at->format('Y/m/d H:i') }}</span>
        @endif
    </div>

    {{-- 買い目一覧 --}}
    <div class="bg-white dark:bg-gray-800 rounded-lg shadow p-5">
        <h2 class="text-sm font-semibold text-gray-700 dark:text-gray-200 mb-3 flex items-center space-x-1">
            <x-icon name="list" class="w-4 h-4" /><span>買い目（{{ $bet->legs->count() }}点）</span>
        </h2>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 dark:bg-gray-700/50 text-xs text-gray-600 dark:text-gray-300">
                    <tr>
                        <th class="px-3 py-2 text-left">組合せ</th>
                        <th class="px-3 py-2 text-right">投資</th>
                        <th class="px-3 py-2 text-right">払戻</th>
                        <th class="px-3 py-2 text-right">収支</th>
                        <th class="px-3 py-2 text-center">的中</th>
                        <th class="px-3 py-2 text-center">人気</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                    @foreach ($bet->legs as $leg)
                    <tr class="{{ $leg->is_hit ? 'bg-emerald-50/60 dark:bg-emerald-900/10' : '' }}">
                        <td class="px-3 py-2 font-mono">
                            @foreach ($leg->numbers as $i => $n)
                                @if ($i > 0)<span class="text-gray-400 mx-0.5">{{ $bet->kind === 'uma-tan' || $bet->kind === 'san-tan' ? '→' : '-' }}</span>@endif
                                <span class="inline-block w-7 h-7 rounded-full text-center leading-7 text-xs font-bold
                                    @if ($i === 0) bg-turf-600 text-white
                                    @elseif ($i === 1) bg-sky-500 text-white
                                    @else bg-rose-500 text-white
                                    @endif">{{ $n }}</span>
                            @endforeach
                        </td>
                        <td class="px-3 py-2 text-right tabular-nums">¥{{ number_format($leg->stake) }}</td>
                        <td class="px-3 py-2 text-right tabular-nums {{ $leg->payout > 0 ? 'text-gold-600 font-bold' : 'text-gray-400' }}">¥{{ number_format($leg->payout) }}</td>
                        <td class="px-3 py-2 text-right tabular-nums font-bold {{ $leg->profit >= 0 ? 'text-emerald-600' : 'text-rose-600' }}">
                            {{ $leg->profit >= 0 ? '+' : '' }}¥{{ number_format($leg->profit) }}
                        </td>
                        <td class="px-3 py-2 text-center">
                            @if ($leg->is_hit)
                                <x-icon name="check" class="w-4 h-4 inline-block text-emerald-600" />
                            @else
                                <span class="text-gray-300">-</span>
                            @endif
                        </td>
                        <td class="px-3 py-2 text-center text-xs text-gray-500">
                            {{ $leg->payout_popularity ? $leg->payout_popularity.'番人気' : '-' }}
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>

    {{-- レース結果（参考） --}}
    @if ($bet->race && $bet->race->results->isNotEmpty())
    <div class="bg-white dark:bg-gray-800 rounded-lg shadow p-5">
        <h2 class="text-sm font-semibold text-gray-700 dark:text-gray-200 mb-3 flex items-center space-x-1">
            <x-icon name="trophy" class="w-4 h-4 text-gold-500" /><span>レース結果</span>
        </h2>
        <div class="grid grid-cols-3 gap-3 text-sm">
            @foreach ($bet->race->results->take(3) as $r)
                @php $rankColor = ['bg-gold-500', 'bg-gray-400', 'bg-amber-700'][$r->finish_position_int - 1] ?? 'bg-gray-300'; @endphp
                <div class="border border-gray-200 dark:border-gray-700 rounded p-3">
                    <div class="flex items-center space-x-2 mb-2">
                        <span class="inline-flex items-center justify-center w-7 h-7 rounded-full {{ $rankColor }} text-white font-bold text-sm">{{ $r->finish_position_int }}</span>
                        <span class="text-xs text-gray-500">馬番 {{ $r->horse_number }}</span>
                    </div>
                    <div class="font-medium">{{ $r->horse?->name }}</div>
                    <div class="text-xs text-gray-500">{{ $r->jockey?->name }} / {{ $r->time }}</div>
                </div>
            @endforeach
        </div>
    </div>
    @endif

    {{-- 払戻データ（参考・公式） --}}
    @if ($bet->race && $bet->race->payouts->isNotEmpty())
    <div class="bg-white dark:bg-gray-800 rounded-lg shadow p-5">
        <h2 class="text-sm font-semibold text-gray-700 dark:text-gray-200 mb-3 flex items-center space-x-1">
            <x-icon name="cash" class="w-4 h-4 text-amber-500" /><span>公式払戻</span>
        </h2>
        <div class="grid grid-cols-2 md:grid-cols-4 gap-2 text-sm">
            @foreach ($bet->race->payouts->groupBy('kind') as $kind => $pays)
            <div class="border border-gray-200 dark:border-gray-700 rounded p-2">
                <div class="text-xs font-bold text-gray-700 dark:text-gray-200 mb-1">{{ \App\Models\Bet::KIND_LABELS[$kind] ?? $kind }}</div>
                @foreach ($pays as $p)
                <div class="text-xs flex justify-between">
                    <span class="font-mono">{{ $p->combination }}</span>
                    <span class="text-gold-600 font-bold tabular-nums">¥{{ number_format($p->amount) }}</span>
                </div>
                @endforeach
            </div>
            @endforeach
        </div>
    </div>
    @endif

    {{-- メモ --}}
    @if ($bet->memo)
    <div class="bg-amber-50 dark:bg-amber-900/20 rounded-lg p-4 border-l-4 border-amber-300">
        <div class="text-xs text-amber-700 dark:text-amber-300 font-bold mb-1">メモ</div>
        <div class="text-sm whitespace-pre-wrap text-gray-800 dark:text-gray-200">{{ $bet->memo }}</div>
    </div>
    @endif
</div>
@endsection
