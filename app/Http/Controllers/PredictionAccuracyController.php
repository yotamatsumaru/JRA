<?php

namespace App\Http\Controllers;

use App\Models\Venue;
use App\Services\PredictionAccuracyService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Illuminate\View\View;

/**
 * 予想精度トラッキング (Phase 4-N / Phase 5-E)
 *  ユーザーの印 vs 着順を集計して、予想精度のレポートを表示
 *  CSV エクスポートにも対応
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

    /**
     * Phase 5-E: 予想精度を CSV でエクスポート
     *  type=summary | monthly | courses (デフォルト summary)
     */
    public function exportCsv(Request $request): StreamedResponse
    {
        $userId = Auth::id();

        $filters = $request->only([
            'from', 'to', 'venue_id', 'track_type', 'grade',
            'distance_min', 'distance_max',
        ]);

        $type = $request->input('type', 'summary');

        $stamp = now()->format('Ymd_His');
        $fileName = "prediction_accuracy_{$type}_{$stamp}.csv";

        return response()->streamDownload(function () use ($type, $userId, $filters) {
            $out = fopen('php://output', 'w');
            // Excel で文字化けしないよう BOM 付与
            fwrite($out, "\xEF\xBB\xBF");

            switch ($type) {
                case 'monthly':
                    fputcsv($out, ['年月', '印', '対象', '勝', '3着内', '勝率(%)', '複勝率(%)', '単勝ROI(%)']);
                    foreach ($this->svc->monthlyTrend($userId, $filters) as $row) {
                        fputcsv($out, [
                            $row['ym'] ?? '',
                            $row['mark'] ?? '',
                            $row['runs'] ?? 0,
                            $row['wins'] ?? 0,
                            $row['top3'] ?? 0,
                            $row['win_rate'] ?? 0,
                            $row['place_rate'] ?? 0,
                            $row['win_roi'] ?? 0,
                        ]);
                    }
                    break;

                case 'courses':
                    fputcsv($out, ['競馬場', 'トラック', '対象(◎)', '勝', '3着内', '勝率(%)', '複勝率(%)', '単勝ROI(%)']);
                    foreach ($this->svc->courseBreakdown($userId, $filters) as $row) {
                        fputcsv($out, [
                            $row['venue'] ?? '',
                            $row['track_type'] ?? '',
                            $row['runs'] ?? 0,
                            $row['wins'] ?? 0,
                            $row['top3'] ?? 0,
                            $row['win_rate'] ?? 0,
                            $row['place_rate'] ?? 0,
                            $row['win_roi'] ?? 0,
                        ]);
                    }
                    break;

                case 'summary':
                default:
                    fputcsv($out, ['印', '対象', '勝', '2着内', '3着内', '勝率(%)', '複勝率(%)', '単勝ROI(%)', '複勝ROI(%)']);
                    $summary = $this->svc->summary($userId, $filters);
                    foreach ($summary as $mark => $row) {
                        fputcsv($out, [
                            $mark,
                            $row['runs'] ?? 0,
                            $row['wins'] ?? 0,
                            $row['top2'] ?? 0,
                            $row['top3'] ?? 0,
                            $row['win_rate'] ?? 0,
                            $row['place_rate'] ?? 0,
                            $row['win_roi'] ?? 0,
                            $row['place_roi'] ?? 0,
                        ]);
                    }
                    break;
            }

            fclose($out);
        }, $fileName, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }
}
