<?php

namespace App\Http\Controllers;

use App\Models\Horse;
use App\Models\Jockey;
use App\Models\Race;
use App\Models\RaceResult;
use App\Models\Venue;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function index(): View
    {
        // 集計用データ
        $stats = [
            'races_total' => Race::count(),
            'horses_total' => Horse::count(),
            'jockeys_total' => Jockey::where('is_active', true)->count(),
            'venues_total' => Venue::count(),
            'recent_races' => Race::with('venue')
                ->orderByDesc('race_date')
                ->orderByDesc('race_number')
                ->limit(10)
                ->get(),
        ];

        // グレード別レース数
        $byGrade = Race::select('grade', DB::raw('count(*) as cnt'))
            ->whereNotNull('grade')
            ->groupBy('grade')
            ->orderByDesc('cnt')
            ->get();

        // 月別レース数（直近12か月）
        $byMonth = Race::select(
                DB::raw("DATE_FORMAT(race_date, '%Y-%m') as ym"),
                DB::raw('count(*) as cnt')
            )
            ->where('race_date', '>=', now()->subMonths(12)->startOfMonth())
            ->groupBy('ym')
            ->orderBy('ym')
            ->get();

        // 競馬場別レース数
        $byVenue = Venue::leftJoin('races', 'races.venue_id', '=', 'venues.id')
            ->select('venues.name', DB::raw('count(races.id) as cnt'))
            ->groupBy('venues.id', 'venues.name')
            ->orderByDesc('cnt')
            ->get();

        // トップ騎手（勝利数）
        $topJockeys = RaceResult::select('jockey_id', DB::raw('count(*) as wins'))
            ->where('finish_position_int', 1)
            ->whereNotNull('jockey_id')
            ->groupBy('jockey_id')
            ->orderByDesc('wins')
            ->limit(10)
            ->with('jockey:id,name')
            ->get();

        return view('dashboard.index', compact('stats', 'byGrade', 'byMonth', 'byVenue', 'topJockeys'));
    }
}
