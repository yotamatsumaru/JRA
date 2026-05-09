@extends('layouts.app')
@section('title', 'ダッシュボード')

@section('content')
<div class="space-y-6">

    <x-page-header title="ダッシュボード" subtitle="JRA 中央競馬データ分析" icon="home">
        <x-slot name="actions">
            <a href="{{ route('races.create') }}" class="inline-flex items-center space-x-1.5 bg-turf-600 hover:bg-turf-700 text-white px-4 py-2 rounded-md text-sm font-medium shadow-sm">
                <x-icon name="plus" class="w-4 h-4" />
                <span>レース登録</span>
            </a>
            <a href="{{ route('import.netkeiba') }}" class="inline-flex items-center space-x-1.5 bg-sand-600 hover:bg-sand-700 text-white px-4 py-2 rounded-md text-sm font-medium shadow-sm">
                <x-icon name="globe" class="w-4 h-4" />
                <span>netkeiba取込</span>
            </a>
        </x-slot>
    </x-page-header>

    {{-- KPI カード (上段) --}}
    <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
        <x-kpi-card label="登録レース" :value="$stats['races_total']" icon="flag" color="turf" :href="route('races.index')" />
        <x-kpi-card label="登録馬" :value="$stats['horses_total']" icon="sparkles" color="sand" :href="route('horses.index')" />
        <x-kpi-card label="現役騎手" :value="$stats['jockeys_total']" icon="user" color="gold" :href="route('jockeys.index')" />
        <x-kpi-card label="競馬場" :value="$stats['venues_total']" icon="map" color="rose" :href="route('venues.index')" />
    </div>

    {{-- KPI カード (下段) --}}
    <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
        <div class="bg-gradient-to-br from-emerald-500 to-teal-600 text-white rounded-lg shadow p-4">
            <div class="text-xs opacity-90">出走頭数累計</div>
            <div class="text-2xl font-bold mt-1">{{ number_format($stats['results_total'] ?? 0) }}</div>
            <div class="text-xs opacity-75 mt-1">レース結果レコード数</div>
        </div>
        <div class="bg-gradient-to-br from-amber-500 to-orange-600 text-white rounded-lg shadow p-4">
            <div class="text-xs opacity-90">現役調教師</div>
            <div class="text-2xl font-bold mt-1">{{ number_format($stats['trainers_total'] ?? 0) }}</div>
            <div class="text-xs opacity-75 mt-1">is_active=true</div>
        </div>
        <div class="bg-gradient-to-br from-sky-500 to-blue-600 text-white rounded-lg shadow p-4">
            <div class="text-xs opacity-90">今月のレース</div>
            <div class="text-2xl font-bold mt-1">{{ number_format($stats['races_this_month'] ?? 0) }}</div>
            <div class="text-xs opacity-75 mt-1">{{ now()->format('Y年n月') }}</div>
        </div>
        <div class="bg-gradient-to-br from-fuchsia-500 to-pink-600 text-white rounded-lg shadow p-4">
            <div class="text-xs opacity-90">最新レース日</div>
            <div class="text-2xl font-bold mt-1">
                @php
                    $ld = $stats['last_race_date'] ?? null;
                    $ldStr = '-';
                    try {
                        if ($ld) {
                            $ldStr = \Illuminate\Support\Carbon::parse($ld)->format('Y/m/d');
                        }
                    } catch (\Throwable $e) { $ldStr = (string) $ld; }
                @endphp
                {{ $ldStr }}
            </div>
            <div class="text-xs opacity-75 mt-1">DB取込済の最新</div>
        </div>
    </div>

    {{-- グラフ 1段目 --}}
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm ring-1 ring-gray-100 dark:ring-gray-700 p-4">
            <div class="flex items-center space-x-2 mb-3">
                <x-icon name="calendar" class="w-5 h-5 text-turf-600 dark:text-turf-400" />
                <h2 class="font-semibold text-gray-700 dark:text-gray-200">月別レース数（直近12か月）</h2>
            </div>
            <div id="chart-monthly"></div>
        </div>

        <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm ring-1 ring-gray-100 dark:ring-gray-700 p-4">
            <div class="flex items-center space-x-2 mb-3">
                <x-icon name="trophy" class="w-5 h-5 text-gold-500" />
                <h2 class="font-semibold text-gray-700 dark:text-gray-200">グレード別レース数</h2>
            </div>
            <div id="chart-grade"></div>
        </div>
    </div>

    {{-- グラフ 2段目: 競馬場 / トラック --}}
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm ring-1 ring-gray-100 dark:ring-gray-700 p-4 lg:col-span-2">
            <div class="flex items-center space-x-2 mb-3">
                <x-icon name="map" class="w-5 h-5 text-rose-500" />
                <h2 class="font-semibold text-gray-700 dark:text-gray-200">競馬場別レース数</h2>
            </div>
            <div id="chart-venue"></div>
        </div>

        <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm ring-1 ring-gray-100 dark:ring-gray-700 p-4">
            <div class="flex items-center space-x-2 mb-3">
                <x-icon name="flag" class="w-5 h-5 text-emerald-500" />
                <h2 class="font-semibold text-gray-700 dark:text-gray-200">トラック種別</h2>
            </div>
            <div id="chart-track"></div>
        </div>
    </div>

    {{-- グラフ 3段目: 距離 / 馬場 / 天候 / 曜日 --}}
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
        <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm ring-1 ring-gray-100 dark:ring-gray-700 p-4">
            <div class="flex items-center space-x-2 mb-3">
                <x-icon name="chart-bar" class="w-5 h-5 text-indigo-500" />
                <h2 class="font-semibold text-gray-700 dark:text-gray-200 text-sm">距離別</h2>
            </div>
            <div id="chart-distance"></div>
        </div>
        <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm ring-1 ring-gray-100 dark:ring-gray-700 p-4">
            <div class="flex items-center space-x-2 mb-3">
                <x-icon name="chart-bar" class="w-5 h-5 text-amber-600" />
                <h2 class="font-semibold text-gray-700 dark:text-gray-200 text-sm">馬場状態別</h2>
            </div>
            <div id="chart-condition"></div>
        </div>
        <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm ring-1 ring-gray-100 dark:ring-gray-700 p-4">
            <div class="flex items-center space-x-2 mb-3">
                <x-icon name="chart-bar" class="w-5 h-5 text-sky-500" />
                <h2 class="font-semibold text-gray-700 dark:text-gray-200 text-sm">天候別</h2>
            </div>
            <div id="chart-weather"></div>
        </div>
        <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm ring-1 ring-gray-100 dark:ring-gray-700 p-4">
            <div class="flex items-center space-x-2 mb-3">
                <x-icon name="calendar" class="w-5 h-5 text-rose-500" />
                <h2 class="font-semibold text-gray-700 dark:text-gray-200 text-sm">曜日別</h2>
            </div>
            <div id="chart-weekday"></div>
        </div>
    </div>

    {{-- 競馬場別傾向 セクション --}}
    @if (($venueTrackWinRate ?? collect())->isNotEmpty() || ($frameWinRates ?? collect())->isNotEmpty() || ($venueStyleStats ?? collect())->isNotEmpty())
    <div class="space-y-6">
        <div class="flex items-center space-x-2 pt-2">
            <x-icon name="map" class="w-6 h-6 text-rose-500" />
            <h2 class="text-lg sm:text-xl font-bold text-gray-800 dark:text-gray-100">競馬場別傾向</h2>
            <span class="text-xs text-gray-500 dark:text-gray-400">全レース集計</span>
        </div>

        {{-- A) 競馬場 × 芝/ダート 勝率ヒートマップ --}}
        @if (($venueTrackWinRate ?? collect())->isNotEmpty())
        <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm ring-1 ring-gray-100 dark:ring-gray-700 p-4">
            <div class="flex items-center space-x-2 mb-3 flex-wrap gap-2">
                <x-icon name="chart-bar" class="w-5 h-5 text-rose-500" />
                <h3 class="font-semibold text-gray-700 dark:text-gray-200">競馬場 × トラック種別 勝率（%）</h3>
                <span class="text-xs text-gray-500 dark:text-gray-400">セルの色が濃いほど勝率高</span>
            </div>
            <div id="chart-venue-track-winrate"></div>
        </div>
        @endif

        {{-- B) 全場合算 枠番別 勝率/複勝率 --}}
        @if (($frameWinRates ?? collect())->isNotEmpty())
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
            <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm ring-1 ring-gray-100 dark:ring-gray-700 p-4">
                <div class="flex items-center space-x-2 mb-3 flex-wrap gap-2">
                    <x-icon name="chart-bar" class="w-5 h-5 text-turf-600" />
                    <h3 class="font-semibold text-gray-700 dark:text-gray-200">枠番別 勝率（芝・ダート）</h3>
                </div>
                <div id="chart-frame-winrate"></div>
            </div>
            <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm ring-1 ring-gray-100 dark:ring-gray-700 p-4">
                <div class="flex items-center space-x-2 mb-3 flex-wrap gap-2">
                    <x-icon name="chart-bar" class="w-5 h-5 text-sand-600" />
                    <h3 class="font-semibold text-gray-700 dark:text-gray-200">枠番別 複勝率（芝・ダート）</h3>
                </div>
                <div id="chart-frame-showrate"></div>
            </div>
        </div>
        @endif

        {{-- C) 競馬場別 脚質傾向 --}}
        @if (($venueStyleStats ?? collect())->isNotEmpty())
        <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm ring-1 ring-gray-100 dark:ring-gray-700 p-4">
            <div class="flex items-center space-x-2 mb-3 flex-wrap gap-2">
                <x-icon name="chart-bar" class="w-5 h-5 text-indigo-500" />
                <h3 class="font-semibold text-gray-700 dark:text-gray-200">競馬場別 脚質勝率（%）</h3>
                <span class="text-xs text-gray-500 dark:text-gray-400">脚質ごとの勝率を競馬場別に比較</span>
            </div>
            <div id="chart-venue-style"></div>
        </div>
        @endif
    </div>
    @endif

    {{-- グラフ 4段目: 平均出走頭数 推移 --}}
    @if ($avgFieldByMonth->isNotEmpty())
    <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm ring-1 ring-gray-100 dark:ring-gray-700 p-4">
        <div class="flex items-center space-x-2 mb-3">
            <x-icon name="chart-bar" class="w-5 h-5 text-purple-500" />
            <h2 class="font-semibold text-gray-700 dark:text-gray-200">平均出走頭数の月推移（直近12か月）</h2>
        </div>
        <div id="chart-avg-field"></div>
    </div>
    @endif

    {{-- ランキング 4列 --}}
    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
        {{-- トップ騎手 --}}
        <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm ring-1 ring-gray-100 dark:ring-gray-700 p-4">
            <div class="flex items-center space-x-2 mb-3">
                <x-icon name="user" class="w-5 h-5 text-sky-500" />
                <h2 class="font-semibold text-gray-700 dark:text-gray-200">トップ騎手（勝利数）</h2>
            </div>
            @if ($topJockeys->isEmpty())
                <x-empty-state icon="user" title="データがまだありません" message="レース結果を取り込むと表示されます" />
            @else
                <ol class="space-y-1 text-sm">
                    @foreach ($topJockeys as $i => $tj)
                        <li class="flex items-center justify-between border-b dark:border-gray-700 py-2">
                            <div class="flex items-center space-x-3">
                                <div class="w-7 h-7 rounded-full flex items-center justify-center font-bold text-xs
                                    @if($i === 0) bg-gold-100 dark:bg-gold-900/40 text-gold-700 dark:text-gold-300
                                    @elseif($i === 1) bg-gray-200 dark:bg-gray-600 text-gray-700 dark:text-gray-200
                                    @elseif($i === 2) bg-sand-100 dark:bg-sand-900/40 text-sand-700 dark:text-sand-300
                                    @else bg-gray-50 dark:bg-gray-700 text-gray-500 dark:text-gray-400 @endif">
                                    {{ $i + 1 }}
                                </div>
                                @if ($tj->jockey)
                                    <a href="{{ route('jockeys.show', $tj->jockey) }}" class="hover:text-turf-600 dark:hover:text-turf-400 dark:text-gray-200">{{ $tj->jockey->name }}</a>
                                @else
                                    <span class="text-gray-400">不明</span>
                                @endif
                            </div>
                            <span class="font-bold text-turf-700 dark:text-turf-400">{{ $tj->wins }}勝</span>
                        </li>
                    @endforeach
                </ol>
            @endif
        </div>

        {{-- トップ調教師 --}}
        <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm ring-1 ring-gray-100 dark:ring-gray-700 p-4">
            <div class="flex items-center space-x-2 mb-3">
                <x-icon name="user" class="w-5 h-5 text-amber-500" />
                <h2 class="font-semibold text-gray-700 dark:text-gray-200">トップ調教師（勝利数）</h2>
            </div>
            @if ($topTrainers->isEmpty())
                <x-empty-state icon="user" title="データがまだありません" message="レース結果を取り込むと表示されます" />
            @else
                <ol class="space-y-1 text-sm">
                    @foreach ($topTrainers as $i => $tt)
                        <li class="flex items-center justify-between border-b dark:border-gray-700 py-2">
                            <div class="flex items-center space-x-3">
                                <div class="w-7 h-7 rounded-full flex items-center justify-center font-bold text-xs
                                    @if($i === 0) bg-gold-100 dark:bg-gold-900/40 text-gold-700 dark:text-gold-300
                                    @elseif($i === 1) bg-gray-200 dark:bg-gray-600 text-gray-700 dark:text-gray-200
                                    @elseif($i === 2) bg-sand-100 dark:bg-sand-900/40 text-sand-700 dark:text-sand-300
                                    @else bg-gray-50 dark:bg-gray-700 text-gray-500 dark:text-gray-400 @endif">
                                    {{ $i + 1 }}
                                </div>
                                <span class="dark:text-gray-200">{{ $tt->trainer?->name ?? '不明' }}</span>
                            </div>
                            <span class="font-bold text-amber-600 dark:text-amber-400">{{ $tt->wins }}勝</span>
                        </li>
                    @endforeach
                </ol>
            @endif
        </div>

        {{-- トップ種牡馬 --}}
        <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm ring-1 ring-gray-100 dark:ring-gray-700 p-4">
            <div class="flex items-center space-x-2 mb-3">
                <x-icon name="sparkles" class="w-5 h-5 text-rose-500" />
                <h2 class="font-semibold text-gray-700 dark:text-gray-200">トップ種牡馬（産駒勝利数）</h2>
            </div>
            @if ($topSires->isEmpty())
                <x-empty-state icon="sparkles" title="データがまだありません" message="血統情報の取込が必要です" />
            @else
                <ol class="space-y-1 text-sm">
                    @foreach ($topSires as $i => $sire)
                        <li class="flex items-center justify-between border-b dark:border-gray-700 py-2">
                            <div class="flex items-center space-x-3">
                                <div class="w-7 h-7 rounded-full flex items-center justify-center font-bold text-xs
                                    @if($i === 0) bg-rose-100 dark:bg-rose-900/40 text-rose-700 dark:text-rose-300
                                    @else bg-gray-50 dark:bg-gray-700 text-gray-500 dark:text-gray-400 @endif">
                                    {{ $i + 1 }}
                                </div>
                                <a href="{{ route('analytics.pedigree', ['father' => $sire->name]) }}"
                                   class="hover:text-rose-600 dark:hover:text-rose-400 dark:text-gray-200">
                                    {{ $sire->name }}
                                </a>
                            </div>
                            <span class="font-bold text-rose-600 dark:text-rose-400">{{ $sire->wins }}勝</span>
                        </li>
                    @endforeach
                </ol>
            @endif
        </div>

        {{-- トップ獲得賞金馬 --}}
        <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm ring-1 ring-gray-100 dark:ring-gray-700 p-4">
            <div class="flex items-center space-x-2 mb-3">
                <x-icon name="trophy" class="w-5 h-5 text-emerald-500" />
                <h2 class="font-semibold text-gray-700 dark:text-gray-200">獲得賞金トップ馬</h2>
            </div>
            @if ($topPrizeHorses->isEmpty())
                <x-empty-state icon="sparkles" title="データがまだありません" message="馬の総獲得賞金を取り込むと表示されます" />
            @else
                <ol class="space-y-1 text-sm">
                    @foreach ($topPrizeHorses as $i => $h)
                        <li class="flex items-center justify-between border-b dark:border-gray-700 py-2">
                            <div class="flex items-center space-x-3">
                                <div class="w-7 h-7 rounded-full flex items-center justify-center font-bold text-xs
                                    @if($i === 0) bg-emerald-100 dark:bg-emerald-900/40 text-emerald-700 dark:text-emerald-300
                                    @else bg-gray-50 dark:bg-gray-700 text-gray-500 dark:text-gray-400 @endif">
                                    {{ $i + 1 }}
                                </div>
                                <a href="{{ route('horses.show', $h) }}" class="hover:text-emerald-600 dark:hover:text-emerald-400 dark:text-gray-200">{{ $h->name }}</a>
                            </div>
                            <span class="font-bold text-emerald-600 dark:text-emerald-400 text-xs">¥{{ number_format($h->total_prize) }}万</span>
                        </li>
                    @endforeach
                </ol>
            @endif
        </div>
    </div>

    {{-- 直近開催日のレース --}}
    @if ($latestDateRaces->isNotEmpty())
    <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm ring-1 ring-gray-100 dark:ring-gray-700 p-4">
        <div class="flex justify-between items-center mb-3 flex-wrap gap-2">
            <div class="flex items-center space-x-2">
                <x-icon name="calendar" class="w-5 h-5 text-fuchsia-500" />
                <h2 class="font-semibold text-gray-700 dark:text-gray-200">
                    直近開催日のレース
                    <span class="text-xs font-normal text-gray-500 dark:text-gray-400 ml-2">
                        @php
                            $ldStr2 = '-';
                            try { if ($latestDate) $ldStr2 = \Illuminate\Support\Carbon::parse($latestDate)->format('Y/m/d (D)'); }
                            catch (\Throwable $e) { $ldStr2 = (string) $latestDate; }
                        @endphp
                        {{ $ldStr2 }}
                    </span>
                </h2>
            </div>
            <span class="text-xs text-gray-500 dark:text-gray-400">{{ $latestDateRaces->count() }}レース</span>
        </div>
        <div class="table-scroll">
            <table class="w-full text-sm min-w-[640px]">
                <thead class="text-xs text-gray-500 dark:text-gray-400 uppercase border-b dark:border-gray-700">
                    <tr>
                        <th class="text-left py-2 px-2">場</th>
                        <th class="text-left py-2 px-2">R</th>
                        <th class="text-left py-2 px-2">レース名</th>
                        <th class="text-left py-2 px-2">距離</th>
                        <th class="text-left py-2 px-2">馬場</th>
                        <th class="text-right py-2 px-2">頭数</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($latestDateRaces as $r)
                    <tr class="border-b dark:border-gray-700 hover:bg-turf-50/50 dark:hover:bg-gray-700/50 transition-colors">
                        <td class="py-2 px-2 dark:text-gray-300">{{ $r->venue?->name }}</td>
                        <td class="py-2 px-2 text-gray-600 dark:text-gray-400">{{ $r->race_number }}R</td>
                        <td class="py-2 px-2">
                            <a href="{{ route('races.show', $r) }}" class="text-turf-700 dark:text-turf-400 hover:underline font-medium">{{ $r->name }}</a>
                            @if ($r->grade)
                                <span class="ml-1 text-xs bg-gold-100 dark:bg-gold-900/40 text-gold-700 dark:text-gold-300 px-1.5 py-0.5 rounded font-medium">{{ $r->grade }}</span>
                            @endif
                        </td>
                        <td class="py-2 px-2 text-gray-600 dark:text-gray-400">
                            <span class="@if($r->track_type === '芝') text-turf-700 dark:text-turf-400 @elseif($r->track_type === 'ダート') text-sand-700 dark:text-sand-400 @endif">
                                {{ $r->track_type }}
                            </span>
                            {{ $r->distance }}m
                        </td>
                        <td class="py-2 px-2 text-xs text-gray-500 dark:text-gray-400">
                            {{ $r->course_condition ?? '-' }}
                            @if ($r->weather) <span class="ml-1">/ {{ $r->weather }}</span> @endif
                        </td>
                        <td class="py-2 px-2 text-right text-xs text-gray-500 dark:text-gray-400">{{ $r->results_count ?? 0 }}頭</td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
    @endif

    {{-- 最近登録したレース --}}
    <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm ring-1 ring-gray-100 dark:ring-gray-700 p-4">
        <div class="flex justify-between items-center mb-3">
            <div class="flex items-center space-x-2">
                <x-icon name="clock" class="w-5 h-5 text-turf-600 dark:text-turf-400" />
                <h2 class="font-semibold text-gray-700 dark:text-gray-200">最近登録したレース</h2>
            </div>
            <a href="{{ route('races.index') }}" class="text-sm text-turf-600 dark:text-turf-400 hover:underline flex items-center space-x-1">
                <span>すべて表示</span>
                <x-icon name="arrow-right" class="w-3 h-3" />
            </a>
        </div>
        @if ($stats['recent_races']->isEmpty())
            <x-empty-state
                icon="flag"
                title="まだレースが登録されていません"
                message="最初のレースを登録して分析を始めましょう"
                actionLabel="レースを登録"
                actionHref="{{ route('races.create') }}"
            />
        @else
            <div class="table-scroll">
                <table class="w-full text-sm min-w-[680px]">
                    <thead class="text-xs text-gray-500 dark:text-gray-400 uppercase border-b dark:border-gray-700">
                        <tr>
                            <th class="text-left py-2 px-2">日付</th>
                            <th class="text-left py-2 px-2">場</th>
                            <th class="text-left py-2 px-2">R</th>
                            <th class="text-left py-2 px-2">レース名</th>
                            <th class="text-left py-2 px-2">距離</th>
                            <th class="text-right py-2 px-2">頭数</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($stats['recent_races'] as $r)
                        <tr class="border-b dark:border-gray-700 hover:bg-turf-50/50 dark:hover:bg-gray-700/50 transition-colors">
                            <td class="py-2 px-2 text-gray-700 dark:text-gray-300">{{ $r->race_date?->format('Y/m/d') }}</td>
                            <td class="py-2 px-2 dark:text-gray-300">{{ $r->venue?->name }}</td>
                            <td class="py-2 px-2 text-gray-600 dark:text-gray-400">{{ $r->race_number }}R</td>
                            <td class="py-2 px-2">
                                <a href="{{ route('races.show', $r) }}" class="text-turf-700 dark:text-turf-400 hover:underline font-medium">{{ $r->name }}</a>
                                @if ($r->grade)
                                    <span class="ml-1 text-xs bg-gold-100 dark:bg-gold-900/40 text-gold-700 dark:text-gold-300 px-1.5 py-0.5 rounded font-medium">{{ $r->grade }}</span>
                                @endif
                            </td>
                            <td class="py-2 px-2 text-gray-600 dark:text-gray-400">
                                <span class="@if($r->track_type === '芝') text-turf-700 dark:text-turf-400 @elseif($r->track_type === 'ダート') text-sand-700 dark:text-sand-400 @endif">
                                    {{ $r->track_type }}
                                </span>
                                {{ $r->distance }}m
                            </td>
                            <td class="py-2 px-2 text-right text-xs text-gray-500 dark:text-gray-400">{{ $r->results_count ?? 0 }}頭</td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
