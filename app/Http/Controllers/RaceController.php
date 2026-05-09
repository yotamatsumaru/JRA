<?php

namespace App\Http\Controllers;

use App\Models\Horse;
use App\Models\Jockey;
use App\Models\Race;
use App\Models\Trainer;
use App\Models\Venue;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

class RaceController extends Controller
{
    public function index(Request $request): View
    {
        $query = Race::with('venue')->withCount('results');

        // フィルタ
        if ($request->filled('venue_id')) {
            $query->where('venue_id', $request->venue_id);
        }
        if ($request->filled('grade')) {
            $query->where('grade', $request->grade);
        }
        if ($request->filled('track_type')) {
            $query->where('track_type', $request->track_type);
        }
        if ($request->filled('from')) {
            $query->whereDate('race_date', '>=', $request->from);
        }
        if ($request->filled('to')) {
            $query->whereDate('race_date', '<=', $request->to);
        }
        if ($request->filled('keyword')) {
            $query->where('name', 'like', '%' . $request->keyword . '%');
        }

        $races = $query->orderByDesc('race_date')
            ->orderByDesc('race_number')
            ->paginate(30)
            ->withQueryString();

        $venues = Venue::orderBy('code')->get();

        return view('races.index', compact('races', 'venues'));
    }

    public function create(): View
    {
        $venues = Venue::orderBy('code')->get();
        return view('races.create', compact('venues'));
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $this->validateRace($request);

        $race = Race::create($validated);

        return redirect()->route('races.show', $race)
            ->with('status', 'レースを登録しました。続けて結果を入力してください。');
    }

    public function show(Race $race): View
    {
        try {
            $race->load([
                'venue',
                'results.horse',
                'results.jockey',
                'results.trainer',
                'notes.user',
                'payouts',
                'bets',
            ]);
        } catch (\Throwable $e) {
            Log::error('RaceController@show eager load failed', [
                'race_id' => $race->id,
                'message' => $e->getMessage(),
            ]);
        }

        // 払戻を券種別にまとめる
        $payoutsByKind = collect();
        try {
            $payoutsByKind = $race->payouts->groupBy('kind');
        } catch (\Throwable $e) {
            Log::error('RaceController@show payouts groupBy failed', [
                'race_id' => $race->id,
                'message' => $e->getMessage(),
            ]);
        }

        // 自分の馬券（このレースに対するもの）
        $myBets = collect();
        $userId = auth()->id();
        if ($userId) {
            try {
                $myBets = $race->bets()->where('user_id', $userId)->orderBy('id')->get();
            } catch (\Throwable $e) {
                Log::error('RaceController@show bets query failed', [
                    'race_id' => $race->id,
                    'message' => $e->getMessage(),
                ]);
            }
        }

        $myBetSummary = [
            'count'   => $myBets->count(),
            'stake'   => (int) $myBets->sum('total_stake'),
            'payout'  => (int) $myBets->sum('total_return'),
            'hit'     => (int) $myBets->where('hit_count', '>', 0)->count(),
        ];
        $myBetSummary['profit'] = $myBetSummary['payout'] - $myBetSummary['stake'];
        $myBetSummary['roi']    = $myBetSummary['stake'] > 0
            ? round($myBetSummary['payout'] / $myBetSummary['stake'] * 100, 1)
            : null;

        return view('races.show', compact('race', 'payoutsByKind', 'myBets', 'myBetSummary'));
    }

    public function edit(Race $race): View
    {
        $venues = Venue::orderBy('code')->get();
        return view('races.edit', compact('race', 'venues'));
    }

    public function update(Request $request, Race $race): RedirectResponse
    {
        $validated = $this->validateRace($request);
        $race->update($validated);

        return redirect()->route('races.show', $race)
            ->with('status', 'レース情報を更新しました');
    }

    public function destroy(Race $race): RedirectResponse
    {
        $race->delete();
        return redirect()->route('races.index')->with('status', 'レースを削除しました');
    }

    private function validateRace(Request $request): array
    {
        return $request->validate([
            'venue_id' => ['required', 'exists:venues,id'],
            'race_date' => ['required', 'date'],
            'kaisai_kai' => ['nullable', 'integer', 'min:1', 'max:10'],
            'kaisai_day' => ['nullable', 'integer', 'min:1', 'max:12'],
            'race_number' => ['required', 'integer', 'min:1', 'max:12'],
            'name' => ['required', 'string', 'max:100'],
            'grade' => ['nullable', 'string', 'max:20'],
            'race_class' => ['nullable', 'string', 'max:30'],
            'track_type' => ['required', 'in:芝,ダート,障害'],
            'distance' => ['required', 'integer', 'min:800', 'max:4250'],
            'direction' => ['nullable', 'in:右,左,直線'],
            'course_detail' => ['nullable', 'string', 'max:30'],
            'course_condition' => ['nullable', 'in:良,稍重,重,不良'],
            'weather' => ['nullable', 'string', 'max:10'],
            'pace' => ['nullable', 'in:H,M,S'],
            'first_3f' => ['nullable', 'string', 'max:10'],
            'last_3f' => ['nullable', 'string', 'max:10'],
            'horses_count' => ['nullable', 'integer', 'min:1', 'max:18'],
            'first_prize' => ['nullable', 'integer', 'min:0'],
            'notes' => ['nullable', 'string'],
        ]);
    }
}
