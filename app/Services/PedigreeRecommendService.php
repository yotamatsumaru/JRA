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
        'pedigree' => 20,   // 血統(父60%/母父40%合成)
        'jockey'   => 20,   // 騎手×条件
        'horse'    => 25,   // 馬の過去走
        'roi'      => 10,   // 父複勝回収率の妙味ボーナス
        'frame'    => 10,   // 枠順 × 同コースの複勝率
        'course'   => 10,   // 同コース(track_type×direction)での馬の複勝率
        'style'    => 5,    // 脚質 × 想定ペース
    ];

    /** スコアキー一覧(combine/saveSettings 等で利用) */
    public const SCORE_KEYS = ['pedigree','jockey','horse','roi','frame','course','style'];

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
        foreach (self::SCORE_KEYS as $k) {
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
        if (!$jockeyId) return ['score' => 0, 'runs' => 0, 'show_rate' => 0, 'win_rate' => 0, 'scope' => 'none'];

        $key = 'rec:jockey:' . md5($jockeyId . '|' . json_encode($cond) . '|' . $minRuns);
        return Cache::remember($key, self::CACHE_TTL, function () use ($jockeyId, $cond, $minRuns) {
            // ステップフォールバック: 厳→緩 の順に試し、最初に minRuns を満たした集計を使う
            //   1) venue + track_type
            //   2) track_type のみ
            //   3) 全条件
            $scopes = [
                ['venue_id' => $cond['venue_id'] ?? null, 'track_type' => $cond['track_type'] ?? null, 'label' => 'venue+track'],
                ['venue_id' => null,                       'track_type' => $cond['track_type'] ?? null, 'label' => 'track'],
                ['venue_id' => null,                       'track_type' => null,                        'label' => 'all'],
            ];

            $best = null;
            foreach ($scopes as $sc) {
                $q = DB::table('race_results')
                    ->join('races', 'races.id', '=', 'race_results.race_id')
                    ->where('race_results.jockey_id', $jockeyId)
                    ->whereNotNull('race_results.finish_position_int');
                if (!empty($sc['venue_id']))   $q->where('races.venue_id', $sc['venue_id']);
                if (!empty($sc['track_type'])) $q->where('races.track_type', $sc['track_type']);

                $row = $q->selectRaw("count(*) as runs,
                    SUM(CASE WHEN finish_position_int=1 THEN 1 ELSE 0 END) as wins,
                    SUM(CASE WHEN finish_position_int<=3 THEN 1 ELSE 0 END) as shows
                ")->first();
                $runs = (int) ($row->runs ?? 0);
                if ($runs >= $minRuns) {
                    $res = $this->packShowSubscore($row, $minRuns);
                    $res['scope'] = $sc['label'];
                    return $res;
                }
                // 最後に全条件の結果は退避(緩めても満たないとき表示用)
                if ($sc['label'] === 'all') {
                    $best = $this->packShowSubscore($row, max(1, min($minRuns, 3)));
                    $best['scope'] = 'all_relaxed';
                }
            }
            // すべて満たないが、最低3走以上あれば緩い基準で評価
            return $best ?? ['score' => 0, 'runs' => 0, 'show_rate' => 0, 'win_rate' => 0, 'scope' => 'insufficient'];
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
            // ステップフォールバック: 距離±200 → ±400 → track のみ → 全レース
            $track = $cond['track_type'] ?? null;
            $dist  = (int) ($cond['distance'] ?? 0);
            $scopes = [
                ['track' => $track, 'dist_range' => $dist > 0 ? [$dist - 200, $dist + 200] : null, 'label' => 'track+dist200'],
                ['track' => $track, 'dist_range' => $dist > 0 ? [$dist - 400, $dist + 400] : null, 'label' => 'track+dist400'],
                ['track' => $track, 'dist_range' => null,                                          'label' => 'track'],
                ['track' => null,   'dist_range' => null,                                          'label' => 'all'],
            ];

            // 個体馬は最低3走で評価(若駒救済)
            $individualMin = max(1, min($minRuns, 3));
            $base = null;
            $usedScope = 'insufficient';
            foreach ($scopes as $sc) {
                $q = DB::table('race_results')
                    ->join('races', 'races.id', '=', 'race_results.race_id')
                    ->where('race_results.horse_id', $horseId)
                    ->whereNotNull('race_results.finish_position_int');
                if (!empty($sc['track']))      $q->where('races.track_type', $sc['track']);
                if (!empty($sc['dist_range'])) $q->whereBetween('races.distance', $sc['dist_range']);

                $row = $q->selectRaw("count(*) as runs,
                    SUM(CASE WHEN finish_position_int=1 THEN 1 ELSE 0 END) as wins,
                    SUM(CASE WHEN finish_position_int<=3 THEN 1 ELSE 0 END) as shows
                ")->first();
                $runs = (int) ($row->runs ?? 0);
                if ($runs >= $individualMin) {
                    $base = $this->packShowSubscore($row, $individualMin);
                    $usedScope = $sc['label'];
                    break;
                }
            }
            if (!$base) {
                // 過去走0件(新馬等) → 0点
                return [
                    'score'        => 0,
                    'base_score'   => 0,
                    'runs'         => 0,
                    'show_rate'    => 0,
                    'win_rate'     => 0,
                    'recent_shows' => 0,
                    'recent_bonus' => 0,
                    'scope'        => 'no_history',
                ];
            }

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
                'scope'        => $usedScope,
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
     * 枠順スコア(同枠 × 同 track_type × 同距離±200 の過去複勝率)
     *
     * 枠順統計は条件馬個体に依存しないので、サンプルは十分得やすい。
     *
     * @param int|null $frame   枠番(1-8)
     * @param array    $cond    ['venue_id', 'track_type', 'distance']
     * @param int      $minRuns 最小出走数(緩和あり)
     */
    public function frameScore(?int $frame, array $cond, int $minRuns): array
    {
        if (!$frame || $frame < 1 || $frame > 8) {
            return ['score' => 0, 'runs' => 0, 'show_rate' => 0, 'win_rate' => 0, 'scope' => 'none'];
        }

        $key = 'rec:frame:' . md5($frame . '|' . json_encode($cond) . '|' . $minRuns);
        return Cache::remember($key, self::CACHE_TTL, function () use ($frame, $cond, $minRuns) {
            $track = $cond['track_type'] ?? null;
            $venue = $cond['venue_id']   ?? null;
            $dist  = (int) ($cond['distance'] ?? 0);

            // 厳→緩
            $scopes = [
                ['venue' => $venue, 'track' => $track, 'dist_range' => $dist > 0 ? [$dist-200, $dist+200] : null, 'label' => 'venue+track+dist'],
                ['venue' => null,   'track' => $track, 'dist_range' => $dist > 0 ? [$dist-200, $dist+200] : null, 'label' => 'track+dist'],
                ['venue' => null,   'track' => $track, 'dist_range' => null,                                      'label' => 'track'],
                ['venue' => null,   'track' => null,   'dist_range' => null,                                      'label' => 'all'],
            ];

            $fallback = null;
            foreach ($scopes as $sc) {
                $q = DB::table('race_results')
                    ->join('races', 'races.id', '=', 'race_results.race_id')
                    ->where('race_results.frame_number', $frame)
                    ->whereNotNull('race_results.finish_position_int');
                if (!empty($sc['venue']))      $q->where('races.venue_id', $sc['venue']);
                if (!empty($sc['track']))      $q->where('races.track_type', $sc['track']);
                if (!empty($sc['dist_range'])) $q->whereBetween('races.distance', $sc['dist_range']);

                $row = $q->selectRaw("count(*) as runs,
                    SUM(CASE WHEN finish_position_int=1 THEN 1 ELSE 0 END) as wins,
                    SUM(CASE WHEN finish_position_int<=3 THEN 1 ELSE 0 END) as shows
                ")->first();
                $runs = (int) ($row->runs ?? 0);
                if ($runs >= $minRuns) {
                    $res = $this->packShowSubscore($row, $minRuns);
                    $res['scope'] = $sc['label'];
                    return $res;
                }
                if ($sc['label'] === 'all') {
                    $fallback = $this->packShowSubscore($row, max(1, min($minRuns, 5)));
                    $fallback['scope'] = 'all_relaxed';
                }
            }
            return $fallback ?? ['score' => 0, 'runs' => 0, 'show_rate' => 0, 'win_rate' => 0, 'scope' => 'insufficient'];
        });
    }

    /**
     * コーススコア(同馬 × 同 track_type × 同 direction の過去複勝率)
     *
     * 「右回り/左回り」の得意不得意を捉える。距離は問わずサンプル確保を優先。
     *
     * @param int   $horseId
     * @param array $cond     ['track_type', 'direction']
     */
    public function courseScore(int $horseId, array $cond, int $minRuns): array
    {
        $dir = $cond['direction'] ?? null;
        $key = 'rec:course:' . md5($horseId . '|' . json_encode($cond) . '|' . $minRuns);
        return Cache::remember($key, self::CACHE_TTL, function () use ($horseId, $cond, $minRuns, $dir) {
            $track = $cond['track_type'] ?? null;

            // 個体馬向け緩和: 最低3走でも評価
            $indMin = max(1, min($minRuns, 3));

            $scopes = [
                ['track' => $track, 'direction' => $dir,  'label' => 'track+dir'],
                ['track' => $track, 'direction' => null,  'label' => 'track'],
                ['track' => null,   'direction' => null,  'label' => 'all'],
            ];

            foreach ($scopes as $sc) {
                $q = DB::table('race_results')
                    ->join('races', 'races.id', '=', 'race_results.race_id')
                    ->where('race_results.horse_id', $horseId)
                    ->whereNotNull('race_results.finish_position_int');
                if (!empty($sc['track']))     $q->where('races.track_type', $sc['track']);
                if (!empty($sc['direction'])) $q->where('races.direction', $sc['direction']);

                $row = $q->selectRaw("count(*) as runs,
                    SUM(CASE WHEN finish_position_int=1 THEN 1 ELSE 0 END) as wins,
                    SUM(CASE WHEN finish_position_int<=3 THEN 1 ELSE 0 END) as shows
                ")->first();
                $runs = (int) ($row->runs ?? 0);
                if ($runs >= $indMin) {
                    $res = $this->packShowSubscore($row, $indMin);
                    $res['scope'] = $sc['label'];
                    return $res;
                }
            }
            return ['score' => 0, 'runs' => 0, 'show_rate' => 0, 'win_rate' => 0, 'scope' => 'no_history'];
        });
    }

    /**
     * 脚質スコア(脚質 × 想定ペース のマッチング)
     *
     * 想定ペースは「逃げ宣言頭数」で推定:
     *   - 逃げ馬2頭以上 → ハイペース (差し/追い込み有利)
     *   - 逃げ馬1頭以下 → スローペース (逃げ/先行有利)
     *
     * マッピング(想定ペース × 脚質):
     *   slow(スロー)   × 逃     = 85
     *   slow           × 先     = 75
     *   slow           × 差     = 35
     *   slow           × 追/マ  = 25
     *   fast(ハイ)     × 逃     = 25
     *   fast           × 先     = 40
     *   fast           × 差     = 80
     *   fast           × 追/マ  = 75
     *
     * @param string|null $style   '逃'|'先'|'差'|'追'|'マ' (race_results.running_style)
     * @param string      $pace    'slow'|'fast'
     */
    public function styleScore(?string $style, string $pace): array
    {
        if (!$style) {
            return ['score' => 0, 'style' => null, 'pace' => $pace, 'scope' => 'unknown'];
        }
        // 先頭1文字で判定(running_style は「逃」「先」「差」「追込」「マクリ」等のため)
        $head = mb_substr($style, 0, 1);
        $matrix = [
            'slow' => ['逃' => 85, '先' => 75, '差' => 35, '追' => 25, 'マ' => 25],
            'fast' => ['逃' => 25, '先' => 40, '差' => 80, '追' => 75, 'マ' => 75],
        ];
        $score = $matrix[$pace][$head] ?? 50;  // 未知の脚質は中立
        return [
            'score' => (float) $score,
            'style' => $style,
            'head'  => $head,
            'pace'  => $pace,
            'scope' => 'mapped',
        ];
    }

    /**
     * 想定ペース推定(逃げ宣言頭数で簡易判定)
     *
     * @param array<int,string|null> $stylesInRace  同一レースの全頭の running_style
     * @return 'slow'|'fast'
     */
    public function estimatePace(array $stylesInRace): string
    {
        $leaders = 0;
        foreach ($stylesInRace as $s) {
            if (!$s) continue;
            $head = mb_substr($s, 0, 1);
            if ($head === '逃') $leaders++;
        }
        return $leaders >= 2 ? 'fast' : 'slow';
    }

    /**
     * 馬1頭の総合スコア
     *
     * @param array $horse  ['id', 'father', 'mother_father', 'frame_number'?, 'running_style'?]
     * @param int|null $jockeyId
     * @param array $cond   ['venue_id', 'track_type', 'distance', 'course_condition', 'direction'?, 'pace'?]
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

        // 新規サブスコア
        $frame  = isset($horse['frame_number']) ? (int) $horse['frame_number'] : null;
        $frm    = $this->frameScore($frame, $cond, $minRuns);
        $crs    = $this->courseScore((int) $horse['id'], $cond, $minRuns);
        $pace   = $cond['pace'] ?? 'slow';
        $stl    = $this->styleScore($horse['running_style'] ?? null, $pace);

        $sub = [
            'pedigree' => $ped['score'],
            'jockey'   => $jky['score'],
            'horse'    => $hrs['score'],
            'roi'      => $roi['score'],
            'frame'    => $frm['score'],
            'course'   => $crs['score'],
            'style'    => $stl['score'],
        ];
        $total = $this->combine($sub, $weights);

        return [
            'total'    => $total,
            'sub'      => $sub,
            'pedigree' => $ped,
            'jockey'   => $jky,
            'horse'    => $hrs,
            'roi'      => $roi,
            'frame'    => $frm,
            'course'   => $crs,
            'style'    => $stl,
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

    // ====================================================================
    // Phase 2 (B): 条件指定での血統(父/母父)ランキング
    // ====================================================================

    /**
     * 指定条件下で、父(または母父)別に集計してスコアと回収率を返す。
     *
     * @param string $kind     'father' | 'mother_father'
     * @param array  $cond     ['venue_id'=>?, 'track_type'=>?, 'distance'=>?, 'distance_cat'=>?, 'course_condition'=>?]
     * @param int    $minRuns  最小出走数
     * @param int    $limit    上位何件
     * @return array{rows: array<int,object>, total_groups: int}
     */
    public function rankPedigreeByCondition(string $kind, array $cond, int $minRuns, int $limit = 30): array
    {
        $col = $kind === 'mother_father' ? 'horses.mother_father' : 'horses.father';

        $key = 'rec:rank:' . md5($col . '|' . json_encode($cond) . '|' . $minRuns . '|' . $limit);
        return Cache::remember($key, self::CACHE_TTL, function () use ($col, $cond, $minRuns, $limit) {
            $q = $this->buildPedigreeAggBaseQuery($col, $cond);

            $rows = $q->selectRaw("$col as name, " .
                "count(*) as runs, " .
                "SUM(CASE WHEN finish_position_int=1 THEN 1 ELSE 0 END) as wins, " .
                "SUM(CASE WHEN finish_position_int<=2 THEN 1 ELSE 0 END) as places, " .
                "SUM(CASE WHEN finish_position_int<=3 THEN 1 ELSE 0 END) as shows, " .
                "SUM(CASE WHEN finish_position_int=1 AND win_odds IS NOT NULL THEN win_odds*100 ELSE 0 END) as win_payout, " .
                "SUM(CASE WHEN win_odds IS NOT NULL THEN 100 ELSE 0 END) as win_stake, " .
                "SUM(CASE WHEN finish_position_int<=3 " .
                "         THEN ((COALESCE(place_odds_min,0)+COALESCE(place_odds_max,0))/2)*100 " .
                "         ELSE 0 END) as place_payout"
            )
            ->groupBy($col)
            ->havingRaw('runs >= ?', [$minRuns])
            ->orderByDesc('shows')  // 3着内回数で並べる(同率なら出走数多い方が信頼度高い)
            ->limit($limit)
            ->get();

            $decorated = [];
            foreach ($rows as $r) {
                $runs   = (int) $r->runs;
                $wins   = (int) $r->wins;
                $places = (int) $r->places;
                $shows  = (int) $r->shows;
                $winPayout   = (float) ($r->win_payout   ?? 0);
                $winStake    = (float) ($r->win_stake    ?? 0);
                $placePayout = (float) ($r->place_payout ?? 0);
                $placeStake  = $runs * 100;

                $showRate  = $runs > 0 ? round($shows  / $runs * 100, 1) : 0;
                $winRate   = $runs > 0 ? round($wins   / $runs * 100, 1) : 0;
                $placeRate = $runs > 0 ? round($places / $runs * 100, 1) : 0;
                $roiWin    = $winStake   > 0 ? round($winPayout   / $winStake   * 100, 1) : 0;
                $roiPlace  = $placeStake > 0 ? round($placePayout / $placeStake * 100, 1) : 0;

                // サブスコア
                $pedigreeScore = min(100, $showRate * 2);
                $roiScore      = max(0, min(100, ($roiPlace - 100) * 0.5));
                // 簡易合成スコア (条件抽出専用: 血統 70% + ROI 30%)
                $score = round($pedigreeScore * 0.7 + $roiScore * 0.3, 1);

                $decorated[] = (object) [
                    'name'           => $r->name,
                    'runs'           => $runs,
                    'wins'           => $wins,
                    'places'         => $places,
                    'shows'          => $shows,
                    'win_rate'       => $winRate,
                    'place_rate'     => $placeRate,
                    'show_rate'      => $showRate,
                    'roi_win'        => $roiWin,
                    'roi_place'      => $roiPlace,
                    'pedigree_score' => round($pedigreeScore, 1),
                    'roi_score'      => round($roiScore, 1),
                    'score'          => $score,
                ];
            }

            return ['rows' => $decorated, 'total_groups' => count($decorated)];
        });
    }

    /**
     * 父×母父クロス表(指定条件)。父TOP×母父TOPの組み合わせで複勝率を見る。
     *
     * @param array       $cond
     * @param int         $minRuns      クロス1セルの最小出走数
     * @param string[]    $fatherList   行に並べる父リスト
     * @param string[]    $mFatherList  列に並べる母父リスト
     * @return array<string, array<string, object>>  $cells[$father][$mFather] = {runs, shows, show_rate, roi_place}
     */
    public function pedigreeCrossByCondition(array $cond, int $minRuns, array $fatherList, array $mFatherList): array
    {
        if (!$fatherList || !$mFatherList) return [];

        $key = 'rec:cross:' . md5(json_encode($cond) . '|' . $minRuns . '|' . md5(implode(',', $fatherList)) . '|' . md5(implode(',', $mFatherList)));
        return Cache::remember($key, self::CACHE_TTL, function () use ($cond, $minRuns, $fatherList, $mFatherList) {
            $q = DB::table('race_results')
                ->join('horses', 'horses.id', '=', 'race_results.horse_id')
                ->join('races',  'races.id',  '=', 'race_results.race_id')
                ->whereNotNull('horses.father')
                ->whereNotNull('horses.mother_father')
                ->whereNotNull('race_results.finish_position_int')
                ->whereIn('horses.father', $fatherList)
                ->whereIn('horses.mother_father', $mFatherList);

            $this->applyCondToQuery($q, $cond);

            $rows = $q->selectRaw("horses.father as father, horses.mother_father as m_father, " .
                "count(*) as runs, " .
                "SUM(CASE WHEN finish_position_int<=3 THEN 1 ELSE 0 END) as shows, " .
                "SUM(CASE WHEN finish_position_int<=3 " .
                "         THEN ((COALESCE(place_odds_min,0)+COALESCE(place_odds_max,0))/2)*100 " .
                "         ELSE 0 END) as place_payout"
            )
            ->groupBy('horses.father', 'horses.mother_father')
            ->havingRaw('runs >= ?', [$minRuns])
            ->get();

            $cells = [];
            foreach ($rows as $r) {
                $runs   = (int) $r->runs;
                $shows  = (int) $r->shows;
                $showRate  = $runs > 0 ? round($shows / $runs * 100, 1) : 0;
                $roiPlace  = $runs > 0 ? round((float)$r->place_payout / ($runs * 100) * 100, 1) : 0;

                $cells[$r->father][$r->m_father] = (object) [
                    'runs'      => $runs,
                    'shows'     => $shows,
                    'show_rate' => $showRate,
                    'roi_place' => $roiPlace,
                ];
            }
            return $cells;
        });
    }

    // ====================================================================
    // Phase 2 (C): 全条件スキャン
    // ====================================================================

    /**
     * 全 (venue × track_type × distance_cat) を総当たりで、各条件下の TOP 父を抽出。
     * 「お宝」=「複勝率N%以上 かつ 複勝回収率100%超 かつ 出走N回以上」を優先表示。
     *
     * 重い処理のため、ベース集計をキャッシュした上で各条件の上位だけ返す。
     *
     * @param int  $minRuns        最小出走数(各セル)
     * @param int  $topPerCell     セルあたりの上位件数
     * @param bool $onlyPositive   true なら roi_place >= 100 のみ
     * @return array<int, object>  各セル {venue_name, venue_id, track_type, distance_cat, top_father, runs, show_rate, roi_place, score}
     */
    public function scanAllConditions(int $minRuns = 20, int $topPerCell = 1, bool $onlyPositive = false): array
    {
        $key = 'rec:scan:' . md5("$minRuns|$topPerCell|" . ($onlyPositive ? '1' : '0'));
        return Cache::remember($key, self::CACHE_TTL, function () use ($minRuns, $topPerCell, $onlyPositive) {

            // venues 一覧
            $venues = DB::table('venues')->orderBy('id')->get(['id', 'name']);

            $distCats = ['短距離', 'マイル', '中距離', '中長距離', '長距離'];
            $tracks   = ['芝', 'ダート'];

            // 一度のクエリで venue×track×dist_cat×father の集計を取り、PHP 側でセル毎に整列
            $rows = DB::table('race_results')
                ->join('horses', 'horses.id', '=', 'race_results.horse_id')
                ->join('races',  'races.id',  '=', 'race_results.race_id')
                ->whereNotNull('horses.father')
                ->whereNotNull('race_results.finish_position_int')
                ->whereIn('races.track_type', $tracks)
                ->selectRaw("races.venue_id as venue_id, " .
                    "races.track_type as track_type, " .
                    "CASE " .
                    "  WHEN races.distance < 1400 THEN '短距離' " .
                    "  WHEN races.distance < 1800 THEN 'マイル' " .
                    "  WHEN races.distance < 2200 THEN '中距離' " .
                    "  WHEN races.distance < 2600 THEN '中長距離' " .
                    "  ELSE '長距離' " .
                    "END as dist_cat, " .
                    "horses.father as father, " .
                    "count(*) as runs, " .
                    "SUM(CASE WHEN finish_position_int=1 THEN 1 ELSE 0 END) as wins, " .
                    "SUM(CASE WHEN finish_position_int<=3 THEN 1 ELSE 0 END) as shows, " .
                    "SUM(CASE WHEN finish_position_int<=3 " .
                    "         THEN ((COALESCE(place_odds_min,0)+COALESCE(place_odds_max,0))/2)*100 " .
                    "         ELSE 0 END) as place_payout"
                )
                ->groupBy('races.venue_id', 'races.track_type', 'dist_cat', 'horses.father')
                ->havingRaw('runs >= ?', [$minRuns])
                ->get();

            // セル毎にバケット化
            $venueMap = [];
            foreach ($venues as $v) $venueMap[$v->id] = $v->name;

            $bucket = []; // [venue_id][track][dist_cat] = []
            foreach ($rows as $r) {
                $runs      = (int) $r->runs;
                $wins      = (int) $r->wins;
                $shows     = (int) $r->shows;
                $showRate  = $runs > 0 ? round($shows / $runs * 100, 1) : 0;
                $winRate   = $runs > 0 ? round($wins  / $runs * 100, 1) : 0;
                $roiPlace  = $runs > 0 ? round((float)$r->place_payout / ($runs * 100) * 100, 1) : 0;

                if ($onlyPositive && $roiPlace < 100) continue;

                $pedigreeScore = min(100, $showRate * 2);
                $roiScore      = max(0, min(100, ($roiPlace - 100) * 0.5));
                $score         = round($pedigreeScore * 0.7 + $roiScore * 0.3, 1);

                $bucket[$r->venue_id][$r->track_type][$r->dist_cat][] = (object) [
                    'father'     => $r->father,
                    'runs'       => $runs,
                    'wins'       => $wins,
                    'shows'      => $shows,
                    'win_rate'   => $winRate,
                    'show_rate'  => $showRate,
                    'roi_place'  => $roiPlace,
                    'score'      => $score,
                ];
            }

            // 各セル内でスコア順に並べ、topPerCell 件まで採用
            $out = [];
            foreach ($venues as $v) {
                foreach ($tracks as $t) {
                    foreach ($distCats as $d) {
                        $list = $bucket[$v->id][$t][$d] ?? [];
                        if (!$list) continue;
                        usort($list, fn($a, $b) => $b->score <=> $a->score);
                        foreach (array_slice($list, 0, $topPerCell) as $top) {
                            $out[] = (object) [
                                'venue_id'     => (int) $v->id,
                                'venue_name'   => $v->name,
                                'track_type'   => $t,
                                'distance_cat' => $d,
                                'top_father'   => $top->father,
                                'runs'         => $top->runs,
                                'wins'         => $top->wins,
                                'shows'        => $top->shows,
                                'win_rate'     => $top->win_rate,
                                'show_rate'    => $top->show_rate,
                                'roi_place'    => $top->roi_place,
                                'score'        => $top->score,
                            ];
                        }
                    }
                }
            }
            // 全体をスコア降順
            usort($out, fn($a, $b) => $b->score <=> $a->score);
            return $out;
        });
    }

    /**
     * 競馬場リスト(条件指定フォームのプルダウン用)
     */
    public function venuesForSelect(): array
    {
        return Cache::remember('rec:venues', self::CACHE_TTL, function () {
            return DB::table('venues')->orderBy('id')->get(['id', 'name'])->toArray();
        });
    }

    // ====================================================================
    // 内部ヘルパ(Phase 2 用)
    // ====================================================================

    /**
     * 父or母父集計の共通ベースクエリ(条件適用済み)
     */
    private function buildPedigreeAggBaseQuery(string $col, array $cond)
    {
        $q = DB::table('race_results')
            ->join('horses', 'horses.id', '=', 'race_results.horse_id')
            ->join('races',  'races.id',  '=', 'race_results.race_id')
            ->whereNotNull($col)
            ->whereNotNull('race_results.finish_position_int');

        $this->applyCondToQuery($q, $cond);
        return $q;
    }

    /**
     * 条件を任意のクエリに適用する(共通)
     */
    private function applyCondToQuery($q, array $cond): void
    {
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
    }
}
