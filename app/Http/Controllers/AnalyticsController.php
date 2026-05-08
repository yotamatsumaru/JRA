<?php

namespace App\Http\Controllers;

use App\Models\Venue;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class AnalyticsController extends Controller
{
    /**
     * 競馬場別傾向分析
     * 枠順×勝率ヒートマップ、脚質別成績、距離別成績
     */
    public function venue(Request $request): View
    {
        $venues = Venue::orderBy('code')->get();
        $venueId = $request->get('venue_id', $venues->first()?->id);
        $trackType = $request->get('track_type', '芝');
        $distance = $request->get('distance');

        $venue = Venue::find($venueId);

        // 枠番×勝率（ヒートマップ用）
        $frameQuery = DB::table('race_results')
            ->join('races', 'races.id', '=', 'race_results.race_id')
            ->where('races.venue_id', $venueId)
            ->where('races.track_type', $trackType)
            ->whereNotNull('frame_number')
            ->whereNotNull('finish_position_int');

        if ($distance) {
            $frameQuery->where('races.distance', $distance);
        }

        $frameStats = $frameQuery
            ->selectRaw('frame_number,
                count(*) as runs,
                SUM(CASE WHEN finish_position_int=1 THEN 1 ELSE 0 END) as wins,
                SUM(CASE WHEN finish_position_int<=2 THEN 1 ELSE 0 END) as places,
                SUM(CASE WHEN finish_position_int<=3 THEN 1 ELSE 0 END) as shows')
            ->groupBy('frame_number')
            ->orderBy('frame_number')
            ->get();

        // 脚質別
        $styleQuery = DB::table('race_results')
            ->join('races', 'races.id', '=', 'race_results.race_id')
            ->where('races.venue_id', $venueId)
            ->where('races.track_type', $trackType)
            ->whereNotNull('running_style')
            ->whereNotNull('finish_position_int');

        if ($distance) {
            $styleQuery->where('races.distance', $distance);
        }

        $styleStats = $styleQuery
            ->selectRaw('running_style,
                count(*) as runs,
                SUM(CASE WHEN finish_position_int=1 THEN 1 ELSE 0 END) as wins,
                SUM(CASE WHEN finish_position_int<=3 THEN 1 ELSE 0 END) as shows')
            ->groupBy('running_style')
            ->get();

        // この競馬場で開催された距離一覧
        $availableDistances = DB::table('races')
            ->where('venue_id', $venueId)
            ->where('track_type', $trackType)
            ->select('distance')
            ->groupBy('distance')
            ->orderBy('distance')
            ->pluck('distance');

        return view('analytics.venue', compact(
            'venues', 'venue', 'venueId', 'trackType', 'distance',
            'frameStats', 'styleStats', 'availableDistances'
        ));
    }

    /**
     * ペース分析（H/M/S別の決着脚質）
     */
    public function pace(): View
    {
        $stats = DB::table('race_results')
            ->join('races', 'races.id', '=', 'race_results.race_id')
            ->whereNotNull('races.pace')
            ->whereNotNull('running_style')
            ->where('finish_position_int', '<=', 3)
            ->selectRaw('races.pace, running_style, count(*) as cnt')
            ->groupBy('races.pace', 'running_style')
            ->get();

        // ピボット
        $pivot = [];
        foreach ($stats as $s) {
            $pivot[$s->pace][$s->running_style] = $s->cnt;
        }

        return view('analytics.pace', compact('pivot'));
    }

    /**
     * 血統傾向（父系別の得意距離・コース）
     */
    public function pedigree(Request $request): View
    {
        $father = $request->get('father');

        $fatherList = DB::table('horses')
            ->whereNotNull('father')
            ->select('father', DB::raw('count(*) as cnt'))
            ->groupBy('father')
            ->orderByDesc('cnt')
            ->limit(50)
            ->get();

        $stats = collect();
        if ($father) {
            $stats = DB::table('race_results')
                ->join('horses', 'horses.id', '=', 'race_results.horse_id')
                ->join('races', 'races.id', '=', 'race_results.race_id')
                ->join('venues', 'venues.id', '=', 'races.venue_id')
                ->where('horses.father', $father)
                ->whereNotNull('finish_position_int')
                ->selectRaw('venues.name as venue, races.track_type, races.distance,
                    count(*) as runs,
                    SUM(CASE WHEN finish_position_int=1 THEN 1 ELSE 0 END) as wins,
                    SUM(CASE WHEN finish_position_int<=3 THEN 1 ELSE 0 END) as shows')
                ->groupBy('venues.name', 'races.track_type', 'races.distance')
                ->orderByDesc('runs')
                ->limit(50)
                ->get();
        }

        return view('analytics.pedigree', compact('fatherList', 'father', 'stats'));
    }

    /**
     * 騎手×コース相性
     */
    public function jockey(Request $request): View
    {
        $jockeyName = $request->get('jockey');

        $stats = collect();
        if ($jockeyName) {
            $stats = DB::table('race_results')
                ->join('jockeys', 'jockeys.id', '=', 'race_results.jockey_id')
                ->join('races', 'races.id', '=', 'race_results.race_id')
                ->join('venues', 'venues.id', '=', 'races.venue_id')
                ->where('jockeys.name', $jockeyName)
                ->whereNotNull('finish_position_int')
                ->selectRaw('venues.name as venue, races.track_type,
                    count(*) as runs,
                    SUM(CASE WHEN finish_position_int=1 THEN 1 ELSE 0 END) as wins,
                    SUM(CASE WHEN finish_position_int<=3 THEN 1 ELSE 0 END) as shows')
                ->groupBy('venues.name', 'races.track_type')
                ->orderByDesc('runs')
                ->get();
        }

        $jockeyList = DB::table('jockeys')
            ->join('race_results', 'race_results.jockey_id', '=', 'jockeys.id')
            ->select('jockeys.name', DB::raw('count(*) as cnt'))
            ->groupBy('jockeys.name')
            ->orderByDesc('cnt')
            ->limit(50)
            ->get();

        return view('analytics.jockey', compact('jockeyList', 'jockeyName', 'stats'));
    }

    /**
     * 回収率シミュレーション
     */
    public function roi(Request $request): View
    {
        $popularity = $request->get('popularity');
        $venueId = $request->get('venue_id');
        $trackType = $request->get('track_type');

        $query = DB::table('race_results')
            ->join('races', 'races.id', '=', 'race_results.race_id')
            ->whereNotNull('win_odds')
            ->whereNotNull('finish_position_int');

        if ($popularity) {
            $query->where('popularity', $popularity);
        }
        if ($venueId) {
            $query->where('races.venue_id', $venueId);
        }
        if ($trackType) {
            $query->where('races.track_type', $trackType);
        }

        $bets = $query->selectRaw('count(*) as bets,
            SUM(CASE WHEN finish_position_int=1 THEN win_odds*100 ELSE 0 END) as winnings')
            ->first();

        $roi = $bets && $bets->bets > 0
            ? round($bets->winnings / ($bets->bets * 100) * 100, 1)
            : 0;

        $venues = Venue::orderBy('code')->get();

        return view('analytics.roi', compact('bets', 'roi', 'venues', 'popularity', 'venueId', 'trackType'));
    }
}
