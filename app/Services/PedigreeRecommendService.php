<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * 推奨スコアリングサービス
 *
 * 父系/母父系・騎手・馬の過去走を組み合わせて1頭または1条件の総合スコアを算出する。
 * 各サブスコアは 0〜100 に正規化され、ユーザー設定の重み(weights)で線形合成される。
 *
 *   total = w_pedigree * pedigree_score
 *         + w_jockey   * jockey_score
 *         + w_horse    * horse_score
 *         + w_roi      * roi_bonus
 *
 * 設定はセッションに保存(DB変更なし)。getWeights()/saveWeights() で出し入れする。
 *
 * @see  Phase 1: 共通基盤(本クラス)+ index/settings UI
 *       Phase 2: B(条件指定) + C(全スキャン) で本クラスを利用
 *       Phase 3: A(出馬表ベース推奨) で本クラスを利用
 */
class PedigreeRecommendService
{
    /** デフォルト重み(合計を100に揃える運用) */
    public const DEFAULT_WEIGHTS = [
        'pedigree' => 30,   // 血統(父60%/母父40%合成)
        'jockey'   => 25,   // 騎手×条件
        'horse'    => 35,   // 馬の過去走
        'roi'      => 10,   // 父複勝回収率の妙味ボーナス
    ];

    /** 推奨印の閾値(スコア) */
    public const MARK_THRESHOLDS = [
        '◎' => 70,   // 本命
        '○' => 60,   // 対抗
        '▲' => 55,   // 単穴
        '△' => 50,   // 連下
        '☆' => 0,    // 妙味(ROIボーナス単独でTOP外でも候補)
    ];

    /** デフォルト最低出走数 */
    public const DEFAULT_MIN_RUNS = 10;

    /** キャッシュTTL(秒) - 5分 */
    public const CACHE_TTL = 300;

    /** セッションキー */
    public const SESSION_KEY = 'pedigree_recommend_settings';

    // ====================================================================
    // 設定の読み書き(セッション)
    // ====================================================================

    /**
     * 現在の設定を取得(セッション or デフォルト)
     *
     * @return array{weights: array<string,int>, min_runs: int}
     */
    public function getSettings(): array
    {
        $stored = session(self::SESSION_KEY, []);
        $weights = array_merge(self::DEFAULT_WEIGHTS, is_array($stored['weights'] ?? null) ? $stored['weights'] : []);
        $minRuns = (int) ($stored['min_runs'] ?? self::DEFAULT_MIN_RUNS);
        if ($minRuns < 1) $minRuns = self::DEFAULT_MIN_RUNS;

        // 重みは 0〜100 にクリップ
        foreach ($weights as $k => $v) {
            $weights[$k] = max(0, min(100, (int) $v));
        }
        return ['weights' => $weights, 'min_runs' => $minRuns];
    }

    /**
     * 設定をセッションに保存
     */
    public function saveSettings(array $weights, int $minRuns): void
    {
        $clean = [];
        foreach (self::DEFAULT_WEIGHTS as $k => $_) {
            $clean[$k] = max(0, min(100, (int) ($weights[$k] ?? self::DEFAULT_WEIGHTS[$k])));
        }
        session([self::SESSION_KEY => [
            'weights'  => $clean,
            'min_runs' => max(1, min(500, $minRuns)),
        ]]);
    }

    /**
     * 重みの合計(0除算ガード用)
     */
    public function weightSum(array $weights): int
    {
        return array_sum(array_map('intval', $weights));
    }

    /**
     * 線形合成。重みの合計が0なら 0 を返す。
     */
    public function combine(array $subScores, array $weights): float
    {
        $sum = $this->weightSum($weights);
        if ($sum <= 0) return 0.0;
        $acc = 0.0;
        foreach (['pedigree','jockey','horse','roi'] as $k) {
            $acc += ($weights[$k] ?? 0) * (float)($subScores[$k] ?? 0);
        }
        return round($acc / $sum, 2);
    }

    // ====================================================================
    // サブスコア(0〜100)算出
    // ====================================================================

