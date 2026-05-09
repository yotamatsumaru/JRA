<?php

namespace App\Http\Controllers;

use App\Models\Venue;
use App\Services\PredictionAccuracyService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * 予想精度トラッキング (Phase 4-N)
 *  ユーザーの印 vs 着順を集計して、予想精度のレポートを表示
 */
class PredictionAccuracyController extends Controller
{
    public function __construct(protected PredictionAccuracyService $svc) {}

    public function index(Request $request): View
    {
        $userId = Auth::id();

        $filters = $request->only([
            'from', 'to', 'venue_id', 'track_type', 'grade',
            'distance_min', 'distance_max',
        ]);

        $summary  = $this->svc->summary($userId, $filters);
        $monthly  = $this->svc->monthlyTrend($userId, $filters);
        $courses  = $this->svc->courseBreakdown($userId, $filters);

        // 月別推移 (◎ のみに絞ってチャート用に整形)
        $chartLabels = [];
        $chartHonmei = ['runs' => [], 'place_rate' => [], 'win_roi' => []];
        $byYm = [];
        foreach ($monthly as $row) {
            if ($row['mark'] !== '◎') continue;
            $byYm[$row['ym']] = $row;
        }
        ksort($byYm);
        foreach ($byYm as $ym => $row) {
            $chartLabels[] = $ym;
            $chartHonmei['runs'][]       = $row['runs'];
            $chartHonmei['place_rate'][] = $row['place_rate'];
            $chartHonmei['win_roi'][]    = $row['win_roi'];
        }

        $venues = Venue::orderBy('code')->get();

        return view('analytics.prediction-accuracy', compact(
            'summary', 'monthly', 'courses', 'venues', 'filters',
            'chartLabels', 'chartHonmei'
        ));
    }
}
