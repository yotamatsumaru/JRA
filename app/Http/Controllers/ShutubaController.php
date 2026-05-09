<?php

namespace App\Http\Controllers;

use App\Models\Bet;
use App\Models\BetLeg;
use App\Models\Race;
use App\Models\RaceMark;
use App\Models\RaceResult;
use App\Models\Venue;
use App\Services\PedigreeRecommendService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * 出馬表ベースの予想・分析ボード
 *
 * 結果が無いレース(=出馬表のみ)を対象に、印付け・メモ・推奨スコア・
 * 過去走/血統表示・印別馬券生成を1画面で提供する。
 *
 * Phase A: index / show / mark / memo
 * Phase B: 各馬の過去5走展開、血統ホバー、印一括コピー(クライアント側)
 * Phase C: コース傾向ヒント、印フィルタ、印別馬券生成
 */
class ShutubaController extends Controller
{
    public function __construct(protected PedigreeRecommendService $svc) {}

    /**
     * 出馬表一覧（結果がまだ無いレースのみ）
     *
     * クエリ:
     *   ?venue_id=    競馬場
     *   ?track_type=  芝/ダート/障害
     *   ?grade=       G1/G2/...
     *   ?from= ?to=   日付範囲
     *   ?keyword=     レース名キーワード
     *   ?include_done=1  着順入りのレースも含める(再予想用)
     */
    public function index(Request $request): View
    {
        $q = Race::with('venue')
            ->withCount(['results as entries_count'])
            ->withCount(['results as finished_count' => function ($qq) {
                $qq->whereNotNull('finish_position_int');
            }]);

        if ($request->filled('venue_id'))   $q->where('venue_id',   $request->venue_id);
        if ($request->filled('track_type')) $q->where('track_type', $request->track_type);
        if ($request->filled('grade'))      $q->where('grade',      $request->grade);
        if ($request->filled('from'))       $q->whereDate('race_date', '>=', $request->from);
        if ($request->filled('to'))         $q->whereDate('race_date', '<=', $request->to);
        if ($request->filled('keyword'))    $q->where('name', 'like', '%' . $request->keyword . '%');

        // デフォルトは「出走馬は登録済 かつ 確定着順がまだ無い」レースのみ
        $q->having('entries_count', '>=', 1);
        if (!$request->boolean('include_done')) {
            $q->having('finished_count', '=', 0);
        }

        $races = $q->orderByDesc('race_date')
            ->orderBy('race_number')
            ->paginate(30)
            ->withQueryString();

        // 印を付けた馬がいるレースを把握(バッジ表示用)
        $userId = $request->user()->id;
        $myMarkedRaceIds = RaceMark::where('user_id', $userId)
            ->whereIn('race_id', collect($races->items())->pluck('id'))
            ->pluck('race_id')
            ->unique()
            ->all();

        return view('shutuba.index', [
            'races'             => $races,
            'venues'            => Venue::orderBy('code')->get(),
            'my_marked_race_ids'=> array_flip($myMarkedRaceIds),
        ]);
    }