    /**
     * 父系スコア(条件下での複勝率を 0〜100 に変換)
     * 複勝率 50% で 100点、0% で 0点になる線形マッピング(*2)。
     *
     * @param string|null $father        父名
     * @param array       $cond          条件 ['venue_id'=>?, 'track_type'=>?, 'distance'=>?, 'course_condition'=>?]
     * @param int         $minRuns       最低出走数
     * @return array{score:float, runs:int, show_rate:float, win_rate:float}
     */
    public function fatherScore(?string $father, array $cond, int $minRuns): array
    {
        if (!$father) return ['score' => 0, 'runs' => 0, 'show_rate' => 0, 'win_rate' => 0];

        $key = 'rec:father:' . md5($father . '|' . json_encode($cond) . '|' . $minRuns);
        return Cache::remember($key, self::CACHE_TTL, function () use ($father, $cond, $minRuns) {
            $row = $this->aggregateByPedigree('horses.father', $father, $cond);
            return $this->packPedigreeSubscore($row, $minRuns);
        });
    }

    /**
     * 母父系スコア
     */
    public function motherFatherScore(?string $mFather, array $cond, int $minRuns): array
    {
        if (!$mFather) return ['score' => 0, 'runs' => 0, 'show_rate' => 0, 'win_rate' => 0];

        $key = 'rec:mfather:' . md5($mFather . '|' . json_encode($cond) . '|' . $minRuns);
        return Cache::remember($key, self::CACHE_TTL, function () use ($mFather, $cond, $minRuns) {
            $row = $this->aggregateByPedigree('horses.mother_father', $mFather, $cond);
            return $this->packPedigreeSubscore($row, $minRuns);
        });
    }

    /**
     * 父60% + 母父40% 合成血統スコア
     */
    public function pedigreeScore(?string $father, ?string $mFather, array $cond, int $minRuns): array
    {
        $f = $this->fatherScore($father, $cond, $minRuns);
        $m = $this->motherFatherScore($mFather, $cond, $minRuns);

        // どちらもサンプル不足ならスコア0
        $hasF = $f['runs'] >= $minRuns;
        $hasM = $m['runs'] >= $minRuns;
        if (!$hasF && !$hasM) {
            return [
                'score'      => 0,
                'father'     => $f,
                'mother_father' => $m,
                'note'       => 'sample_insufficient',
            ];
        }

        // 片方しかない場合はそちらを100%
        $score = match (true) {
            $hasF && $hasM => $f['score'] * 0.6 + $m['score'] * 0.4,
            $hasF          => $f['score'],
            default        => $m['score'],
        };

        return [
            'score'         => round($score, 2),
            'father'        => $f,
            'mother_father' => $m,
            'note'          => $hasF && $hasM ? 'both' : ($hasF ? 'father_only' : 'mfather_only'),
        ];
    }

