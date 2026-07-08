<?php

namespace App\Http\Controllers;

use App\Models\Venue;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class VenueController extends Controller
{
    public function index(Request $request): View|JsonResponse
    {
        $venues = Venue::withCount('races')->orderBy('code')->get();

        if ($request->wantsJson()) {
            return response()->json([
                'ok'   => true,
                'data' => $venues->map(fn (Venue $v) => [
                    'id'           => $v->id,
                    'code'         => $v->code,
                    'name'         => $v->name,
                    'region'       => $v->region,
                    'races_count'  => $v->races_count,
                ]),
            ]);
        }

        return view('venues.index', compact('venues'));
    }

    public function show(Venue $venue): View
    {
        // 距離・トラック種別ごとの統計
        $byDistance = DB::table('races')
            ->where('venue_id', $venue->id)
            ->select('track_type', 'distance', DB::raw('count(*) as cnt'))
            ->groupBy('track_type', 'distance')
            ->orderBy('track_type')
            ->orderBy('distance')
            ->get();

        // 直近レース
        $recentRaces = $venue->races()
            ->orderByDesc('race_date')
            ->orderByDesc('race_number')
            ->limit(20)
            ->get();

        // 枠順×着順ヒートマップ用データ
        $frameStats = DB::table('race_results')
            ->join('races', 'races.id', '=', 'race_results.race_id')
            ->where('races.venue_id', $venue->id)
            ->whereNotNull('frame_number')
            ->whereNotNull('finish_position_int')
            ->selectRaw('frame_number, SUM(CASE WHEN finish_position_int=1 THEN 1 ELSE 0 END) as wins, count(*) as cnt')
            ->groupBy('frame_number')
            ->orderBy('frame_number')
            ->get();

        // 脚質別成績
        $styleStats = DB::table('race_results')
            ->join('races', 'races.id', '=', 'race_results.race_id')
            ->where('races.venue_id', $venue->id)
            ->whereNotNull('running_style')
            ->whereNotNull('finish_position_int')
            ->selectRaw('running_style, SUM(CASE WHEN finish_position_int=1 THEN 1 ELSE 0 END) as wins, count(*) as cnt')
            ->groupBy('running_style')
            ->get();

        return view('venues.show', compact('venue', 'byDistance', 'recentRaces', 'frameStats', 'styleStats'));
    }
}
