@extends('layouts.app')
@section('title', '通算成績スタッツ')

@section('content')
<div class="space-y-5">
    <x-page-header title="通算成績スタッツ" subtitle="騎手 / 調教師 / 馬の出走数・勝率・複勝率・競馬場別成績" icon="trophy">
        <x-slot name="actions">
            <a href="{{ route('analytics.venue') }}" class="inline-flex items-center space-x-1 bg-gray-200 hover:bg-gray-300 dark:bg-gray-700 dark:hover:bg-gray-600 text-gray-800 dark:text-gray-100 px-3 py-2 rounded text-sm">
                <x-icon name="chart" class="w-4 h-4" /><span>競馬場分析</span>
            </a>
            <a href="{{ route('analytics.roi') }}" class="inline-flex items-center space-x-1 bg-gray-200 hover:bg-gray-300 dark:bg-gray-700 dark:hover:bg-gray-600 text-gray-800 dark:text-gray-100 px-3 py-2 rounded text-sm">
                <x-icon name="cash" class="w-4 h-4" /><span>回収率</span>
            </a>
        </x-slot>
    </x-page-header>

    {{-- タイプ切替タブ --}}
    <div class="flex flex-wrap gap-2">
        @php
            $tabs = [
                'jockey'  => ['騎手',   'user'],
                'trainer' => ['調教師', 'users'],
                'horse'   => ['馬',     'flag'],
            ];
        @endphp
        @foreach ($tabs as $t => [$tlabel, $ticon])
            <a href="{{ route('analytics.stats', array_merge(request()->except(['type','page']), ['type' => $t])) }}"
               class="inline-flex items-center space-x-1 px-4 py-2 rounded-lg text-sm font-medium transition
                      {{ $type === $t ? 'bg-turf-600 text-white shadow' : 'bg-white dark:bg-gray-800 text-gray-700 dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-700 ring-1 ring-gray-200 dark:ring-gray-700' }}">
                <x-icon name="{{ $ticon }}" class="w-4 h-4" />
                <span>{{ $tlabel }}</span>
            </a>
        @endforeach
    </div>

    {{-- フィルタ --}}
    <form method="GET" x-data="{ open: window.innerWidth >= 640 }" class="bg-white dark:bg-gray-800 rounded-lg shadow-sm ring-1 ring-gray-100 dark:ring-gray-700 p-4">
        <input type="hidden" name="type" value="{{ $type }}">
        <button type="button" @click="open = !open" class="w-full flex items-center justify-between mb-3 text-sm font-semibold text-gray-700 dark:text-gray-200 sm:hidden">
            <span class="flex items-center space-x-2"><x-icon name="filter" class="w-4 h-4" /><span>フィルタ</span></span>
            <span x-text="open ? '−' : '＋'"></span>
        </button>
        <div x-show="open" x-transition.opacity class="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-6 gap-3 text-sm">
            <div>
                <label class="block text-xs text-gray-600 dark:text-gray-400 mb-1">From</label>
                <input type="date" name="from" value="{{ $from }}" class="w-full border rounded px-2 py-1.5 dark:bg-gray-700 dark:border-gray-600">
            </div>
            <div>
                <label class="block text-xs text-gray-600 dark:text-gray-400 mb-1">To</label>
                <input type="date" name="to" value="{{ $to }}" class="w-full border rounded px-2 py-1.5 dark:bg-gray-700 dark:border-gray-600">
            </div>
            <div>
                <label class="block text-xs text-gray-600 dark:text-gray-400 mb-1">競馬場</label>
                <select name="venue_id" class="w-full border rounded px-2 py-1.5 dark:bg-gray-700 dark:border-gray-600">
                    <option value="">すべて</option>
                    @foreach ($venues as $v)
                        <option value="{{ $v->id }}" @selected((string)$venueId === (string)$v->id)>{{ $v->name }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="block text-xs text-gray-600 dark:text-gray-400 mb-1">トラック</label>
                <select name="track_type" class="w-full border rounded px-2 py-1.5 dark:bg-gray-700 dark:border-gray-600">
                    <option value="">すべて</option>
                    <option value="芝"     @selected($trackType==='芝')>芝</option>
                    <option value="ダート" @selected($trackType==='ダート')>ダート</option>
                    <option value="障害"   @selected($trackType==='障害')>障害</option>
                </select>
            </div>
            <div>
                <label class="block text-xs text-gray-600 dark:text-gray-400 mb-1">最小出走数</label>
                <input type="number" name="min_runs" value="{{ $minRuns }}" min="1" class="w-full border rounded px-2 py-1.5 dark:bg-gray-700 dark:border-gray-600">
            </div>
            <div>
                <label class="block text-xs text-gray-600 dark:text-gray-400 mb-1">並び順</label>
                <select name="sort" class="w-full border rounded px-2 py-1.5 dark:bg-gray-700 dark:border-gray-600">
                    <option value="win_rate"   @selected($sort==='win_rate')>勝率（高い順）</option>
                    <option value="place_rate" @selected($sort==='place_rate')>連対率（高い順）</option>
                    <option value="show_rate"  @selected($sort==='show_rate')>複勝率（高い順）</option>
                    <option value="win"        @selected($sort==='win')>勝利数（多い順）</option>
                    <option value="runs"       @selected($sort==='runs')>出走数（多い順）</option>
                </select>
            </div>
        </div>
        <div x-show="open" x-transition.opacity class="flex gap-2 mt-3">
            <button class="bg-turf-600 hover:bg-turf-700 text-white px-4 py-1.5 rounded text-sm inline-flex items-center space-x-1">
                <x-icon name="filter" class="w-4 h-4" /><span>適用</span>
            </button>
            <a href="{{ route('analytics.stats', ['type' => $type]) }}" class="text-gray-500 hover:text-gray-700 dark:hover:text-gray-300 px-2 text-sm self-center">クリア</a>
        </div>
    </form>

    {{-- 全体サマリ --}}
    <div class="grid grid-cols-2 md:grid-cols-4 gap-3" x-data>
        <x-kpi-card label="対象{{ $label }}数" :value="number_format($total['actors'])" subtext="人 / 頭" icon="users" color="turf" />
        <x-kpi-card label="総出走数"        :value="number_format($total['runs'])"   subtext="件" icon="list" color="sand" />
        <x-kpi-card label="全体勝率"        :value="$total['win_rate'].'%'"          subtext="1着率" icon="trophy" color="gold" />
        <x-kpi-card label="全体複勝率"      :value="$total['show_rate'].'%'"         subtext="3着以内率" icon="chart" color="sky" />
    </div>

    {{-- ランキングテーブル --}}
    <div class="bg-white dark:bg-gray-800 rounded-lg shadow overflow-hidden">
        <div class="px-4 py-3 border-b border-gray-100 dark:border-gray-700 flex items-center justify-between flex-wrap gap-2">
            <h2 class="font-semibold text-gray-800 dark:text-gray-100">{{ $label }}ランキング (TOP100)</h2>
            <span class="text-xs text-gray-500">最小出走数: {{ $minRuns }}件</span>
        </div>
        @if ($rows->isEmpty())
            <div class="p-10 text-center text-sm text-gray-400">条件に該当するデータがありません</div>
        @else
        <div class="table-scroll">
            <table class="w-full text-sm min-w-[900px]">
                <thead class="bg-gray-50 dark:bg-gray-700/50 text-xs text-gray-600 dark:text-gray-300">
                    <tr>
                        <th class="px-3 py-2 text-right">#</th>
                        <th class="px-3 py-2 text-left">{{ $label }}</th>
                        <th class="px-3 py-2 text-right">出走</th>
                        <th class="px-3 py-2 text-right">1着</th>
                        <th class="px-3 py-2 text-right">2着以内</th>
                        <th class="px-3 py-2 text-right">3着以内</th>
                        <th class="px-3 py-2 text-right">勝率</th>
                        <th class="px-3 py-2 text-right">連対率</th>
                        <th class="px-3 py-2 text-right">複勝率</th>
                        <th class="px-3 py-2 text-right">平均人気</th>
                        <th class="px-3 py-2 text-right">平均着順</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                    @foreach ($rows as $i => $r)
                    <tr class="hover:bg-gray-50 dark:hover:bg-gray-700/30">
                        <td class="px-3 py-2 text-right text-xs text-gray-400 tabular-nums">{{ $i + 1 }}</td>
                        <td class="px-3 py-2 font-medium text-gray-800 dark:text-gray-100">
                            @if ($type === 'jockey')
                                <a href="{{ route('analytics.jockey', ['jockey' => $r['name']]) }}" class="text-turf-600 hover:underline">{{ $r['name'] }}</a>
                            @elseif ($type === 'horse')
                                <a href="{{ route('horses.show', $r['id']) }}" class="text-turf-600 hover:underline">{{ $r['name'] }}</a>
                            @else
                                {{ $r['name'] }}
                            @endif
                        </td>
                        <td class="px-3 py-2 text-right tabular-nums">{{ number_format($r['runs']) }}</td>
                        <td class="px-3 py-2 text-right tabular-nums font-bold text-gold-600">{{ number_format($r['wins']) }}</td>
                        <td class="px-3 py-2 text-right tabular-nums text-emerald-600">{{ number_format($r['places']) }}</td>
                        <td class="px-3 py-2 text-right tabular-nums text-sky-600">{{ number_format($r['shows']) }}</td>
                        <td class="px-3 py-2 text-right tabular-nums">
                            @php $wr = $r['win_rate']; @endphp
                            <span class="inline-block px-2 py-0.5 rounded {{ $wr >= 25 ? 'bg-gold-100 text-gold-700 dark:bg-gold-900/30 dark:text-gold-300 font-bold' : ($wr >= 15 ? 'bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-300' : '') }}">
                                {{ $wr }}%
                            </span>
                        </td>
                        <td class="px-3 py-2 text-right tabular-nums text-gray-600 dark:text-gray-300">{{ $r['place_rate'] }}%</td>
                        <td class="px-3 py-2 text-right tabular-nums text-gray-600 dark:text-gray-300">{{ $r['show_rate'] }}%</td>
                        <td class="px-3 py-2 text-right tabular-nums text-xs text-gray-500">
                            {{ $r['avg_pop'] !== null ? $r['avg_pop'] : '-' }}
                        </td>
                        <td class="px-3 py-2 text-right tabular-nums text-xs text-gray-500">
                            {{ $r['avg_finish'] !== null ? $r['avg_finish'] : '-' }}
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @endif
    </div>

    {{-- 競馬場別勝率（TOP10対象者のみ） --}}
    @if ($byVenue->isNotEmpty())
    <div class="bg-white dark:bg-gray-800 rounded-lg shadow overflow-hidden">
        <div class="px-4 py-3 border-b border-gray-100 dark:border-gray-700">
            <h2 class="font-semibold text-gray-800 dark:text-gray-100">競馬場別勝率（{{ $label }}TOP10）</h2>
            <p class="text-xs text-gray-500 mt-1">セルの色が濃いほど勝率が高い</p>
        </div>
        @php
            // ピボット: actor x venue → win_rate
            $actors  = $byVenue->groupBy('actor_id');
            $venuesUsed = $byVenue->pluck('venue_name')->unique()->values();
        @endphp
        <div class="table-scroll">
            <table class="w-full text-sm min-w-[640px]">
                <thead class="bg-gray-50 dark:bg-gray-700/50 text-xs text-gray-600 dark:text-gray-300">
                    <tr>
                        <th class="px-3 py-2 text-left">{{ $label }}</th>
                        @foreach ($venuesUsed as $vn)
                            <th class="px-2 py-2 text-center">{{ $vn }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                    @foreach ($actors as $actorId => $cells)
                        @php $first = $cells->first(); @endphp
                        <tr>
                            <td class="px-3 py-2 font-medium text-gray-800 dark:text-gray-100 whitespace-nowrap">{{ $first->actor_name }}</td>
                            @foreach ($venuesUsed as $vn)
                                @php
                                    $cell = $cells->firstWhere('venue_name', $vn);
                                @endphp
                                <td class="px-2 py-1 text-center text-xs tabular-nums">
                                    @if ($cell)
                                        @php
                                            $wr  = $cell->win_rate;
                                            $sat = min(100, max(0, $wr * 4));   // 25%で100% saturation
                                            $bg  = $wr >= 25 ? 'background-color: rgba(217,119,6,'.($sat/100).');'
                                                 : ($wr >= 15 ? 'background-color: rgba(245,158,11,'.($sat/100).');'
                                                 : ($wr >  0  ? 'background-color: rgba(125,211,252,'.($sat/200).');' : ''));
                                        @endphp
                                        <div class="rounded px-1 py-0.5" style="{{ $bg }}">
                                            <div class="font-semibold {{ $wr >= 20 ? 'text-white' : 'text-gray-800 dark:text-gray-100' }}">{{ $wr }}%</div>
                                            <div class="text-[10px] {{ $wr >= 20 ? 'text-white/90' : 'text-gray-500' }}">{{ $cell->wins }}/{{ $cell->runs }}</div>
                                        </div>
                                    @else
                                        <span class="text-gray-300">-</span>
                                    @endif
                                </td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
    @endif
</div>
@endsection
