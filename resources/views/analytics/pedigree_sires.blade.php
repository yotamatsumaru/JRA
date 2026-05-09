@extends('layouts.app')
@section('title', '父系ランキング')

@section('content')
<div class="space-y-4">
    <h1 class="text-xl sm:text-2xl font-bold text-gray-800">👑 父系ランキング</h1>

    @include('analytics._pedigree_nav', ['active' => 'sires'])

    {{-- フィルタ --}}
    <form method="GET" class="bg-white rounded-lg shadow p-3 grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3 items-end text-xs sm:text-sm">
        <div>
            <label class="block text-gray-600 text-xs mb-1">期間 from</label>
            <input type="date" name="from" value="{{ $from }}" class="border rounded px-2 py-1 w-full">
        </div>
        <div>
            <label class="block text-gray-600 text-xs mb-1">期間 to</label>
            <input type="date" name="to" value="{{ $to }}" class="border rounded px-2 py-1 w-full">
        </div>
        <div>
            <label class="block text-gray-600 text-xs mb-1">トラック</label>
            <select name="track_type" class="border rounded px-2 py-1 w-full">
                <option value="">すべて</option>
                @foreach (['芝','ダート','障害'] as $t)
                    <option value="{{ $t }}" @selected($trackType === $t)>{{ $t }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="block text-gray-600 text-xs mb-1">最小出走数</label>
            <input type="number" min="1" name="min_runs" value="{{ $minRuns }}" class="border rounded px-2 py-1 w-full">
        </div>
        <div>
            <label class="block text-gray-600 text-xs mb-1">父名キーワード</label>
            <input type="text" name="keyword" value="{{ $keyword }}" placeholder="例: ディープ" class="border rounded px-2 py-1 w-full">
        </div>
        <div class="flex gap-2">
            <input type="hidden" name="sort" value="{{ $sort }}">
            <button class="bg-purple-600 hover:bg-purple-700 text-white px-4 py-1.5 rounded">適用</button>
            @if ($from || $to || $trackType || $keyword || $minRuns != 20)
                <a href="{{ route('analytics.pedigree.sires') }}" class="text-gray-500 hover:text-gray-700 underline self-center text-xs">クリア</a>
            @endif
        </div>
    </form>

    {{-- 結果数とソート --}}
    <div class="flex items-center justify-between text-xs text-gray-600">
        <div>該当 <span class="font-bold text-purple-700">{{ count($rows) }}</span> 系統 (上位500件)</div>
        <div>並べ替え: <span class="font-mono">{{ $sort }}</span> ↓</div>
    </div>

    @php
        // 列クリックでソート切替するためのヘルパ
        $sortLink = function ($key, $label) use ($sort, $from, $to, $trackType, $minRuns, $keyword) {
            $params = array_filter([
                'from' => $from, 'to' => $to, 'track_type' => $trackType,
                'min_runs' => $minRuns, 'keyword' => $keyword,
                'sort' => $key,
            ], fn($v) => $v !== null && $v !== '');
            $url = route('analytics.pedigree.sires') . '?' . http_build_query($params);
            $arrow = $sort === $key ? ' ↓' : '';
            return '<a href="'.$url.'" class="hover:text-purple-700">'.$label.$arrow.'</a>';
        };
    @endphp

    <div class="bg-white rounded-lg shadow overflow-hidden">
        @if (count($rows) === 0)
            <div class="p-6 text-sm text-gray-500 text-center">条件に合致するデータがありません</div>
        @else
        <div class="table-scroll">
            <table class="w-full text-xs sm:text-sm min-w-[820px]">
                <thead class="bg-gray-100 text-gray-600 text-xs">
                    <tr>
                        <th class="text-left px-3 py-2">#</th>
                        <th class="text-left px-3 py-2">父</th>
                        <th class="px-3 py-2 cursor-pointer">{!! $sortLink('runs','出走') !!}</th>
                        <th class="px-3 py-2 cursor-pointer">{!! $sortLink('wins','勝') !!}</th>
                        <th class="px-3 py-2 cursor-pointer">{!! $sortLink('win_rate','勝率') !!}</th>
                        <th class="px-3 py-2 cursor-pointer">{!! $sortLink('place_rate','連対率') !!}</th>
                        <th class="px-3 py-2 cursor-pointer">{!! $sortLink('show_rate','複勝率') !!}</th>
                        <th class="px-3 py-2 cursor-pointer">{!! $sortLink('roi_win','単回') !!}</th>
                        <th class="px-3 py-2 cursor-pointer">{!! $sortLink('roi_place','複回') !!}</th>
                        <th class="px-3 py-2"></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($rows as $i => $r)
                        <tr class="border-b hover:bg-purple-50">
                            <td class="px-3 py-1.5 text-gray-500">{{ $i + 1 }}</td>
                            <td class="px-3 py-1.5 font-medium text-purple-700">{{ $r->name }}</td>
                            <td class="px-3 py-1.5 text-center">{{ number_format($r->runs) }}</td>
                            <td class="px-3 py-1.5 text-center text-yellow-700 font-bold">{{ number_format($r->wins) }}</td>
                            <td class="px-3 py-1.5 text-center">{{ $r->win_rate }}%</td>
                            <td class="px-3 py-1.5 text-center">{{ $r->place_rate }}%</td>
                            <td class="px-3 py-1.5 text-center {{ $r->show_rate >= 35 ? 'text-emerald-700 font-bold' : '' }}">{{ $r->show_rate }}%</td>
                            <td class="px-3 py-1.5 text-center {{ $r->roi_win   >= 100 ? 'text-emerald-600 font-bold' : ($r->roi_win   >= 80 ? 'text-gray-700' : 'text-gray-400') }}">{{ $r->roi_win }}%</td>
                            <td class="px-3 py-1.5 text-center {{ $r->roi_place >= 100 ? 'text-emerald-600 font-bold' : ($r->roi_place >= 80 ? 'text-gray-700' : 'text-gray-400') }}">{{ $r->roi_place }}%</td>
                            <td class="px-3 py-1.5">
                                <a href="{{ route('analytics.pedigree', ['father' => $r->name]) }}"
                                   class="text-xs text-purple-600 hover:underline">詳細 →</a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @endif
    </div>

    <div class="text-xs text-gray-500 space-y-1">
        <p>※ 単回(単勝回収率) = 単勝オッズ × 100 を1着で勝ち取ったときの合計払戻 ÷ 投資額 × 100。</p>
        <p>※ 複回(複勝回収率) = 複勝オッズ中央値ベースの概算。</p>
        <p>※ 列ヘッダーをクリックすると並べ替えできます。</p>
    </div>
</div>
@endsection