    /**
     * 予想ボード本体
     *
     * クエリ:
     *   ?filter_mark=◎  指定印を付けた馬だけ表示(Phase C)
     *   ?sort=horse_no|score|popularity|odds  並び順(default: horse_no)
     *   ?recompute=1    キャッシュを無視してスコア再計算
     */
    public function show(Race $race, Request $request): View
    {
        $userId = $request->user()->id;

        $race->load(['venue', 'results.horse', 'results.jockey', 'results.trainer']);

        $cond = [
            'venue_id'         => $race->venue_id,
            'track_type'       => $race->track_type,
            'distance'         => $race->distance,
            'course_condition' => $race->course_condition,
        ];

        $settings = $this->svc->getSettings();
        $weights  = $settings['weights'];
        $minRuns  = $settings['min_runs'];

        // 既存の印・スコアキャッシュを取得
        $marks = RaceMark::where('user_id', $userId)
            ->where('race_id', $race->id)
            ->get()
            ->keyBy('race_result_id');

        $recompute = $request->boolean('recompute');

        // 各馬の評価行を組み立て
        $rows = [];
        foreach ($race->results as $result) {
            $mark = $marks->get($result->id);

            // スコアキャッシュが無い or recompute なら再計算
            $needRecalc = $recompute
                || !$mark
                || $mark->score_total === null
                || $mark->scored_at === null
                || $mark->scored_at->lt(now()->subDays(7));

            $eval = null;
            if ($needRecalc && $result->horse) {
                $horse = $result->horse;
                $eval = $this->svc->evaluateHorse(
                    horse:    [
                        'id'            => $horse->id,
                        'father'        => $horse->father,
                        'mother_father' => $horse->mother_father,
                    ],
                    jockeyId: $result->jockey_id ? (int) $result->jockey_id : null,
                    cond:     $cond,
                    weights:  $weights,
                    minRuns:  $minRuns,
                );

                // upsert
                $mark = RaceMark::updateOrCreate(
                    ['user_id' => $userId, 'race_result_id' => $result->id],
                    [
                        'race_id'         => $race->id,
                        'mark'            => $mark?->mark,
                        'memo'            => $mark?->memo,
                        'score_total'     => round((float) $eval['total'], 2),
                        'score_pedigree'  => round((float) $eval['sub']['pedigree'], 2),
                        'score_jockey'    => round((float) $eval['sub']['jockey'], 2),
                        'score_horse'     => round((float) $eval['sub']['horse'], 2),
                        'score_roi'       => round((float) $eval['sub']['roi'], 2),
                        'scored_at'       => now(),
                    ]
                );
            }

            // 直近5走(過去成績)を取得
            $recent = [];
            if ($result->horse_id) {
                $recent = RaceResult::with(['race.venue'])
                    ->where('horse_id', $result->horse_id)
                    ->where('race_id', '!=', $race->id)
                    ->whereNotNull('finish_position_int')
                    ->whereHas('race', fn($qq) => $qq->where('race_date', '<=', $race->race_date))
                    ->join('races', 'races.id', '=', 'race_results.race_id')
                    ->orderByDesc('races.race_date')
                    ->select('race_results.*')
                    ->limit(5)
                    ->get();
            }

            // 種牡馬コース傾向ヒント (Phase C-1)
            $sireHint = null;
            if ($result->horse?->father) {
                $sireHint = $this->buildSireCourseHint($result->horse->father, $cond);
            }

            $rows[] = (object) [
                'result'    => $result,
                'horse'     => $result->horse,
                'jockey'    => $result->jockey,
                'trainer'   => $result->trainer,
                'mark_obj'  => $mark,           // RaceMark or null
                'mark'      => $mark?->mark,
                'memo'      => $mark?->memo,
                'eval'      => $eval,           // 直近で再計算した場合のみ
                'recent'    => $recent,
                'sire_hint' => $sireHint,
            ];
        }

        // 並び替え
        $sort = $request->get('sort', 'horse_no');
        $rows = $this->sortRows($rows, $sort);

        // 印フィルタ (Phase C-2)
        $filterMark = $request->get('filter_mark');
        if ($filterMark && in_array($filterMark, RaceMark::MARKS, true)) {
            $rows = array_values(array_filter($rows, fn($r) => $r->mark === $filterMark));
        } elseif ($filterMark === 'none') {
            $rows = array_values(array_filter($rows, fn($r) => empty($r->mark)));
        } elseif ($filterMark === 'marked') {
            $rows = array_values(array_filter($rows, fn($r) => !empty($r->mark)));
        }

        // 印別馬券生成 (Phase C-3)
        $recommendedBets = $this->buildRecommendedBets($race);

        // 印サマリ (◎○▲△☆✕ 各印の馬番一覧)
        $markSummary = [];
        foreach (RaceMark::MARKS as $m) {
            $markSummary[$m] = [];
        }
        foreach ($rows as $r) {
            if ($r->mark && isset($markSummary[$r->mark])) {
                $markSummary[$r->mark][] = $r->result->horse_number;
            }
        }
        // フィルタの影響を受けないよう、サマリは全馬から再計算
        $allMarks = RaceMark::where('user_id', $userId)
            ->where('race_id', $race->id)
            ->whereNotNull('mark')
            ->get();
        foreach (RaceMark::MARKS as $m) {
            $markSummary[$m] = [];
        }
        foreach ($allMarks as $m) {
            $rr = $race->results->firstWhere('id', $m->race_result_id);
            if ($rr && $m->mark && isset($markSummary[$m->mark])) {
                $markSummary[$m->mark][] = $rr->horse_number;
            }
        }
        foreach ($markSummary as $k => $v) {
            sort($markSummary[$k]);
        }

        return view('shutuba.show', [
            'race'             => $race,
            'rows'             => $rows,
            'cond'             => $cond,
            'settings'         => $settings,
            'sort'             => $sort,
            'filter_mark'      => $filterMark,
            'recommended_bets' => $recommendedBets,
            'mark_summary'     => $markSummary,
        ]);
    }

