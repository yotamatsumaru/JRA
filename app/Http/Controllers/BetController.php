<?php

namespace App\Http\Controllers;

use App\Models\Bet;
use App\Models\BetLeg;
use App\Models\Race;
use App\Services\BetTicketService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class BetController extends Controller
{
    public function __construct(protected BetTicketService $tickets)
    {
    }

    public function index(Request $request): View
    {
        $userId = Auth::id();

        $query = Bet::with(['race.venue', 'legs'])
            ->where('user_id', $userId);

        // フィルタ
        if ($request->filled('kind')) {
            $query->where('kind', $request->kind);
        }
        if ($request->filled('status')) {
            match ($request->status) {
                'hit'    => $query->where('hit_count', '>', 0),
                'miss'   => $query->where('is_settled', true)->where('hit_count', 0),
                'open'   => $query->where('is_settled', false),
                default  => null,
            };
        }
        if ($request->filled('from')) {
            $query->whereHas('race', fn($q) => $q->whereDate('race_date', '>=', $request->from));
        }
        if ($request->filled('to')) {
            $query->whereHas('race', fn($q) => $q->whereDate('race_date', '<=', $request->to));
        }
        if ($request->filled('venue_id')) {
            $query->whereHas('race', fn($q) => $q->where('venue_id', $request->venue_id));
        }

        $bets = $query->orderByDesc('id')->paginate(30)->withQueryString();

        // 集計サマリ
        $summary = $this->summary($userId, $request);

        $venues = \App\Models\Venue::orderBy('code')->get();
        $kinds  = Bet::KIND_LABELS;

        return view('bets.index', compact('bets', 'summary', 'venues', 'kinds'));
    }

    protected function summary(int $userId, Request $request): array
    {
        $base = Bet::where('user_id', $userId);
        if ($request->filled('from')) $base->whereHas('race', fn($q) => $q->whereDate('race_date', '>=', $request->from));
        if ($request->filled('to'))   $base->whereHas('race', fn($q) => $q->whereDate('race_date', '<=', $request->to));
        if ($request->filled('venue_id')) $base->whereHas('race', fn($q) => $q->where('venue_id', $request->venue_id));
        if ($request->filled('kind')) $base->where('kind', $request->kind);

        $row = (clone $base)->selectRaw('
            COUNT(*) as bet_count,
            COALESCE(SUM(total_stake),0)  as stake,
            COALESCE(SUM(total_return),0) as ret,
            COALESCE(SUM(CASE WHEN hit_count > 0 THEN 1 ELSE 0 END),0) as hits,
            COALESCE(SUM(CASE WHEN is_settled = 1 THEN 1 ELSE 0 END),0) as settled
        ')->first();

        $stake = (int) ($row->stake ?? 0);
        $ret   = (int) ($row->ret ?? 0);

        return [
            'count'    => (int) ($row->bet_count ?? 0),
            'stake'    => $stake,
            'return'   => $ret,
            'profit'   => $ret - $stake,
            'hits'     => (int) ($row->hits ?? 0),
            'settled'  => (int) ($row->settled ?? 0),
            'hit_rate' => ($row->settled ?? 0) > 0 ? round($row->hits / $row->settled * 100, 1) : null,
            'roi'      => $stake > 0 ? round($ret / $stake * 100, 1) : null,
        ];
    }

    public function create(Request $request): View
    {
        $race = null;
        if ($request->filled('race_id')) {
            $race = Race::with(['venue', 'results'])->find($request->race_id);
        }
        $races = Race::with('venue')
            ->orderByDesc('race_date')
            ->orderByDesc('race_number')
            ->limit(100)
            ->get();
        $kinds   = Bet::KIND_LABELS;
        $methods = Bet::METHOD_LABELS;

        return view('bets.create', compact('race', 'races', 'kinds', 'methods'));
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $this->validateBet($request);
        $bet = $this->save(new Bet(['user_id' => Auth::id()]), $validated);

        // レース結果がすでに登録済なら自動精算
        $this->autoSettleIfPossible($bet);

        return redirect()->route('bets.show', $bet)
            ->with('status', '馬券を登録しました（' . $bet->points . '点 ' . number_format($bet->total_stake) . '円）');
    }

    public function show(Bet $bet): View
    {
        $this->authorizeBet($bet);
        $bet->load(['race.venue', 'race.results.horse', 'legs', 'race.payouts']);
        return view('bets.show', compact('bet'));
    }

    public function edit(Bet $bet): View
    {
        $this->authorizeBet($bet);
        $bet->load('race.venue', 'legs');
        $races = Race::with('venue')->orderByDesc('race_date')->limit(100)->get();
        $kinds   = Bet::KIND_LABELS;
        $methods = Bet::METHOD_LABELS;
        return view('bets.edit', compact('bet', 'races', 'kinds', 'methods'));
    }

    public function update(Request $request, Bet $bet): RedirectResponse
    {
        $this->authorizeBet($bet);
        $validated = $this->validateBet($request);
        $bet = $this->save($bet, $validated);

        $this->autoSettleIfPossible($bet);

        return redirect()->route('bets.show', $bet)->with('status', '馬券を更新しました');
    }

    public function destroy(Bet $bet): RedirectResponse
    {
        $this->authorizeBet($bet);
        $bet->delete();
        return redirect()->route('bets.index')->with('status', '馬券を削除しました');
    }

    /** 手動精算（レース結果を後から登録した場合の再判定にも使う） */
    public function settle(Bet $bet): RedirectResponse
    {
        $this->authorizeBet($bet);
        $result = $this->tickets->settle($bet);
        return back()->with('status', sprintf(
            '精算完了: %d点的中 / 払戻 %s円',
            $result['hit_count'],
            number_format($result['total_return'])
        ));
    }

    // ==========================================================
    //  内部ヘルパー
    // ==========================================================

    protected function authorizeBet(Bet $bet): void
    {
        abort_unless($bet->user_id === Auth::id(), 403);
    }

    protected function validateBet(Request $request): array
    {
        return $request->validate([
            'race_id'      => ['required', 'exists:races,id'],
            'kind'         => ['required', 'in:tan,fuku,waku-ren,uma-ren,uma-tan,wide,san-fuku,san-tan'],
            'method'       => ['required', 'in:single,box,formation'],
            'unit_stake'   => ['required', 'integer', 'min:100', 'max:1000000'],
            'numbers'      => ['nullable', 'array'],
            'numbers.*'    => ['integer', 'min:1', 'max:18'],
            'axis'         => ['nullable', 'array'],
            'axis.*'       => ['integer', 'min:1', 'max:18'],
            'second'       => ['nullable', 'array'],
            'second.*'     => ['integer', 'min:1', 'max:18'],
            'third'        => ['nullable', 'array'],
            'third.*'      => ['integer', 'min:1', 'max:18'],
            'purchased_at' => ['nullable', 'date'],
            'memo'         => ['nullable', 'string', 'max:1000'],
        ]);
    }

    /** Bet本体保存＆BetLeg展開 */
    protected function save(Bet $bet, array $v): Bet
    {
        return DB::transaction(function () use ($bet, $v) {
            $selection = [
                'numbers' => $v['numbers'] ?? [],
                'axis'    => $v['axis']    ?? [],
                'second'  => $v['second']  ?? [],
                'third'   => $v['third']   ?? [],
            ];

            $combos = $this->tickets->expandCombinations($v['kind'], $v['method'], $selection);
            if (empty($combos)) {
                abort(422, '買い目の組合せが0点です。入力を確認してください。');
            }

            $bet->fill([
                'race_id'      => $v['race_id'],
                'kind'         => $v['kind'],
                'method'       => $v['method'],
                'unit_stake'   => (int) $v['unit_stake'],
                'points'       => count($combos),
                'total_stake'  => count($combos) * (int) $v['unit_stake'],
                'selection'    => $selection,
                'purchased_at' => $v['purchased_at'] ?? null,
                'memo'         => $v['memo'] ?? null,
                'is_settled'   => false,
                'hit_count'    => 0,
                'total_return' => 0,
            ]);
            $bet->save();

            // 既存の legs を全削除して再作成
            $bet->legs()->delete();
            $rows = [];
            foreach ($combos as $c) {
                $rows[] = [
                    'bet_id'      => $bet->id,
                    'combination' => $c,
                    'stake'       => (int) $v['unit_stake'],
                    'created_at'  => now(),
                    'updated_at'  => now(),
                ];
            }
            BetLeg::insert($rows);

            return $bet->fresh('legs');
        });
    }

    /** レース結果が登録済なら自動精算 */
    protected function autoSettleIfPossible(Bet $bet): void
    {
        $bet->loadMissing('race.results');
        if ($bet->race && $bet->race->results->isNotEmpty()) {
            $this->tickets->settle($bet);
        }
    }
}
