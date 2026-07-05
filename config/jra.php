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
    | EV ラベルの閾値(10段階, Phase EV-4):
    |   グレードは S+ (最高) 〜 E (最低) の10段階。
    |   各段階の下限 EV 値を 'grades' に降順で列挙し、該当する最初の
    |   段階(EV がその下限以上)を採用する。閾値は env でチューニング可能。
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

        // EV ラベル閾値 (10段階, 降順)
        //   S+ : 破格の妙味 (EV >= 0.50)
        //   S  : 大きな妙味 (EV >= 0.35)
        //   A+ : かなりお得 (EV >= 0.20)
        //   A  : お得       (EV >= 0.10)
        //   B+ : やや妙味   (EV >= 0.02)
        //   B  : 均衡やや妙味(EV >= -0.05)
        //   C+ : ほぼ均衡   (EV >= -0.12)
        //   C  : やや過大   (EV >= -0.20)
        //   D  : 過大評価   (EV >= -0.35)
        //   E  : 大幅過大   (それ以外)
        'label_thresholds_v2' => [
            'sp' => (float) env('JRA_EV_THR_SP', 0.50),
            's'  => (float) env('JRA_EV_THR_S',  0.35),
            'ap' => (float) env('JRA_EV_THR_AP', 0.20),
            'a'  => (float) env('JRA_EV_THR_A',  0.10),
            'bp' => (float) env('JRA_EV_THR_BP', 0.02),
            'b'  => (float) env('JRA_EV_THR_B', -0.05),
            'cp' => (float) env('JRA_EV_THR_CP', -0.12),
            'c'  => (float) env('JRA_EV_THR_C', -0.20),
            'd'  => (float) env('JRA_EV_THR_D', -0.35),
        ],

        // 旧5段階閾値(後方互換用に残置。label_thresholds_v2 が優先される)
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
    | (netkeiba 側で確定オッズに固定され、繰り返し叩いても値が変わらないため)
    |
    | Phase EV-3 精密化:
    |   優先順位:
    |     1) races.post_time (DATETIME) があれば "post_time + N分" で判定 (精密)
    |     2) 無ければ "race_date 00:00 + N分" で判定 (粗い後方互換)
    |     3) minutes_after_post が null/0/負値なら判定自体を無効化 (常時許可)
    |
    | デフォルト 90 分:
    |   発走 → 数分でオッズ確定 → その後も 30 分程度は netkeiba 側で値取得可能。
    |   保守的に 90 分置くと確定値の取込漏れが起きにくい。
    */
    'odds_capture' => [
        'minutes_after_post' => env('JRA_ODDS_CAPTURE_MINUTES_AFTER_POST', 90),

        // フロントの自動更新間隔 (秒)。ブラウザ側の setInterval 用。
        // 短くしすぎると netkeiba への負荷が増えるため 30-120 秒推奨。
        'auto_refresh_seconds' => (int) env('JRA_ODDS_AUTO_REFRESH_SECONDS', 60),
    ],

];