    /**
     * 印を更新(Ajax)
     *
     * POST /shutuba/{race}/mark
     * body: { race_result_id: int, mark: string|null }
     */
    public function mark(Race $race, Request $request): JsonResponse
    {
        $validated = $request->validate([
            'race_result_id' => ['required', 'integer'],
            'mark'           => ['nullable', 'string', 'max:4'],
        ]);

        // 印は MARKS のいずれか or 空
        $mark = $validated['mark'] ?? null;
        if ($mark !== null && $mark !== '' && !in_array($mark, RaceMark::MARKS, true)) {
            return response()->json(['ok' => false, 'error' => 'invalid mark'], 422);
        }
        if ($mark === '') $mark = null;

        // 該当 race_result が当該レースに属するかチェック
        $rr = RaceResult::where('id', $validated['race_result_id'])
            ->where('race_id', $race->id)
            ->first();
        if (!$rr) {
            return response()->json(['ok' => false, 'error' => 'race_result not found'], 404);
        }

        $userId = $request->user()->id;
        $rm = RaceMark::updateOrCreate(
            ['user_id' => $userId, 'race_result_id' => $rr->id],
            ['race_id' => $race->id, 'mark' => $mark]
        );

        return response()->json([
            'ok'   => true,
            'mark' => $rm->mark,
            'id'   => $rm->id,
        ]);
    }

    /**
     * メモを更新(Ajax)
     */
    public function memo(Race $race, Request $request): JsonResponse
    {
        $validated = $request->validate([
            'race_result_id' => ['required', 'integer'],
            'memo'           => ['nullable', 'string', 'max:2000'],
        ]);

        $rr = RaceResult::where('id', $validated['race_result_id'])
            ->where('race_id', $race->id)
            ->first();
        if (!$rr) {
            return response()->json(['ok' => false, 'error' => 'race_result not found'], 404);
        }

        $userId = $request->user()->id;
        $rm = RaceMark::updateOrCreate(
            ['user_id' => $userId, 'race_result_id' => $rr->id],
            ['race_id' => $race->id, 'memo' => $validated['memo'] ?? null]
        );

        return response()->json(['ok' => true, 'id' => $rm->id]);
    }

