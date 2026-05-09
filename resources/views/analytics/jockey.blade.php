@extends('layouts.app')
@section('title', '騎手×コース相性分析')

@section('content')
<div class="space-y-6">
    <div class="flex items-end justify-between flex-wrap gap-2">
        <div>
            <h1 class="text-2xl font-bold text-gray-800">騎手 × コース相性分析</h1>
            <p class="text-sm text-gray-600">騎手ごとの騎乗数・勝率・連対率・複勝率を一覧で比較。気になる騎手をクリックすると詳細(競馬場×トラック)が見られます。</p>
        </div>
    </div>

    {{-- ============ フィルター ============ --}}
    <form method="get" class="bg-white rounded-lg shadow p-4">
        <div class="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-7 gap-3 text-sm">
            <div>
                <label class="block text-xs text-gray-500 mb-1">騎手名検索</label>
                <input type="text" name="keyword" value="{{ $filters['keyword'] ?? '' }}"
                       placeholder="例: ルメール"
                       class="w-full border rounded px-2 py-1">
            </div>
            <div>
                <label class="block text-xs text-gray-500 mb-1">最低騎乗数</label>
                <input type="number" name="min_runs" value="{{ $filters['minRuns'] ?? 50 }}" min="1" step="10"
                       class="w-full border rounded px-2 py-1">
            </div>
            <div>
                <label class="block text-xs text-gray-500 mb-1">競馬場</label>
                <select name="venue_id" class="w-full border rounded px-2 py-1">
                    <option value="">全て</option>
                    @foreach ($venues as $v)
                        <option value="{{ $v->id }}" @selected(($filters['venueId'] ?? null) == $v->id)>{{ $v->name }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="block text-xs text-gray-500 mb-1">トラック</label>
                <select name="track_type" class="w-full border rounded px-2 py-1">
                    <option value="">全て</option>
                    <option value="芝"     @selected(($filters['trackType'] ?? null) === '芝')>芝</option>
                    <option value="ダート" @selected(($filters['trackType'] ?? null) === 'ダート')>ダート</option>
                    <option value="障害"   @selected(($filters['trackType'] ?? null) === '障害')>障害</option>
                </select>
            </div>
            <div>
                <label class="block text-xs text-gray-500 mb-1">並び順</label>
                <select name="sort" class="w-full border rounded px-2 py-1">
                    <option value="win_rate"   @selected(($filters['sort'] ?? null) === 'win_rate')>勝率順</option>
                    <option value="show_rate"  @selected(($filters['sort'] ?? null) === 'show_rate')>複勝率順</option>
                    <option value="place_rate" @selected(($filters['sort'] ?? null) === 'place_rate')>連対率順</option>
                    <option value="wins"       @selected(($filters['sort'] ?? null) === 'wins')>勝利数順</option>
                    <option value="runs"       @selected(($filters['sort'] ?? null) === 'runs')>騎乗数順</option>
                    <option value="avg_pop"    @selected(($filters['sort'] ?? null) === 'avg_pop')>平均人気順</option>
                </select>
            </div>
            <div>
                <label class="block text-xs text-gray-500 mb-1">開催日 from</label>
                <input type="date" name="from" value="{{ $filters['from'] ?? '' }}" class="w-full border rounded px-2 py-1">
            </div>
            <div>
                <label class="block text-xs text-gray-500 mb-1">開催日 to</label>
                <input type="date" name="to" value="{{ $filters['to'] ?? '' }}" class="w-full border rounded px-2 py-1">
            </div>
        </div>
        <div class="mt-3 flex gap-2">
            <button class="px-4 py-2 bg-emerald-600 text-white rounded hover:bg-emerald-700 text-sm">適用</button>
            <a href="{{ route('analytics.jockey') }}" class="px-4 py-2 bg-gray-200 text-gray-700 rounded hover:bg-gray-300 text-sm">リセット</a>
            @if ($jockeyName)
                <input type="hidden" name="jockey" value="{{ $jockeyName }}">
            @endif
        </div>
    </form>

    {{-- ============ サマリ KPI ============ --}}
    <div class="grid grid-cols-2 md:grid-cols-5 gap-3">
        <div class="bg-gradient-to-br from-emerald-500 to-emerald-700 text-white rounded-lg shadow p-4">
            <div class="text-xs opacity-80">対象騎手</div>
            <div class="text-2xl font-bold">{{ number_format($summary['jockey_count'] ?? 0) }}</div>
        </div>
        <div class="bg-gradient-to-br from-sky-500 to-sky-700 text-white rounded-lg shadow p-4">
            <div class="text-xs opacity-80">合計騎乗</div>
            <div class="text-2xl font-bold">{{ number_format($summary['total_runs'] ?? 0) }}</div>
        </div>
        <div class="bg-gradient-to-br from-amber-500 to-amber-700 text-white rounded-lg shadow p-4">
            <div class="text-xs opacity-80">合計勝利</div>
            <div class="text-2xl font-bold">{{ number_format($summary['total_wins'] ?? 0) }}</div>
        </div>
        <div class="bg-gradient-to-br from-rose-500 to-rose-700 text-white rounded-lg shadow p-4">
            <div class="text-xs opacity-80">最高勝率</div>
            <div class="text-2xl font-bold">{{ number_format($summary['top_win_rate'] ?? 0, 1) }}%</div>
        </div>
        <div class="bg-gradient-to-br from-violet-500 to-violet-700 text-white rounded-lg shadow p-4">
            <div class="text-xs opacity-80">最高複勝率</div>
            <div class="text-2xl font-bold">{{ number_format($summary['top_show_rate'] ?? 0, 1) }}%</div>
        </div>
    </div>

    {{-- ============ 騎手一覧表 ============ --}}
    <div class="bg-white rounded-lg shadow p-4 overflow-x-auto">
        <div class="flex items-center justify-between mb-3 flex-wrap gap-2">
            <h2 class="font-semibold text-gray-700">騎手一覧 ({{ $jockeyRows->count() }} 名)</h2>
            <div class="text-xs text-gray-500">
                クリックで詳細表示 / 列ヘッダで並び替え変更
            </div>
        </div>
        @if ($jockeyRows->isEmpty())
            <p class="text-sm text-gray-500 text-center py-8">条件に合致する騎手がいません。最低騎乗数を下げるかフィルターを緩めてみてください。</p>
        @else
        @php
            $sortLink = function ($key, $label) use ($filters) {
                $isActive = ($filters['sort'] ?? '') === $key;
                $params = array_merge($filters ?? [], ['sort' => $key]);
                // フィルタ名を元のクエリ名に再マッピング
                $query = [
                    'keyword'    => $params['keyword']   ?? '',
                    'min_runs'   => $params['minRuns']   ?? '',
                    'venue_id'   => $params['venueId']   ?? '',
                    'track_type' => $params['trackType'] ?? '',
                    'sort'       => $key,
                    'from'       => $params['from']      ?? '',
                    'to'         => $params['to']        ?? '',
                ];
                $url = route('analytics.jockey') . '?' . http_build_query(array_filter($query, fn($v) => $v !== '' && $v !== null));
                $arrow = $isActive ? ' ▼' : '';
                return '<a href="'.$url.'" class="hover:underline '.($isActive ? 'text-emerald-700 font-bold' : '').'">'.$label.$arrow.'</a>';
            };
        @endphp
        <table class="w-full text-sm">
            <thead class="bg-gray-100 text-xs text-gray-600">
                <tr>
                    <th class="px-3 py-2 text-left">#</th>
                    <th class="px-3 py-2 text-left">騎手</th>
                    <th class="px-3 py-2 text-center">{!! $sortLink('runs', '騎乗') !!}</th>
                    <th class="px-3 py-2 text-center">{!! $sortLink('wins', '勝') !!}</th>
                    <th class="px-3 py-2 text-center">2着</th>
                    <th class="px-3 py-2 text-center">3着</th>
                    <th class="px-3 py-2 text-center">{!! $sortLink('win_rate', '勝率') !!}</th>
                    <th class="px-3 py-2 text-center">{!! $sortLink('place_rate', '連対率') !!}</th>
                    <th class="px-3 py-2 text-center">{!! $sortLink('show_rate', '複勝率') !!}</th>
                    <th class="px-3 py-2 text-center">{!! $sortLink('avg_pop', '平均人気') !!}</th>
                    <th class="px-3 py-2 text-center">平均着順</th>
                    <th class="px-3 py-2 text-center w-32">複勝率バー</th>
                    <th class="px-3 py-2 text-center">最終騎乗</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($jockeyRows as $i => $r)
                    @php
                        $places2  = max(0, $r->places - $r->wins);
                        $places3  = max(0, $r->shows  - $r->places);
                        $isPicked = $jockeyName === $r->name;
                        $barWidth = min(100, max(2, (float) $r->show_rate * 1.2));
                        $detailUrl = route('analytics.jockey', array_filter([
                            'jockey'     => $r->name,
                            'min_runs'   => $filters['minRuns']   ?? null,
                            'venue_id'   => $filters['venueId']   ?? null,
                            'track_type' => $filters['trackType'] ?? null,
                            'sort'       => $filters['sort']      ?? null,
                            'from'       => $filters['from']      ?? null,
                            'to'         => $filters['to']        ?? null,
                            'keyword'    => $filters['keyword']   ?? null,
                        ], fn($v) => $v !== null && $v !== ''));
                    @endphp
                    <tr class="border-b hover:bg-emerald-50 {{ $isPicked ? 'bg-emerald-100' : '' }}">
                        <td class="px-3 py-2 text-gray-400">{{ $i + 1 }}</td>
                        <td class="px-3 py-2 font-semibold">
                            <a href="{{ $detailUrl }}" class="text-emerald-700 hover:underline">{{ $r->name }}</a>
                        </td>
                        <td class="px-3 py-2 text-center">{{ number_format($r->runs) }}</td>
                        <td class="px-3 py-2 text-center text-yellow-600 font-bold">{{ number_format($r->wins) }}</td>
                        <td class="px-3 py-2 text-center">{{ number_format($places2) }}</td>
                        <td class="px-3 py-2 text-center">{{ number_format($places3) }}</td>
                        <td class="px-3 py-2 text-center font-bold">{{ $r->win_rate }}%</td>
                        <td class="px-3 py-2 text-center">{{ $r->place_rate }}%</td>
                        <td class="px-3 py-2 text-center text-emerald-700 font-semibold">{{ $r->show_rate }}%</td>
                        <td class="px-3 py-2 text-center">{{ $r->avg_pop ?? '—' }}</td>
                        <td class="px-3 py-2 text-center">{{ $r->avg_finish ?? '—' }}</td>
                        <td class="px-3 py-2">
                            <div class="h-4 rounded relative overflow-hidden bg-gray-100">
                                <div class="absolute inset-y-0 left-0 bg-gradient-to-r from-blue-300 via-blue-500 to-emerald-600" style="width: {{ $barWidth }}%;"></div>
                            </div>
                        </td>
                        <td class="px-3 py-2 text-center text-xs text-gray-500">
                            {{ $r->last_ride ? \Carbon\Carbon::parse($r->last_ride)->format('Y-m-d') : '—' }}
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        @endif
    </div>

    {{-- ============ 個別騎手の詳細 ============ --}}
    @if ($jockeyName)
    <div class="bg-white rounded-lg shadow p-4">
        <div class="flex items-center justify-between mb-3">
            <h2 class="font-semibold text-gray-700">{{ $jockeyName }} の競馬場 × トラック相性</h2>
            <a href="{{ route('analytics.jockey', array_filter([
                'min_runs'   => $filters['minRuns']   ?? null,
                'venue_id'   => $filters['venueId']   ?? null,
                'track_type' => $filters['trackType'] ?? null,
                'sort'       => $filters['sort']      ?? null,
                'from'       => $filters['from']      ?? null,
                'to'         => $filters['to']        ?? null,
                'keyword'    => $filters['keyword']   ?? null,
            ], fn($v) => $v !== null && $v !== '')) }}"
               class="text-xs px-3 py-1 bg-gray-200 rounded hover:bg-gray-300">× 閉じる</a>
        </div>

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
                            $winRate  = $s->runs > 0 ? round($s->wins  / $s->runs * 100, 1) : 0;
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
