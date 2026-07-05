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
        'pedigree' => 15,   // 血統(父60%/母父40%合成)
        'jockey'   => 20,   // 騎手×条件
        'horse'    => 30,   // 馬の過去走
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

        // キャッシュキーには騎手スコアに影響する条件のみ含める(pace 等は無関係)
        $cacheKey = [
            'venue_id'   => $cond['venue_id']   ?? null,
            'track_type' => $cond['track_type'] ?? null,
            'distance'   => $cond['distance']   ?? null,
        ];
        $key = 'rec:jockey:v3:' . md5($jockeyId . '|' . json_encode($cacheKey) . '|' . $minRuns);
        return Cache::remember($key, self::CACHE_TTL, function () use ($jockeyId, $cond, $minRuns) {
            // 騎手 ID 解決:
            // 出馬表取込みと過去レース取込みで同じ騎手が異なる jockeys.id に
            // 紐付くことがあるため、可能なすべての経路で「同一人物」の全 ID を集める。
            //   経路A) name 完全一致 (Round 9)
            //   経路B) netkeiba_id 完全一致 (Round 11)
            //   経路C) スペース類を除去した完全一致 (Round 11)
            //   経路D) 出馬表の name が過去レースの name の前方一致 (Round 12, 実データ確認)
            //          例: 出馬表='丹内' nk=01091 / 過去='丹内祐次' nk=null → 同一人物
            //          名前先頭の見習印(▲☆△◇)も除去してから比較する
            $row0 = DB::table('jockeys')->where('id', $jockeyId)
                ->select('name', 'netkeiba_id')->first();
            $name      = $row0->name        ?? null;
            $netkeiba  = $row0->netkeiba_id ?? null;

            $jockeyIds = [$jockeyId];

            // 経路A: name 完全一致
            if ($name !== null && $name !== '') {
                $ids = DB::table('jockeys')->where('name', $name)->pluck('id')->all();
                $jockeyIds = array_merge($jockeyIds, $ids);
            }
            // 経路B: netkeiba_id 完全一致
            if ($netkeiba !== null && $netkeiba !== '') {
                $ids = DB::table('jockeys')->where('netkeiba_id', $netkeiba)->pluck('id')->all();
                $jockeyIds = array_merge($jockeyIds, $ids);
            }
            // 経路C: 表記ゆれ(全/半角スペース除去後の一致)
            //   例: '国分優' と '国分 優作' は別文字列だが、normalize すれば 1 つに揃う場合がある。
            //   ここではあくまでスペース類だけ除去して比較する(過剰一致を避けるため2文字以上必須)。
            if ($name !== null && mb_strlen($name) >= 2) {
                $norm = preg_replace('/[\s\x{3000}]+/u', '', $name);
                if ($norm !== '' && $norm !== $name) {
                    $ids = DB::table('jockeys')
                        ->whereRaw("REPLACE(REPLACE(name,' ',''),'　','') = ?", [$norm])
                        ->pluck('id')->all();
                    $jockeyIds = array_merge($jockeyIds, $ids);
                }
            }

            // 経路D: 前方一致(prefix match) — 出馬表は省略名、過去レースはフルネームになっている
            //   netkeiba 出馬表は『丹内 / 大野 / 横山武 / 田辺 / 三浦 / 岩田望』のような
            //   姓のみ or 姓+下名前1文字 で取り込まれる。過去レースは『丹内祐次 / 横山武史』
            //   のようなフルネームで保存されている。両者を結びつけるため、
            //     出馬表の name + 任意の続き  ⊆  jockeys.name
            //   の前方一致でマージする。
            //   過剰一致回避のためのガード:
            //     - 印マーク(▲☆△◇○)を name 先頭から除去してから比較
            //     - スペース類も除去 (経路C と統合)
            //     - 前方一致の base 長さが 2 文字以上であること
            //     - base がカナの場合は、過去走数の最大のものを 1 件だけ採用
            //       (例: 'ゴンサルベ' が 'ゴンサル' に当たるなど、外国人騎手の表記揺れに限定)
            if ($name !== null) {
                // 印マーク(▲☆△◇○◎)が name 先頭に付いている場合、その騎手は
                // 見習または評価マーク付き表示。後ろが「苗字のみ(姓だけ)」だと
                // 別人と誤マージする恐れが高いので、印マーク付きはこの経路の対象外。
                $hasMark = (bool) preg_match('/^[▲☆△◇○◎\*]/u', $name);
                $cleanName = preg_replace('/^[▲☆△◇○◎\*]+/u', '', $name);
                $cleanName = preg_replace('/[\s\x{3000}]+/u', '', $cleanName);
                // 印付きで残り 2 文字以下(苗字相当)なら経路Dは行わない
                $tooShortForMarked = $hasMark && mb_strlen($cleanName) <= 2;
                if (!$tooShortForMarked && $cleanName !== '' && mb_strlen($cleanName) >= 2) {
                    // base 名で前方一致する jockeys を取得 (自分自身を含めて OK)
                    // ただし、その対象に過去走 (race_results) が紐付いている行のみを採用する
                    // (空の重複行を拾わないため)
                    $candidates = DB::table('jockeys as j')
                        ->leftJoin('race_results as r', 'r.jockey_id', '=', 'j.id')
                        ->where(function ($q) use ($cleanName) {
                            $q->where('j.name', 'like', $cleanName . '%')
                              ->orWhereRaw("REPLACE(REPLACE(j.name,' ',''),'　','') LIKE ?", [$cleanName . '%']);
                        })
                        ->whereNotNull('r.id')
                        ->groupBy('j.id', 'j.name', 'j.netkeiba_id')
                        ->select('j.id', 'j.name', 'j.netkeiba_id', DB::raw('COUNT(r.id) as runs'))
                        ->get();

                    if ($candidates->isNotEmpty()) {
                        // カナ騎手 (外国人) かどうかで挙動を分岐:
                        //   - 漢字を含む → 同字姓は基本的に同一人物の表記違いとみなして全部マージ
                        //     ただし字面の似た別人混入を完全には排除できないので、
                        //     『最も過去走数が多い 1 件』のみ採用する(=同一人物が一意)
                        //   - カナのみ → カナ表記の揺れ。同じく最多 runs 1 件のみ採用
                        $top = $candidates->sortByDesc('runs')->first();
                        if ($top && (int)$top->runs > 0) {
                            $jockeyIds[] = (int) $top->id;
                        }
                    }
                }
            }

            $jockeyIds = array_values(array_unique(array_map('intval', $jockeyIds)));

            // ステップフォールバック: 厳→緩 の順に試し、最初に minRuns を満たした集計を使う
            //   1) venue + track_type + distance±200m (距離得意)
            //   2) venue + track_type           (コース得意)
            //   3) track_type のみ               (馬場種得意)
            //   4) venue のみ                    (場所得意)
            //   5) 全条件                        (騎手の素の腕)
            $dist  = (int) ($cond['distance'] ?? 0);
            $scopes = [
                ['venue_id' => $cond['venue_id'] ?? null, 'track_type' => $cond['track_type'] ?? null, 'dist_range' => $dist > 0 ? [$dist-200, $dist+200] : null, 'label' => 'venue+track+dist'],
                ['venue_id' => $cond['venue_id'] ?? null, 'track_type' => $cond['track_type'] ?? null, 'dist_range' => null,                                     'label' => 'venue+track'],
                ['venue_id' => null,                       'track_type' => $cond['track_type'] ?? null, 'dist_range' => null,                                     'label' => 'track'],
                ['venue_id' => $cond['venue_id'] ?? null, 'track_type' => null,                        'dist_range' => null,                                     'label' => 'venue'],
                ['venue_id' => null,                       'track_type' => null,                        'dist_range' => null,                                     'label' => 'all'],
            ];

            // 騎手はそもそも何百走もあるはずなので、騎手専用の最低出走数を 3 まで緩和。
            // (中央デビュー直後の若手騎手でも 3 走以上はある)
            $hardMin = max(3, min($minRuns, 10));

            // 名寄せした jockey_ids のいずれかに合致する race_results を対象にする
            // → 1 件しかなければ自動的に等価なので速度劣化はない
            $isMulti = count($jockeyIds) > 1;

            $lastRow = null;
            $lastLabel = 'insufficient';
            foreach ($scopes as $sc) {
                $q = DB::table('race_results')
                    ->join('races', 'races.id', '=', 'race_results.race_id')
                    ->whereNotNull('race_results.finish_position_int');
                if ($isMulti) {
                    $q->whereIn('race_results.jockey_id', $jockeyIds);
                } else {
                    $q->where('race_results.jockey_id', $jockeyIds[0]);
                }
                if (!empty($sc['venue_id']))    $q->where('races.venue_id', $sc['venue_id']);
                if (!empty($sc['track_type']))  $q->where('races.track_type', $sc['track_type']);
                if (!empty($sc['dist_range'])) $q->whereBetween('races.distance', $sc['dist_range']);

                $row = $q->selectRaw("count(*) as runs,
                    SUM(CASE WHEN finish_position_int=1 THEN 1 ELSE 0 END) as wins,
                    SUM(CASE WHEN finish_position_int<=3 THEN 1 ELSE 0 END) as shows
                ")->first();
                $runs = (int) ($row->runs ?? 0);

                // minRuns 以上のサンプルが得られたらここで採用
                if ($runs >= $minRuns) {
                    $res = $this->packShowSubscore($row, $minRuns);
                    $res['scope'] = $sc['label'] . ($isMulti ? '+merged' : '');
                    return $res;
                }
                // 最低 hardMin 以上で「all」スコープなら、緩い基準で確定採用
                if ($sc['label'] === 'all' && $runs >= $hardMin) {
                    $res = $this->packShowSubscore($row, $hardMin);
                    $res['scope'] = 'all_relaxed' . ($isMulti ? '+merged' : '');
                    return $res;
                }
                $lastRow = $row;
                $lastLabel = $sc['label'];
            }

            // ここまで来たら全レース通算でも 3 走未満。若手騎手などのケース。
            $runs = (int) ($lastRow->runs ?? 0);
            if ($runs > 0) {
                $res = $this->packShowSubscore($lastRow, 1);  // 1 走でも評価
                $res['scope'] = 'minimal' . ($isMulti ? '+merged' : '');
                return $res;
            }
            return ['score' => 0, 'runs' => 0, 'show_rate' => 0, 'win_rate' => 0, 'scope' => 'no_history'];
        });
    }

    /** 馬個体評価: 直近走ほど重みを大きくする減衰係数(0.82^0=1, 0.82^1=0.82, ...) */
    private const HORSE_RECENT_DECAY = 0.82;

    /** 馬個体評価: 直近フォーム/上がり3F分析で遡る過去走の最大数 */
    private const HORSE_RECENT_LOOKBACK = 8;

    /**
     * 馬スコア(Phase EV-5: 多因子評価に拡張)
     *
     * 過去は「同条件複勝率×2」+「直近5走の3着内回数ボーナス(0〜20点)」のみだったが、
     * 以下の3要素を合成した、より精密な個体評価に拡張する。
     *
     *   1) base_score  : 同条件(距離±200等)の複勝率ベーススコア(0〜100, 従来通り)
     *   2) form_score  : 直近{最大8走}の「出走頭数に対する相対着順」を指数減衰加重平均
     *                    したスコア(0〜100)。直近走ほど重視し、同じ5着でも
     *                    18頭立てと8頭立てでは評価が変わる(相対着順で正規化)。
     *   3) speed_score : 直近走の上がり3Fが同レース平均よりどれだけ速いかを
     *                    指数減衰加重平均したスコア(0〜100, データ不足時は算出せず
     *                    base+form の2要素で正規化して合成する)。
     *
     *   score = base_score*0.50 + form_score*0.35 + speed_score*0.15  (速さデータありの場合)
     *   score = base_score*0.55 + form_score*0.45                     (速さデータなしの場合)
     *
     * @param int   $horseId
     * @param array $cond     ['track_type'=>?, 'distance'=>?]
     */
    public function horseScore(int $horseId, array $cond, int $minRuns): array
    {
        $cacheKey = [
            'track_type' => $cond['track_type'] ?? null,
            'distance'   => $cond['distance']   ?? null,
        ];
        $key = 'rec:horse:v3:' . md5($horseId . '|' . json_encode($cacheKey) . '|' . $minRuns);
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
                    'form_score'   => 0,
                    'speed_score'  => null,
                    'scope'        => 'no_history',
                ];
            }

            // ---- 直近走データ取得(頭数・着順・上がり3F・race_id) ----
            $recentRows = DB::table('race_results')
                ->join('races', 'races.id', '=', 'race_results.race_id')
                ->where('race_results.horse_id', $horseId)
                ->whereNotNull('race_results.finish_position_int')
                ->orderByDesc('races.race_date')
                ->limit(self::HORSE_RECENT_LOOKBACK)
                ->select([
                    'races.id as race_id',
                    'races.horses_count',
                    'race_results.finish_position_int',
                    'race_results.last_3f_seconds',
                ])
                ->get();

            // 直近5走の3着内回数(後方互換の表示用テキストに利用)
            $recentShows = $recentRows->take(5)
                ->filter(fn($r) => (int) $r->finish_position_int <= 3)
                ->count();

            // ---- 1) 加重直近フォームスコア(相対着順を指数減衰加重平均) ----
            $decay = self::HORSE_RECENT_DECAY;
            $wSum = 0.0; $qSum = 0.0;
            foreach ($recentRows as $i => $row) {
                $hc = (int) ($row->horses_count ?? 0);
                if ($hc < 2) $hc = 16; // 頭数不明時は JRA 平均的な16頭で近似
                $pos = (int) $row->finish_position_int;
                // 相対着順品質: 1着なら1.0、最下位なら0.0
                $quality = 1.0 - (($pos - 1) / max(1, $hc - 1));
                $quality = max(0.0, min(1.0, $quality));
                $w = $decay ** $i;
                $qSum += $quality * $w;
                $wSum += $w;
            }
            $formScore = $wSum > 0 ? round(($qSum / $wSum) * 100, 2) : (float) $base['score'];

            // ---- 2) 上がり3F相対スピードスコア(同レース平均との差を指数減衰加重平均) ----
            $raceIds = $recentRows->pluck('race_id')->unique()->values()->all();
            $raceAvgL3f = empty($raceIds) ? collect() : DB::table('race_results')
                ->whereIn('race_id', $raceIds)
                ->whereNotNull('last_3f_seconds')
                ->groupBy('race_id')
                ->selectRaw('race_id, AVG(last_3f_seconds) as avg_l3f, COUNT(*) as n')
                ->get()
                ->keyBy('race_id');

            $dwSum = 0.0; $dSum = 0.0;
            foreach ($recentRows as $i => $row) {
                if ($row->last_3f_seconds === null) continue;
                $avgRow = $raceAvgL3f->get($row->race_id);
                // レース内サンプルが少なすぎる(3頭未満)場合は平均の信頼性が低いので除外
                if (!$avgRow || (int) $avgRow->n < 3) continue;
                // 正の値 = 平均より速い(上がり3Fは秒数が小さいほど速い)
                $diff = (float) $avgRow->avg_l3f - (float) $row->last_3f_seconds;
                $w = $decay ** $i;
                $dSum  += $diff * $w;
                $dwSum += $w;
            }
            $speedScore = null;
            $avgLast3fDiff = null;
            if ($dwSum > 0) {
                $avgLast3fDiff = round($dSum / $dwSum, 2);
                // 差 0秒 = 50点を基準に、0.6秒速いごとに+30点相当の傾き(経験則)
                $speedScore = max(0.0, min(100.0, 50.0 + $avgLast3fDiff * 50.0));
                $speedScore = round($speedScore, 2);
            }

            // ---- 3) 合成 ----
            if ($speedScore !== null) {
                $score = ((float) $base['score']) * 0.50 + $formScore * 0.35 + $speedScore * 0.15;
            } else {
                $score = ((float) $base['score']) * 0.55 + $formScore * 0.45;
            }
            $score = max(0.0, min(100.0, $score));

            // recent_bonus: 後方互換の「直近フォームによる加点分」の目安値(base_score単体との差分)
            $recentBonus = round($score - (float) $base['score'], 2);

            return [
                'score'             => round($score, 2),
                'base_score'        => $base['score'],
                'runs'              => $base['runs'],
                'show_rate'         => $base['show_rate'],
                'win_rate'          => $base['win_rate'],
                'recent_shows'      => $recentShows,
                'recent_bonus'      => $recentBonus,
                'form_score'        => $formScore,
                'speed_score'       => $speedScore,
                'avg_last_3f_diff'  => $avgLast3fDiff,
                'scope'             => $usedScope,
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

        $cacheKey = [
            'venue_id'   => $cond['venue_id']   ?? null,
            'track_type' => $cond['track_type'] ?? null,
            'distance'   => $cond['distance']   ?? null,
        ];
        $key = 'rec:frame:v2:' . md5($frame . '|' . json_encode($cacheKey) . '|' . $minRuns);
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
        $cacheKey = [
            'track_type' => $cond['track_type'] ?? null,
            'direction'  => $dir,
        ];
        $key = 'rec:course:v2:' . md5($horseId . '|' . json_encode($cacheKey) . '|' . $minRuns);
        return Cache::remember($key, self::CACHE_TTL, function () use ($horseId, $cond, $minRuns, $dir) {
            $track = $cond['track_type'] ?? null;

            // 個体馬向け緩和: 最低1走でも評価(0だと若駒救済が効かない)
            $indMin = 1;

            // 段階フォールバック: 厳→緩
            //   1) track + direction
            //   2) track のみ (direction が DB 欠損のレースを救う)
            //   3) direction のみ
            //   4) 全レース (個体馬の素の連対率)
            $scopes = [
                ['track' => $track, 'direction' => $dir,  'label' => 'track+dir'],
                ['track' => $track, 'direction' => null,  'label' => 'track'],
                ['track' => null,   'direction' => $dir,  'label' => 'dir'],
                ['track' => null,   'direction' => null,  'label' => 'all'],
            ];

            $lastRow = null;
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
                if ($runs >= max(3, $indMin)) {
                    $res = $this->packShowSubscore($row, $indMin);
                    $res['scope'] = $sc['label'];
                    return $res;
                }
                $lastRow = $row;
            }
            // 全レース通算でも3走未満。1走でも評価する。
            $runs = (int) ($lastRow->runs ?? 0);
            if ($runs > 0) {
                $res = $this->packShowSubscore($lastRow, 1);
                $res['scope'] = 'minimal';
                return $res;
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
     * 強制再計算時に、この馬・騎手に紐づく Cache::remember キーを破棄する
     *
     * 各 *Score() メソッドのキー構築と同じロジックでキーを再構築して forget する。
     * (キー構築が変わるたびにここも合わせる必要があるので、近い場所に置いておく)
     */
    protected function forgetHorseCaches(
        int     $horseId,
        ?int    $jockeyId,
        ?string $father,
        ?string $mFather,
        ?int    $frame,
        array   $cond,
        int     $minRuns
    ): void {
        $jockeyCacheKey = [
            'venue_id'   => $cond['venue_id']   ?? null,
            'track_type' => $cond['track_type'] ?? null,
            'distance'   => $cond['distance']   ?? null,
        ];
        $horseCacheKey = [
            'track_type' => $cond['track_type'] ?? null,
            'distance'   => $cond['distance']   ?? null,
        ];
        $frameCacheKey = [
            'venue_id'   => $cond['venue_id']   ?? null,
            'track_type' => $cond['track_type'] ?? null,
            'distance'   => $cond['distance']   ?? null,
        ];
        $courseCacheKey = [
            'venue_id'   => $cond['venue_id']   ?? null,
            'track_type' => $cond['track_type'] ?? null,
            'distance'   => $cond['distance']   ?? null,
            'direction'  => $cond['direction']  ?? null,
        ];
        $keys = [
            // 血統 (father / mother_father)
            $father  ? 'rec:father:'  . md5($father  . '|' . json_encode($cond) . '|' . $minRuns) : null,
            $mFather ? 'rec:mfather:' . md5($mFather . '|' . json_encode($cond) . '|' . $minRuns) : null,
            // 騎手
            $jockeyId ? 'rec:jockey:v3:' . md5($jockeyId . '|' . json_encode($jockeyCacheKey) . '|' . $minRuns) : null,
            // 馬
            'rec:horse:v3:' . md5($horseId . '|' . json_encode($horseCacheKey) . '|' . $minRuns),
            // 回収率ボーナス
            $father ? 'rec:roi:' . md5($father . '|' . json_encode($cond) . '|' . $minRuns) : null,
            // 枠
            $frame ? 'rec:frame:v2:' . md5($frame . '|' . json_encode($frameCacheKey) . '|' . $minRuns) : null,
            // コース
            'rec:course:v2:' . md5($horseId . '|' . json_encode($courseCacheKey) . '|' . $minRuns),
        ];
        foreach (array_filter($keys) as $k) {
            Cache::forget($k);
        }
    }

    /**
     * 馬1頭の総合スコア
     *
     * @param array $horse  ['id', 'father', 'mother_father', 'frame_number'?, 'running_style'?]
     * @param int|null $jockeyId
     * @param array $cond   ['venue_id', 'track_type', 'distance', 'course_condition', 'direction'?, 'pace'?]
     * @param array $weights / int $minRuns  未指定なら設定から読む
     * @param bool  $forceRefresh true なら各サブスコアの Cache::remember キャッシュも破棄してから再集計
     */
    public function evaluateHorse(array $horse, ?int $jockeyId, array $cond, ?array $weights = null, ?int $minRuns = null, bool $forceRefresh = false): array
    {
        $settings = $this->getSettings();
        $weights  = $weights  ?? $settings['weights'];
        $minRuns  = $minRuns  ?? $settings['min_runs'];

        // 強制再計算時: この馬・騎手に紐づくスコアキャッシュを破棄してから集計
        // (Cache::remember は無条件にヒットしてしまうため、recompute=1 時は明示的にforgetする)
        if ($forceRefresh) {
            $this->forgetHorseCaches(
                horseId: (int) $horse['id'],
                jockeyId: $jockeyId,
                father:   $horse['father'] ?? null,
                mFather:  $horse['mother_father'] ?? null,
                frame:    isset($horse['frame_number']) ? (int) $horse['frame_number'] : null,
                cond:     $cond,
                minRuns:  $minRuns,
            );
        }

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
