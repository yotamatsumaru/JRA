@extends('layouts.app')
@section('title', '全条件スキャン (C)')

@section('content')
<div class="space-y-4">
    <h1 class="inline-flex items-center gap-2 text-xl sm:text-2xl font-bold text-gray-800">
        <x-icon name="search" class="w-6 h-6 text-emerald-600" />
        <span>全条件スキャン</span>
    </h1>
    <p class="text-xs sm:text-sm text-gray-600">
        DB 全体を <strong>競馬場 × トラック × 距離カテゴリ</strong> で総当たり集計し、
        各条件で最も成績の良い父系を抽出します。お宝血統の発見ボードとしてどうぞ。
    </p>

    @include('analytics.recommend._nav', ['active' => 'scan'])

    {{-- フォーム --}}
    <form method="GET" action="{{ route('analytics.recommend.scan') }}"
          class="bg-white rounded-lg shadow p-4 grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3 text-xs">
        <div>
            <label class="block text-gray-600 mb-1">最小出走数</label>
            <input type="number" name="min_runs" min="1" max="500" value="{{ $min_runs }}"
                   class="w-full border rounded px-2 py-1.5">
        </div>
        <div>
            <label class="block text-gray-600 mb-1">セルあたり件数</label>
            <input type="number" name="top_per_cell" min="1" max="5" value="{{ $top_per_cell }}"
                   class="w-full border rounded px-2 py-1.5" title="各セルから取り出すTOP件数">
        </div>
        <div>
            <label class="block text-gray-600 mb-1">競馬場(絞込)</label>
            <select name="venue_id" class="w-full border rounded px-2 py-1.5">
                <option value="">全競馬場</option>
                @foreach ($venues as $v)
                    <option value="{{ $v->id }}" @selected((int)($venue_id ?? 0) === (int)$v->id)>{{ $v->name }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="block text-gray-600 mb-1">トラック(絞込)</label>
            <select name="track_type" class="w-full border rounded px-2 py-1.5">
                <option value="">全トラック</option>
                @foreach (['芝','ダート'] as $t)
                    <option value="{{ $t }}" @selected(($track_type ?? '') === $t)>{{ $t }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="block text-gray-600 mb-1">距離カテゴリ(絞込)</label>
            <select name="distance_cat" class="w-full border rounded px-2 py-1.5">
                <option value="">全距離</option>
                @foreach (['短距離','マイル','中距離','中長距離','長距離'] as $d)
                    <option value="{{ $d }}" @selected(($distance_cat ?? '') === $d)>{{ $d }}</option>
                @endforeach
            </select>
        </div>
        <div class="flex items-end">
            <label class="inline-flex items-center gap-1.5 text-gray-700">
                <input type="checkbox" name="only_positive" value="1" @checked($only_positive)>
                複回値 ≥ 100 のみ
            </label>
        </div>

        <div class="col-span-2 sm:col-span-3 lg:col-span-6 flex flex-wrap gap-3 pt-2 border-t">
            <button type="submit"
                    class="inline-flex items-center gap-1.5 px-4 py-1.5 bg-emerald-500 hover:bg-emerald-600 text-white rounded font-bold">
                <x-icon name="search" class="w-4 h-4" />
                <span>スキャン実行</span>
            </button>
            <a href="{{ route('analytics.recommend.scan') }}"
               class="px-3 py-1.5 bg-gray-100 hover:bg-gray-200 text-gray-700 rounded">条件クリア</a>
            <span class="ml-auto text-gray-500 self-center">
                ※ 結果はキャッシュされます(最大5分)
            </span>
        </div>
    </form>

    {{-- KPI --}}
    <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
        <div class="bg-white rounded-lg shadow p-3 border-l-4 border-emerald-500">
            <div class="text-[11px] text-gray-500">該当セル数</div>
            <div class="text-2xl font-bold text-gray-800 dark:text-gray-100">{{ number_format($stats['total_cells']) }}</div>
        </div>
        <div class="bg-white rounded-lg shadow p-3 border-l-4 border-rose-500">
            <div class="text-[11px] text-gray-500">複回値 ≥ 100</div>
            <div class="text-2xl font-bold text-gray-800 dark:text-gray-100">{{ number_format($stats['positive_roi']) }}</div>
        </div>
        <div class="bg-white rounded-lg shadow p-3 border-l-4 border-amber-500">
            <div class="text-[11px] text-gray-500">スコア ≥ 60</div>
            <div class="text-2xl font-bold text-gray-800 dark:text-gray-100">{{ number_format($stats['high_score']) }}</div>
        </div>
        <div class="bg-white rounded-lg shadow p-3 border-l-4 border-sky-500">
            <div class="text-[11px] text-gray-500">平均複回値</div>
            <div class="text-2xl font-bold text-gray-800 dark:text-gray-100">{{ $stats['avg_roi'] }}</div>
        </div>
    </div>

    {{-- 結果テーブル --}}
    <div class="bg-white rounded-lg shadow overflow-hidden">
        <div class="px-4 py-2.5 bg-emerald-50 border-b border-emerald-100 flex items-center justify-between">
            <h2 class="inline-flex items-center gap-1.5 font-bold text-emerald-800">
                <x-icon name="chart" class="w-5 h-5" />
                <span>スコア順 お宝発見ボード</span>
            </h2>
            <span class="text-xs text-emerald-700">クリックで条件指定(B)へ →</span>
        </div>

        @if (count($rows) === 0)
            <div class="p-8 text-center text-sm text-gray-500">
                該当するデータがありません。<br>
                <span class="text-xs">最小出走数を下げる、または「複回値 ≥ 100 のみ」のチェックを外してみてください。</span>
            </div>
        @else
        <div class="overflow-x-auto">
            <table class="min-w-full text-xs whitespace-nowrap">
                <thead class="bg-gray-50 text-gray-600 sticky top-0">
                    <tr>
                        <th class="px-2 py-1.5 text-left">#</th>
                        <th class="px-2 py-1.5 text-left">競馬場</th>
                        <th class="px-2 py-1.5 text-left">トラック</th>
                        <th class="px-2 py-1.5 text-left">距離</th>
                        <th class="px-2 py-1.5 text-left">TOP 父系</th>
                        <th class="px-2 py-1.5 text-right">出走</th>
                        <th class="px-2 py-1.5 text-right">勝</th>
                        <th class="px-2 py-1.5 text-right">複3着内</th>
                        <th class="px-2 py-1.5 text-right">勝率</th>
                        <th class="px-2 py-1.5 text-right">複勝率</th>
                        <th class="px-2 py-1.5 text-right">複回値</th>
                        <th class="px-2 py-1.5 text-right">スコア</th>
                        <th class="px-2 py-1.5 text-center">→</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($rows as $i => $r)
                        @php
                            $sc = $r->score;
                            $bg = match (true) {
                                $sc >= 75 => 'bg-rose-100',
                                $sc >= 65 => 'bg-amber-100',
                                $sc >= 55 => 'bg-yellow-50',
                                default   => '',
                            };
                            $roiCls  = $r->roi_place >= 100 ? 'text-emerald-700 font-bold' : 'text-gray-700';
                            $showCls = $r->show_rate >= 33  ? 'text-rose-700 font-bold'    : 'text-gray-700';

                            $jumpUrl = route('analytics.recommend.conditions', [
                                'venue_id'     => $r->venue_id,
                                'track_type'   => $r->track_type,
                                'distance_cat' => $r->distance_cat,
                                'min_runs'     => $min_runs,
                            ]);
                        @endphp
                        <tr class="border-t hover:bg-gray-50 {{ $bg }}">
                            <td class="px-2 py-1 text-gray-500">{{ $i + 1 }}</td>
                            <td class="px-2 py-1 font-medium">{{ $r->venue_name }}</td>
                            <td class="px-2 py-1">
                                <span class="px-1.5 py-0.5 rounded text-[10px] {{ $r->track_type === '芝' ? 'bg-emerald-100 text-emerald-800' : 'bg-amber-100 text-amber-800' }}">
                                    {{ $r->track_type }}
                                </span>
                            </td>
                            <td class="px-2 py-1">{{ $r->distance_cat }}</td>
                            <td class="px-2 py-1 font-bold text-gray-800">{{ $r->top_father }}</td>
                            <td class="px-2 py-1 text-right">{{ number_format($r->runs) }}</td>
                            <td class="px-2 py-1 text-right">{{ $r->wins }}</td>
                            <td class="px-2 py-1 text-right">{{ $r->shows }}</td>
                            <td class="px-2 py-1 text-right">{{ $r->win_rate }}%</td>
                            <td class="px-2 py-1 text-right {{ $showCls }}">{{ $r->show_rate }}%</td>
                            <td class="px-2 py-1 text-right {{ $roiCls }}">{{ $r->roi_place }}</td>
                            <td class="px-2 py-1 text-right font-bold">{{ $r->score }}</td>
                            <td class="px-2 py-1 text-center">
                                <a href="{{ $jumpUrl }}"
                                   class="text-emerald-600 hover:text-emerald-800 hover:underline text-[11px]"
                                   title="この条件で B(条件指定抽出)を開く">
                                    詳細 →
                                </a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @endif
    </div>

    {{-- 凡例 --}}
    <div class="bg-white rounded-lg shadow p-4 text-xs text-gray-600 space-y-2">
        <h3 class="inline-flex items-center gap-1.5 font-bold text-gray-700">
            <x-icon name="document" class="w-4 h-4 text-gray-500" />
            <span>表の見方</span>
        </h3>
        <ul class="list-disc list-inside space-y-0.5">
            <li><strong>セル</strong> = 競馬場 × トラック × 距離カテゴリ の組み合わせ(最大10×2×5=100セル)</li>
            <li><strong>TOP 父系</strong> = そのセル内でスコアが最も高かった父名(同セル複数表示はセルあたり件数で調整可)</li>
            <li><strong>スコア</strong> = <code>複勝率×2</code>(0〜100, 70%重み) + <code>(複回値-100)×0.5</code>(0〜100, 30%重み) の合成</li>
            <li><strong>複回値</strong> = 複勝オッズの平均から算出。100超なら 100円買って100円以上戻る目安</li>
            <li>背景色: <span class="bg-rose-100 px-1">75+</span> / <span class="bg-amber-100 px-1">65+</span> / <span class="bg-yellow-50 px-1">55+</span></li>
            <li>「詳細→」をクリックすると、その条件で <a href="{{ route('analytics.recommend.conditions') }}" class="underline text-purple-600">条件指定抽出(B)</a> が自動で開きます</li>
        </ul>
    </div>
</div>
@endsection
