<?php

namespace App\Http\Controllers;

use App\Models\Trainer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * 調教師 (Phase 6-T)
 *  Jockey と同じインターフェースで一覧・詳細を提供
 */
class TrainerController extends Controller
{
    public function index(Request $request): View
    {
        $query = Trainer::query();

        if ($request->filled('keyword')) {
            $query->where('name', 'like', '%' . $request->keyword . '%');
        }
        if ($request->filled('belonging')) {
            $query->where('belonging', $request->belonging);
        }

        // 勝利数をサブクエリ
        $winsSub = DB::table('race_results')
            ->select('trainer_id', DB::raw('count(*) as wins'))
            ->where('finish_position_int', 1)
            ->groupBy('trainer_id');

        $trainers = $query
            ->leftJoinSub($winsSub, 'w', function ($join) {
                $join->on('w.trainer_id', '=', 'trainers.id');
            })
            ->select('trainers.*', DB::raw('COALESCE(w.wins, 0) as wins'))
            ->orderByDesc('wins')
            ->orderBy('trainers.name')
            ->paginate(40)
            ->withQueryString();

        return view('trainers.index', compact('trainers'));
    }

    public function show(Trainer $trainer): View
    {
        $summary = $trainer->summary();

        // 競馬場別成績
        $byVenue = $trainer->results()
            ->whereNotNull('finish_position_int')
            ->join('races', 'races.id', '=', 'race_results.race_id')
            ->join('venues', 'venues.id', '=', 'races.venue_id')
            ->selectRaw('venues.name, count(*) as cnt, SUM(CASE WHEN finish_position_int=1 THEN 1 ELSE 0 END) as wins, SUM(CASE WHEN finish_position_int<=3 THEN 1 ELSE 0 END) as shows')
            ->groupBy('venues.id', 'venues.name')
            ->orderByDesc('cnt')
            ->get();

        // トラック別成績
        $byTrack = $trainer->results()
            ->whereNotNull('finish_position_int')
            ->join('races', 'races.id', '=', 'race_results.race_id')
            ->whereNotNull('races.track_type')
            ->selectRaw('races.track_type, count(*) as cnt, SUM(CASE WHEN finish_position_int=1 THEN 1 ELSE 0 END) as wins, SUM(CASE WHEN finish_position_int<=3 THEN 1 ELSE 0 END) as shows')
            ->groupBy('races.track_type')
            ->orderByDesc('cnt')
            ->get();

        // よく組む騎手 TOP10 (騎乗数順)
        $topJockeys = $trainer->results()
            ->whereNotNull('finish_position_int')
            ->whereNotNull('jockey_id')
            ->join('jockeys', 'jockeys.id', '=', 'race_results.jockey_id')
            ->selectRaw('jockeys.id, jockeys.name, count(*) as cnt, SUM(CASE WHEN finish_position_int=1 THEN 1 ELSE 0 END) as wins')
            ->groupBy('jockeys.id', 'jockeys.name')
            ->orderByDesc('cnt')
            ->limit(10)
            ->get();

        // 直近のレース結果
        $recentResults = $trainer->results()
            ->with(['race.venue', 'horse', 'jockey'])
            ->orderByDesc('id')
            ->limit(20)
            ->get();

        return view('trainers.show', compact(
            'trainer', 'summary', 'byVenue', 'byTrack', 'topJockeys', 'recentResults'
        ));
    }
}
