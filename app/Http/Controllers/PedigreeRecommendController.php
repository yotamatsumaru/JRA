<?php

namespace App\Http\Controllers;

use App\Models\Race;
use App\Models\Venue;
use App\Services\PedigreeRecommendService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * 推奨機能(血統+騎手+馬実績スコアリング)のコントローラ
 *
 * Phase 1: index(トップ) / settings(重み設定)
 * Phase 2: conditions(B) / scan(C)
 * Phase 3: race(A) / raceShow(A詳細)  ← 本コミットで追加
 */
class PedigreeRecommendController extends Controller
{
    public function __construct(protected PedigreeRecommendService $svc) {}

    /**
     * 推奨機能トップ(3つの入口の案内 + 現在の重み表示)
     */
    public function index(Request $request): View
    {
        $settings = $this->svc->getSettings();
        return view('analytics.recommend.index', [
            'settings' => $settings,
        ]);
    }

    /**
     * 重み設定フォーム表示
     */
    public function settings(Request $request): View
    {
        $settings = $this->svc->getSettings();
        return view('analytics.recommend.settings', [
            'settings' => $settings,
            'defaults' => PedigreeRecommendService::DEFAULT_WEIGHTS,
        ]);
    }

    /**
     * 重み設定 保存
     */
    public function settingsStore(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'weights.pedigree' => 'required|integer|min:0|max:100',
            'weights.jockey'   => 'required|integer|min:0|max:100',
            'weights.horse'    => 'required|integer|min:0|max:100',
            'weights.roi'      => 'required|integer|min:0|max:100',
            // 新規3軸: 旧フォーム互換のため nullable
            'weights.frame'    => 'nullable|integer|min:0|max:100',
            'weights.course'   => 'nullable|integer|min:0|max:100',
            'weights.style'    => 'nullable|integer|min:0|max:100',
            'min_runs'         => 'required|integer|min:1|max:500',
        ]);
        $this->svc->saveSettings($validated['weights'], (int) $validated['min_runs']);

        return redirect()->route('analytics.recommend.settings')->with('success', '設定を保存しました');
    }

    /**
     * 重み設定 デフォルトに戻す
     */
    public function settingsReset(Request $request): RedirectResponse
    {
        $this->svc->saveSettings(
            PedigreeRecommendService::DEFAULT_WEIGHTS,
            PedigreeRecommendService::DEFAULT_MIN_RUNS
        );
        return redirect()->route('analytics.recommend.settings')->with('success', 'デフォルトに戻しました');
    }

    // ========================================================================
    // Phase 2 (B): 条件指定で狙い目血統を抽出
    // ========================================================================

    /**
     * 条件指定抽出
     *
     * クエリ:
     *   ?venue_id=         競馬場ID
     *   ?track_type=       芝 | ダート | 障害
     *   ?distance=         距離(m, 任意) ※±200で曖昧マッチ
     *   ?distance_cat=     短距離|マイル|中距離|中長距離|長距離 (distance未指定時)
     *   ?course_condition= 良|稍重|重|不良
     *   ?min_runs=         最小出走数 (default: 設定値)
     *   ?limit=            上位件数 (default: 30)
     *   ?show_cross=1      父×母父クロス表を表示
     */
    public function conditions(Request $request): View
    {
        $settings = $this->svc->getSettings();

        $cond = [
            'venue_id'         => $request->filled('venue_id')         ? (int) $request->get('venue_id') : null,
            'track_type'       => $request->filled('track_type')       ? (string) $request->get('track_type') : null,
            'distance'         => $request->filled('distance')         ? (int) $request->get('distance') : null,
            'distance_cat'     => $request->filled('distance_cat')     ? (string) $request->get('distance_cat') : null,
            'course_condition' => $request->filled('course_condition') ? (string) $request->get('course_condition') : null,
        ];
        $minRuns = max(1, (int) $request->get('min_runs', $settings['min_runs']));
        $limit   = max(5, min(100, (int) $request->get('limit', 30)));

        $hasFilter = $cond['venue_id'] || $cond['track_type'] || $cond['distance'] || $cond['distance_cat'] || $cond['course_condition'];

        $fathers     = ['rows' => [], 'total_groups' => 0];
        $mFathers    = ['rows' => [], 'total_groups' => 0];
        $crossCells  = [];

        if ($hasFilter) {
            $fathers  = $this->svc->rankPedigreeByCondition('father',        $cond, $minRuns, $limit);
            $mFathers = $this->svc->rankPedigreeByCondition('mother_father', $cond, $minRuns, $limit);

            // 父×母父クロス表(top10 × top10)
            if ($request->boolean('show_cross') && !empty($fathers['rows']) && !empty($mFathers['rows'])) {
                $fList = array_slice(array_map(fn($r) => $r->name, $fathers['rows']), 0, 10);
                $mList = array_slice(array_map(fn($r) => $r->name, $mFathers['rows']), 0, 10);
                $crossCells = $this->svc->pedigreeCrossByCondition($cond, max(1, intdiv($minRuns, 4)), $fList, $mList);
            }
        }

        return view('analytics.recommend.conditions', [
            'settings'    => $settings,
            'cond'        => $cond,
            'min_runs'    => $minRuns,
            'limit'       => $limit,
            'has_filter'  => $hasFilter,
            'show_cross'  => $request->boolean('show_cross'),
            'venues'      => $this->svc->venuesForSelect(),
            'fathers'     => $fathers,
            'm_fathers'   => $mFathers,
            'cross_cells' => $crossCells,
        ]);
    }

    // ========================================================================
    // Phase 2 (C): 全条件スキャン
    // ========================================================================

    /**
     * 全条件スキャン
     *
     * クエリ:
     *   ?min_runs=        最小出走数(各セル) (default: 20)
     *   ?top_per_cell=    各セルからの抽出件数 (default: 1)
     *   ?only_positive=1  ROI 100% 超のみ
     *   ?venue_id=        絞り込み(任意)
     *   ?track_type=      絞り込み(任意)
     *   ?distance_cat=    絞り込み(任意)
     */
    public function scan(Request $request): View
    {
        $minRuns      = max(1,  (int) $request->get('min_runs', 20));
        $topPerCell   = max(1, min(5, (int) $request->get('top_per_cell', 1)));
        $onlyPositive = $request->boolean('only_positive');

        $rows = $this->svc->scanAllConditions($minRuns, $topPerCell, $onlyPositive);

        // 後段フィルタ(再キャッシュ防止)
        $venueId    = $request->filled('venue_id')     ? (int) $request->get('venue_id') : null;
        $trackType  = $request->filled('track_type')   ? (string) $request->get('track_type') : null;
        $distCat    = $request->filled('distance_cat') ? (string) $request->get('distance_cat') : null;

        if ($venueId)   $rows = array_values(array_filter($rows, fn($r) => $r->venue_id === $venueId));
        if ($trackType) $rows = array_values(array_filter($rows, fn($r) => $r->track_type === $trackType));
        if ($distCat)   $rows = array_values(array_filter($rows, fn($r) => $r->distance_cat === $distCat));

        // 統計
        $stats = [
            'total_cells'   => count($rows),
            'positive_roi'  => count(array_filter($rows, fn($r) => $r->roi_place >= 100)),
            'high_score'    => count(array_filter($rows, fn($r) => $r->score    >= 60)),
            'avg_roi'       => count($rows) > 0 ? round(array_sum(array_map(fn($r) => $r->roi_place, $rows)) / count($rows), 1) : 0,
        ];

        return view('analytics.recommend.scan', [
            'rows'           => $rows,
            'stats'          => $stats,
            'min_runs'       => $minRuns,
            'top_per_cell'   => $topPerCell,
            'only_positive'  => $onlyPositive,
            'venue_id'       => $venueId,
            'track_type'     => $trackType,
            'distance_cat'   => $distCat,
            'venues'         => $this->svc->venuesForSelect(),
        ]);
    }

    // ========================================================================
    // Phase 3 (A): 出馬表ベース推奨(◎○▲△☆ 自動付与)
    // ========================================================================

    /**
     * レース選択画面
     *
     * クエリ:
     *   ?venue_id=    競馬場
     *   ?track_type=  芝/ダート/障害
     *   ?grade=       G1/G2/...
     *   ?from= ?to=   日付範囲
     *   ?keyword=     レース名キーワード
     */
    public function race(Request $request): View
    {
        $q = Race::with('venue')->withCount('results');

        if ($request->filled('venue_id'))   $q->where('venue_id',   $request->venue_id);
        if ($request->filled('track_type')) $q->where('track_type', $request->track_type);
        if ($request->filled('grade'))      $q->where('grade',      $request->grade);
        if ($request->filled('from'))       $q->whereDate('race_date', '>=', $request->from);
        if ($request->filled('to'))         $q->whereDate('race_date', '<=', $request->to);
        if ($request->filled('keyword'))    $q->where('name', 'like', '%' . $request->keyword . '%');

        // 出走頭数 1 以上のレースのみ(空レコードを除外)
        $q->having('results_count', '>=', 1);

        $races = $q->orderByDesc('race_date')
            ->orderByDesc('race_number')
            ->paginate(30)
            ->withQueryString();

        return view('analytics.recommend.race', [
            'races'    => $races,
            'venues'   => Venue::orderBy('code')->get(),
            'settings' => $this->svc->getSettings(),
        ]);
    }

    /**
     * 推奨結果画面(指定レースの全出走馬をスコアリングして印を付与)
     */
    public function raceShow(Race $race, Request $request): View
    {
        $settings = $this->svc->getSettings();
        $weights  = $settings['weights'];
        $minRuns  = $settings['min_runs'];

        // 該当レースの条件
        $cond = [
            'venue_id'         => $race->venue_id,
            'track_type'       => $race->track_type,
            'distance'         => $race->distance,
            'course_condition' => $race->course_condition,
        ];

        // 出走馬を読み込み(馬・騎手の血統情報も含めて)
        $race->load(['venue', 'results.horse', 'results.jockey']);

        // 各馬を評価
        $evaluations = [];
        foreach ($race->results as $result) {
            $horse = $result->horse;
            if (!$horse) continue;

            $eval = $this->svc->evaluateHorse(
                horse:   ['id' => $horse->id, 'father' => $horse->father, 'mother_father' => $horse->mother_father],
                jockeyId: $result->jockey_id ? (int) $result->jockey_id : null,
                cond:    $cond,
                weights: $weights,
                minRuns: $minRuns,
            );

            $evaluations[] = (object) [
                'result'  => $result,
                'horse'   => $horse,
                'jockey'  => $result->jockey,
                'eval'    => $eval,
                'reasons' => $this->buildReasons($eval, $horse, $result->jockey),
            ];
        }

        // スコア降順 → 印を付与
        usort($evaluations, fn($a, $b) => $b->eval['total'] <=> $a->eval['total']);
        foreach ($evaluations as $idx => $e) {
            $e->rank = $idx + 1;
            $e->mark = $this->svc->decideMark(
                (float) $e->eval['total'],
                $e->rank,
                (float) $e->eval['sub']['roi']
            );
        }

        // 推奨馬券組み合わせを構築
        $recommendedBets = $this->buildRecommendedBets($evaluations);

        // 馬番順の表示も用意(着順比較用)
        $byHorseNo = collect($evaluations)->sortBy(fn($e) => $e->result->horse_number ?? 999)->values();

        return view('analytics.recommend.race_show', [
            'race'            => $race,
            'settings'        => $settings,
            'evaluations'     => $evaluations,           // スコア順
            'by_horse_no'     => $byHorseNo,             // 馬番順
            'recommended_bets'=> $recommendedBets,
            'cond'            => $cond,
        ]);
    }

    /**
     * 評価から推奨理由(自然文)を組み立て
     */
    private function buildReasons(array $eval, $horse, $jockey): array
    {
        $reasons = [];

        // 血統
        $ped = $eval['pedigree'];
        if (($ped['note'] ?? null) === 'both') {
            $reasons[] = sprintf(
                '🧬 父%s(複勝率%s%%) × 母父%s(複勝率%s%%)が好走条件',
                $horse->father ?? '?',
                $ped['father']['show_rate'] ?? 0,
                $horse->mother_father ?? '?',
                $ped['mother_father']['show_rate'] ?? 0,
            );
        } elseif (($ped['note'] ?? null) === 'father_only') {
            $reasons[] = sprintf(
                '🧬 父%s が条件下で複勝率%s%%(母父はサンプル不足)',
                $horse->father ?? '?',
                $ped['father']['show_rate'] ?? 0,
            );
        } elseif (($ped['note'] ?? null) === 'mfather_only') {
            $reasons[] = sprintf(
                '🧬 母父%s が条件下で複勝率%s%%(父はサンプル不足)',
                $horse->mother_father ?? '?',
                $ped['mother_father']['show_rate'] ?? 0,
            );
        } elseif (($ped['note'] ?? null) === 'sample_insufficient') {
            $reasons[] = '🧬 血統データのサンプルが不足';
        }

        // 騎手
        $jky = $eval['jockey'];
        if (($jky['runs'] ?? 0) > 0 && $jockey) {
            $reasons[] = sprintf(
                '👤 %s 騎手は同条件で複勝率%s%% (%d走)',
                $jockey->name ?? '?',
                $jky['show_rate'] ?? 0,
                $jky['runs'],
            );
        }

        // 馬の過去走
        $hrs = $eval['horse'];
        if (($hrs['runs'] ?? 0) > 0) {
            $reasons[] = sprintf(
                '🐎 馬個体は同条件で複勝率%s%% (%d走) + 直近5走の3着内 %d 回',
                $hrs['show_rate'] ?? 0,
                $hrs['runs'],
                $hrs['recent_shows'] ?? 0,
            );
        }

        // ROI
        $roi = $eval['roi'];
        if (($roi['roi_place'] ?? 0) >= 100) {
            $reasons[] = sprintf(
                '💰 父系の複勝回収率 %s%% (妙味あり)',
                $roi['roi_place']
            );
        }

        return $reasons;
    }

    /**
     * 推奨馬券組み合わせを構築
     * 入力: スコア順の evaluations(rank, mark 付与済み)
     */
    private function buildRecommendedBets(array $evaluations): array
    {
        $byMark = ['◎' => null, '○' => null, '▲' => null, '△' => [], '☆' => []];
        foreach ($evaluations as $e) {
            $hno = $e->result->horse_number;
            if ($e->mark === '◎' && !$byMark['◎']) $byMark['◎'] = $hno;
            elseif ($e->mark === '○' && !$byMark['○']) $byMark['○'] = $hno;
            elseif ($e->mark === '▲' && !$byMark['▲']) $byMark['▲'] = $hno;
            elseif ($e->mark === '△') $byMark['△'][] = $hno;
            elseif ($e->mark === '☆') $byMark['☆'][] = $hno;
        }

        $bets = [];

        // 馬連: ◎-○
        if ($byMark['◎'] && $byMark['○']) {
            $bets[] = [
                'type'   => '馬連',
                'combo'  => "{$byMark['◎']} - {$byMark['○']}",
                'detail' => '本命と対抗の組み合わせ(順不同)',
                'risk'   => 'low',
            ];
        }

        // 馬単: ◎ → ○▲
        if ($byMark['◎'] && ($byMark['○'] || $byMark['▲'])) {
            $head = (string) $byMark['◎'];
            $tail = array_filter([$byMark['○'], $byMark['▲']]);
            $bets[] = [
                'type'   => '馬単',
                'combo'  => "{$head} → " . implode(',', $tail),
                'detail' => '本命1着固定 + 対抗・単穴を相手に流し',
                'risk'   => 'mid',
            ];
        }

        // 3連複: ◎○▲ ボックス(または +△)
        $core = array_filter([$byMark['◎'], $byMark['○'], $byMark['▲']]);
        if (count($core) >= 3) {
            $bets[] = [
                'type'   => '3連複',
                'combo'  => implode(' - ', $core),
                'detail' => '本命・対抗・単穴の3点ボックス',
                'risk'   => 'mid',
            ];
            // △含めた拡張
            if (!empty($byMark['△'])) {
                $with = array_merge($core, array_slice($byMark['△'], 0, 2));
                $bets[] = [
                    'type'   => '3連複(△追加)',
                    'combo'  => implode(' - ', $with),
                    'detail' => '◎○▲ + △ を加えた拡張ボックス(点数増)',
                    'risk'   => 'high',
                ];
            }
        }

        // ワイド: ◎-{○,▲,△}
        if ($byMark['◎']) {
            $opps = array_filter(array_merge([$byMark['○'], $byMark['▲']], $byMark['△']));
            if (!empty($opps)) {
                $bets[] = [
                    'type'   => 'ワイド',
                    'combo'  => "{$byMark['◎']} - " . implode(',', $opps),
                    'detail' => '本命の3着内を信頼。相手は対抗以下複数点',
                    'risk'   => 'low',
                ];
            }
        }

        // 妙味: ☆ がいれば穴狙い単複
        if (!empty($byMark['☆'])) {
            $bets[] = [
                'type'   => '妙味狙い(単複)',
                'combo'  => '☆ ' . implode(',', $byMark['☆']),
                'detail' => 'ROIサブスコアの高い穴候補。少額の単勝・複勝で',
                'risk'   => 'speculative',
            ];
        }

        return $bets;
    }
}
