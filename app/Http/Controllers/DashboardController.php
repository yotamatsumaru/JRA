<?php

namespace App\Http\Controllers;

use App\Models\Horse;
use App\Models\Jockey;
use App\Models\PredictionShare;
use App\Models\Race;
use App\Models\RaceMark;
use App\Models\RaceResult;
use App\Models\Trainer;
use App\Models\Venue;
use App\Services\PredictionAccuracyService;
use App\Services\WatchlistService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

class DashboardController extends Controller
{
    /** ライト集計キャッシュキー (KPI / 月別 / 競馬場別 / ランキング 等) */
    public const CACHE_KEY_LIGHT = 'dashboard:aggregates:v2';
    /** 重量集計キャッシュキー (競馬場×トラック ヒートマップ / 枠番別 / 脚質傾向) */
    public const CACHE_KEY_HEAVY = 'dashboard:heavy:v2';
    /** 集計キャッシュ TTL (秒) — 24時間。取込完了時に Cache::forget で破棄。 */
    public const CACHE_TTL_SECONDS = 86400;

    public function index(WatchlistService $watchlistService, PredictionAccuracyService $accuracyService): View
    {
        // ========= Phase 5-D: パーソナル サマリ (ログインユーザー) =========
        // パーソナル領域はユーザー固有なのでキャッシュは個別に短時間 (60秒) のみ
        $userId = Auth::id();
        $personal = Cache::remember(
            'dashboard:personal:' . ($userId ?? 'guest'),
            now()->addSeconds(60),
            fn() => $this->buildPersonalSummary($userId, $watchlistService, $accuracyService)
        );

        // ========= ライト集計のみ 24h キャッシュ =========
        // 全ユーザー共通。取込完了時に Cache::forget で破棄するので長くて OK。
        // 重量3種 (venueTrackWinRate / frameWinRates / venueStyleStats) は
        // /dashboard/aggregates.json で遅延ロードするためここには含めない。
        $light = Cache::remember(
            self::CACHE_KEY_LIGHT,
            now()->addSeconds(self::CACHE_TTL_SECONDS),
            fn() => $this->buildLightAggregates()
        );

        // 重量3種は初回ビューで「空コレクション」を渡しておく → スケルトン表示用
        $heavyPlaceholder = [
            'venueTrackWinRate' => collect(),
            'frameWinRates'     => collect(),
            'venueStyleStats'   => collect(),
        ];

        return view('dashboard.index', array_merge(
            ['personal' => $personal],
            $light,
            $heavyPlaceholder,
            ['heavyDeferred' => true] // ビュー側で「読込中スケルトン」を出す目印
        ));
    }

    /**
     * 重量集計のみを返す JSON エンドポイント (遅延ロード用)
     *  - 24h キャッシュ。取込完了時に Cache::forget で破棄
     *  - フロント側で fetch('/dashboard/aggregates.json') して chart に流し込む
     */
    public function aggregatesJson(): JsonResponse
    {
        $heavy = Cache::remember(
            self::CACHE_KEY_HEAVY,
            now()->addSeconds(self::CACHE_TTL_SECONDS),
            fn() => $this->buildHeavyAggregates()
        );

        return response()->json([
            'venueTrackWinRate' => $heavy['venueTrackWinRate']->values(),
            'frameWinRates'     => $heavy['frameWinRates']->values(),
            'venueStyleStats'   => $heavy['venueStyleStats']->values(),
        ])->setEncodingOptions(JSON_UNESCAPED_UNICODE);
    }

