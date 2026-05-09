@extends('layouts.app')
@section('title', '推奨(血統+騎手+馬実績)')

@section('content')
<div class="space-y-4">
    <h1 class="text-xl sm:text-2xl font-bold text-gray-800">💡 推奨機能</h1>
    <p class="text-xs sm:text-sm text-gray-600">
        血統・騎手・馬の過去走を組み合わせたスコアリングで「狙い目」を自動抽出します。
        重み付けはユーザー設定で自由にカスタマイズできます。
    </p>

    @include('analytics.recommend._nav', ['active' => 'index'])

    {{-- 現在の重み設定サマリ --}}
    <div class="bg-white rounded-lg shadow p-4">
        <div class="flex items-center justify-between mb-3">
            <h2 class="font-semibold text-gray-700">⚙️ 現在の重み設定</h2>
            <a href="{{ route('analytics.recommend.settings') }}"
               class="text-xs text-amber-600 hover:underline">設定を変更 →</a>
        </div>

        @php
            $w = $settings['weights'];
            $sum = array_sum($w);
            $bars = [
                'pedigree' => ['label' => '🧬 血統(父60%/母父40%)', 'color' => 'bg-purple-500'],
                'jockey'   => ['label' => '👤 騎手 × 条件',         'color' => 'bg-sky-500'],
                'horse'    => ['label' => '🐎 馬の過去走',           'color' => 'bg-rose-500'],
                'roi'      => ['label' => '💰 回収率ボーナス',         'color' => 'bg-amber-500'],
            ];
        @endphp

        <div class="space-y-2">
            @foreach ($bars as $k => $b)
                @php
                    $v = (int) $w[$k];
                    $pct = $sum > 0 ? round($v / $sum * 100, 1) : 0;
                @endphp
                <div>
                    <div class="flex items-center justify-between text-xs mb-1">
                        <span class="font-medium text-gray-700">{{ $b['label'] }}</span>
                        <span class="text-gray-500">重み {{ $v }} / 合成比 <span class="font-bold text-gray-700">{{ $pct }}%</span></span>
                    </div>
                    <div class="h-2 bg-gray-100 rounded overflow-hidden">
                        <div class="h-full {{ $b['color'] }}" style="width: {{ $pct }}%"></div>
                    </div>
                </div>
            @endforeach
        </div>

        <div class="mt-4 text-xs text-gray-600 flex flex-wrap gap-x-6 gap-y-1">
            <span>最低出走数: <span class="font-bold text-gray-800">{{ $settings['min_runs'] }}</span> 回</span>
            <span>合計重み: <span class="font-bold text-gray-800">{{ $sum }}</span></span>
            @if ($sum === 0)
                <span class="text-rose-600 font-bold">⚠ 重み合計が0のためスコアは常に0になります</span>
            @endif
        </div>
    </div>

    {{-- 3つの入口の案内カード --}}
    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
        {{-- A: 出馬表ベース --}}
        <div class="bg-white rounded-lg shadow border-l-4 border-rose-400 p-5 opacity-60">
            <div class="flex items-start justify-between mb-2">
                <h3 class="font-bold text-gray-800">🐎 出馬表ベース推奨</h3>
                <span class="text-[10px] bg-gray-300 text-white px-1.5 py-0.5 rounded">Phase 3</span>
            </div>
            <p class="text-xs text-gray-600 mb-3">
                これから走るレースを選択すると、各馬を血統・騎手・過去走でスコアリングし
                <strong>◎○▲△☆</strong>の印を自動付与します。
            </p>
            <ul class="text-xs text-gray-500 space-y-0.5 list-disc list-inside">
                <li>馬ごとのサブスコア内訳バー</li>
                <li>推奨馬券組み合わせ(◎-○、◎-○-▲)</li>
                <li>推奨理由の根拠表示</li>
            </ul>
            <div class="mt-3 text-xs text-gray-400">準備中(Phase 3で実装予定)</div>
        </div>

        {{-- B: 条件指定 --}}
        <div class="bg-white rounded-lg shadow border-l-4 border-purple-400 p-5 opacity-60">
            <div class="flex items-start justify-between mb-2">
                <h3 class="font-bold text-gray-800">🎯 条件指定で狙い目抽出</h3>
                <span class="text-[10px] bg-gray-300 text-white px-1.5 py-0.5 rounded">Phase 2</span>
            </div>
            <p class="text-xs text-gray-600 mb-3">
                競馬場・トラック・距離・馬場状態を指定して、その条件で
                <strong>狙うべき血統(父・母父)</strong>を一覧表示します。
            </p>
            <ul class="text-xs text-gray-500 space-y-0.5 list-disc list-inside">
                <li>父TOP30・母父TOP30 を色付き表で</li>
                <li>父×母父のクロス表(ニックス発見)</li>
                <li>該当条件のお宝血統を即特定</li>
            </ul>
            <div class="mt-3 text-xs text-gray-400">準備中(Phase 2で実装予定)</div>
        </div>

        {{-- C: 全条件スキャン --}}
        <div class="bg-white rounded-lg shadow border-l-4 border-emerald-400 p-5 opacity-60">
            <div class="flex items-start justify-between mb-2">
                <h3 class="font-bold text-gray-800">🔍 全条件スキャン</h3>
                <span class="text-[10px] bg-gray-300 text-white px-1.5 py-0.5 rounded">Phase 2</span>
            </div>
            <p class="text-xs text-gray-600 mb-3">
                DB全体から<strong>「複勝回収率100%超え かつ 出走N回以上」</strong>の
                美味しい組み合わせを総当たりで発掘します。
            </p>
            <ul class="text-xs text-gray-500 space-y-0.5 list-disc list-inside">
                <li>10場 × 2トラック × 5距離 × 4馬場 を総当たり</li>
                <li>父TOP30をスキャン → お宝発見ボード</li>
                <li>1行クリックで条件指定(B)へジャンプ</li>
            </ul>
            <div class="mt-3 text-xs text-gray-400">準備中(Phase 2で実装予定)</div>
        </div>
    </div>

    {{-- スコアリングの説明 --}}
    <div class="bg-white rounded-lg shadow p-4">
        <h2 class="font-semibold text-gray-700 mb-3">📐 スコアリングの仕組み</h2>
        <div class="text-xs sm:text-sm text-gray-700 space-y-3">
            <p>
                各馬・各条件に対して以下の4つのサブスコア(0〜100点)を算出し、ユーザー指定の重みで線形合成して総合スコア(0〜100点)を出します。
            </p>

            <div class="bg-gray-50 rounded p-3 font-mono text-[11px] sm:text-xs leading-relaxed">
                total_score =
                  <span class="text-purple-600">w_pedigree</span> × pedigree_score
                + <span class="text-sky-600">w_jockey</span>   × jockey_score<br>
                &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;
                + <span class="text-rose-600">w_horse</span>    × horse_score
                + <span class="text-amber-600">w_roi</span>      × roi_bonus
                <br>
                <span class="text-gray-500">(各重みの合計で割って正規化)</span>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-3 mt-2">
                <div class="border rounded p-3">
                    <div class="font-bold text-purple-700 mb-1">🧬 pedigree_score</div>
                    <p class="text-xs text-gray-600">
                        父の該当条件での複勝率と母父の複勝率を <strong>父60% + 母父40%</strong> で合成。
                        複勝率50%で100点(線形マッピング ×2)。
                    </p>
                </div>
                <div class="border rounded p-3">
                    <div class="font-bold text-sky-700 mb-1">👤 jockey_score</div>
                    <p class="text-xs text-gray-600">
                        その騎手の<strong>同競馬場×同トラック</strong>での複勝率を 0〜100点に変換。
                        距離や馬場は加味しない(サンプル枯渇防止)。
                    </p>
                </div>
                <div class="border rounded p-3">
                    <div class="font-bold text-rose-700 mb-1">🐎 horse_score</div>
                    <p class="text-xs text-gray-600">
                        馬の<strong>同距離±200m or 同トラック</strong>の複勝率(0〜80点)に、直近5走の3着内回数 ×4(0〜20点)を加点。
                        現在好調補正を含む。
                    </p>
                </div>
                <div class="border rounded p-3">
                    <div class="font-bold text-amber-700 mb-1">💰 roi_bonus</div>
                    <p class="text-xs text-gray-600">
                        父系の<strong>複勝回収率</strong>が100%を超えた分を <code>(複回-100)×0.5</code> で 0〜100に変換。
                        妙味血統馬を後押しする補正。
                    </p>
                </div>
            </div>

            <div class="mt-3">
                <div class="font-bold text-gray-700 mb-1">🏷️ 推奨印の付与ルール(出馬表推奨用)</div>
                <table class="text-xs border-collapse">
                    <tr class="border-b">
                        <td class="px-2 py-1 font-bold text-2xl text-rose-700">◎</td>
                        <td class="px-3 py-1">本命: 1位 かつ 70点以上</td>
                    </tr>
                    <tr class="border-b">
                        <td class="px-2 py-1 font-bold text-2xl text-amber-700">○</td>
                        <td class="px-3 py-1">対抗: 2位 かつ 60点以上</td>
                    </tr>
                    <tr class="border-b">
                        <td class="px-2 py-1 font-bold text-2xl text-purple-700">▲</td>
                        <td class="px-3 py-1">単穴: 3位 かつ 55点以上</td>
                    </tr>
                    <tr class="border-b">
                        <td class="px-2 py-1 font-bold text-2xl text-sky-700">△</td>
                        <td class="px-3 py-1">連下: 4-5位 かつ 50点以上</td>
                    </tr>
                    <tr>
                        <td class="px-2 py-1 font-bold text-2xl text-emerald-700">☆</td>
                        <td class="px-3 py-1">妙味: TOP外でもROIサブスコア50超えなら穴候補</td>
                    </tr>
                </table>
            </div>
        </div>
    </div>

    {{-- 既存血統分析へのリンク --}}
    <div class="bg-amber-50 border border-amber-200 rounded-lg p-3 text-xs text-amber-900">
        💡 推奨機能で使うスコアリングのベースとなる集計値は、
        <a href="{{ route('analytics.pedigree.overview') }}" class="font-bold underline hover:text-amber-700">血統分析</a>
        ページから直接眺められます。サンプル数に違和感があれば、まず血統分析でデータ量を確認してください。
    </div>
</div>
@endsection
