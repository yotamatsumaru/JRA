@extends('layouts.app')
@section('title', $horse->name)

@section('content')
<div class="space-y-6">

    {{-- ヘッダー --}}
    <div class="bg-white rounded-lg shadow p-4 sm:p-6 flex justify-between items-start gap-3">
        <div class="min-w-0 flex-1">
            <h1 class="text-xl sm:text-2xl font-bold text-gray-800 break-words">
                {{ $horse->name }}
                @if ($horse->sex) <span class="text-sm bg-gray-200 text-gray-700 px-2 py-0.5 rounded">{{ $horse->sex }}</span> @endif
            </h1>
            <div class="mt-2 text-xs sm:text-sm text-gray-600 space-y-1">
                @if ($horse->name_kana)<div>{{ $horse->name_kana }}</div>@endif
                @if ($horse->birthday)<div>生年月日: {{ $horse->birthday->format('Y年m月d日') }}</div>@endif
                @if ($horse->color)<div>毛色: {{ $horse->color }}</div>@endif
                @if ($horse->father)<div>父: {{ $horse->father }}</div>@endif
                @if ($horse->mother)<div>母: {{ $horse->mother }}（母父: {{ $horse->mother_father }}）</div>@endif
                @if ($horse->owner)<div>馬主: {{ $horse->owner }}</div>@endif
                @if ($horse->breeder)<div>生産者: {{ $horse->breeder }}</div>@endif
            </div>
        </div>
        <div class="flex items-start gap-2 shrink-0">
            <x-watchlist-button type="horse" :targetId="$horse->id" :label="$horse->name" />
            <a href="{{ route('horses.edit', $horse) }}" class="text-sm text-gray-500 hover:text-primary-600 px-3 py-1 border rounded">編集</a>
        </div>
    </div>

    {{-- 成績サマリー --}}
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
            <div class="text-sm font-bold">
                {{ $summary['win_rate'] ?? 0 }}% / {{ $summary['place_rate'] ?? 0 }}% / {{ $summary['show_rate'] ?? 0 }}%
            </div>
        </div>
    </div>

    {{-- 距離別 / 競馬場別 --}}
    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
        <div class="bg-white rounded-lg shadow p-4 table-scroll">
            <h2 class="font-semibold text-gray-700 mb-3">距離別成績</h2>
            <table class="w-full text-sm min-w-[360px]">
                <thead class="text-xs text-gray-500 border-b">
                    <tr><th class="text-left py-1">距離</th><th class="py-1">出走</th><th class="py-1">勝利</th><th class="py-1">勝率</th></tr>
                </thead>
                <tbody>
                    @forelse ($byDistance as $d)
                    <tr class="border-b">
                        <td class="py-1">{{ $d->distance }}m</td>
                        <td class="text-center">{{ $d->cnt }}</td>
                        <td class="text-center">{{ $d->wins }}</td>
                        <td class="text-center">{{ $d->cnt > 0 ? round($d->wins / $d->cnt * 100, 1) : 0 }}%</td>
                    </tr>
                    @empty
                    <tr><td colspan="4" class="text-center text-gray-400 py-4">データなし</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="bg-white rounded-lg shadow p-4 table-scroll">
            <h2 class="font-semibold text-gray-700 mb-3">競馬場別成績</h2>
            <table class="w-full text-sm min-w-[360px]">
                <thead class="text-xs text-gray-500 border-b">
                    <tr><th class="text-left py-1">場</th><th class="py-1">出走</th><th class="py-1">勝利</th><th class="py-1">勝率</th></tr>
                </thead>
                <tbody>
                    @forelse ($byVenue as $v)
                    <tr class="border-b">
                        <td class="py-1">{{ $v->name }}</td>
                        <td class="text-center">{{ $v->cnt }}</td>
                        <td class="text-center">{{ $v->wins }}</td>
                        <td class="text-center">{{ $v->cnt > 0 ? round($v->wins / $v->cnt * 100, 1) : 0 }}%</td>
                    </tr>
                    @empty
                    <tr><td colspan="4" class="text-center text-gray-400 py-4">データなし</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    {{-- 出走履歴 --}}
    <div class="bg-white rounded-lg shadow p-4">
        <h2 class="font-semibold text-gray-700 mb-3">出走履歴</h2>
        @if ($horse->results->isEmpty())
            <p class="text-sm text-gray-500">まだ出走記録がありません</p>
        @else
        <div class="table-scroll">
            <table class="w-full text-sm min-w-[820px]">
                <thead class="bg-gray-100 text-xs text-gray-600 uppercase">
                    <tr>
                        <th class="text-left px-2 py-2">日付</th>
                        <th class="text-left px-2 py-2">場</th>
                        <th class="text-left px-2 py-2">レース</th>
                        <th class="px-2 py-2">距離</th>
                        <th class="px-2 py-2">着順</th>
                        <th class="text-left px-2 py-2">騎手</th>
                        <th class="px-2 py-2">タイム</th>
                        <th class="px-2 py-2">脚質</th>
                        <th class="px-2 py-2">人気</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($horse->results->sortByDesc(fn($r) => $r->race->race_date) as $r)
                    <tr class="border-b hover:bg-gray-50">
                        <td class="px-2 py-2">{{ $r->race?->race_date?->format('Y/m/d') }}</td>
                        <td class="px-2 py-2">{{ $r->race?->venue?->name }}</td>
                        <td class="px-2 py-2"><a href="{{ route('races.show', $r->race) }}" class="text-primary-600 hover:underline">{{ $r->race?->name }}</a></td>
                        <td class="px-2 py-2 text-center">{{ $r->race?->track_type }}{{ $r->race?->distance }}m</td>
                        <td class="px-2 py-2 text-center font-bold {{ $r->finish_position_int == 1 ? 'text-yellow-600' : '' }}">{{ $r->finish_position }}</td>
                        <td class="px-2 py-2">{{ $r->jockey?->name }}</td>
                        <td class="px-2 py-2 text-center font-mono">{{ $r->time }}</td>
                        <td class="px-2 py-2 text-center">{{ $r->running_style }}</td>
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