    /**
     * ライト集計を構築 (キャッシュ対象, 24h)
     *  - KPI / 月別 / 競馬場別 / ランキング / 直近開催 など
     *  - 重量3種は含まない (遅延ロード対象)
     */
    private function buildLightAggregates(): array
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
                fn() => Race::with('venue:id,name')
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
            $latestDateRaces = $this->safe(fn() => Race::with('venue:id,name')
                ->withCount('results')
                ->whereDate('race_date', $latestDate)
                ->orderBy('venue_id')
                ->orderBy('race_number')
                ->get(), collect());
        }

        return compact(
            'stats',
            'byGrade', 'byMonth', 'byVenue',
            'byTrack', 'byDistanceCat', 'byCondition', 'byWeather', 'byWeekday',
            'avgFieldByMonth',
            'topJockeys', 'topTrainers', 'topSires', 'topPrizeHorses',
            'latestDate', 'latestDateRaces'
        );
    }

    /**
     * 重量集計を構築 (キャッシュ対象, 24h, 遅延ロード経由でのみ呼ばれる)
     *  - 競馬場×トラック種別 勝率ヒートマップ
     *  - 全場合算 枠番別 勝率/複勝率
     *  - 競馬場別 脚質傾向
     *
     * いずれも race_results.finish_position_int フィルタ + JOIN race を含むため
     * 件数が増えるとフルテーブルスキャンに近くなる。
     * → 2026_05_14 マイグレーションで以下のインデックスを追加済:
     *    finish_position_int, (race_id, frame_number) 等
     */
    private function buildHeavyAggregates(): array
    {
        // A) 競馬場 × トラック種別 勝率ヒートマップ
        $venueTrackWinRate = $this->safe(function () {
            return DB::table('race_results')
                ->join('races', 'races.id', '=', 'race_results.race_id')
                ->join('venues', 'venues.id', '=', 'races.venue_id')
                ->whereNotNull('race_results.finish_position_int')
                ->whereIn('races.track_type', ['芝', 'ダート'])
                ->selectRaw("
                    venues.name as venue,
                    races.track_type as track_type,
                    count(*) as runs,
                    SUM(CASE WHEN race_results.finish_position_int = 1 THEN 1 ELSE 0 END) as wins,
                    SUM(CASE WHEN race_results.finish_position_int <= 3 THEN 1 ELSE 0 END) as shows
                ")
                ->groupBy('venues.id', 'venues.name', 'races.track_type')
                ->orderBy('venues.id')
                ->get();
        }, collect());

        // B) 全場合算 枠番別 勝率/複勝率（芝・ダート別）
        $frameWinRates = $this->safe(function () {
            return DB::table('race_results')
                ->join('races', 'races.id', '=', 'race_results.race_id')
                ->whereNotNull('race_results.frame_number')
                ->whereNotNull('race_results.finish_position_int')
                ->whereIn('races.track_type', ['芝', 'ダート'])
                ->whereBetween('race_results.frame_number', [1, 8])
                ->selectRaw("
                    races.track_type as track_type,
                    race_results.frame_number as frame_number,
                    count(*) as runs,
                    SUM(CASE WHEN race_results.finish_position_int = 1 THEN 1 ELSE 0 END) as wins,
                    SUM(CASE WHEN race_results.finish_position_int <= 3 THEN 1 ELSE 0 END) as shows
                ")
                ->groupBy('races.track_type', 'race_results.frame_number')
                ->orderBy('races.track_type')
                ->orderBy('race_results.frame_number')
                ->get();
        }, collect());

        // C) 競馬場別 脚質傾向 (running_style 別 勝率)
        $venueStyleStats = $this->safe(function () {
            return DB::table('race_results')
                ->join('races', 'races.id', '=', 'race_results.race_id')
                ->join('venues', 'venues.id', '=', 'races.venue_id')
                ->whereNotNull('race_results.running_style')
                ->whereNotNull('race_results.finish_position_int')
                ->where('race_results.running_style', '!=', '')
                ->selectRaw("
                    venues.name as venue,
                    race_results.running_style as style,
                    count(*) as runs,
                    SUM(CASE WHEN race_results.finish_position_int = 1 THEN 1 ELSE 0 END) as wins
                ")
                ->groupBy('venues.id', 'venues.name', 'race_results.running_style')
                ->orderBy('venues.id')
                ->get();
        }, collect());

        return compact('venueTrackWinRate', 'frameWinRates', 'venueStyleStats');
    }

    /**
     * ダッシュボード集計キャッシュをすべて破棄 (取込完了などから呼ばれる)
     */
    public static function flushAggregatesCache(): void
    {
        Cache::forget(self::CACHE_KEY_LIGHT);
        Cache::forget(self::CACHE_KEY_HEAVY);
    }

    /**
     * Phase 5-D: ログインユーザー向けの個人ダッシュボード セクション
     *  - ウォッチリスト出走予定 (今日〜3日)
     *  - 本日の◎進捗 (印付与レース vs 結果確定)
     *  - 直近30日 ◎ROI/勝率
     *  - 共有スナップショット
     */
    private function buildPersonalSummary(?int $userId, WatchlistService $wls, PredictionAccuracyService $pas): array
    {
        if (!$userId) {
            return [
                'enabled' => false,
            ];
        }

        // ウォッチリスト出走予定 (今日〜3日, 上位5件)
        $upcoming = $this->safe(fn() => array_slice($wls->upcomingEntries($userId, 3), 0, 5), []);

        // 本日の◎進捗
        $todayMarks = $this->safe(function () use ($userId) {
            $today = Carbon::today()->toDateString();
            $rows = DB::table('race_marks')
                ->join('race_results', 'race_results.id', '=', 'race_marks.race_result_id')
                ->join('races', 'races.id', '=', 'race_results.race_id')
                ->where('race_marks.user_id', $userId)
                ->where('race_marks.mark', '◎')
                ->whereDate('races.race_date', $today)
                ->select(
                    'races.id as race_id',
                    DB::raw('SUM(CASE WHEN race_results.finish_position_int IS NOT NULL THEN 1 ELSE 0 END) as finished'),
                    DB::raw('SUM(CASE WHEN race_results.finish_position_int = 1 THEN 1 ELSE 0 END) as wins'),
                    DB::raw('SUM(CASE WHEN race_results.finish_position_int <= 3 THEN 1 ELSE 0 END) as top3')
                )
                ->groupBy('races.id')
                ->get();
            return [
                'races'    => $rows->count(),
                'finished' => (int) $rows->sum('finished'),
                'wins'     => (int) $rows->sum('wins'),
                'top3'     => (int) $rows->sum('top3'),
            ];
        }, ['races' => 0, 'finished' => 0, 'wins' => 0, 'top3' => 0]);

        // 直近30日 ◎ROI/勝率
        $recent30 = $this->safe(function () use ($userId, $pas) {
            $from = Carbon::today()->subDays(30)->toDateString();
            $to   = Carbon::today()->toDateString();
            $summary = $pas->summary($userId, ['from' => $from, 'to' => $to]);
            return $summary['◎'] ?? null;
        }, null);

        // 共有スナップショット
        $shares = $this->safe(function () use ($userId) {
            return [
                'active' => PredictionShare::where('user_id', $userId)
                    ->where('is_active', true)
                    ->where(function ($q) {
                        $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
                    })->count(),
                'total'  => PredictionShare::where('user_id', $userId)->count(),
                'views'  => (int) PredictionShare::where('user_id', $userId)->sum('view_count'),
            ];
        }, ['active' => 0, 'total' => 0, 'views' => 0]);

        // 直近の◎獲得 (バッジ表示用) — 過去7日に的中したレース
        $recentWins = $this->safe(function () use ($userId) {
            return DB::table('race_marks')
                ->join('race_results', 'race_results.id', '=', 'race_marks.race_result_id')
                ->join('races', 'races.id', '=', 'race_results.race_id')
                ->leftJoin('venues', 'venues.id', '=', 'races.venue_id')
                ->leftJoin('horses', 'horses.id', '=', 'race_results.horse_id')
                ->where('race_marks.user_id', $userId)
                ->where('race_marks.mark', '◎')
                ->where('race_results.finish_position_int', 1)
                ->whereDate('races.race_date', '>=', Carbon::today()->subDays(7)->toDateString())
                ->select(
                    'races.id as race_id',
                    'races.name as race_name',
                    'races.race_date',
                    'races.race_number',
                    'venues.name as venue',
                    'horses.name as horse',
                    'race_results.win_odds'
                )
                ->orderByDesc('races.race_date')
                ->orderByDesc('races.race_number')
                ->limit(5)
                ->get();
        }, collect());

        return [
            'enabled'    => true,
            'upcoming'   => $upcoming,
            'todayMarks' => $todayMarks,
            'recent30'   => $recent30,
            'shares'     => $shares,
            'recentWins' => $recentWins,
        ];
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
