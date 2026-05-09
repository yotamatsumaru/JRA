@extends('layouts.app')
@section('title', '騎手×コース相性分析')

@section('content')
<div class="space-y-6">
    <div class="flex items-end justify-between flex-wrap gap-2">
        <div>
            <h1 class="text-2xl font-bold text-gray-800">騎手 × コース相性分析</h1>
            <p class="text-sm text-gray-600">主要指標(勝率・連対率・複勝率・平均人気・平均着順)を一覧で比較できます。</p>
        </div>
        <div class="flex gap-3 text-xs text-gray-500">
            <div>対象騎手: <b class="text-gray-700">{{ number_format($summary['jockey_count']) }}</b></div>
            <div>総騎乗: <b class="text-gray-700">{{ number_format($summary['total_runs']) }}</b></div>
            <div>総勝利: <b class="text-gray-700">{{ number_format($summary['total_wins']) }}</b></div>
        </div>
    </div>

    {{-- ============ フィルター ============ --}}
    <form method="get" class="bg-white rounded-lg shadow p-4">
        <div class="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-7 gap-3 text-sm">
            <div>
                <label class="block text-xs text-gray-500 mb-1">騎手名で検索</label>
                <input type="text" name="keyword" value="{{ $filters['keyword'] }}" placeholder="例: ルメール" class="w-full border rounded px-2 py-1">
            </div>
            <div>
                <label class="block text-xs text-gray-500 mb-1">最小騎乗数</label>
                <input type="number" name="min_runs" value="{{ $filters['minRuns'] }}" min="1" class="w-full border rounded px-2 py-1">
            </div>
            <div>
                <label class="block text-xs text-gray-500 mb-1">並び替え</label>
                <select name="sort" class="w-full border rounded px-2 py-1">
                    <option value="win_rate"   @selected($filters['sort']==='win_rate')>勝率↓</option>
                    <option value="show_rate"  @selected($filters['sort']==='show_rate')>複勝率↓</option>
                    <option value="place_rate" @selected($filters['sort']==='place_rate')>連対率↓</option>
                    <option value="wins"       @selected($filters['sort']==='wins')>勝利数↓</option>
                    <option value="runs"       @selected($filters['sort']==='runs')>騎乗数↓</option>
                    <option value="avg_pop"    @selected($filters['sort']==='avg_pop')>平均人気↑</option>
                </select>
            </div>
            <div>
                <label class="block text-xs text-gray-500 mb-1">競馬場</label>
                <select name="venue_id" class="w-full border rounded px-2 py-1">
                    <option value="">全て</option>
                    @foreach ($venues as $v)
                        <option value="{{ $v->id }}" @selected($filters['venueId']==$v->id)>{{ $v->name }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="block text-xs text-gray-500 mb-1">トラック</label>
                <select name="track_type" class="w-full border rounded px-2 py-1">
                    <option value="">全て</option>
                    <option value="芝"     @selected($filters['trackType']==='芝')>芝</option>
                    <option value="ダート" @selected($filters['trackType']==='ダート')>ダート</option>
                    <option value="障害"   @selected($filters['trackType']==='障害')>障害</option>
                </select>
            </div>
            <div>
                <label class="block text-xs text-gray-500 mb-1">期間 from</label>
                <input type="date" name="from" value="{{ $filters['from'] }}" class="w-full border rounded px-2 py-1">
            </div>
            <div>
                <label class="block text-xs text-gray-500 mb-1">期間 to</label>
                <input type="date" name="to" value="{{ $filters['to'] }}" class="w-full border rounded px-2 py-1">
            </div>
        </div>
        <div class="mt-3 flex gap-2">
            @if ($jockeyName)
                <input type="hidden" name="jockey" value="{{ $jockeyName }}">
            @endif
            <button class="px-4 py-2 bg-emerald-600 text-white rounded hover:bg-emerald-700 text-sm">適用</button>
            <a href="{{ route('analytics.jockey') }}" class="px-4 py-2 bg-gray-200 text-gray-700 rounded hover:bg-gray-300 text-sm">リセット</a>
        </div>
    </form>

    {{-- ============ 騎手一覧 ============ --}}
    <div class="bg-white rounded-lg shadow p-4">
        <div class="flex items-center justify-between mb-3">
            <h2 class="font-semibold text-gray-700">騎手一覧 ({{ $jockeyRows->count() }} 名)</h2>
            <div class="text-xs text-gray-500">
                並び替え: <b>{{ [
                    'win_rate'=>'勝率↓','show_rate'=>'複勝率↓','place_rate'=>'連対率↓',
                    'wins'=>'勝利数↓','runs'=>'騎乗数↓','avg_pop'=>'平均人気↑'
                ][$filters['sort']] ?? '勝率↓' }}</b>
            </div>
        </div>

        @if ($jockeyRows->isEmpty())
            <p class="text-sm text-gray-500 py-4 text-center">条件に合致する騎手がいません。最小騎乗数を下げるか、フィルターをリセットしてみてください。</p>
        @else
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-gray-100 text-xs text-gray-600 sticky top-0">
                    <tr>
                        <th class="px-2 py-2 text-left w-8">#</th>
                        <th class="px-3 py-2 text-left">騎手</th>
                        <th class="px-2 py-2 text-right">騎乗</th>
                        <th class="px-2 py-2 text-right">1着</th>
                        <th class="px-2 py-2 text-right">2着内</th>
                        <th class="px-2 py-2 text-right">3着内</th>
                        <th class="px-2 py-2 text-right">勝率</th>
                        <th class="px-2 py-2 text-right">連対率</th>
                        <th class="px-2 py-2 text-right">複勝率</th>
                        <th class="px-2 py-2 w-32">複勝率バー</th>
                        <th class="px-2 py-2 text-right">平均人気</th>
                        <th class="px-2 py-2 text-right">平均着順</th>
                        <th class="px-2 py-2 text-center">最終騎乗</th>
                        <th class="px-2 py-2"></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($jockeyRows as $idx => $r)
                        @php
                            $intensity = min(100, $r->show_rate * 1.4);
                            $isTop = $idx < 3;
                            $rowBg = $isTop ? ['bg-yellow-50','bg-gray-50','bg-orange-50'][$idx] : '';
                        @endphp
                        <tr class="border-b hover:bg-emerald-50 {{ $rowBg }} {{ $jockeyName === $r->name ? 'ring-2 ring-emerald-400' : '' }}">
                            <td class="px-2 py-2 text-gray-500 text-xs">{{ $idx + 1 }}</td>
                            <td class="px-3 py-2 font-semibold">
                                <a href="{{ route('analytics.jockey', array_merge(request()->query(), ['jockey' => $r->name])) }}#detail"
                                   class="text-emerald-700 hover:underline">{{ $r->name }}</a>
                                @if ($idx === 0) <span class="text-yellow-500 ml-1">🏆</span>
                                @elseif ($idx === 1) <span class="text-gray-400 ml-1">🥈</span>
                                @elseif ($idx === 2) <span class="text-orange-400 ml-1">🥉</span>
                                @endif
                            </td>
                            <td class="px-2 py-2 text-right">{{ number_format($r->runs) }}</td>
                            <td class="px-2 py-2 text-right text-yellow-600 font-bold">{{ number_format($r->wins) }}</td>
                            <td class="px-2 py-2 text-right">{{ number_format($r->places) }}</td>
                            <td class="px-2 py-2 text-right text-emerald-600">{{ number_format($r->shows) }}</td>
                            <td class="px-2 py-2 text-right font-bold">{{ $r->win_rate }}%</td>
                            <td class="px-2 py-2 text-right">{{ $r->place_rate }}%</td>
                            <td class="px-2 py-2 text-right font-bold">{{ $r->show_rate }}%</td>
                            <td class="px-2 py-2">
                                <div class="h-4 rounded relative overflow-hidden bg-gray-100">
                                    <div class="absolute inset-y-0 left-0 bg-gradient-to-r from-blue-300 via-blue-500 to-red-500" style="width: {{ $intensity }}%;"></div>
                                </div>
                            </td>
                            <td class="px-2 py-2 text-right text-gray-600">{{ $r->avg_pop ?? '—' }}</td>
                            <td class="px-2 py-2 text-right text-gray-600">{{ $r->avg_finish ?? '—' }}</td>
                            <td class="px-2 py-2 text-center text-xs text-gray-500">{{ $r->last_ride ? \Carbon\Carbon::parse($r->last_ride)->format('Y/m/d') : '—' }}</td>
                            <td class="px-2 py-2 text-right">
                                <a href="{{ route('analytics.jockey', array_merge(request()->query(), ['jockey' => $r->name])) }}#detail"
                                   class="text-xs px-2 py-1 bg-emerald-600 text-white rounded hover:bg-emerald-700">詳細</a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @endif
    </div>

    {{-- ============ 詳細モード ============ --}}
    @if ($jockeyName)
    <div id="detail" class="bg-white rounded-lg shadow p-4 border-2 border-emerald-300">
        <div class="flex items-center justify-between mb-3">
            <h2 class="font-semibold text-gray-700">
                <span class="text-emerald-700">{{ $jockeyName }}</span> の競馬場×トラック相性
            </h2>
            <a href="{{ route('analytics.jockey', request()->except('jockey')) }}"
               class="text-xs px-3 py-1 bg-gray-200 text-gray-700 rounded hover:bg-gray-300">× 詳細を閉じる</a>
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
                            <th class="px-3 py-2 text-right">騎乗</th>
                            <th class="px-3 py-2 text-right">勝</th>
                            <th class="px-3 py-2 text-right">複勝</th>
                            <th class="px-3 py-2 text-right">勝率</th>
                            <th class="px-3 py-2 text-right">複勝率</th>
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
                                <td class="px-3 py-2 font-semibold">{{ $s->venue }}</td>
                                <td class="px-3 py-2 text-center">
                                    <span class="inline-block px-2 py-0.5 rounded text-xs {{ $s->track_type === '芝' ? 'bg-green-100 text-green-700' : ($s->track_type === 'ダート' ? 'bg-yellow-100 text-yellow-700' : 'bg-gray-100 text-gray-700') }}">{{ $s->track_type }}</span>
                                </td>
                                <td class="px-3 py-2 text-right">{{ $s->runs }}</td>
                                <td class="px-3 py-2 text-right text-yellow-600 font-bold">{{ $s->wins }}</td>
                                <td class="px-3 py-2 text-right text-emerald-600">{{ $s->shows }}</td>
                                <td class="px-3 py-2 text-right">{{ $winRate }}%</td>
                                <td class="px-3 py-2 text-right font-bold">{{ $showRate }}%</td>
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

    <div class="bg-blue-50 border-l-4 border-blue-400 p-4 rounded">
        <h3 class="font-semibold text-blue-800 mb-2">読み方のヒント</h3>
        <ul class="text-sm text-blue-700 list-disc list-inside space-y-1">
            <li><b>勝率</b>: 1着 / 騎乗数。単勝向きの勝負強さを示す</li>
            <li><b>連対率</b>: 2着以内 / 騎乗数。馬連・馬単の指標</li>
            <li><b>複勝率</b>: 3着以内 / 騎乗数。安定感の指標(複勝・ワイド・3連系の軸候補)</li>
            <li><b>平均人気</b>: 数字が小さいほど人気馬中心。逆に大きい騎手は穴を運ぶことも</li>
            <li>騎手名クリックで競馬場×トラックの詳細が見られます</li>
        </ul>
    </div>
</div>
@endsection
