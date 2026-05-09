@extends('layouts.app')
@section('title', '騎手×コース相性分析')

@section('content')
<div class="space-y-4 sm:space-y-6">
    <div class="flex items-end justify-between flex-wrap gap-2">
        <div>
            <h1 class="text-xl sm:text-2xl font-bold text-gray-800">騎手 × コース相性分析</h1>
            <p class="text-xs sm:text-sm text-gray-600">騎手ごとの騎乗数・勝率・連対率・複勝率を一覧で比較。気になる騎手をタップすると詳細(競馬場×トラック)が見られます。</p>
        </div>
    </div>

    {{-- ============ フィルター (スマホは折り畳み) ============ --}}
    <form method="get" class="bg-white rounded-lg shadow p-3 sm:p-4" x-data="{ openFilter: window.innerWidth >= 640 }">
        <button type="button" @click="openFilter = !openFilter"
                class="sm:hidden w-full flex items-center justify-between text-sm font-semibold text-gray-700 mb-2">
            <span>🔍 絞り込み・並び替え</span>
            <span x-text="openFilter ? '▲' : '▼'"></span>
        </button>
        <div x-show="openFilter" x-cloak class="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-7 gap-2 sm:gap-3 text-sm">
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
        <div x-show="openFilter" x-cloak class="mt-3 flex gap-2">
            <button class="flex-1 sm:flex-none px-4 py-2 bg-emerald-600 text-white rounded hover:bg-emerald-700 active:bg-emerald-800 text-sm font-medium">適用</button>
            <a href="{{ route('analytics.jockey') }}" class="flex-1 sm:flex-none text-center px-4 py-2 bg-gray-200 text-gray-700 rounded hover:bg-gray-300 active:bg-gray-400 text-sm">リセット</a>
            @if ($jockeyName)
                <input type="hidden" name="jockey" value="{{ $jockeyName }}">
            @endif
        </div>
    </form>

    {{-- ============ サマリ KPI ============ --}}
    <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-5 gap-2 sm:gap-3">
        <div class="bg-gradient-to-br from-emerald-500 to-emerald-700 text-white rounded-lg shadow p-3 sm:p-4">
            <div class="text-[11px] sm:text-xs opacity-80">対象騎手</div>
            <div class="text-xl sm:text-2xl font-bold">{{ number_format($summary['jockey_count'] ?? 0) }}</div>
        </div>
        <div class="bg-gradient-to-br from-sky-500 to-sky-700 text-white rounded-lg shadow p-3 sm:p-4">
            <div class="text-[11px] sm:text-xs opacity-80">合計騎乗</div>
            <div class="text-xl sm:text-2xl font-bold">{{ number_format($summary['total_runs'] ?? 0) }}</div>
        </div>
        <div class="bg-gradient-to-br from-amber-500 to-amber-700 text-white rounded-lg shadow p-3 sm:p-4">
            <div class="text-[11px] sm:text-xs opacity-80">合計勝利</div>
            <div class="text-xl sm:text-2xl font-bold">{{ number_format($summary['total_wins'] ?? 0) }}</div>
        </div>
        <div class="bg-gradient-to-br from-rose-500 to-rose-700 text-white rounded-lg shadow p-3 sm:p-4">
            <div class="text-[11px] sm:text-xs opacity-80">最高勝率</div>
            <div class="text-xl sm:text-2xl font-bold">{{ number_format($summary['top_win_rate'] ?? 0, 1) }}%</div>
        </div>
        <div class="bg-gradient-to-br from-violet-500 to-violet-700 text-white rounded-lg shadow p-3 sm:p-4 col-span-2 sm:col-span-1">
            <div class="text-[11px] sm:text-xs opacity-80">最高複勝率</div>
            <div class="text-xl sm:text-2xl font-bold">{{ number_format($summary['top_show_rate'] ?? 0, 1) }}%</div>
        </div>
    </div>

    {{-- ============ 騎手一覧表 ============ --}}
    <div class="bg-white rounded-lg shadow p-3 sm:p-4">
        <div class="flex items-center justify-between mb-3 flex-wrap gap-2">
            <h2 class="font-semibold text-gray-700 text-sm sm:text-base">騎手一覧 ({{ $jockeyRows->count() }} 名)</h2>
            <div class="text-xs text-gray-500 hidden sm:block">
                クリックで詳細表示 / 列ヘッダで並び替え変更
            </div>
            <div class="text-[11px] text-gray-500 sm:hidden">→ 横スクロールで全列表示</div>
        </div>
        @if ($jockeyRows->isEmpty())
            <p class="text-sm text-gray-500 text-center py-8">条件に合致する騎手がいません。最低騎乗数を下げるかフィルターを緩めてみてください。</p>
        @else
        <div class="table-scroll -mx-3 sm:mx-0 px-3 sm:px-0">
        <div class="min-w-[800px] sm:min-w-0">
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
                        ], fn($v) => $v !== null && $v !== '')) . '#jockey-detail';
                        // 騎手プロフィールページ(jockeys.show) は jockey_id が必要
                        $profileUrl = isset($r->jockey_id)
                            ? route('jockeys.show', $r->jockey_id)
                            : null;
                    @endphp
                    <tr class="border-b hover:bg-emerald-50 {{ $isPicked ? 'bg-emerald-100' : '' }}">
                        <td class="px-3 py-2 text-gray-400">{{ $i + 1 }}</td>
                        <td class="px-3 py-2 font-semibold">
                            <a href="{{ $detailUrl }}"
                               class="text-emerald-700 hover:underline inline-flex items-center gap-1"
                               title="クリックで下の詳細を表示">
                                <span>{{ $r->name }}</span>
                                <span class="text-xs text-gray-400">▼</span>
                            </a>
                            @if ($profileUrl)
                                <a href="{{ $profileUrl }}"
                                   class="ml-1 text-xs text-sky-600 hover:underline"
                                   title="騎手詳細ページへ">[個別ページ]</a>
                            @endif
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
        </div>{{-- /min-w --}}
        </div>{{-- /table-scroll --}}
        @endif
    </div>

    {{-- ============ 個別騎手の詳細 (右サイドパネル) ============ --}}
    @if ($jockeyName)
        @php
            $closeUrl = route('analytics.jockey', array_filter([
                'min_runs'   => $filters['minRuns']   ?? null,
                'venue_id'   => $filters['venueId']   ?? null,
                'track_type' => $filters['trackType'] ?? null,
                'sort'       => $filters['sort']      ?? null,
                'from'       => $filters['from']      ?? null,
                'to'         => $filters['to']        ?? null,
                'keyword'    => $filters['keyword']   ?? null,
            ], fn($v) => $v !== null && $v !== ''));
        @endphp
        {{-- スマホでは半透明オーバーレイでバックドロップ --}}
        <div
            x-data="{ open: true }"
            x-show="open"
            x-cloak
            x-transition.opacity
            class="sm:hidden fixed inset-0 bg-black/40 z-30"
            @click="window.location.href = @js($closeUrl)"
        ></div>
        <div
            id="jockey-detail"
            x-data="{ open: true, closeUrl: @js($closeUrl) }"
            x-show="open"
            x-transition:enter="transition ease-out duration-200"
            x-transition:enter-start="translate-y-full sm:translate-y-0 sm:translate-x-full opacity-0"
            x-transition:enter-end="translate-y-0 sm:translate-x-0 opacity-100"
            x-cloak
            @keydown.escape.window="open = false; window.location.href = closeUrl;"
            class="fixed inset-x-0 bottom-0 sm:inset-x-auto sm:top-0 sm:right-0 sm:h-screen
                   max-h-[85vh] sm:max-h-none h-auto sm:h-screen
                   w-full sm:w-[480px] lg:w-[560px]
                   z-40 bg-white shadow-2xl ring-2 ring-emerald-300
                   rounded-t-2xl sm:rounded-none
                   flex flex-col safe-area-bottom"
        >
            {{-- スマホ用ドラッグハンドル --}}
            <div class="sm:hidden pt-2 pb-1 flex justify-center">
                <div class="w-10 h-1.5 bg-gray-300 rounded-full"></div>
            </div>

            {{-- ヘッダー (sticky) --}}
            <div class="bg-emerald-50 border-b px-4 py-3 flex items-center justify-between flex-wrap gap-2">
                <h2 class="font-semibold text-gray-700 text-sm sm:text-base flex-1 min-w-0">
                    <span class="text-emerald-700">▶</span>
                    <span class="truncate inline-block max-w-[60vw] align-middle">{{ $jockeyName }}</span>
                    <span class="text-xs text-gray-500 hidden sm:inline">の競馬場 × トラック相性</span>
                </h2>
                <div class="flex items-center gap-1.5 flex-shrink-0">
                    <a href="{{ route('jockeys.show', ['jockey' => $jockeyName]) }}"
                       class="text-xs px-2.5 py-1.5 bg-emerald-600 text-white rounded hover:bg-emerald-700 active:bg-emerald-800 whitespace-nowrap">
                        個別 →
                    </a>
                    <a href="{{ $closeUrl }}"
                       class="text-xs px-2.5 py-1.5 bg-gray-200 rounded hover:bg-gray-300 active:bg-gray-400 whitespace-nowrap">× 閉じる</a>
                </div>
            </div>

            {{-- 本文 (スクロール可能) --}}
            <div class="flex-1 overflow-y-auto p-4">
                @if ($stats->isEmpty())
                    <p class="text-sm text-gray-500">データがありません</p>
                @else
                <table class="w-full text-xs">
                    <thead class="bg-gray-100 text-gray-600">
                        <tr>
                            <th class="text-left px-2 py-1.5">競馬場</th>
                            <th class="px-2 py-1.5">トラック</th>
                            <th class="px-2 py-1.5">騎乗</th>
                            <th class="px-2 py-1.5">勝</th>
                            <th class="px-2 py-1.5">複</th>
                            <th class="px-2 py-1.5">勝率</th>
                            <th class="px-2 py-1.5">複勝率</th>
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
                                <td class="px-2 py-1.5">{{ $s->venue }}</td>
                                <td class="px-2 py-1.5 text-center">{{ $s->track_type }}</td>
                                <td class="px-2 py-1.5 text-center">{{ $s->runs }}</td>
                                <td class="px-2 py-1.5 text-center text-yellow-600 font-bold">{{ $s->wins }}</td>
                                <td class="px-2 py-1.5 text-center text-emerald-600">{{ $s->shows }}</td>
                                <td class="px-2 py-1.5 text-center">{{ $winRate }}%</td>
                                <td class="px-2 py-1.5 text-center font-bold">{{ $showRate }}%</td>
                            </tr>
                            <tr class="border-b">
                                <td colspan="7" class="px-2 pb-2">
                                    <div class="h-3 rounded relative overflow-hidden bg-gray-100">
                                        <div class="absolute inset-y-0 left-0 bg-gradient-to-r from-blue-300 via-blue-600 to-red-500" style="width: {{ $intensity }}%;"></div>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                @endif
            </div>
        </div>

        {{-- ページ本体に右余白を追加して被らないように (sm以上のみ。スマホはボトムシート扱い) --}}
        <style>
            @media (min-width: 640px) {
                body { padding-right: 480px; }
            }
            @media (min-width: 1024px) {
                body { padding-right: 560px; }
            }
            /* スマホではサイドパネルが下から出るので右余白は不要。
               下に余白を入れて最下行が隠れないように */
            @media (max-width: 639px) {
                body { padding-bottom: 30vh; }
            }
        </style>
    @endif
</div>

@push('scripts')
<style>[x-cloak]{display:none!important;}</style>
@endpush
@endsection
