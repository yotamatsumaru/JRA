@extends('layouts.app')
@section('title', $jockey->name)

@section('content')
<div class="space-y-6">

    <div class="bg-white rounded-lg shadow p-4 sm:p-6 flex justify-between items-start gap-3">
        <div class="min-w-0 flex-1">
            <h1 class="text-xl sm:text-2xl font-bold text-gray-800 break-words">
                {{ $jockey->name }}
                @if ($jockey->belonging) <span class="ml-2 text-sm bg-gray-200 text-gray-700 px-2 py-0.5 rounded">{{ $jockey->belonging }}</span> @endif
            </h1>
            @if ($jockey->name_kana) <div class="text-sm text-gray-500 mt-1">{{ $jockey->name_kana }}</div> @endif
        </div>
        <div class="shrink-0">
            <x-watchlist-button type="jockey" :targetId="$jockey->id" :label="$jockey->name" />
        </div>
    </div>

    {{-- サマリー --}}
    <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-5 gap-3">
        <div class="bg-white rounded-lg shadow p-4">
            <div class="text-xs text-gray-500">騎乗数</div>
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

    {{-- 競馬場別 --}}
    <div class="bg-white rounded-lg shadow p-4">
        <h2 class="font-semibold text-gray-700 mb-3">競馬場別成績</h2>
        <div class="table-scroll">
        <table class="w-full text-sm min-w-[520px]">
            <thead class="text-xs text-gray-500 border-b">
                <tr>
                    <th class="text-left py-1">競馬場</th>
                    <th class="py-1">騎乗</th>
                    <th class="py-1">勝利</th>
                    <th class="py-1">複勝</th>
                    <th class="py-1">勝率</th>
                    <th class="py-1">複勝率</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($byVenue as $v)
                <tr class="border-b">
                    <td class="py-1">{{ $v->name }}</td>
                    <td class="text-center">{{ $v->cnt }}</td>
                    <td class="text-center font-bold text-yellow-600">{{ $v->wins }}</td>
                    <td class="text-center text-emerald-600">{{ $v->shows }}</td>
                    <td class="text-center">{{ $v->cnt > 0 ? round($v->wins / $v->cnt * 100, 1) : 0 }}%</td>
                    <td class="text-center">{{ $v->cnt > 0 ? round($v->shows / $v->cnt * 100, 1) : 0 }}%</td>
                </tr>
                @empty
                <tr><td colspan="6" class="text-center text-gray-400 py-4">データなし</td></tr>
                @endforelse
            </tbody>
        </table>
        </div>
    </div>

    {{-- 直近 --}}
    <div class="bg-white rounded-lg shadow p-4">
        <h2 class="font-semibold text-gray-700 mb-3">直近の騎乗（最新20件）</h2>
        @if ($recentResults->isEmpty())
            <p class="text-sm text-gray-500">データなし</p>
        @else
        <div class="table-scroll">
            <table class="w-full text-sm min-w-[680px]">
                <thead class="bg-gray-100 text-xs text-gray-600 uppercase">
                    <tr>
                        <th class="text-left px-2 py-2">日付</th>
                        <th class="text-left px-2 py-2">場</th>
                        <th class="text-left px-2 py-2">レース</th>
                        <th class="text-left px-2 py-2">馬</th>
                        <th class="px-2 py-2">着順</th>
                        <th class="px-2 py-2">人気</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($recentResults as $r)
                    <tr class="border-b hover:bg-gray-50">
                        <td class="px-2 py-2">{{ $r->race?->race_date?->format('Y/m/d') }}</td>
                        <td class="px-2 py-2">{{ $r->race?->venue?->name }}</td>
                        <td class="px-2 py-2"><a href="{{ route('races.show', $r->race) }}" class="text-primary-600 hover:underline">{{ $r->race?->name }}</a></td>
                        <td class="px-2 py-2"><a href="{{ route('horses.show', $r->horse) }}" class="text-primary-600 hover:underline">{{ $r->horse?->name }}</a></td>
                        <td class="px-2 py-2 text-center font-bold {{ $r->finish_position_int == 1 ? 'text-yellow-600' : '' }}">{{ $r->finish_position }}</td>
                        <td class="px-2 py-2 text-center">{{ $r->popularity }}</td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @endif
    </div>

</div>
@endsection
