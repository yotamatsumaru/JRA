<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Bet;
use App\Models\BetLeg;
use App\Models\OddsSnapshot;
use App\Models\Race;
use App\Models\RaceMark;
use App\Models\RaceResult;
use App\Models\Venue;
use App\Services\NetkeibaScraper;
use App\Services\OddsSnapshotService;
use App\Services\PedigreeRecommendService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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
        // ゲスト閲覧時 ($request->user() === null) はマーク無しとして扱う
        $userId  = optional($request->user())->id;
        $raceIds = collect($races->items())->pluck('id');
        $myMarkedRaceIds = [];
        if ($userId) {
            $myMarkedRaceIds = RaceMark::where('user_id', $userId)
                ->whereIn('race_id', $raceIds)
                ->pluck('race_id')
                ->unique()
                ->all();
        }

        // ライブオッズの最新取得時刻を一覧バッジ用に取得 (Phase EV-2)
        //   race_id => 最新 captured_at の Carbon
        $liveOddsLatestAt = [];
        if ($raceIds->isNotEmpty()) {
            OddsSnapshot::selectRaw('race_id, MAX(captured_at) as latest_at')
                ->whereIn('race_id', $raceIds)
                ->groupBy('race_id')
                ->get()
                ->each(function ($row) use (&$liveOddsLatestAt) {
                    $liveOddsLatestAt[(int) $row->race_id] = $row->latest_at
                        ? \Carbon\Carbon::parse($row->latest_at)
                        : null;
                });
        }

        return view('shutuba.index', [
            'races'              => $races,
            'venues'             => Venue::orderBy('code')->get(),
            'my_marked_race_ids' => array_flip($myMarkedRaceIds),
            'live_odds_latest_at'=> $liveOddsLatestAt, // race_id => Carbon|null
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
        // ゲスト閲覧時はマーク・メモが見えないだけで、出馬表本体は表示される
        $userId = optional($request->user())->id;

        $race->load(['venue', 'results.horse', 'results.jockey', 'results.trainer']);

        $cond = [
            'venue_id'         => $race->venue_id,
            'track_type'       => $race->track_type,
            'distance'         => $race->distance,
            'course_condition' => $race->course_condition,
            'direction'        => $race->direction,    // 右/左/直線 — courseScore で使う
        ];

        $settings = $this->svc->getSettings();
        $weights  = $settings['weights'];
        $minRuns  = $settings['min_runs'];

        // 既存の印・スコアキャッシュを取得 (ゲスト閲覧時は空コレクション)
        $marks = $userId
            ? RaceMark::where('user_id', $userId)
                ->where('race_id', $race->id)
                ->get()
                ->keyBy('race_result_id')
            : collect();

        $recompute = $request->boolean('recompute');

        // -- 最新オッズスナップショット読込 (Phase EV-2)
        //    odds_snapshots テーブルの最新行を読み、horse_number=>['win_odds'=>..,'popularity'=>..] の形に整形。
        //    出馬表時点の race_results.win_odds より優先して EV 計算に使う。
        //    スナップショットが無い場合は null のまま (フォールバックで race_results.win_odds を使う)
        $latestSnapshot = OddsSnapshot::where('race_id', $race->id)
            ->orderByDesc('captured_at')
            ->first();
        $liveOddsMap = [];   // horse_number => ['win_odds' => float|null, 'popularity' => int|null]
        $liveOddsAt  = null; // 取得時刻 (画面表示用)
        if ($latestSnapshot && is_array($latestSnapshot->payload)) {
            $liveOddsAt = $latestSnapshot->captured_at;
            foreach ($latestSnapshot->payload as $hno => $row) {
                $hno = (int) $hno;
                if ($hno < 1) continue;
                $liveOddsMap[$hno] = [
                    'win_odds'   => isset($row['win_odds']) ? (float) $row['win_odds'] : null,
                    'popularity' => isset($row['popularity']) ? (int) $row['popularity'] : null,
                ];
            }
        }

        // -- 1パス目: 各馬の過去走/脚質を先に集計してペースを推定 --
        //    (脚質スコアは pace に依存するため evaluateHorse の前に決定する必要がある)
        $precalc = [];
        foreach ($race->results as $result) {
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
            $runningStyle = $this->estimateRunningStyle($recent);
            $precalc[$result->id] = [
                'recent'        => $recent,
                'running_style' => $runningStyle,
            ];
        }

        // ペース判定: 逃げ宣言馬2頭以上で fast(ハイ)、それ以外 slow
        $stylesList = array_map(fn($p) => $p['running_style'], $precalc);
        $pace = $this->svc->estimatePace($stylesList);
        $cond['pace'] = $pace;

        // -- 2パス目: 各馬の評価行を組み立て --
        $rows = [];
        foreach ($race->results as $result) {
            $mark = $marks->get($result->id);
            $pre  = $precalc[$result->id] ?? ['recent' => [], 'running_style' => '不'];
            $runningStyle = $pre['running_style'];
            $recent       = $pre['recent'];

            // スコアキャッシュが無い or recompute なら再計算
            //   旧キャッシュ(枠/コース/脚質カラム未保存)も再計算対象にする
            //   さらに「騎手 ID があるのに score_jockey=0 のもの」も古いバグの被害者なので再計算
            $hasJockeyButZeroScore = $result->jockey_id
                && $mark
                && $mark->score_jockey !== null
                && (float) $mark->score_jockey === 0.0;
            $hasHorseButZeroCourse = $result->horse_id
                && $mark
                && $mark->score_course !== null
                && (float) $mark->score_course === 0.0;
            // 「血統・騎手・馬・回収・コース」の5つの『過去走由来』スコアのうち、
            //  3つ以上が 0.0 のままで かつ scored_at が今日より前なら、
            //  キャッシュ汚染や jockey_id 不整合の可能性が高いので 1 回だけ自動再計算する。
            //  (新馬戦などで本当に過去走が無いケースでは、再計算しても 0 のまま scored_at が今日になり、
            //   翌日以降の表示では再走しない)
            $zeroCount = 0;
            if ($mark) {
                foreach (['score_pedigree','score_jockey','score_horse','score_roi','score_course'] as $col) {
                    if ($mark->{$col} !== null && (float) $mark->{$col} === 0.0) $zeroCount++;
                }
            }
            $tooManyZeros = $mark
                && $zeroCount >= 3
                && (!$mark->scored_at || $mark->scored_at->lt(now()->startOfDay()));

            $needRecalc = $recompute
                || !$mark
                || $mark->score_total === null
                || $mark->scored_at === null
                || $mark->scored_at->lt(now()->subDays(7))
                || $mark->score_frame === null
                || $mark->score_course === null
                || $mark->score_style === null
                || $hasJockeyButZeroScore   // 旧バグで騎手スコアが0のまま保存されたケース
                || $hasHorseButZeroCourse   // 同上、コーススコアが0のまま
                || $tooManyZeros;           // 過去走由来スコアの大半が0(キャッシュ汚染の可能性)

            $eval = null;
            if ($needRecalc && $result->horse) {
                $horse = $result->horse;
                $eval = $this->svc->evaluateHorse(
                    horse:    [
                        'id'            => $horse->id,
                        'father'        => $horse->father,
                        'mother_father' => $horse->mother_father,
                        'frame_number'  => $result->frame_number,
                        'running_style' => $runningStyle,
                    ],
                    jockeyId: $result->jockey_id ? (int) $result->jockey_id : null,
                    cond:     $cond,
                    weights:  $weights,
                    minRuns:  $minRuns,
                    // ?recompute=1 か「ゼロ多すぎ」検出時は内部の Cache::remember も無視して完全再集計する
                    forceRefresh: $recompute || $tooManyZeros,
                );

                // upsert: ログインユーザーのみ DB にスコアを保存。
                // ゲスト閲覧時はオンメモリの RaceMark インスタンスを作って画面表示にだけ使う。
                $scoreAttrs = [
                    'race_id'         => $race->id,
                    'mark'            => $mark?->mark,
                    'memo'            => $mark?->memo,
                    'score_total'     => round((float) $eval['total'], 2),
                    'score_pedigree'  => round((float) $eval['sub']['pedigree'], 2),
                    'score_jockey'    => round((float) $eval['sub']['jockey'], 2),
                    'score_horse'     => round((float) $eval['sub']['horse'], 2),
                    'score_roi'       => round((float) $eval['sub']['roi'], 2),
                    'score_frame'     => round((float) $eval['sub']['frame'], 2),
                    'score_course'    => round((float) $eval['sub']['course'], 2),
                    'score_style'     => round((float) $eval['sub']['style'], 2),
                    'scored_at'       => now(),
                ];
                if ($userId) {
                    $mark = RaceMark::updateOrCreate(
                        ['user_id' => $userId, 'race_result_id' => $result->id],
                        $scoreAttrs
                    );
                } else {
                    // ゲスト: DB 書き込みせず、画面表示用の一時インスタンスだけ作る
                    $mark = new RaceMark(array_merge($scoreAttrs, [
                        'user_id'        => null,
                        'race_result_id' => $result->id,
                    ]));
                }
            }

            // 種牡馬コース傾向ヒント (Phase C-1)
            $sireHint = null;
            if ($result->horse?->father) {
                $sireHint = $this->buildSireCourseHint($result->horse->father, $cond);
            }

            // 期待値計算 (Phase 1-B → EV-2: ライブオッズ優先)
            //   優先順:
            //     1) odds_snapshots の最新 win_odds (10分毎の自動取得 or 手動取得)
            //     2) race_results.win_odds (出馬表/結果インポート時)
            //   どちらの出典かを ev[source] に記録してテンプレートでバッジ表示する
            $ev = null;
            $oddsSource = null;
            $usedWinOdds = null;
            $live = $liveOddsMap[$result->horse_number] ?? null;
            if ($live && $live['win_odds']) {
                $usedWinOdds = (float) $live['win_odds'];
                $oddsSource  = 'live';
            } elseif ($result->win_odds) {
                $usedWinOdds = (float) $result->win_odds;
                $oddsSource  = 'static';
            }
            if ($usedWinOdds && $mark?->score_total !== null) {
                $ev = $this->calcExpectedValue((float) $mark->score_total, $usedWinOdds);
                $ev['source']      = $oddsSource;
                $ev['win_odds']    = $usedWinOdds;
                $ev['captured_at'] = $oddsSource === 'live' ? $liveOddsAt : null;
            }

            $rows[] = (object) [
                'result'        => $result,
                'horse'         => $result->horse,
                'jockey'        => $result->jockey,
                'trainer'       => $result->trainer,
                'mark_obj'      => $mark,
                'mark'          => $mark?->mark,
                'memo'          => $mark?->memo,
                'eval'          => $eval,
                'recent'        => $recent,
                'sire_hint'     => $sireHint,
                'running_style' => $runningStyle,    // 脚質: 逃/先/差/追/不
                'ev'            => $ev,              // 期待値 + 推定勝率
                'is_favorite'   => false,            // M で更新
                // ライブオッズ (Phase EV-2, ライブ)
                //   テンプレの単オッズ列/人気列は EV 計算の有無に依存せず、
                //   ここに入れた live_* を最優先で表示する。
                //   スコア未算出の馬でも「最新オッズ取得」した瞬間から表に反映される。
                'live_win_odds'    => $live['win_odds']    ?? null,
                'live_popularity'  => $live['popularity']  ?? null,
                'live_captured_at' => $live ? $liveOddsAt : null,
            ];
        }

        // ペース予想 (Phase 1-A): 出走馬全体の脚質構成から推定
        $paceForecast = $this->forecastPace($rows);

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
        // フィルタの影響を受けないよう、サマリは全馬から再計算 (ゲスト時は空)
        $allMarks = $userId
            ? RaceMark::where('user_id', $userId)
                ->where('race_id', $race->id)
                ->whereNotNull('mark')
                ->get()
            : collect();
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

        // お気に入り判定 (Phase 1-M) — ゲスト時は空配列
        $favHorseIds   = $userId ? \App\Models\Favorite::userKey($userId, 'horse')   : [];
        $favJockeyIds  = $userId ? \App\Models\Favorite::userKey($userId, 'jockey')  : [];
        $favTrainerIds = $userId ? \App\Models\Favorite::userKey($userId, 'trainer') : [];
        foreach ($rows as $r) {
            $r->is_favorite = ($r->horse && in_array($r->horse->id, $favHorseIds, true))
                || ($r->jockey && in_array($r->jockey->id, $favJockeyIds, true))
                || ($r->trainer && in_array($r->trainer->id, $favTrainerIds, true));
        }

        // レース全体メモ (Phase 1-T) — ゲスト時は無し
        $raceNote = $userId
            ? \App\Models\RaceUserNote::where('user_id', $userId)
                ->where('race_id', $race->id)
                ->first()
            : null;

        // レースナビゲーター用データ (JRA 公式風: 開催日 × 競馬場 × R番号)
        // 同じ開催週末 (=直近の土日) の全レースを取得し、Blade で日付・会場別にグルーピング
        $navigator = $this->buildRaceNavigator($race);

        return view('shutuba.show', [
            'race'             => $race,
            'rows'             => $rows,
            'cond'             => $cond,
            'settings'         => $settings,
            'sort'             => $sort,
            'filter_mark'      => $filterMark,
            'recommended_bets' => $recommendedBets,
            'mark_summary'     => $markSummary,
            'pace_forecast'    => $paceForecast,
            'race_note'        => $raceNote,
            // ライブオッズ表示用 (Phase EV-2)
            'live_odds_at'     => $liveOddsAt,
            'has_live_odds'    => !empty($liveOddsMap),
            // レースナビゲーター (JRA 公式風)
            'navigator'        => $navigator,
        ]);
    }

    /**
     * 最新オッズを netkeiba から手動取得 (Phase EV-2)
     *
     * POST /shutuba/{race}/capture-odds (Ajax)
     *
     * 出馬表ページから「📊 最新オッズ取得」ボタンで呼び出される。
     * 取得結果は odds_snapshots テーブルに保存され、次回ページ表示時に
     * EV 計算に最新値として反映される。
     *
     * Response: { ok: bool, captured_at?: iso8601, message?: string, count?: int }
     */
    public function captureOdds(Race $race, Request $request): JsonResponse
    {
        if (empty($race->netkeiba_id)) {
            return response()->json([
                'ok'      => false,
                'message' => 'netkeiba_id が登録されていないレースです (出馬表をインポートして下さい)',
            ], 422);
        }

        // 発走後 N 分以上経過していたら取得しない (Phase EV-3, 精密ガード)
        //
        // 優先順位:
        //   1) race.post_time (DATETIME) があれば「post_time + N分」で判定 (精密)
        //   2) 無ければ「race_date 00:00 + N分」で判定 (粗い, 後方互換)
        //   3) config('jra.odds_capture.minutes_after_post') が null/0/負値なら無効
        //
        // 精密ガードなら「発走前」〜「発走後 N 分」の間は取得可能、
        // それ以降は netkeiba 側でオッズが確定固定なので取得抑止。
        // Phase EV-3 修正:
        //   race_date (00:00起点) でのガードは廃止した。
        //   race_date は「日付だけ」なので、発走 12:10 のレースでも
        //   "race_date + 90分" は当日 01:30 になり、朝の時点で
        //   誤って「90分超過」判定になっていた (2026/07/04 スクショで確認)。
        //
        //   ガードを働かせるのは post_time が確実に埋まっているレースだけとする。
        //   post_time が未取込のレースはガードをスキップ (誤判定より netkeiba 側の
        //   「オッズなし」で自然に skip される方が安全)。
        $minutesAfterPost = config('jra.odds_capture.minutes_after_post');
        if (is_numeric($minutesAfterPost) && (int) $minutesAfterPost > 0 && $race->post_time) {
            $minutesAfterPost = (int) $minutesAfterPost;
            $threshold = $race->post_time->copy()->addMinutes($minutesAfterPost);
            if (now()->gt($threshold)) {
                return response()->json([
                    'ok'      => false,
                    'message' => "発走時刻 ({$race->post_time->format('H:i')}) から{$minutesAfterPost}分超過のためオッズ取得を停止しました",
                ], 422);
            }
        }

        try {
            $service = new OddsSnapshotService(app(NetkeibaScraper::class));
            $snap = $service->captureForRace($race);
        } catch (\Throwable $e) {
            Log::warning("ShutubaController::captureOdds failed race#{$race->id}: " . $e->getMessage());
            return response()->json([
                'ok'      => false,
                'message' => 'オッズ取得エラー: ' . $e->getMessage(),
            ], 500);
        }

        if (!$snap) {
            return response()->json([
                'ok'      => false,
                'message' => 'スナップショット作成不可 (発走後 / オッズが取得できない)',
            ], 422);
        }

        try {
            // ゲスト実行時は IP を残して監査を容易にする (ユーザー名は AuditLog.user_id が null になる)
            $isGuest = !$request->user();
            AuditLog::record('odds.capture', $race, [
                'source'    => 'shutuba_manual',
                'horses'    => count($snap->payload ?? []),
                'is_guest'  => $isGuest,
                'client_ip' => $isGuest ? $request->ip() : null,
            ]);
        } catch (\Throwable $e) {}

        return response()->json([
            'ok'          => true,
            'captured_at' => $snap->captured_at->toIso8601String(),
            'captured_at_human' => $snap->captured_at->format('H:i:s'),
            'count'       => count($snap->payload ?? []),
            'message'     => sprintf('オッズを取得しました (%d頭, %s)', count($snap->payload ?? []), $snap->captured_at->format('H:i:s')),
        ]);
    }

    /**
     * オッズ推移データ (Phase EV-3, グラフ用)
     *
     * GET /shutuba/{race}/odds-timeline
     *
     * Response:
     *   {
     *     ok: true,
     *     race_id: 27779,
     *     snapshot_count: 5,
     *     horses: [
     *       { horse_number: 1, horse_name: "コスモハナミズキ", latest_odds: 53.8, latest_popularity: 12 },
     *       ...
     *     ],
     *     series: {
     *       "1": [ { t: "2026-07-04T09:12:00+09:00", odds: 55.2, pop: 12 }, ... ],
     *       "2": [ ... ],
     *       ...
     *     }
     *   }
     */
    public function oddsTimeline(Race $race): JsonResponse
    {
        $snaps = OddsSnapshot::where('race_id', $race->id)
            ->orderBy('captured_at')
            ->get();

        // 各馬の series
        $series = [];      // horse_number(str) => [ {t, odds, pop}, ... ]
        $horseName = [];   // horse_number => name
        foreach ($snaps as $snap) {
            $ts = $snap->captured_at->toIso8601String();
            $payload = is_array($snap->payload) ? $snap->payload : [];
            foreach ($payload as $hno => $row) {
                $hno = (int) $hno;
                if ($hno < 1) continue;
                $odds = isset($row['win_odds']) ? (float) $row['win_odds'] : null;
                $pop  = isset($row['popularity']) ? (int) $row['popularity'] : null;
                $series[(string) $hno][] = [
                    't'    => $ts,
                    'odds' => $odds,
                    'pop'  => $pop,
                ];
                if (!isset($horseName[$hno]) && !empty($row['horse_name'])) {
                    $horseName[$hno] = $row['horse_name'];
                }
            }
        }

        // 馬名フォールバック: race_results から補完
        if (!empty($series)) {
            $rrs = $race->results()->with('horse')->get();
            foreach ($rrs as $rr) {
                $hno = (int) $rr->horse_number;
                if ($hno > 0 && !isset($horseName[$hno])) {
                    $horseName[$hno] = $rr->horse?->name ?? "馬{$hno}";
                }
            }
        }

        // horses サマリ: 最新オッズ・人気を添える (グラフ選択UI用)
        $horses = [];
        foreach ($series as $hnoStr => $arr) {
            $hno = (int) $hnoStr;
            $last = end($arr);
            $horses[] = [
                'horse_number'      => $hno,
                'horse_name'        => $horseName[$hno] ?? "馬{$hno}",
                'latest_odds'       => $last['odds'] ?? null,
                'latest_popularity' => $last['pop']  ?? null,
                'point_count'       => count($arr),
            ];
        }
        usort($horses, fn($a, $b) => $a['horse_number'] <=> $b['horse_number']);

        return response()->json([
            'ok'             => true,
            'race_id'        => $race->id,
            'snapshot_count' => $snaps->count(),
            'first_at'       => $snaps->first()?->captured_at?->toIso8601String(),
            'last_at'        => $snaps->last()?->captured_at?->toIso8601String(),
            'horses'         => $horses,
            'series'         => $series,
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

        $this->audit('shutuba.mark', $rm, [
            'race_id' => $race->id, 'race_result_id' => $rr->id,
            'horse_number' => $rr->horse_number, 'mark' => $mark,
        ]);

        return response()->json([
            'ok'   => true,
            'mark' => $rm->mark,
            'id'   => $rm->id,
        ]);
    }

    /**
     * 印を自動提案して一括保存 (Phase 1-C)
     *
     * POST /shutuba/{race}/auto-mark
     * body: { overwrite: bool }
     *
     * スコアランクに応じて ◎○▲△☆ を自動付与:
     *   1位 & total>=70 → ◎
     *   2位 & total>=60 → ○
     *   3位 & total>=55 → ▲
     *   4-5位 & total>=50 → △
     *   ROI≥50 でTOP外でも → ☆
     */
    public function autoMark(Race $race, Request $request): JsonResponse
    {
        $validated = $request->validate([
            'overwrite' => ['nullable', 'boolean'],
        ]);
        $overwrite = (bool) ($validated['overwrite'] ?? false);
        $userId = $request->user()->id;

        $race->load(['venue', 'results.horse']);

        $cond = [
            'venue_id'         => $race->venue_id,
            'track_type'       => $race->track_type,
            'distance'         => $race->distance,
            'course_condition' => $race->course_condition,
            'direction'        => $race->direction,
        ];

        $settings = $this->svc->getSettings();
        $weights  = $settings['weights'];
        $minRuns  = $settings['min_runs'];

        $existingMarks = RaceMark::where('user_id', $userId)
            ->where('race_id', $race->id)
            ->get()
            ->keyBy('race_result_id');

        // 各馬の過去走→脚質→ペース判定(脚質スコアに必要)
        $styles = [];
        $recentMap = [];
        foreach ($race->results as $rr) {
            $recent = [];
            if ($rr->horse_id) {
                $recent = RaceResult::with(['race'])
                    ->where('horse_id', $rr->horse_id)
                    ->where('race_id', '!=', $race->id)
                    ->whereNotNull('finish_position_int')
                    ->whereHas('race', fn($qq) => $qq->where('race_date', '<=', $race->race_date))
                    ->join('races', 'races.id', '=', 'race_results.race_id')
                    ->orderByDesc('races.race_date')
                    ->select('race_results.*')
                    ->limit(5)
                    ->get();
            }
            $styles[$rr->id]    = $this->estimateRunningStyle($recent);
            $recentMap[$rr->id] = $recent;
        }
        $cond['pace'] = $this->svc->estimatePace(array_values($styles));

        $scored = [];
        foreach ($race->results as $rr) {
            if (!$rr->horse) continue;
            $eval = $this->svc->evaluateHorse(
                horse:    [
                    'id'            => $rr->horse->id,
                    'father'        => $rr->horse->father,
                    'mother_father' => $rr->horse->mother_father,
                    'frame_number'  => $rr->frame_number,
                    'running_style' => $styles[$rr->id] ?? '不',
                ],
                jockeyId: $rr->jockey_id ? (int) $rr->jockey_id : null,
                cond:     $cond,
                weights:  $weights,
                minRuns:  $minRuns,
            );
            $scored[] = (object) [
                'rr'      => $rr,
                'total'   => (float) $eval['total'],
                'roi_sub' => (float) $eval['sub']['roi'],
                'eval'    => $eval,
            ];
        }

        usort($scored, fn($a, $b) => $b->total <=> $a->total);

        $applied = ['◎' => 0, '○' => 0, '▲' => 0, '△' => 0, '☆' => 0];
        $skipped = 0;

        DB::transaction(function () use ($scored, $existingMarks, $overwrite, $race, $userId, &$applied, &$skipped) {
            foreach ($scored as $i => $s) {
                $rank = $i + 1;
                $proposedMark = $this->svc->decideMark($s->total, $rank, $s->roi_sub);

                $existing = $existingMarks->get($s->rr->id);
                if (!$overwrite && $existing && $existing->mark) {
                    $skipped++;
                    continue;
                }

                $newMark = $proposedMark !== '' ? $proposedMark : null;

                RaceMark::updateOrCreate(
                    ['user_id' => $userId, 'race_result_id' => $s->rr->id],
                    [
                        'race_id'        => $race->id,
                        'mark'           => $newMark,
                        'score_total'    => round($s->total, 2),
                        'score_pedigree' => round((float) $s->eval['sub']['pedigree'], 2),
                        'score_jockey'   => round((float) $s->eval['sub']['jockey'], 2),
                        'score_horse'    => round((float) $s->eval['sub']['horse'], 2),
                        'score_roi'      => round((float) $s->eval['sub']['roi'], 2),
                        'score_frame'    => round((float) $s->eval['sub']['frame'], 2),
                        'score_course'   => round((float) $s->eval['sub']['course'], 2),
                        'score_style'    => round((float) $s->eval['sub']['style'], 2),
                        'scored_at'      => now(),
                    ]
                );

                if ($newMark && isset($applied[$newMark])) {
                    $applied[$newMark]++;
                }
            }
        });

        $this->audit('shutuba.auto_mark', null, [
            'race_id' => $race->id, 'applied' => $applied, 'skipped' => $skipped,
            'overwrite' => $overwrite,
        ]);

        return response()->json([
            'ok'      => true,
            'applied' => $applied,
            'skipped' => $skipped,
            'message' => sprintf(
                '◎%d ○%d ▲%d △%d ☆%d を提案しました(スキップ: %d)',
                $applied['◎'], $applied['○'], $applied['▲'], $applied['△'], $applied['☆'],
                $skipped
            ),
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

        $this->audit('shutuba.memo', $rm, [
            'race_id' => $race->id, 'race_result_id' => $rr->id,
            'len' => mb_strlen((string)($validated['memo'] ?? '')),
        ]);

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
        $this->audit('shutuba.generate_bets', null, [
            'race_id' => $race->id, 'generated' => $generated,
            'skipped' => $skipped, 'kinds' => $validated['kinds'],
            'unit_stake' => $unitStake,
        ]);
        return redirect()
            ->route('shutuba.show', $race)
            ->with('status', $msg);
    }

    /**
     * レース全体メモを更新 (Phase 1-T)
     *
     * POST /shutuba/{race}/race-note
     */
    public function raceNote(Race $race, Request $request): JsonResponse
    {
        $validated = $request->validate([
            'note'      => ['nullable', 'string', 'max:5000'],
            'watch_next'=> ['nullable', 'boolean'],
        ]);
        $userId = $request->user()->id;

        $note = \App\Models\RaceUserNote::updateOrCreate(
            ['user_id' => $userId, 'race_id' => $race->id],
            [
                'note'       => $validated['note'] ?? null,
                'watch_next' => (bool) ($validated['watch_next'] ?? false),
            ]
        );

        $this->audit('shutuba.race_note', $note, [
            'race_id' => $race->id,
            'len' => mb_strlen((string)($validated['note'] ?? '')),
            'watch_next' => (bool) ($validated['watch_next'] ?? false),
        ]);

        return response()->json(['ok' => true, 'id' => $note->id]);
    }

    /**
     * お気に入りトグル (Phase 1-M)
     *
     * POST /shutuba/favorite
     * body: { type: 'horse'|'jockey'|'trainer', target_id: int }
     *
     * 内部的には Polymorphic 関連 (favoritable_type + favoritable_id) として保存。
     */
    public function toggleFavorite(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'type'      => ['required', 'string', 'in:horse,jockey,trainer'],
            'target_id' => ['required', 'integer'],
        ]);
        $userId = $request->user()->id;
        $cls    = \App\Models\Favorite::classFor($validated['type']);
        if (!$cls) {
            return response()->json(['ok' => false, 'error' => 'invalid type'], 422);
        }

        $existing = \App\Models\Favorite::where('user_id', $userId)
            ->where('favoritable_type', $cls)
            ->where('favoritable_id', $validated['target_id'])
            ->first();

        if ($existing) {
            $existing->delete();
            $this->audit('favorite.toggle', null, [
                'type' => $validated['type'], 'target_id' => $validated['target_id'], 'state' => 'off',
            ]);
            return response()->json(['ok' => true, 'state' => 'off']);
        }

        $fav = \App\Models\Favorite::create([
            'user_id'          => $userId,
            'favoritable_type' => $cls,
            'favoritable_id'   => $validated['target_id'],
        ]);
        $this->audit('favorite.toggle', $fav, [
            'type' => $validated['type'], 'target_id' => $validated['target_id'], 'state' => 'on',
        ]);
        return response()->json(['ok' => true, 'state' => 'on']);
    }

    /** AuditLog 記録 (audit_logs テーブル未マイグレーションでも落ちないようガード) */
    protected function audit(string $action, ?\Illuminate\Database\Eloquent\Model $subject = null, array $meta = []): void
    {
        try {
            AuditLog::record($action, $subject, $meta);
        } catch (\Throwable $e) {
            // ignore
        }
    }

    // ======================================================================
    // 内部ヘルパ
    // ======================================================================

    /**
     * 脚質を推定 (Phase 1-A)
     *
     * 過去走の corner_positions と当該レースの頭数から
     * RaceResult::detectRunningStyle() で判定し、最頻値を返す。
     *
     * @return string '逃'|'先'|'差'|'追'|'不'
     */
    private function estimateRunningStyle($recent): string
    {
        if (empty($recent) || count($recent) === 0) return '不';

        $styles = [];
        foreach ($recent as $past) {
            $cp = $past->corner_positions ?? null;
            // 過去レースの出走頭数が不明な場合は推定: corner_positions の最大値などから推測
            $count = null;
            // 取れる情報が無い場合は 16 で代替(主要レース想定)
            if (method_exists($past, 'getRelation') && $past->relationLoaded('race') && $past->race) {
                $count = $past->race->results_count ?? null;
            }
            $count = $count ?: 16;

            $style = \App\Models\RaceResult::detectRunningStyle($cp, $count);
            if ($style) $styles[] = $style;
        }

        if (empty($styles)) {
            // フォールバック: finish_position から大雑把に
            $finishes = [];
            foreach ($recent as $past) {
                if ($past->finish_position_int !== null) {
                    $finishes[] = (int) $past->finish_position_int;
                }
            }
            if (!empty($finishes)) {
                $avg = array_sum($finishes) / count($finishes);
                if ($avg <= 3.0) return '先';
                if ($avg <= 7.0) return '差';
                return '追';
            }
            return '不';
        }

        // 最頻値
        $counts = array_count_values($styles);
        arsort($counts);
        return (string) array_key_first($counts);
    }

    /**
     * ペース予想 (Phase 1-A)
     *
     * 出馬全体の脚質構成からペースを予想する。
     * 逃げ馬が多ければハイペース、少なければスロー。
     *
     * @return array{pace:string, label:string, note:string, counts:array}
     */
    private function forecastPace(array $rows): array
    {
        $counts = ['逃' => 0, '先' => 0, '差' => 0, '追' => 0, '不' => 0];
        foreach ($rows as $r) {
            $s = $r->running_style ?? '不';
            if (isset($counts[$s])) $counts[$s]++;
        }

        $front = $counts['逃'] + $counts['先'];
        $back  = $counts['差'] + $counts['追'];
        $total = max(1, count($rows));
        $frontRate = $front / $total;

        if ($counts['逃'] >= 3 || $frontRate >= 0.5) {
            return [
                'pace'   => 'H',
                'label'  => 'ハイペース予想',
                'note'   => '逃げ・先行型が多く、後方からの差し・追込が決まりやすい展開',
                'counts' => $counts,
            ];
        }
        if ($counts['逃'] <= 1 && $frontRate <= 0.25) {
            return [
                'pace'   => 'S',
                'label'  => 'スローペース予想',
                'note'   => '前残りが期待でき、先行・好位差し有利の展開',
                'counts' => $counts,
            ];
        }
        return [
            'pace'   => 'M',
            'label'  => 'ミドルペース予想',
            'note'   => '展開の偏りは少なく、力勝負になりやすい',
            'counts' => $counts,
        ];
    }

    /**
     * 期待値(EV)を計算 (Phase 1-B → EV-2 拡張)
     *
     * total スコアを推定勝率に変換し、単勝オッズで EV を出す。
     * さらに「単勝オッズから複勝オッズを推定」して複勝EVも計算する。
     *
     *   推定単勝勝率 = clamp( total / 100 * 0.42, 0.01, 0.5 )
     *   推定複勝率   = clamp( 単勝勝率 * 2.6,    0.05, 0.90 )  ※経験則(JRA 全体平均で複勝/単勝 ≒ 2.5-2.8)
     *   推定複勝オッズ = max( 1.1, 1 + (単勝オッズ - 1) * 0.30 )  ※経験則(複勝/単勝 ≒ 0.25-0.35)
     *
     *   単勝EV = 単勝勝率 * 単勝オッズ - 1
     *   複勝EV = 複勝率   * 推定複勝オッズ - 1
     *
     * EV>0 が「お得」、EV<0 が「過大評価」。
     *
     * @return array{
     *   prob:float, ev:float, label:string,
     *   place_prob:float, place_odds:float, place_ev:float, place_label:string
     * }
     */
    private function calcExpectedValue(float $total, float $winOdds): array
    {
        // 設定値を読込 (config/jra.php で env からチューニング可能)
        $cfg = config('jra.ev', []);
        $probCoef       = (float) ($cfg['prob_coef']        ?? 0.42);
        $probMin        = (float) ($cfg['prob_min']         ?? 0.01);
        $probMax        = (float) ($cfg['prob_max']         ?? 0.50);
        $placeProbRatio = (float) ($cfg['place_prob_ratio'] ?? 2.6);
        $placeProbMin   = (float) ($cfg['place_prob_min']   ?? 0.05);
        $placeProbMax   = (float) ($cfg['place_prob_max']   ?? 0.90);
        $placeOddsCoef  = (float) ($cfg['place_odds_coef']  ?? 0.30);
        $placeOddsFloor = (float) ($cfg['place_odds_floor'] ?? 1.1);

        // ===== 単勝 =====
        // total を 0〜100 → 0.02〜0.5 程度の勝率に圧縮
        // total=70 で 21%、total=85 で 33% 程度 (デフォルト係数の場合)
        $prob = max($probMin, min($probMax, ($total / 100) * $probCoef));
        $ev   = $prob * $winOdds - 1.0;
        $label = $this->labelForEv($ev);

        // ===== 複勝 (オッズ推定) =====
        // 経験則: 複勝率 ≒ 単勝率 × place_prob_ratio (デフォルト 2.6)
        // 3着以内に入る確率は単勝の約2.5-2.8倍 (JRA全体平均)
        $placeProb = max($placeProbMin, min($placeProbMax, $prob * $placeProbRatio));

        // 複勝オッズ推定: 1 + (単勝オッズ - 1) * place_odds_coef (デフォルト 0.30)
        //   単勝1.5倍 → 複勝1.15倍
        //   単勝3倍   → 複勝1.60倍
        //   単勝10倍  → 複勝3.70倍
        //   単勝50倍  → 複勝15.7倍
        $placeOdds = max($placeOddsFloor, 1.0 + ($winOdds - 1.0) * $placeOddsCoef);
        $placeEv   = $placeProb * $placeOdds - 1.0;
        $placeLabel = $this->labelForEv($placeEv);

        return [
            // 単勝
            'prob'        => round($prob * 100, 1),    // %
            'ev'          => round($ev, 3),
            'label'       => $label,
            // 複勝 (Phase EV-2)
            'place_prob'  => round($placeProb * 100, 1),
            'place_odds'  => round($placeOdds, 1),
            'place_ev'    => round($placeEv, 3),
            'place_label' => $placeLabel,
        ];
    }

    /** EV値から「お得/中/過大」のラベルを返す (閾値は config 化) */
    private function labelForEv(float $ev): string
    {
        $thr = config('jra.ev.label_thresholds', []);
        if ($ev >= (float) ($thr['great']       ??  0.30)) return '◎お得';
        if ($ev >= (float) ($thr['good']        ??  0.10)) return '○妙味';
        if ($ev >= (float) ($thr['neutral_low'] ?? -0.10)) return '中';
        if ($ev >= (float) ($thr['overrated']   ?? -0.30)) return '△やや過大';
        return '✕過大評価';
    }

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
     * JRA 公式風レースナビゲーター用データを構築
     *
     * 同じ開催週末 (=直近の土+日) の全レースを取得し、
     * 日付 → 競馬場 → R番号 の階層構造で返す。
     *
     * @return array{
     *   dates: array<string, array{
     *     date:string,
     *     label:string,
     *     weekday:string,
     *     is_current:bool,
     *     venues: array<int, array{
     *       venue_id:int,
     *       venue_name:string,
     *       kaisai_label:string,
     *       is_current:bool,
     *       first_race_id:int,
     *       races: array<int, array{id:int, race_number:int, name:string, is_current:bool}>,
     *     }>,
     *   }>,
     *   current_race_id:int,
     *   current_date:string,
     *   current_venue_id:int,
     * }
     */
    private function buildRaceNavigator(Race $race): array
    {
        $raceDate = $race->race_date instanceof \Carbon\CarbonInterface
            ? $race->race_date->copy()
            : \Carbon\Carbon::parse($race->race_date);

        // 当該レースの日付を含む土〜日の2日間を求める
        // (例: 火曜なら直前の土・日, 木曜なら次の土・日 ではなく、
        //  競馬は基本土日開催なので "近い方の土曜を起点に土+日" にする)
        $dow = $raceDate->dayOfWeek; // 0=Sun, 6=Sat
        if ($dow === \Carbon\Carbon::SATURDAY) {
            $sat = $raceDate->copy();
        } elseif ($dow === \Carbon\Carbon::SUNDAY) {
            $sat = $raceDate->copy()->subDay();
        } else {
            // 平日 (祝日開催など) → 直近の土曜を基点に
            $sat = $raceDate->copy()->previous(\Carbon\Carbon::SATURDAY);
            // ただし当該日が土より前なら次の土曜
            if ($sat->lt($raceDate->copy()->startOfDay()->subDays(6))) {
                $sat = $raceDate->copy()->next(\Carbon\Carbon::SATURDAY);
            }
        }
        $sun = $sat->copy()->addDay();

        // 該当2日間 + 当該レース日 をカバーする日付一覧
        $targetDates = collect([$sat, $sun])
            ->push($raceDate)
            ->map(fn ($d) => $d->toDateString())
            ->unique()
            ->sort()
            ->values();

        $races = Race::with('venue')
            ->whereIn('race_date', $targetDates->all())
            ->orderBy('race_date')
            ->orderBy('venue_id')
            ->orderBy('race_number')
            ->get();

        $weekdayJp = ['日曜', '月曜', '火曜', '水曜', '木曜', '金曜', '土曜'];
        $currentDateStr = $raceDate->toDateString();
        $currentVenueId = (int) $race->venue_id;
        $currentRaceId  = (int) $race->id;

        $byDate = [];
        foreach ($races as $r) {
            $dateStr = $r->race_date instanceof \Carbon\CarbonInterface
                ? $r->race_date->toDateString()
                : (string) $r->race_date;
            $dateCarbon = \Carbon\Carbon::parse($dateStr);

            if (!isset($byDate[$dateStr])) {
                $byDate[$dateStr] = [
                    'date'       => $dateStr,
                    'label'      => $dateCarbon->format('n月j日'),
                    'weekday'    => $weekdayJp[$dateCarbon->dayOfWeek] ?? '',
                    'is_current' => $dateStr === $currentDateStr,
                    'venues'     => [],
                ];
            }

            $vid = (int) $r->venue_id;
            if (!isset($byDate[$dateStr]['venues'][$vid])) {
                // 開催ラベル: "2回福島1日" 形式 (kaisai_kai と kaisai_day がある場合)
                $venueName = $r->venue?->name ?? '?';
                $kai  = $r->kaisai_kai ?? null;
                $day  = $r->kaisai_day ?? null;
                if ($kai && $day) {
                    $kaisaiLabel = "{$kai}回{$venueName}{$day}日";
                } else {
                    $kaisaiLabel = $venueName;
                }

                $byDate[$dateStr]['venues'][$vid] = [
                    'venue_id'      => $vid,
                    'venue_name'    => $venueName,
                    'kaisai_label'  => $kaisaiLabel,
                    'is_current'    => ($dateStr === $currentDateStr && $vid === $currentVenueId),
                    'first_race_id' => (int) $r->id,
                    'races'         => [],
                ];
            }

            $byDate[$dateStr]['venues'][$vid]['races'][] = [
                'id'          => (int) $r->id,
                'race_number' => (int) $r->race_number,
                'name'        => (string) $r->name,
                'is_current'  => ((int) $r->id === $currentRaceId),
            ];
        }

        // venues の連想配列を数値添字配列に戻す (Blade での @foreach 安定化)
        foreach ($byDate as $dkey => $block) {
            $byDate[$dkey]['venues'] = array_values($block['venues']);
        }

        return [
            'dates'            => $byDate,
            'current_race_id'  => $currentRaceId,
            'current_date'     => $currentDateStr,
            'current_venue_id' => $currentVenueId,
        ];
    }

    /**
     * 全馬の印から推奨買い目セットを構築 (Phase C-3 表示用)
     *
     * @return array<int, array{type:string, combo:string, points:int, detail:string}>
     */
    private function buildRecommendedBets(Race $race): array
    {
        // ゲスト閲覧時は推奨買い目セクションを空にする
        $userId = auth()->id();
        if (!$userId) return [];
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
