<?php

namespace App\Http\Controllers;

use App\Models\Horse;
use App\Models\Jockey;
use App\Models\Race;
use App\Models\RaceResult;
use App\Models\Trainer;
use App\Models\Venue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function index(): View
    {
        // ========= 基本 KPI =========
        $stats = [
            'races_total'    => $this->safe(fn() => Race::count(), 0),
            'horses_total'   => $this->safe(fn() => Horse::count(), 0),
            'jockeys_total'  => $this->safe(fn() => Jockey::where('is_active', true)->count(), 0),
            'trainers_total' => $this->safe(fn() => Trainer::where('is_active', true)->count(), 0),
            'venues_total'   => $this->safe(fn() => Venue::count(), 0),
            'results_total'  => $this->safe(fn() => RaceResult::count(), 0),
            'races_this_month' => $this->safe(
                fn() => Race::whereYear('race_date', now()->year)
                    ->whereMonth('race_date', now()->month)
                    ->count(),
                0
            ),
            'last_race_date' => $this->safe(fn() => Race::max('race_date'), null),
            'recent_races'   => $this->safe(
                fn() => Race::with('venue')
                    ->withCount('results')
                    ->orderByDesc('race_date')
                    ->orderByDesc('race_number')
                    ->limit(10)
                    ->get(),
                collect()
            ),
        ];

        // ========= レース系の集計 =========
        // グレード別
        $byGrade = $this->safe(fn() => Race::select('grade', DB::raw('count(*) as cnt'))
            ->whereNotNull('grade')
            ->groupBy('grade')
            ->orderByDesc('cnt')
            ->get(), collect());

        // 月別レース数（直近12か月）
        $byMonth = $this->safe(fn() => Race::select(
                DB::raw("DATE_FORMAT(race_date, '%Y-%m') as ym"),
                DB::raw('count(*) as cnt')
            )
            ->where('race_date', '>=', now()->subMonths(12)->startOfMonth())
            ->groupBy('ym')
            ->orderBy('ym')
            ->get(), collect());

        // 競馬場別レース数
        $byVenue = $this->safe(fn() => Venue::leftJoin('races', 'races.venue_id', '=', 'venues.id')
            ->select('venues.name', DB::raw('count(races.id) as cnt'))
            ->groupBy('venues.id', 'venues.name')
            ->orderByDesc('cnt')
            ->get(), collect());

        // トラック別（芝/ダート/障害）
        $byTrack = $this->safe(fn() => Race::select('track_type', DB::raw('count(*) as cnt'))
            ->whereNotNull('track_type')
            ->groupBy('track_type')
            ->orderByDesc('cnt')
            ->get(), collect());

        // 距離カテゴリ別
        $byDistanceCat = $this->safe(function () {
            $expr = "CASE
                WHEN distance <= 1400 THEN '短距離(〜1400)'
                WHEN distance <= 1800 THEN 'マイル(〜1800)'
                WHEN distance <= 2200 THEN '中距離(〜2200)'
                WHEN distance <= 2600 THEN '中長距離(〜2600)'
                ELSE '長距離(2700〜)' END";
            return Race::selectRaw("{$expr} as cat, count(*) as cnt")
                ->groupByRaw($expr)
                ->get();
        }, collect());

        // 馬場状態別
        $byCondition = $this->safe(fn() => Race::select('course_condition', DB::raw('count(*) as cnt'))
            ->whereNotNull('course_condition')
            ->groupBy('course_condition')
            ->orderByDesc('cnt')
            ->get(), collect());

        // 天候別
        $byWeather = $this->safe(fn() => Race::select('weather', DB::raw('count(*) as cnt'))
            ->whereNotNull('weather')
            ->groupBy('weather')
            ->orderByDesc('cnt')
            ->get(), collect());

        // 曜日別レース数
        $byWeekday = $this->safe(function () {
            // MariaDB: DAYOFWEEK 1=Sun..7=Sat
            $rows = Race::selectRaw('DAYOFWEEK(race_date) as wd, count(*) as cnt')
                ->groupByRaw('DAYOFWEEK(race_date)')
                ->orderBy('wd')
                ->get();
            $labels = [1=>'日',2=>'月',3=>'火',4=>'水',5=>'木',6=>'金',7=>'土'];
            return $rows->map(fn($r) => (object)[
                'label' => $labels[$r->wd] ?? '-',
                'cnt'   => (int)$r->cnt,
            ]);
        }, collect());

        // 月別 平均出走頭数
        $avgFieldByMonth = $this->safe(fn() => Race::selectRaw("
                DATE_FORMAT(race_date, '%Y-%m') as ym,
                AVG(horses_count) as avg_field
            ")
            ->where('race_date', '>=', now()->subMonths(12)->startOfMonth())
            ->whereNotNull('horses_count')
            ->groupBy('ym')
            ->orderBy('ym')
            ->get(), collect());

        // ========= ランキング =========
        // トップ騎手（勝利数）
        $topJockeys = $this->safe(fn() => RaceResult::select('jockey_id', DB::raw('count(*) as wins'))
            ->where('finish_position_int', 1)
            ->whereNotNull('jockey_id')
            ->groupBy('jockey_id')
            ->orderByDesc('wins')
            ->limit(10)
            ->with('jockey:id,name')
            ->get(), collect());

        // トップ調教師（勝利数）
        $topTrainers = $this->safe(fn() => RaceResult::select('trainer_id', DB::raw('count(*) as wins'))
            ->where('finish_position_int', 1)
            ->whereNotNull('trainer_id')
            ->groupBy('trainer_id')
            ->orderByDesc('wins')
            ->limit(10)
            ->with('trainer:id,name')
            ->get(), collect());

        // トップ種牡馬（産駒勝利数）
        $topSires = $this->safe(fn() => DB::table('race_results')
            ->join('horses', 'horses.id', '=', 'race_results.horse_id')
            ->where('race_results.finish_position_int', 1)
            ->whereNotNull('horses.father')
            ->select('horses.father as name', DB::raw('count(*) as wins'))
            ->groupBy('horses.father')
            ->orderByDesc('wins')
            ->limit(10)
            ->get(), collect());

        // トップ獲得賞金馬
        $topPrizeHorses = $this->safe(fn() => Horse::select('id', 'name', 'total_prize')
            ->whereNotNull('total_prize')
            ->orderByDesc('total_prize')
            ->limit(10)
            ->get(), collect());

        // 直近開催日のレース一覧
        $latestDateRaces = collect();
        $latestDate = $stats['last_race_date'] ?? null;
        if ($latestDate) {
            $latestDateRaces = $this->safe(fn() => Race::with('venue')
                ->withCount('results')
                ->whereDate('race_date', $latestDate)
                ->orderBy('venue_id')
                ->orderBy('race_number')
                ->get(), collect());
        }

        return view('dashboard.index', compact(
            'stats',
            'byGrade', 'byMonth', 'byVenue',
            'byTrack', 'byDistanceCat', 'byCondition', 'byWeather', 'byWeekday',
            'avgFieldByMonth',
            'topJockeys', 'topTrainers', 'topSires', 'topPrizeHorses',
            'latestDate', 'latestDateRaces'
        ));
    }

    /**
     * 個別の集計が失敗してもダッシュボード全体が500にならないようにする
     */
    private function safe(\Closure $fn, $default)
    {
        try {
            return $fn();
        } catch (\Throwable $e) {
            Log::error('DashboardController safe() caught exception', [
                'message' => $e->getMessage(),
                'file'    => $e->getFile(),
                'line'    => $e->getLine(),
            ]);
            return $default;
        }
    }
}
