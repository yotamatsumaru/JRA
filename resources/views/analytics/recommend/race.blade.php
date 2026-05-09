@extends('layouts.app')
@section('title', '出馬表ベース推奨 (A) - レース選択')

@section('content')
<div class="space-y-4">
    <h1 class="text-xl sm:text-2xl font-bold text-gray-800">🐎 出馬表ベース推奨 - レース選択</h1>
    <p class="text-xs sm:text-sm text-gray-600">
        登録済みレースから1つ選んでください。出走馬を血統・騎手・過去走でスコアリングし、
        <strong>◎○▲△☆</strong> の印を自動で付与します。
    </p>

    @include('analytics.recommend._nav', ['active' => 'race'])

    {{-- 現在の重み設定の小バナー --}}
    @php $w = $settings['weights']; $sumW = array_sum($w); @endphp
    <div class="bg-amber-50 border border-amber-200 rounded p-3 text-xs text-amber-900 flex flex-wrap items-center gap-x-4 gap-y-1">
        <span>現在の重み:</span>
        <span class="px-2 py-0.5 bg-purple-100 text-purple-800 rounded">🧬 血統 {{ $w['pedigree'] }}</span>
        <span class="px-2 py-0.5 bg-sky-100 text-sky-800 rounded">👤 騎手 {{ $w['jockey'] }}</span>
        <span class="px-2 py-0.5 bg-rose-100 text-rose-800 rounded">🐎 馬 {{ $w['horse'] }}</span>
        <span class="px-2 py-0.5 bg-amber-100 text-amber-800 rounded">💰 ROI {{ $w['roi'] }}</span>
        <span>最低出走数 ≥ {{ $settings['min_runs'] }}</span>
        <a href="{{ route('analytics.recommend.settings') }}" class="ml-auto text-amber-700 hover:underline font-bold">⚙️ 設定変更 →</a>
    </div>

    {{-- フィルタ --}}
    <form method="GET" action="{{ route('analytics.recommend.race') }}"
          class="bg-white rounded-lg shadow p-4 grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3 text-xs">
        <div>
            <label class="block text-gray-600 mb-1">競馬場</label>
            <select name="venue_id" class="w-full border rounded px-2 py-1.5">
                <option value="">すべて</option>
                @foreach ($venues as $v)
                    <option value="{{ $v->id }}" @selected(request('venue_id') == $v->id)>{{ $v->name }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="block text-gray-600 mb-1">トラック</label>
            <select name="track_type" class="w-full border rounded px-2 py-1.5">
                <option value="">すべて</option>
                @foreach (['芝','ダート','障害'] as $t)
                    <option value="{{ $t }}" @selected(request('track_type') === $t)>{{ $t }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="block text-gray-600 mb-1">グレード</label>
            <select name="grade" class="w-full border rounded px-2 py-1.5">
                <option value="">すべて</option>
                @foreach (['G1','G2','G3','OP','L','3勝','2勝','1勝','未勝利','新馬'] as $g)
                    <option value="{{ $g }}" @selected(request('grade') === $g)>{{ $g }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="block text-gray-600 mb-1">開催日(から)</label>
            <input type="date" name="from" value="{{ request('from') }}" class="w-full border rounded px-2 py-1.5">
        </div>
        <div>
            <label class="block text-gray-600 mb-1">開催日(まで)</label>
            <input type="date" name="to" value="{{ request('to') }}" class="w-full border rounded px-2 py-1.5">
        </div>
        <div>
            <label class="block text-gray-600 mb-1">レース名キーワード</label>
            <input type="text" name="keyword" value="{{ request('keyword') }}" placeholder="例:ダービー" class="w-full border rounded px-2 py-1.5">
        </div>

        <div class="col-span-2 sm:col-span-3 lg:col-span-6 flex gap-2 pt-2 border-t">
            <button type="submit" class="px-4 py-1.5 bg-rose-500 hover:bg-rose-600 text-white rounded font-bold">
                🔎 検索
            </button>
            <a href="{{ route('analytics.recommend.race') }}" class="px-3 py-1.5 bg-gray-100 hover:bg-gray-200 text-gray-700 rounded">クリア</a>
            <span class="ml-auto text-gray-500 self-center">{{ $races->total() }} 件</span>
        </div>
    </form>

    {{-- 結果テーブル --}}
    <div class="bg-white rounded-lg shadow overflow-hidden">
        @if ($races->count() === 0)
            <div class="p-8 text-center text-sm text-gray-500">
                該当するレースがありません。条件を緩めてみてください。
            </div>
        @else
        <div class="overflow-x-auto">
            <table class="min-w-full text-xs whitespace-nowrap">
                <thead class="bg-gray-50 text-gray-600">
                    <tr>
                        <th class="px-2 py-1.5 text-left">日付</th>
                        <th class="px-2 py-1.5 text-left">競馬場</th>
                        <th class="px-2 py-1.5 text-right">R</th>
                        <th class="px-2 py-1.5 text-left">レース名</th>
                        <th class="px-2 py-1.5 text-left">グレード</th>
                        <th class="px-2 py-1.5 text-left">トラック</th>
                        <th class="px-2 py-1.5 text-right">距離</th>
                        <th class="px-2 py-1.5 text-left">馬場</th>
                        <th class="px-2 py-1.5 text-right">頭数</th>
                        <th class="px-2 py-1.5 text-center">推奨</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($races as $r)
                        @php
                            $gradeCls = match ($r->grade) {
                                'G1' => 'bg-rose-500 text-white',
                                'G2' => 'bg-amber-500 text-white',
                                'G3' => 'bg-emerald-500 text-white',
                                'OP','L' => 'bg-sky-500 text-white',
                                default => 'bg-gray-200 text-gray-700',
                            };
                        @endphp
                        <tr class="border-t hover:bg-rose-50">
                            <td class="px-2 py-1">{{ $r->race_date?->format('Y/m/d') }}</td>
                            <td class="px-2 py-1">{{ $r->venue?->name }}</td>
                            <td class="px-2 py-1 text-right font-bold">{{ $r->race_number }}R</td>
                            <td class="px-2 py-1 font-medium text-gray-800">{{ $r->name }}</td>
                            <td class="px-2 py-1">
                                @if ($r->grade)
                                    <span class="px-1.5 py-0.5 rounded text-[10px] {{ $gradeCls }}">{{ $r->grade }}</span>
                                @endif
                            </td>
                            <td class="px-2 py-1">
                                <span class="px-1.5 py-0.5 rounded text-[10px] {{ $r->track_type === '芝' ? 'bg-emerald-100 text-emerald-800' : ($r->track_type === 'ダート' ? 'bg-amber-100 text-amber-800' : 'bg-gray-100 text-gray-700') }}">
                                    {{ $r->track_type }}
                                </span>
                            </td>
                            <td class="px-2 py-1 text-right">{{ $r->distance }}m</td>
                            <td class="px-2 py-1">{{ $r->course_condition ?? '-' }}</td>
                            <td class="px-2 py-1 text-right">{{ $r->results_count }}</td>
                            <td class="px-2 py-1 text-center">
                                <a href="{{ route('analytics.recommend.race.show', $r) }}"
                                   class="inline-block px-2.5 py-1 bg-rose-500 hover:bg-rose-600 text-white rounded text-[11px] font-bold">
                                    印を見る →
                                </a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="px-4 py-3 border-t">
            {{ $races->links() }}
        </div>
        @endif
    </div>
</div>
@endsection