    /**
     * 印別馬券を生成して bets/bet_legs に登録 (Phase C-3)
     *
     * POST /shutuba/{race}/generate-bets
     * body: { kinds: ['tan', 'fuku', 'uma-ren', 'wide', 'san-fuku', 'san-tan'], unit_stake: 100 }
     */
    public function generateBets(Race $race, Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'kinds'      => ['required', 'array', 'min:1'],
            'kinds.*'    => ['string', 'in:tan,fuku,uma-ren,uma-tan,wide,san-fuku,san-tan'],
            'unit_stake' => ['required', 'integer', 'min:100', 'max:100000'],
        ]);

        $userId    = $request->user()->id;
        $unitStake = (int) $validated['unit_stake'];

        // 印 → 馬番マップを作る
        $rows = $race->results()->with('marks')->get();
        $byMark = ['◎' => [], '○' => [], '▲' => [], '△' => [], '☆' => [], '✕' => []];
        foreach ($rows as $rr) {
            $mark = $rr->marks->firstWhere('user_id', $userId);
            if ($mark && $mark->mark && isset($byMark[$mark->mark])) {
                $byMark[$mark->mark][] = (int) $rr->horse_number;
            }
        }
        foreach ($byMark as $k => $v) sort($byMark[$k]);

        $hasAny = collect($byMark)->flatten()->isNotEmpty();
        if (!$hasAny) {
            return back()->withErrors(['kinds' => '印が1つも付いていません']);
        }

        $generated = 0;
        $skipped = [];
        DB::transaction(function () use ($race, $userId, $unitStake, $validated, $byMark, &$generated, &$skipped) {
            foreach ($validated['kinds'] as $kind) {
                $combos = $this->buildCombosByMark($kind, $byMark);
                if (empty($combos)) {
                    $skipped[] = $kind;
                    continue;
                }

                $points = count($combos);
                $bet = Bet::create([
                    'user_id'      => $userId,
                    'race_id'      => $race->id,
                    'kind'         => $kind,
                    'method'       => count($combos) > 1 ? 'box' : 'single',
                    'unit_stake'   => $unitStake,
                    'points'       => $points,
                    'total_stake'  => $unitStake * $points,
                    'is_settled'   => false,
                    'selection'    => ['from' => 'shutuba_marks', 'marks' => $byMark],
                    'memo'         => '出馬表ボードから自動生成',
                ]);

                foreach ($combos as $c) {
                    BetLeg::create([
                        'bet_id'      => $bet->id,
                        'combination' => $c,
                        'stake'       => $unitStake,
                        'is_hit'      => false,
                        'payout'      => 0,
                    ]);
                }

                $generated++;
            }
        });

        $msg = "{$generated} 件の馬券を登録しました";
        if (!empty($skipped)) {
            $msg .= ' (スキップ: ' . implode(',', $skipped) . ' = 必要な印が不足)';
        }
        return redirect()
            ->route('shutuba.show', $race)
            ->with('status', $msg);
    }

    // ======================================================================
    // 内部ヘルパ
    // ======================================================================

    /**
     * 行の並び替え
     */
    private function sortRows(array $rows, string $sort): array
    {
        $cmp = match ($sort) {
            'score' => fn($a, $b) =>
                ($b->mark_obj?->score_total ?? -1) <=> ($a->mark_obj?->score_total ?? -1),
            'popularity' => fn($a, $b) =>
                ($a->result->popularity ?? 999) <=> ($b->result->popularity ?? 999),
            'odds' => fn($a, $b) =>
                (float)($a->result->win_odds ?? 9999) <=> (float)($b->result->win_odds ?? 9999),
            default => fn($a, $b) =>
                ($a->result->horse_number ?? 999) <=> ($b->result->horse_number ?? 999),
        };
        usort($rows, $cmp);
        return $rows;
    }

    /**
     * 種牡馬 × 当該レース条件 の勝率/複勝率を取得 (Phase C-1)
     *
     * @return array{runs:int, wins:int, shows:int, win_rate:float, show_rate:float}|null
     */
    private function buildSireCourseHint(string $father, array $cond): ?array
    {
        if (!$father) return null;

        $q = DB::table('race_results')
            ->join('horses', 'horses.id', '=', 'race_results.horse_id')
            ->join('races',  'races.id',  '=', 'race_results.race_id')
            ->where('horses.father', $father)
            ->whereNotNull('race_results.finish_position_int');

        if (!empty($cond['venue_id']))   $q->where('races.venue_id', $cond['venue_id']);
        if (!empty($cond['track_type'])) $q->where('races.track_type', $cond['track_type']);
        if (!empty($cond['distance'])) {
            $d = (int) $cond['distance'];
            $q->whereBetween('races.distance', [$d - 200, $d + 200]);
        }

        $row = $q->selectRaw("count(*) as runs,
            SUM(CASE WHEN finish_position_int=1 THEN 1 ELSE 0 END) as wins,
            SUM(CASE WHEN finish_position_int<=3 THEN 1 ELSE 0 END) as shows
        ")->first();

        $runs = (int) ($row->runs ?? 0);
        if ($runs < 5) return null;

        $wins = (int) ($row->wins ?? 0);
        $shows = (int) ($row->shows ?? 0);
        return [
            'runs'      => $runs,
            'wins'      => $wins,
            'shows'     => $shows,
            'win_rate'  => $runs > 0 ? round($wins / $runs * 100, 1)  : 0,
            'show_rate' => $runs > 0 ? round($shows / $runs * 100, 1) : 0,
        ];
    }

    /**
     * 全馬の印から推奨買い目セットを構築 (Phase C-3 表示用)
     *
     * @return array<int, array{type:string, combo:string, points:int, detail:string}>
     */
    private function buildRecommendedBets(Race $race): array
    {
        $userId = auth()->id();
        $marks = RaceMark::where('user_id', $userId)
            ->where('race_id', $race->id)
            ->whereNotNull('mark')
            ->get();
        if ($marks->isEmpty()) return [];

        $resultsById = $race->results->keyBy('id');
        $byMark = ['◎' => [], '○' => [], '▲' => [], '△' => [], '☆' => [], '✕' => []];
        foreach ($marks as $m) {
            $rr = $resultsById->get($m->race_result_id);
            if (!$rr) continue;
            if ($m->mark && isset($byMark[$m->mark])) {
                $byMark[$m->mark][] = (int) $rr->horse_number;
            }
        }
        foreach ($byMark as $k => $v) sort($byMark[$k]);

        $bets = [];

        // 単勝・複勝: ◎
        if (!empty($byMark['◎'])) {
            $h = $byMark['◎'][0];
            $bets[] = ['type' => '単勝',  'combo' => "{$h}",     'points' => 1, 'detail' => '◎本命の単勝'];
            $bets[] = ['type' => '複勝',  'combo' => "{$h}",     'points' => 1, 'detail' => '◎本命の複勝'];
        }

        // 馬連 ◎-○ , 馬単 ◎→○, ワイド ◎-{○,▲,△}
        if (!empty($byMark['◎']) && !empty($byMark['○'])) {
            $h = $byMark['◎'][0];
            $o = $byMark['○'][0];
            [$a, $b] = $h < $o ? [$h, $o] : [$o, $h];
            $bets[] = ['type' => '馬連',  'combo' => "{$a}-{$b}", 'points' => 1, 'detail' => '◎-○'];
            $bets[] = ['type' => '馬単',  'combo' => "{$h}-{$o}", 'points' => 1, 'detail' => '◎→○ (1着固定)'];
        }
        if (!empty($byMark['◎'])) {
            $h = $byMark['◎'][0];
            $opps = array_unique(array_merge($byMark['○'], $byMark['▲'], $byMark['△']));
            sort($opps);
            $combos = [];
            foreach ($opps as $o) {
                if ($o === $h) continue;
                [$a, $b] = $h < $o ? [$h, $o] : [$o, $h];
                $combos[] = "{$a}-{$b}";
            }
            if (!empty($combos)) {
                $bets[] = [
                    'type'   => 'ワイド',
                    'combo'  => implode(' / ', $combos),
                    'points' => count($combos),
                    'detail' => '◎-{○▲△} 流し',
                ];
            }
        }

        // 3連複: ◎○▲ ボックス, ◎○▲△ ボックス
        $core3 = array_filter([$byMark['◎'][0] ?? null, $byMark['○'][0] ?? null, $byMark['▲'][0] ?? null]);
        if (count($core3) >= 3) {
            sort($core3);
            $bets[] = [
                'type'   => '3連複',
                'combo'  => implode('-', $core3),
                'points' => 1,
                'detail' => '◎○▲ ボックス',
            ];
        }
        if (count($core3) >= 3 && !empty($byMark['△'])) {
            $with = array_unique(array_merge($core3, $byMark['△']));
            sort($with);
            $combos = [];
            $n = count($with);
            for ($i = 0; $i < $n; $i++) {
                for ($j = $i + 1; $j < $n; $j++) {
                    for ($k = $j + 1; $k < $n; $k++) {
                        $combos[] = "{$with[$i]}-{$with[$j]}-{$with[$k]}";
                    }
                }
            }
            if (!empty($combos)) {
                $bets[] = [
                    'type'   => '3連複(△追加)',
                    'combo'  => count($combos) <= 3 ? implode(' / ', $combos) : (count($combos) . '点ボックス'),
                    'points' => count($combos),
                    'detail' => '◎○▲△ ボックス',
                ];
            }
        }

        // 3連単 ◎→○→▲
        if (!empty($byMark['◎'][0] ?? null) && !empty($byMark['○'][0] ?? null) && !empty($byMark['▲'][0] ?? null)) {
            $bets[] = [
                'type'   => '3連単',
                'combo'  => "{$byMark['◎'][0]}-{$byMark['○'][0]}-{$byMark['▲'][0]}",
                'points' => 1,
                'detail' => '◎→○→▲ 1着固定2着固定',
            ];
        }

        return $bets;
    }

    /**
     * 馬券種ごとの組合せを構築（generateBets で使う）
     *
     * @return string[]  combination 文字列の配列(BetLeg.combination 形式)
     */
    private function buildCombosByMark(string $kind, array $byMark): array
    {
        $H = $byMark['◎'][0] ?? null;  // ◎(1頭目)
        $O = $byMark['○'][0] ?? null;  // ○
        $S = $byMark['▲'][0] ?? null;  // ▲
        $D = $byMark['△'];             // △複数
        $Star = $byMark['☆'];          // ☆複数

        switch ($kind) {
            case 'tan':
                return $H ? [(string) $H] : [];

            case 'fuku':
                return $H ? [(string) $H] : [];

            case 'uma-ren':
                if ($H && $O) {
                    [$a, $b] = $H < $O ? [$H, $O] : [$O, $H];
                    return ["{$a}-{$b}"];
                }
                return [];

            case 'uma-tan':
                if ($H && $O) {
                    return ["{$H}-{$O}"];
                }
                return [];

            case 'wide':
                $opps = array_unique(array_merge(array_filter([$O, $S]), $D));
                if (!$H || empty($opps)) return [];
                $combos = [];
                foreach ($opps as $o) {
                    if ($o === $H) continue;
                    [$a, $b] = $H < $o ? [$H, $o] : [$o, $H];
                    $combos[] = "{$a}-{$b}";
                }
                return array_values(array_unique($combos));

            case 'san-fuku':
                $core = array_filter([$H, $O, $S]);
                if (count($core) < 3) return [];
                $with = array_values(array_unique(array_merge($core, $D)));
                sort($with);
                $combos = [];
                $n = count($with);
                for ($i = 0; $i < $n; $i++) {
                    for ($j = $i + 1; $j < $n; $j++) {
                        for ($k = $j + 1; $k < $n; $k++) {
                            $combos[] = "{$with[$i]}-{$with[$j]}-{$with[$k]}";
                        }
                    }
                }
                return $combos;

            case 'san-tan':
                if (!$H || !$O || !$S) return [];
                // ◎→○→▲ 1点(本命固定型)
                return ["{$H}-{$O}-{$S}"];
        }
        return [];
    }
}
