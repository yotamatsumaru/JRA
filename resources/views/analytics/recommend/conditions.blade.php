@extends('layouts.app')
@section('title', '条件指定で狙い目抽出 (B)')

@section('content')
<div class="space-y-4">
    <h1 class="text-xl sm:text-2xl font-bold text-gray-800">🎯 条件指定で狙い目血統を抽出</h1>
    <p class="text-xs sm:text-sm text-gray-600">
        指定した「競馬場 × トラック × 距離 × 馬場」の条件下で、複勝率と回収率の高い <strong>父・母父</strong> をランキング表示します。
        スコアは <code>血統 70% + ROI 30%</code> の簡易合成です。
    </p>

    @include('analytics.recommend._nav', ['active' => 'conditions'])

    {{-- 検索フォーム --}}
    <form method="GET" action="{{ route('analytics.recommend.conditions') }}"
          class="bg-white rounded-lg shadow p-4 grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3 text-xs">
        <div>
            <label class="block text-gray-600 mb-1">競馬場</label>
            <select name="venue_id" class="w-full border rounded px-2 py-1.5">
                <option value="">指定なし</option>
                @foreach ($venues as $v)
                    <option value="{{ $v->id }}" @selected((int)($cond['venue_id'] ?? 0) === (int)$v->id)>{{ $v->name }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="block text-gray-600 mb-1">トラック</label>
            <select name="track_type" class="w-full border rounded px-2 py-1.5">
                <option value="">指定なし</option>
                @foreach (['芝','ダート','障害'] as $t)
                    <option value="{{ $t }}" @selected(($cond['track_type'] ?? '') === $t)>{{ $t }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="block text-gray-600 mb-1">距離(m, ±200)</label>
            <input type="number" name="distance" step="100" min="800" max="4000"
                   value="{{ $cond['distance'] }}" placeholder="例:1600"
                   class="w-full border rounded px-2 py-1.5">
        </div>
        <div>
            <label class="block text-gray-600 mb-1">距離カテゴリ</label>
            <select name="distance_cat" class="w-full border rounded px-2 py-1.5">
                <option value="">指定なし</option>
                @foreach (['短距離','マイル','中距離','中長距離','長距離'] as $d)
                    <option value="{{ $d }}" @selected(($cond['distance_cat'] ?? '') === $d)>{{ $d }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="block text-gray-600 mb-1">馬場状態</label>
            <select name="course_condition" class="w-full border rounded px-2 py-1.5">
                <option value="">指定なし</option>
                @foreach (['良','稍重','重','不良'] as $c)
                    <option value="{{ $c }}" @selected(($cond['course_condition'] ?? '') === $c)>{{ $c }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="block text-gray-600 mb-1">最小出走数 / 表示件数</label>
            <div class="flex gap-1">
                <input type="number" name="min_runs" min="1" max="500" value="{{ $min_runs }}"
                       class="w-1/2 border rounded px-2 py-1.5" title="最小出走数">
                <input type="number" name="limit"    min="5" max="100" value="{{ $limit }}"
                       class="w-1/2 border rounded px-2 py-1.5" title="表示件数">
            </div>
        </div>

        <div class="col-span-2 sm:col-span-3 lg:col-span-6 flex flex-wrap items-center gap-3 pt-2 border-t mt-1">
            <label class="inline-flex items-center gap-1.5 text-gray-700">
                <input type="checkbox" name="show_cross" value="1" @checked($show_cross)>
                父×母父クロス表を表示(各TOP10)
            </label>
            <button type="submit"
                    class="ml-auto px-4 py-1.5 bg-purple-500 hover:bg-purple-600 text-white rounded font-bold">
                🔎 抽出する
            </button>
            <a href="{{ route('analytics.recommend.conditions') }}"
               class="px-3 py-1.5 bg-gray-100 hover:bg-gray-200 text-gray-700 rounded">条件クリア</a>
        </div>
    </form>

    @if (! $has_filter)
        <div class="bg-amber-50 border border-amber-200 rounded-lg p-4 text-xs sm:text-sm text-amber-900">
            ☝️ 条件を1つ以上指定してから「抽出する」を押してください(無条件抽出は重いため非対応)。<br>
            例: 「東京 × 芝 × 1600m」「中山 × ダート × 短距離」など。
        </div>
    @else
        {{-- 適用条件サマリ --}}
        <div class="bg-purple-50 border border-purple-200 rounded p-3 text-xs sm:text-sm text-purple-900">
            <span class="font-bold">適用条件:</span>
            @php
                $venueName = collect($venues)->firstWhere('id', $cond['venue_id'])->name ?? null;
                $bits = array_filter([
                    $venueName               ? "🏟 {$venueName}"          : null,
                    $cond['track_type']      ? "🏇 {$cond['track_type']}" : null,
                    $cond['distance']        ? "📏 {$cond['distance']}m ±200" : null,
                    !$cond['distance']
                        && $cond['distance_cat'] ? "📏 {$cond['distance_cat']}" : null,
                    $cond['course_condition']? "🌧 {$cond['course_condition']}" : null,
                ]);
            @endphp
            @if (count($bits))
                @foreach ($bits as $b) <span class="inline-block bg-white border border-purple-300 px-2 py-0.5 rounded mr-1">{{ $b }}</span> @endforeach
            @else
                <span>(条件なし)</span>
            @endif
            <span class="ml-2 text-purple-700">最小出走数 ≥ {{ $min_runs }} / 上位 {{ $limit }} 件</span>
        </div>

        {{-- 父・母父ランキング 2カラム --}}
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
            @foreach ([
                ['label' => '父系 (Father)',   'rows' => $fathers['rows'],   'color' => 'purple', 'count' => $fathers['total_groups']],
                ['label' => '母父系 (Broodmare Sire)', 'rows' => $m_fathers['rows'], 'color' => 'sky',     'count' => $m_fathers['total_groups']],
            ] as $blk)
                <div class="bg-white rounded-lg shadow overflow-hidden">
                    <div class="px-4 py-2.5 bg-{{ $blk['color'] }}-50 border-b border-{{ $blk['color'] }}-100 flex items-center justify-between">
                        <h2 class="font-bold text-{{ $blk['color'] }}-800">{{ $blk['label'] }}</h2>
                        <span class="text-xs text-{{ $blk['color'] }}-700">{{ $blk['count'] }} 件</span>
                    </div>
                    @if (count($blk['rows']) === 0)
                        <div class="p-6 text-center text-sm text-gray-500">
                            該当する集計データがありません。<br>
                            <span class="text-xs">最小出走数を下げるか、条件を緩めてみてください。</span>
                        </div>
                    @else
                    <div class="overflow-x-auto">
                        <table class="min-w-full text-xs whitespace-nowrap">
                            <thead class="bg-gray-50 text-gray-600">
                                <tr>
                                    <th class="px-2 py-1.5 text-left">#</th>
                                    <th class="px-2 py-1.5 text-left">名前</th>
                                    <th class="px-2 py-1.5 text-right">出走</th>
                                    <th class="px-2 py-1.5 text-right">勝</th>
                                    <th class="px-2 py-1.5 text-right">複3着内</th>
                                    <th class="px-2 py-1.5 text-right">勝率</th>
                                    <th class="px-2 py-1.5 text-right">複勝率</th>
                                    <th class="px-2 py-1.5 text-right">単回値</th>
                                    <th class="px-2 py-1.5 text-right">複回値</th>
                                    <th class="px-2 py-1.5 text-right">スコア</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($blk['rows'] as $i => $r)
                                    @php
                                        $sc = $r->score;
                                        $bg = match (true) {
                                            $sc >= 70 => 'bg-rose-100',
                                            $sc >= 60 => 'bg-amber-100',
                                            $sc >= 50 => 'bg-yellow-50',
                                            default   => '',
                                        };
                                        $roiCls   = $r->roi_place >= 100 ? 'text-emerald-700 font-bold' : 'text-gray-700';
                                        $showCls  = $r->show_rate >= 33  ? 'text-rose-700 font-bold'    : 'text-gray-700';
                                    @endphp
                                    <tr class="border-t hover:bg-gray-50 {{ $bg }}">
                                        <td class="px-2 py-1 text-gray-500">{{ $i + 1 }}</td>
                                        <td class="px-2 py-1 font-medium text-gray-800">{{ $r->name }}</td>
                                        <td class="px-2 py-1 text-right">{{ number_format($r->runs) }}</td>
                                        <td class="px-2 py-1 text-right">{{ $r->wins }}</td>
                                        <td class="px-2 py-1 text-right">{{ $r->shows }}</td>
                                        <td class="px-2 py-1 text-right">{{ $r->win_rate }}%</td>
                                        <td class="px-2 py-1 text-right {{ $showCls }}">{{ $r->show_rate }}%</td>
                                        <td class="px-2 py-1 text-right">{{ $r->roi_win }}</td>
                                        <td class="px-2 py-1 text-right {{ $roiCls }}">{{ $r->roi_place }}</td>
                                        <td class="px-2 py-1 text-right font-bold">{{ $r->score }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    @endif
                </div>
            @endforeach
        </div>

        {{-- クロス表 (父×母父) --}}
        @if ($show_cross && !empty($cross_cells))
            <div class="bg-white rounded-lg shadow overflow-hidden">
                <div class="px-4 py-2.5 bg-rose-50 border-b border-rose-100">
                    <h2 class="font-bold text-rose-800">🔀 父 × 母父 クロス表 (各TOP10, 複勝率%)</h2>
                    <p class="text-xs text-rose-700 mt-0.5">
                        セルの数値は複勝率(%)、色が濃いほど好成績。括弧内は出走数。
                        最小出走数は <strong>{{ max(1, intdiv($min_runs, 4)) }}</strong> に緩和。
                    </p>
                </div>
                @php
                    $fNames = array_slice(array_map(fn($r) => $r->name, $fathers['rows']),  0, 10);
                    $mNames = array_slice(array_map(fn($r) => $r->name, $m_fathers['rows']), 0, 10);
                @endphp
                <div class="overflow-x-auto">
                    <table class="min-w-full text-[11px] whitespace-nowrap">
                        <thead class="bg-gray-50 text-gray-600 sticky top-0">
                            <tr>
                                <th class="px-2 py-1 text-left bg-gray-100 sticky left-0 z-10">父 ＼ 母父</th>
                                @foreach ($mNames as $m)
                                    <th class="px-2 py-1 text-center" title="{{ $m }}">{{ mb_strimwidth($m, 0, 12, '…') }}</th>
                                @endforeach
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($fNames as $f)
                                <tr class="border-t">
                                    <td class="px-2 py-1 font-medium bg-gray-50 sticky left-0 z-10" title="{{ $f }}">
                                        {{ mb_strimwidth($f, 0, 14, '…') }}
                                    </td>
                                    @foreach ($mNames as $m)
                                        @php
                                            $cell = $cross_cells[$f][$m] ?? null;
                                            if ($cell) {
                                                $sr = (float) $cell->show_rate;
                                                $bg = match (true) {
                                                    $sr >= 50 => 'bg-rose-300',
                                                    $sr >= 40 => 'bg-rose-200',
                                                    $sr >= 30 => 'bg-amber-100',
                                                    $sr >= 20 => 'bg-yellow-50',
                                                    default   => 'bg-gray-50',
                                                };
                                            }
                                        @endphp
                                        @if ($cell)
                                            <td class="px-2 py-1 text-center {{ $bg }}"
                                                title="出走 {{ $cell->runs }} / 3着内 {{ $cell->shows }} / 複勝率 {{ $cell->show_rate }}% / 複回 {{ $cell->roi_place }}">
                                                <div class="font-bold">{{ $cell->show_rate }}</div>
                                                <div class="text-[10px] text-gray-500">({{ $cell->runs }})</div>
                                            </td>
                                        @else
                                            <td class="px-2 py-1 text-center text-gray-300">—</td>
                                        @endif
                                    @endforeach
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @elseif ($show_cross)
            <div class="bg-amber-50 border border-amber-200 rounded p-3 text-xs text-amber-900">
                クロス表を表示するには、まず父・母父のランキングが両方とも結果を返す必要があります。
                条件をもう少し緩めるか、最小出走数を下げてみてください。
            </div>
        @endif
    @endif
</div>
@endsection