    /**
     * 騎手スコア(該当条件での複勝率 → 0〜100)
     *
     * @param int|null $jockeyId
     * @param array    $cond     ['venue_id'=>?, 'track_type'=>?]  距離/馬場は使わない(サンプル枯渇防止)
     */
    public function jockeyScore(?int $jockeyId, array $cond, int $minRuns): array
    {
        if (!$jockeyId) return ['score' => 0, 'runs' => 0, 'show_rate' => 0, 'win_rate' => 0];

        $key = 'rec:jockey:' . md5($jockeyId . '|' . json_encode($cond) . '|' . $minRuns);
        return Cache::remember($key, self::CACHE_TTL, function () use ($jockeyId, $cond, $minRuns) {
            $q = DB::table('race_results')
                ->join('races', 'races.id', '=', 'race_results.race_id')
                ->where('race_results.jockey_id', $jockeyId)
                ->whereNotNull('race_results.finish_position_int');

            if (!empty($cond['venue_id']))   $q->where('races.venue_id', $cond['venue_id']);
            if (!empty($cond['track_type'])) $q->where('races.track_type', $cond['track_type']);

            $row = $q->selectRaw("count(*) as runs,
                SUM(CASE WHEN finish_position_int=1 THEN 1 ELSE 0 END) as wins,
                SUM(CASE WHEN finish_position_int<=3 THEN 1 ELSE 0 END) as shows
            ")->first();

            return $this->packShowSubscore($row, $minRuns);
        });
    }

    /**
     * 馬スコア(過去走の複勝率 + 直近5走の3着内回数 を加味)
     *
     * 馬の過去走集計は同距離±200m or 同track_type で。
     * 直近5走の3着内が多いほど加点(現在好調補正)。
     *
     * @param int   $horseId
     * @param array $cond     ['track_type'=>?, 'distance'=>?]
     */
    public function horseScore(int $horseId, array $cond, int $minRuns): array
    {
        $key = 'rec:horse:' . md5($horseId . '|' . json_encode($cond) . '|' . $minRuns);
        return Cache::remember($key, self::CACHE_TTL, function () use ($horseId, $cond, $minRuns) {
            // 全体集計(同距離±200 or 同track)
            $q = DB::table('race_results')
                ->join('races', 'races.id', '=', 'race_results.race_id')
                ->where('race_results.horse_id', $horseId)
                ->whereNotNull('race_results.finish_position_int');

            if (!empty($cond['track_type'])) {
                $q->where('races.track_type', $cond['track_type']);
            }
            if (!empty($cond['distance'])) {
                $d = (int) $cond['distance'];
                $q->whereBetween('races.distance', [$d - 200, $d + 200]);
            }

            $row = $q->selectRaw("count(*) as runs,
                SUM(CASE WHEN finish_position_int=1 THEN 1 ELSE 0 END) as wins,
                SUM(CASE WHEN finish_position_int<=3 THEN 1 ELSE 0 END) as shows
            ")->first();
            $base = $this->packShowSubscore($row, max(1, min($minRuns, 3)));  // 馬個体は最低3走でも評価

            // 直近5走の3着内ボーナス(0〜20点)
            $recentShows = DB::table('race_results')
                ->join('races', 'races.id', '=', 'race_results.race_id')
                ->where('race_results.horse_id', $horseId)
                ->whereNotNull('race_results.finish_position_int')
                ->orderByDesc('races.race_date')
                ->limit(5)
                ->pluck('race_results.finish_position_int')
                ->filter(fn($p) => $p <= 3)
                ->count();
            $recentBonus = $recentShows * 4;  // 5/5なら +20

            $score = min(100, $base['score'] + $recentBonus);
            return [
                'score'        => round($score, 2),
                'base_score'   => $base['score'],
                'runs'         => $base['runs'],
                'show_rate'    => $base['show_rate'],
                'win_rate'     => $base['win_rate'],
                'recent_shows' => $recentShows,
                'recent_bonus' => $recentBonus,
            ];
        });
    }

    /**
     * 回収率ボーナス(父複勝回収率 が 100% を超えた分を加点)
     *
     * (複回% - 100) * 0.5 を 0〜100 にクリップ。
     * 妙味のある血統馬を後押しする補正。
     */
    public function roiBonus(?string $father, array $cond, int $minRuns): array
    {
        if (!$father) return ['score' => 0, 'roi_place' => 0, 'runs' => 0];

        $key = 'rec:roi:' . md5($father . '|' . json_encode($cond) . '|' . $minRuns);
        return Cache::remember($key, self::CACHE_TTL, function () use ($father, $cond, $minRuns) {
            $row = $this->aggregateByPedigree('horses.father', $father, $cond);

            $runs = (int)($row->runs ?? 0);
            if ($runs < $minRuns) {
                return ['score' => 0, 'roi_place' => 0, 'runs' => $runs];
            }
            $placeStake   = $runs * 100;
            $placePayout  = (float)($row->place_payout ?? 0);
            $roiPlace = $placeStake > 0 ? round($placePayout / $placeStake * 100, 1) : 0.0;

            $score = max(0, min(100, ($roiPlace - 100) * 0.5));
            return ['score' => round($score, 2), 'roi_place' => $roiPlace, 'runs' => $runs];
        });
    }

    /**
     * 馬1頭の総合スコア
     *
     * @param array $horse  ['id', 'father', 'mother_father']
     * @param int|null $jockeyId
     * @param array $cond   ['venue_id', 'track_type', 'distance', 'course_condition']
     * @param array $weights / int $minRuns  未指定なら設定から読む
     */
    public function evaluateHorse(array $horse, ?int $jockeyId, array $cond, ?array $weights = null, ?int $minRuns = null): array
    {
        $settings = $this->getSettings();
        $weights  = $weights  ?? $settings['weights'];
        $minRuns  = $minRuns  ?? $settings['min_runs'];

        $ped = $this->pedigreeScore($horse['father'] ?? null, $horse['mother_father'] ?? null, $cond, $minRuns);
        $jky = $this->jockeyScore($jockeyId, $cond, $minRuns);
        $hrs = $this->horseScore((int) $horse['id'], $cond, $minRuns);
        $roi = $this->roiBonus($horse['father'] ?? null, $cond, $minRuns);

        $sub = [
            'pedigree' => $ped['score'],
            'jockey'   => $jky['score'],
            'horse'    => $hrs['score'],
            'roi'      => $roi['score'],
        ];
        $total = $this->combine($sub, $weights);

        return [
            'total'    => $total,
            'sub'      => $sub,
            'pedigree' => $ped,
            'jockey'   => $jky,
            'horse'    => $hrs,
            'roi'      => $roi,
            'weights'  => $weights,
            'min_runs' => $minRuns,
        ];
    }

    /**
     * スコアから推奨印を決める
     *
     * @param float $total          スコア
     * @param int   $rank           1〜N (1=最高得点)
     * @param float $roiSubScore    ROIサブスコア(妙味判定用)
     */
    public function decideMark(float $total, int $rank, float $roiSubScore): string
    {
        if ($rank === 1 && $total >= self::MARK_THRESHOLDS['◎']) return '◎';
        if ($rank === 2 && $total >= self::MARK_THRESHOLDS['○']) return '○';
        if ($rank === 3 && $total >= self::MARK_THRESHOLDS['▲']) return '▲';
        if ($rank <= 5 && $total >= self::MARK_THRESHOLDS['△'])  return '△';
        if ($roiSubScore >= 50) return '☆';
        return '';
    }

    // ====================================================================
    // 内部ヘルパ
    // ====================================================================

    /**
     * 父or母父 × 条件 で集計(単一行を返す)
     *
     * @param string $col   'horses.father' | 'horses.mother_father'
     */
    private function aggregateByPedigree(string $col, string $value, array $cond): \stdClass
    {
        $q = DB::table('race_results')
            ->join('horses', 'horses.id', '=', 'race_results.horse_id')
            ->join('races',  'races.id',  '=', 'race_results.race_id')
            ->where($col, $value)
            ->whereNotNull('race_results.finish_position_int');

        if (!empty($cond['venue_id']))         $q->where('races.venue_id', $cond['venue_id']);
        if (!empty($cond['track_type']))       $q->where('races.track_type', $cond['track_type']);
        if (!empty($cond['course_condition'])) $q->where('races.course_condition', $cond['course_condition']);
        if (!empty($cond['distance'])) {
            $d = (int) $cond['distance'];
            $q->whereBetween('races.distance', [$d - 200, $d + 200]);
        } elseif (!empty($cond['distance_cat'])) {
            [$min, $max] = $this->distanceCatRange($cond['distance_cat']);
            $q->whereBetween('races.distance', [$min, $max]);
        }

        $row = $q->selectRaw("count(*) as runs,
            SUM(CASE WHEN finish_position_int=1 THEN 1 ELSE 0 END) as wins,
            SUM(CASE WHEN finish_position_int<=3 THEN 1 ELSE 0 END) as shows,
            SUM(CASE WHEN finish_position_int<=3
                     THEN ((COALESCE(place_odds_min,0)+COALESCE(place_odds_max,0))/2)*100
                     ELSE 0 END) as place_payout
        ")->first();

        return $row ?? (object) ['runs' => 0, 'wins' => 0, 'shows' => 0, 'place_payout' => 0];
    }

    /**
     * 集計行から複勝率ベースのサブスコアを構築
     */
    private function packShowSubscore($row, int $minRuns): array
    {
        $runs  = (int) ($row->runs  ?? 0);
        $wins  = (int) ($row->wins  ?? 0);
        $shows = (int) ($row->shows ?? 0);
        if ($runs < $minRuns) {
            return ['score' => 0, 'runs' => $runs, 'show_rate' => 0, 'win_rate' => 0];
        }
        $showRate = round($shows / $runs * 100, 1);
        $winRate  = round($wins  / $runs * 100, 1);
        // 複勝率 50% で 100点(*2 倍), 0%で 0点
        $score = min(100, $showRate * 2);
        return [
            'score'     => round($score, 2),
            'runs'      => $runs,
            'show_rate' => $showRate,
            'win_rate'  => $winRate,
        ];
    }

    /** 血統サブスコアもベースは複勝率(共通) */
    private function packPedigreeSubscore($row, int $minRuns): array
    {
        return $this->packShowSubscore($row, $minRuns);
    }

    /** 距離カテゴリ → [min,max] */
    private function distanceCatRange(string $cat): array
    {
        return match ($cat) {
            '短距離'   => [1000, 1399],
            'マイル'   => [1400, 1799],
            '中距離'   => [1800, 2199],
            '中長距離' => [2200, 2599],
            '長距離'   => [2600, 4000],
            default    => [1000, 4000],
        };
    }

    /**
     * 全キャッシュクリア(設定変更時に呼ぶと、最新の集計が即反映される)
     */
    public function clearCache(): void
    {
        // タグ未対応のドライバ(file/db)でも動く方式: 個別キーは TTL で自然失効に任せる。
        // ここでは何もしない(設定変更後5分以内は古い値が混じる可能性があるが許容)。
        // 将来 Redis を使う場合は Cache::tags(['rec'])->flush(); に切り替え可能。
    }
}
