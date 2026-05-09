<?php

namespace App\Http\Controllers;

use App\Services\PedigreeRecommendService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * 推奨機能(血統+騎手+馬実績スコアリング)のコントローラ
 *
 * Phase 1: index(トップ) / settings(重み設定)
 * Phase 2: conditions(B) / scan(C)  ← 本コミットで追加
 * Phase 3: race(A) を追加予定
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
}