</div>

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', () => {
    const isDark = () => document.documentElement.classList.contains('dark');
    const themeMode = () => isDark() ? 'dark' : 'light';
    const grid = () => ({ borderColor: isDark() ? '#374151' : '#e5e7eb' });

    // === 月別レース数 ===
    new ApexCharts(document.querySelector('#chart-monthly'), {
        chart: { type: 'area', height: 250, toolbar: { show: false }, foreColor: isDark() ? '#9ca3af' : '#6b7280' },
        series: [{ name: 'レース数', data: @json($byMonth->pluck('cnt')) }],
        xaxis: { categories: @json($byMonth->pluck('ym')) },
        colors: ['#16a34a'],
        stroke: { curve: 'smooth', width: 3 },
        fill: { type: 'gradient', gradient: { opacityFrom: 0.6, opacityTo: 0.1 } },
        dataLabels: { enabled: false },
        grid: grid(),
        tooltip: { theme: themeMode() },
    }).render();

    // === グレード別 ===
    new ApexCharts(document.querySelector('#chart-grade'), {
        chart: { type: 'bar', height: 250, toolbar: { show: false }, foreColor: isDark() ? '#9ca3af' : '#6b7280' },
        series: [{ name: 'レース数', data: @json($byGrade->pluck('cnt')) }],
        xaxis: { categories: @json($byGrade->pluck('grade')) },
        colors: ['#f59e0b'],
        plotOptions: { bar: { borderRadius: 4 } },
        grid: grid(),
        tooltip: { theme: themeMode() },
        dataLabels: { enabled: false },
    }).render();

    // === 競馬場別 ===
    new ApexCharts(document.querySelector('#chart-venue'), {
        chart: { type: 'bar', height: 320, toolbar: { show: false }, foreColor: isDark() ? '#9ca3af' : '#6b7280' },
        series: [{ name: 'レース数', data: @json($byVenue->pluck('cnt')) }],
        xaxis: { categories: @json($byVenue->pluck('name')) },
        colors: ['#dc8330'],
        plotOptions: { bar: { borderRadius: 4, horizontal: true } },
        grid: grid(),
        tooltip: { theme: themeMode() },
        dataLabels: { enabled: true, style: { fontSize: '11px' } },
    }).render();

    // === トラック別 (donut) ===
    @if ($byTrack->isNotEmpty())
    new ApexCharts(document.querySelector('#chart-track'), {
        chart: { type: 'donut', height: 320, foreColor: isDark() ? '#9ca3af' : '#6b7280' },
        series: @json($byTrack->pluck('cnt')->map(fn($v) => (int)$v)),
        labels: @json($byTrack->pluck('track_type')),
        colors: ['#16a34a', '#dc8330', '#9ca3af'],
        legend: { position: 'bottom' },
        dataLabels: { enabled: true, formatter: (val) => val.toFixed(1) + '%' },
        tooltip: { theme: themeMode() },
    }).render();
    @endif

    // === 距離別 (bar) ===
    @if ($byDistanceCat->isNotEmpty())
    new ApexCharts(document.querySelector('#chart-distance'), {
        chart: { type: 'bar', height: 220, toolbar: { show: false }, foreColor: isDark() ? '#9ca3af' : '#6b7280' },
        series: [{ name: 'レース数', data: @json($byDistanceCat->pluck('cnt')->map(fn($v) => (int)$v)) }],
        xaxis: { categories: @json($byDistanceCat->pluck('cat')), labels: { style: { fontSize: '10px' } } },
        colors: ['#6366f1'],
        plotOptions: { bar: { borderRadius: 4, distributed: true } },
        legend: { show: false },
        grid: grid(),
        tooltip: { theme: themeMode() },
        dataLabels: { enabled: false },
    }).render();
    @endif

    // === 馬場状態別 (donut) ===
    @if ($byCondition->isNotEmpty())
    new ApexCharts(document.querySelector('#chart-condition'), {
        chart: { type: 'donut', height: 220, foreColor: isDark() ? '#9ca3af' : '#6b7280' },
        series: @json($byCondition->pluck('cnt')->map(fn($v) => (int)$v)),
        labels: @json($byCondition->pluck('course_condition')),
        colors: ['#16a34a', '#84cc16', '#f59e0b', '#dc2626'],
        legend: { position: 'bottom', fontSize: '11px' },
        dataLabels: { enabled: true, formatter: (val) => val.toFixed(0) + '%' },
        tooltip: { theme: themeMode() },
    }).render();
    @endif

    // === 天候別 (donut) ===
    @if ($byWeather->isNotEmpty())
    new ApexCharts(document.querySelector('#chart-weather'), {
        chart: { type: 'donut', height: 220, foreColor: isDark() ? '#9ca3af' : '#6b7280' },
        series: @json($byWeather->pluck('cnt')->map(fn($v) => (int)$v)),
        labels: @json($byWeather->pluck('weather')),
        colors: ['#fbbf24', '#9ca3af', '#3b82f6', '#1e40af', '#e5e7eb'],
        legend: { position: 'bottom', fontSize: '11px' },
        dataLabels: { enabled: true, formatter: (val) => val.toFixed(0) + '%' },
        tooltip: { theme: themeMode() },
    }).render();
    @endif

    // === 曜日別 (bar) ===
    @if ($byWeekday->isNotEmpty())
    new ApexCharts(document.querySelector('#chart-weekday'), {
        chart: { type: 'bar', height: 220, toolbar: { show: false }, foreColor: isDark() ? '#9ca3af' : '#6b7280' },
        series: [{ name: 'レース数', data: @json($byWeekday->pluck('cnt')->map(fn($v) => (int)$v)) }],
        xaxis: { categories: @json($byWeekday->pluck('label')) },
        colors: ['#f43f5e'],
        plotOptions: { bar: { borderRadius: 4, distributed: true } },
        legend: { show: false },
        grid: grid(),
        tooltip: { theme: themeMode() },
        dataLabels: { enabled: false },
    }).render();
    @endif

    // === 競馬場 × トラック種別 勝率ヒートマップ ===
    @if (($venueTrackWinRate ?? collect())->isNotEmpty())
    @php
        $vtVenues = $venueTrackWinRate->pluck('venue')->unique()->values();
        $vtMap = [];
        foreach ($venueTrackWinRate as $row) {
            $vtMap[$row->track_type][$row->venue] = [
                'rate' => ($row->runs > 0) ? round(($row->wins / $row->runs) * 100, 2) : 0,
                'runs' => (int)$row->runs,
                'wins' => (int)$row->wins,
            ];
        }
        $vtSeries = [];
        foreach (['芝', 'ダート'] as $tt) {
            if (!isset($vtMap[$tt])) continue;
            $data = [];
            foreach ($vtVenues as $v) {
                $cell = $vtMap[$tt][$v] ?? null;
                $data[] = [
                    'x' => $v,
                    'y' => $cell ? $cell['rate'] : 0,
                ];
            }
            $vtSeries[] = ['name' => $tt, 'data' => $data];
        }
    @endphp
    new ApexCharts(document.querySelector('#chart-venue-track-winrate'), {
        chart: { type: 'heatmap', height: 220, toolbar: { show: false }, foreColor: isDark() ? '#9ca3af' : '#6b7280' },
        series: @json($vtSeries),
        colors: ['#dc2626'],
        plotOptions: {
            heatmap: {
                shadeIntensity: 0.6,
                radius: 4,
                useFillColorAsStroke: false,
                colorScale: {
                    ranges: [
                        { from: 0,  to: 5,   color: '#fee2e2', name: '〜5%' },
                        { from: 5,  to: 8,   color: '#fca5a5', name: '5-8%' },
                        { from: 8,  to: 11,  color: '#f87171', name: '8-11%' },
                        { from: 11, to: 14,  color: '#ef4444', name: '11-14%' },
                        { from: 14, to: 100, color: '#b91c1c', name: '14%〜' },
                    ]
                }
            }
        },
        dataLabels: { enabled: true, style: { fontSize: '11px', colors: ['#111'] }, formatter: (v) => v.toFixed(1) + '%' },
        xaxis: { labels: { style: { fontSize: '11px' } } },
        grid: grid(),
        tooltip: { theme: themeMode() },
    }).render();
    @endif

    // === 枠番別 勝率/複勝率（芝・ダート）===
    @if (($frameWinRates ?? collect())->isNotEmpty())
    @php
        $frameCats = [1,2,3,4,5,6,7,8];
        $byTT = [];
        foreach ($frameWinRates as $row) {
            $byTT[$row->track_type][(int)$row->frame_number] = [
                'runs'  => (int)$row->runs,
                'wins'  => (int)$row->wins,
                'shows' => (int)$row->shows,
            ];
        }
        $frameWinSeries  = [];
        $frameShowSeries = [];
        foreach (['芝', 'ダート'] as $tt) {
            if (!isset($byTT[$tt])) continue;
            $win = []; $show = [];
            foreach ($frameCats as $f) {
                $c = $byTT[$tt][$f] ?? null;
                $win[]  = $c && $c['runs'] > 0 ? round($c['wins']  / $c['runs'] * 100, 2) : 0;
                $show[] = $c && $c['runs'] > 0 ? round($c['shows'] / $c['runs'] * 100, 2) : 0;
            }
            $frameWinSeries[]  = ['name' => $tt, 'data' => $win];
            $frameShowSeries[] = ['name' => $tt, 'data' => $show];
        }
    @endphp
    new ApexCharts(document.querySelector('#chart-frame-winrate'), {
        chart: { type: 'bar', height: 280, toolbar: { show: false }, foreColor: isDark() ? '#9ca3af' : '#6b7280' },
        series: @json($frameWinSeries),
        xaxis: { categories: @json($frameCats), title: { text: '枠番' } },
        yaxis: { labels: { formatter: (v) => v.toFixed(1) + '%' } },
        colors: ['#16a34a', '#dc8330'],
        plotOptions: { bar: { borderRadius: 4, columnWidth: '70%' } },
        dataLabels: { enabled: false },
        legend: { position: 'top' },
        grid: grid(),
        tooltip: { theme: themeMode(), y: { formatter: (v) => v.toFixed(2) + '%' } },
    }).render();

    new ApexCharts(document.querySelector('#chart-frame-showrate'), {
        chart: { type: 'bar', height: 280, toolbar: { show: false }, foreColor: isDark() ? '#9ca3af' : '#6b7280' },
        series: @json($frameShowSeries),
        xaxis: { categories: @json($frameCats), title: { text: '枠番' } },
        yaxis: { labels: { formatter: (v) => v.toFixed(1) + '%' } },
        colors: ['#0ea5e9', '#a855f7'],
        plotOptions: { bar: { borderRadius: 4, columnWidth: '70%' } },
        dataLabels: { enabled: false },
        legend: { position: 'top' },
        grid: grid(),
        tooltip: { theme: themeMode(), y: { formatter: (v) => v.toFixed(2) + '%' } },
    }).render();
    @endif

    // === 競馬場別 脚質勝率 ===
    @if (($venueStyleStats ?? collect())->isNotEmpty())
    @php
        $vsVenues = $venueStyleStats->pluck('venue')->unique()->values();
        $expectedStyles = ['逃げ', '先行', '差し', '追込'];
        $foundStyles = $venueStyleStats->pluck('style')->unique()->values()->all();
        $vsStyles = array_values(array_unique(array_merge(
            array_values(array_filter($expectedStyles, fn($s) => in_array($s, $foundStyles, true))),
            array_values(array_diff($foundStyles, $expectedStyles))
        )));
        $vsLookup = [];
        foreach ($venueStyleStats as $r) {
            $vsLookup[$r->style][$r->venue] = [
                'runs' => (int)$r->runs,
                'wins' => (int)$r->wins,
            ];
        }
        $vsSeries = [];
        foreach ($vsStyles as $style) {
            $data = [];
            foreach ($vsVenues as $v) {
                $c = $vsLookup[$style][$v] ?? null;
                $data[] = $c && $c['runs'] > 0 ? round($c['wins'] / $c['runs'] * 100, 2) : 0;
            }
            $vsSeries[] = ['name' => $style, 'data' => $data];
        }
    @endphp
    new ApexCharts(document.querySelector('#chart-venue-style'), {
        chart: { type: 'bar', height: 360, toolbar: { show: false }, foreColor: isDark() ? '#9ca3af' : '#6b7280' },
        series: @json($vsSeries),
        xaxis: { categories: @json($vsVenues), labels: { style: { fontSize: '11px' } } },
        yaxis: { labels: { formatter: (v) => v.toFixed(1) + '%' }, title: { text: '勝率（%）' } },
        colors: ['#ef4444', '#f59e0b', '#0ea5e9', '#8b5cf6', '#10b981'],
        plotOptions: { bar: { borderRadius: 4, columnWidth: '75%' } },
        dataLabels: { enabled: false },
        legend: { position: 'top' },
        grid: grid(),
        tooltip: { theme: themeMode(), y: { formatter: (v) => v.toFixed(2) + '%' } },
    }).render();
    @endif

    // === 平均出走頭数の月推移 ===
    @if ($avgFieldByMonth->isNotEmpty())
    new ApexCharts(document.querySelector('#chart-avg-field'), {
        chart: { type: 'line', height: 250, toolbar: { show: false }, foreColor: isDark() ? '#9ca3af' : '#6b7280' },
        series: [{
            name: '平均頭数',
            data: @json($avgFieldByMonth->pluck('avg_field')->map(fn($v) => round((float)$v, 2)))
        }],
        xaxis: { categories: @json($avgFieldByMonth->pluck('ym')) },
        colors: ['#a855f7'],
        stroke: { curve: 'smooth', width: 3 },
        markers: { size: 4 },
        dataLabels: { enabled: true, formatter: (val) => val.toFixed(1) + '頭' },
        yaxis: { min: 0, max: 18, labels: { formatter: (v) => v.toFixed(0) } },
        grid: grid(),
        tooltip: { theme: themeMode() },
    }).render();
    @endif
});
</script>
@endpush

@endsection
