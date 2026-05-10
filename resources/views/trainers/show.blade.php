@extends('layouts.app')
@section('title', $trainer->name)

@section('content')
<div class="space-y-6">

    {{-- ヘッダー --}}
    <div class="bg-white rounded-lg shadow p-4 sm:p-6 flex justify-between items-start gap-3">
        <div class="min-w-0 flex-1">
            <h1 class="text-xl sm:text-2xl font-bold text-gray-800 dark:text-gray-100 break-words">
                {{ $trainer->name }}
                @if ($trainer->belonging) <span class="ml-2 text-sm bg-gray-200 dark:bg-gray-700 text-gray-700 dark:text-gray-200 px-2 py-0.5 rounded">{{ $trainer->belonging }}</span> @endif
                @if (!$trainer->is_active) <span class="ml-2 text-xs bg-gray-300 dark:bg-gray-600 text-gray-700 dark:text-gray-200 px-2 py-0.5 rounded">引退</span> @endif
            </h1>
            @if ($trainer->name_kana) <div class="text-sm text-gray-500 mt-1">{{ $trainer->name_kana }}</div> @endif
        </div>
        <div class="shrink-0">
            <x-watchlist-button type="trainer" :targetId="$trainer->id" :label="$trainer->name" />
        </div>
    </div>

    {{-- サマリー --}}
    <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-5 gap-3">
        <div class="bg-white rounded-lg shadow p-4">
            <div class="text-xs text-gray-500">出走</div>
            <div class="text-2xl font-bold text-primary-700">{{ $summary['runs'] ?? 0 }}</div>
        </div>
        <div class="bg-white rounded-lg shadow p-4">
            <div class="text-xs text-gray-500">勝利</div>
            <div class="text-2xl font-bold text-yellow-600">{{ $summary['wins'] ?? 0 }}</div>
        </div>
        <div class="bg-white rounded-lg shadow p-4">
            <div class="text-xs text-gray-500">連対</div>
            <div class="text-2xl font-bold text-blue-600">{{ $summary['places'] ?? 0 }}</div>
        </div>
        <div class="bg-white rounded-lg shadow p-4">
            <div class="text-xs text-gray-500">複勝</div>
            <div class="text-2xl font-bold text-emerald-600">{{ $summary['shows'] ?? 0 }}</div>
        </div>
        <div class="bg-white rounded-lg shadow p-4">
            <div class="text-xs text-gray-500">勝率/連対率/複勝率</div>
            <div class="text-sm font-bold">{{ $summary['win_rate'] ?? 0 }}% / {{ $summary['place_rate'] ?? 0 }}% / {{ $summary['show_rate'] ?? 0 }}%</div>
        </div>
    </div>

    {{-- 競馬場別 / トラック別 --}}
    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
        <div class="bg-white rounded-lg shadow p-4">
            <h2 class="font-semibold text-gray-700 mb-3">競馬場別成績</h2>
            <div class="table-scroll">
                <table class="w-full text-sm min-w-[440px]">
                    <thead class="text-xs text-gray-500 border-b">
                        <tr>
                            <th class="text-left py-1">競馬場</th>
                            <th class="py-1">出走</th>
                            <th class="py-1">勝利</th>
                            <th class="py-1">3着内</th>
                            <th class="py-1">勝率</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($byVenue as $row)
                            <tr class="border-b last:border-0">
                                <td class="py-1.5 font-medium">{{ $row->name }}</td>
                                <td class="text-center">{{ $row->cnt }}</td>
                                <td class="text-center text-yellow-600 font-semibold">{{ $row->wins }}</td>
                                <td class="text-center text-emerald-600">{{ $row->shows }}</td>
                                <td class="text-center">{{ $row->cnt > 0 ? round($row->wins / $row->cnt * 100, 1) : 0 }}%</td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="py-4 text-center text-gray-400">データがありません</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <div class="bg-white rounded-lg shadow p-4">
            <h2 class="font-semibold text-gray-700 mb-3">トラック別成績</h2>
            <div class="table-scroll">
                <table class="w-full text-sm min-w-[360px]">
                    <thead class="text-xs text-gray-500 border-b">
                        <tr>
                            <th class="text-left py-1">トラック</th>
                            <th class="py-1">出走</th>
                            <th class="py-1">勝利</th>
                            <th class="py-1">3着内</th>
                            <th class="py-1">勝率</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($byTrack as $row)
                            <tr class="border-b last:border-0">
                                <td class="py-1.5 font-medium">{{ $row->track_type }}</td>
                                <td class="text-center">{{ $row->cnt }}</td>
                                <td class="text-center text-yellow-600 font-semibold">{{ $row->wins }}</td>
                                <td class="text-center text-emerald-600">{{ $row->shows }}</td>
                                <td class="text-center">{{ $row->cnt > 0 ? round($row->wins / $row->cnt * 100, 1) : 0 }}%</td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="py-4 text-center text-gray-400">データがありません</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    {{-- よく組む騎手 --}}
    <div class="bg-white rounded-lg shadow p-4">
        <h2 class="font-semibold text-gray-700 mb-3">よく組む騎手 (TOP10)</h2>
        <div class="table-scroll">
            <table class="w-full text-sm min-w-[420px]">
                <thead class="text-xs text-gray-500 border-b">
                    <tr>
                        <th class="text-left py-1">騎手</th>
                        <th class="py-1">騎乗数</th>
                        <th class="py-1">勝利</th>
                        <th class="py-1">勝率</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($topJockeys as $j)
                        <tr class="border-b last:border-0">
                            <td class="py-1.5">
                                <a href="{{ route('jockeys.show', $j->id) }}" class="text-turf-700 hover:underline font-medium">{{ $j->name }}</a>
                            </td>
                            <td class="text-center">{{ $j->cnt }}</td>
                            <td class="text-center text-yellow-600 font-semibold">{{ $j->wins }}</td>
                            <td class="text-center">{{ $j->cnt > 0 ? round($j->wins / $j->cnt * 100, 1) : 0 }}%</td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="py-4 text-center text-gray-400">データがありません</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    {{-- 直近のレース --}}
    <div class="bg-white rounded-lg shadow p-4">
        <h2 class="font-semibold text-gray-700 mb-3">直近のレース (20件)</h2>
        <div class="table-scroll">
            <table class="w-full text-sm min-w-[760px]">
                <thead class="text-xs text-gray-500 border-b">
                    <tr>
                        <th class="text-left py-1">日付</th>
                        <th class="text-left py-1">競馬場</th>
                        <th class="text-left py-1">レース</th>
                        <th class="text-left py-1">馬</th>
                        <th class="text-left py-1">騎手</th>
                        <th class="py-1">着順</th>
                        <th class="py-1">単勝</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($recentResults as $r)
                        <tr class="border-b dark:border-gray-700 last:border-0 hover:bg-turf-50/40 dark:hover:bg-gray-800/60">
                            <td class="py-1.5 text-xs text-gray-500">{{ $r->race?->race_date?->format('Y/m/d') }}</td>
                            <td class="py-1.5 text-xs">{{ $r->race?->venue?->name }}</td>
                            <td class="py-1.5">
                                @if ($r->race)
                                    <a href="{{ route('races.show', $r->race) }}" class="text-turf-700 hover:underline">{{ $r->race->name }}</a>
                                @endif
                            </td>
                            <td class="py-1.5">
                                @if ($r->horse)
                                    <a href="{{ route('horses.show', $r->horse) }}" class="hover:underline">{{ $r->horse->name }}</a>
                                @endif
                            </td>
                            <td class="py-1.5 text-xs">{{ $r->jockey?->name }}</td>
                            <td class="py-1.5 text-center font-semibold {{ $r->finish_position_int === 1 ? 'text-rose-600' : ($r->finish_position_int <= 3 ? 'text-emerald-600' : 'text-gray-500') }}">
                                {{ $r->finish_position_int ?? '-' }}
                            </td>
                            <td class="py-1.5 text-center text-xs font-mono">{{ $r->win_odds ? number_format($r->win_odds, 1) : '-' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="py-4 text-center text-gray-400">データがありません</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

</div>
@endsection
