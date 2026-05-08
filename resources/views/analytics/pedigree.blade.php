@extends('layouts.app')
@section('title', '血統傾向分析')

@section('content')
<div class="space-y-6">
    <h1 class="text-2xl font-bold text-gray-800">血統傾向分析（父系）</h1>
    <p class="text-sm text-gray-600">父馬を選択すると、その産駒が好走している競馬場・距離が分析されます。</p>

    {{-- 父馬選択 --}}
    <div class="bg-white rounded-lg shadow p-4">
        <h2 class="font-semibold text-gray-700 mb-3">父馬を選択（出走数上位50）</h2>
        <div class="flex flex-wrap gap-2">
            @forelse ($fatherList as $f)
                <a href="{{ route('analytics.pedigree', ['father' => $f->father]) }}"
                   class="px-3 py-1 rounded-full text-xs border transition {{ $father == $f->father ? 'bg-primary-600 text-white border-primary-600' : 'bg-gray-50 text-gray-700 border-gray-300 hover:bg-primary-50' }}">
                    {{ $f->father }} <span class="opacity-70">({{ $f->cnt }})</span>
                </a>
            @empty
                <p class="text-sm text-gray-500">血統データがまだありません</p>
            @endforelse
        </div>
    </div>

    @if ($father)
    <div class="bg-white rounded-lg shadow p-4">
        <h2 class="font-semibold text-gray-700 mb-3">{{ $father }} の産駒成績（場×トラック×距離）</h2>
        @if ($stats->isEmpty())
            <p class="text-sm text-gray-500">データがありません</p>
        @else
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-gray-100 text-xs text-gray-600">
                    <tr>
                        <th class="text-left px-3 py-2">競馬場</th>
                        <th class="px-3 py-2">トラック</th>
                        <th class="px-3 py-2">距離</th>
                        <th class="px-3 py-2">出走</th>
                        <th class="px-3 py-2">勝</th>
                        <th class="px-3 py-2">複勝</th>
                        <th class="px-3 py-2">勝率</th>
                        <th class="px-3 py-2">複勝率</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($stats as $s)
                        @php
                            $winRate = $s->runs > 0 ? round($s->wins / $s->runs * 100, 1) : 0;
                            $showRate = $s->runs > 0 ? round($s->shows / $s->runs * 100, 1) : 0;
                            $highlight = $showRate >= 35;
                        @endphp
                        <tr class="border-b {{ $highlight ? 'bg-emerald-50' : '' }}">
                            <td class="px-3 py-2">{{ $s->venue }}</td>
                            <td class="px-3 py-2 text-center">{{ $s->track_type }}</td>
                            <td class="px-3 py-2 text-center">{{ $s->distance }}m</td>
                            <td class="px-3 py-2 text-center">{{ $s->runs }}</td>
                            <td class="px-3 py-2 text-center text-yellow-600 font-bold">{{ $s->wins }}</td>
                            <td class="px-3 py-2 text-center text-emerald-600">{{ $s->shows }}</td>
                            <td class="px-3 py-2 text-center">{{ $winRate }}%</td>
                            <td class="px-3 py-2 text-center font-bold {{ $highlight ? 'text-emerald-700' : '' }}">{{ $showRate }}%</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div class="mt-3 text-xs text-gray-500">※ 複勝率35%以上は緑色でハイライト</div>
        @endif
    </div>
    @endif
</div>
@endsection
