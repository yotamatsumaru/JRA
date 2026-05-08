@extends('layouts.app')
@section('title', '騎手×コース相性分析')

@section('content')
<div class="space-y-6">
    <h1 class="text-2xl font-bold text-gray-800">騎手 × コース相性分析</h1>

    <div class="bg-white rounded-lg shadow p-4">
        <h2 class="font-semibold text-gray-700 mb-3">騎手を選択（騎乗数上位50）</h2>
        <div class="flex flex-wrap gap-2">
            @forelse ($jockeyList as $j)
                <a href="{{ route('analytics.jockey', ['jockey' => $j->name]) }}"
                   class="px-3 py-1 rounded-full text-xs border transition {{ $jockeyName == $j->name ? 'bg-primary-600 text-white border-primary-600' : 'bg-gray-50 text-gray-700 border-gray-300 hover:bg-primary-50' }}">
                    {{ $j->name }} <span class="opacity-70">({{ $j->cnt }})</span>
                </a>
            @empty
                <p class="text-sm text-gray-500">騎乗データがまだありません</p>
            @endforelse
        </div>
    </div>

    @if ($jockeyName)
    <div class="bg-white rounded-lg shadow p-4">
        <h2 class="font-semibold text-gray-700 mb-3">{{ $jockeyName }} の競馬場×トラック相性</h2>
        @if ($stats->isEmpty())
            <p class="text-sm text-gray-500">データがありません</p>
        @else
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-gray-100 text-xs text-gray-600">
                    <tr>
                        <th class="text-left px-3 py-2">競馬場</th>
                        <th class="px-3 py-2">トラック</th>
                        <th class="px-3 py-2">騎乗</th>
                        <th class="px-3 py-2">勝</th>
                        <th class="px-3 py-2">複勝</th>
                        <th class="px-3 py-2">勝率</th>
                        <th class="px-3 py-2">複勝率</th>
                        <th class="px-3 py-2 w-1/4">複勝率ヒートマップ</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($stats as $s)
                        @php
                            $winRate = $s->runs > 0 ? round($s->wins / $s->runs * 100, 1) : 0;
                            $showRate = $s->runs > 0 ? round($s->shows / $s->runs * 100, 1) : 0;
                            $intensity = min(100, $showRate * 1.5);
                        @endphp
                        <tr class="border-b">
                            <td class="px-3 py-2">{{ $s->venue }}</td>
                            <td class="px-3 py-2 text-center">{{ $s->track_type }}</td>
                            <td class="px-3 py-2 text-center">{{ $s->runs }}</td>
                            <td class="px-3 py-2 text-center text-yellow-600 font-bold">{{ $s->wins }}</td>
                            <td class="px-3 py-2 text-center text-emerald-600">{{ $s->shows }}</td>
                            <td class="px-3 py-2 text-center">{{ $winRate }}%</td>
                            <td class="px-3 py-2 text-center font-bold">{{ $showRate }}%</td>
                            <td class="px-3 py-2">
                                <div class="h-5 rounded relative overflow-hidden bg-gray-100">
                                    <div class="absolute inset-y-0 left-0 bg-gradient-to-r from-blue-300 via-blue-600 to-red-500" style="width: {{ $intensity }}%;"></div>
                                    <div class="relative z-10 flex items-center justify-center h-full text-xs font-bold text-gray-800">{{ $showRate }}%</div>
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @endif
    </div>
    @endif
</div>
@endsection
