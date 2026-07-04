<?php

/**
 * JRA Analyzer 固有の設定
 *
 * このファイルは EV 計算式・スコアキャッシュ・スクレイピングなど、
 * アプリ固有の調整可能パラメータを集約する。
 * env() 直接呼び出しを避け、config:cache 後も動作するようにする。
 */

return [

    /*
    |--------------------------------------------------------------------------
    | 期待値 (EV) 計算
    |--------------------------------------------------------------------------
    |
    | score_total (0-100) → 単勝勝率の換算式:
    |   prob = clamp(total / 100 * prob_coef, prob_min, prob_max)
    |
    | 複勝オッズ/複勝率の推定式 (経験則):
    |   place_prob  = clamp(win_prob * place_prob_ratio,         place_prob_min, place_prob_max)
    |   place_odds  = max(place_odds_floor, 1 + (win_odds - 1) * place_odds_coef)
    |
    | EV ラベルの閾値:
    |   ev >= great        → ◎お得
    |   ev >= good         → ○妙味
    |   ev >= neutral_low  → 中
    |   ev >= overrated    → △やや過大
    |   それ以外            → ✕過大評価
    */
    'ev' => [
        // 単勝勝率の換算係数
        'prob_coef' => (float) env('JRA_EV_PROB_COEF', 0.42),
        'prob_min'  => (float) env('JRA_EV_PROB_MIN',  0.01),
        'prob_max'  => (float) env('JRA_EV_PROB_MAX',  0.5),

        // 複勝率の推定係数 (単勝率 * place_prob_ratio)
        // JRA 全体平均で 複勝率 / 単勝率 ≒ 2.5〜2.8 なので 2.6 をデフォルト
        'place_prob_ratio' => (float) env('JRA_EV_PLACE_PROB_RATIO', 2.6),
        'place_prob_min'   => (float) env('JRA_EV_PLACE_PROB_MIN',   0.05),
        'place_prob_max'   => (float) env('JRA_EV_PLACE_PROB_MAX',   0.90),

        // 複勝オッズの推定係数: 1 + (単勝オッズ - 1) * place_odds_coef
        // JRA 全体で 複勝オッズ / 単勝オッズ ≒ 0.25〜0.35 なので 0.30 をデフォルト
        'place_odds_coef'  => (float) env('JRA_EV_PLACE_ODDS_COEF', 0.30),
        'place_odds_floor' => (float) env('JRA_EV_PLACE_ODDS_FLOOR', 1.1),

        // EV ラベル閾値
        'label_thresholds' => [
            'great'       => (float) env('JRA_EV_THR_GREAT',       0.30),
            'good'        => (float) env('JRA_EV_THR_GOOD',        0.10),
            'neutral_low' => (float) env('JRA_EV_THR_NEUTRAL_LOW', -0.10),
            'overrated'   => (float) env('JRA_EV_THR_OVERRATED',   -0.30),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | オッズ取得ガード
    |--------------------------------------------------------------------------
    |
    | 発走後 minutes_after_post 分以上経過したレースはオッズ取得不可とする。
    | (netkeiba 側でオッズ表示が無効化されるため)
    |
    | 値の解釈:
    |   - null / 0 / 負値: ガード無効 (レース後もいつでも取得試行可能)
    |   - 正の整数     : 開催日 00:00 + N分 を過ぎたら拒否
    |
    | ※ race_date は日付型 (時刻無し) のため、"厳密な発走時刻ベースのガード"
    |   ではなく "開催日ベースの粗いガード"。運用上は誤検知が多いので、
    |   デフォルトで null (無効) にしている。
    */
    'odds_capture' => [
        'minutes_after_post' => env('JRA_ODDS_CAPTURE_MINUTES_AFTER_POST', null),

        // フロントの自動更新間隔 (秒)。ブラウザ側の setInterval 用。
        // 短くしすぎると netkeiba への負荷が増えるため 30-120 秒推奨。
        'auto_refresh_seconds' => (int) env('JRA_ODDS_AUTO_REFRESH_SECONDS', 60),
    ],

];
