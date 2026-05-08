<?php

namespace App\Http\Controllers;

use App\Models\Jockey;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class JockeyController extends Controller
{
    public function index(Request $request): View
    {
        $query = Jockey::query();

        if ($request->filled('keyword')) {
            $query->where('name', 'like', '%' . $request->keyword . '%');
        }
        if ($request->filled('belonging')) {
            $query->where('belonging', $request->belonging);
        }

        // 勝利数をサブクエリで取得（ONLY_FULL_GROUP_BY 対策）
        $winsSub = DB::table('race_results')
            ->select('jockey_id', DB::raw('count(*) as wins'))
            ->where('finish_position_int', 1)
            ->groupBy('jockey_id');

        $jockeys = $query
            ->leftJoinSub($winsSub, 'w', function ($join) {
                $join->on('w.jockey_id', '=', 'jockeys.id');
            })
            ->select('jockeys.*', DB::raw('COALESCE(w.wins, 0) as wins'))
            ->orderByDesc('wins')
            ->orderBy('jockeys.name')
            ->paginate(40)
            ->withQueryString();

        return view('jockeys.index', compact('jockeys'));
    }

    public function show(Jockey $jockey): View
    {
        $summary = $jockey->summary();

        // 競馬場別成績
        $byVenue = $jockey->results()
            ->whereNotNull('finish_position_int')
            ->join('races', 'races.id', '=', 'race_results.race_id')
            ->join('venues', 'venues.id', '=', 'races.venue_id')
            ->selectRaw('venues.name, count(*) as cnt, SUM(CASE WHEN finish_position_int=1 THEN 1 ELSE 0 END) as wins, SUM(CASE WHEN finish_position_int<=3 THEN 1 ELSE 0 END) as shows')
            ->groupBy('venues.id', 'venues.name')
            ->orderByDesc('cnt')
            ->get();

        // 直近のレース結果
        $recentResults = $jockey->results()
            ->with(['race.venue', 'horse'])
            ->orderByDesc('id')
            ->limit(20)
            ->get();

        return view('jockeys.show', compact('jockey', 'summary', 'byVenue', 'recentResults'));
    }
}
