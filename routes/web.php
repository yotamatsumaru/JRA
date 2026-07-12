<?php

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\RegisteredUserController;
use App\Http\Controllers\AnalyticsController;
use App\Http\Controllers\BetController;
use App\Http\Controllers\BettingDashboardController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DbViewerController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\HorseController;
use App\Http\Controllers\ImportController;
use App\Http\Controllers\JockeyController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\OperationsController;
use App\Http\Controllers\PedigreeRecommendController;
use App\Http\Controllers\PredictionAccuracyController;
use App\Http\Controllers\PredictionShareController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\RaceController;
use App\Http\Controllers\RaceNoteController;
use App\Http\Controllers\RaceResultController;
use App\Http\Controllers\ShutubaController;
use App\Http\Controllers\TrainerController;
use App\Http\Controllers\VenueController;
use App\Http\Controllers\WatchlistController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| アクセスポリシー:
|   - 閲覧系 (GET) は誰でもアクセス可 (ゲスト解放)
|   - 書き込み系 (POST/PUT/PATCH/DELETE) と個人データ系 (馬券/プロフィール/
|     通知/ウォッチリスト/共有/インポート/運用/管理) は要ログイン
|
*/

// =====================================================================
// 🌐 ゲスト + ログインユーザ 共通で見られる閲覧系
// =====================================================================
Route::get('/', [HomeController::class, 'index'])->name('home');

// Phase 4-S: 予想スナップショット 公開閲覧
Route::get('/share/{token}', [PredictionShareController::class, 'show'])->name('share.show');

// ダッシュボード (集計)
Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
Route::get('/dashboard/aggregates.json', [DashboardController::class, 'aggregatesJson'])
    ->name('dashboard.aggregates.json');

// レース一覧/詳細 (閲覧のみ)
Route::get('/races',           [RaceController::class, 'index'])->name('races.index');
Route::get('/races/{race}',    [RaceController::class, 'show'])->name('races.show');

// 出馬表 予想ボード (閲覧)
Route::prefix('shutuba')->name('shutuba.')->group(function () {
    Route::get('/',        [ShutubaController::class, 'index'])->name('index');
    Route::get('/{race}',  [ShutubaController::class, 'show'])->name('show');

    // 最新オッズ取得 (Phase EV-2, ゲスト解放)
    //   公共オッズを netkeiba から取得するだけの操作なので誰でも実行可能。
    //   1分ごとの自動更新も許容できるよう IP 単位で 1 分に 60 回まで
    //   (= 1秒に1回ペースまでを許容)。それを超えたら 429 で拒否される。
    //   フロント側は 60秒間隔で発火するので通常運用では上限に触れない。
    Route::post('/{race}/capture-odds', [ShutubaController::class, 'captureOdds'])
        ->middleware('throttle:60,1')
        ->name('capture-odds');

    // オッズ推移グラフ用時系列データ (Phase EV-3, ゲスト解放)
    //   1レース分の odds_snapshots を JSON で返す。グラフ描画に使う。
    Route::get('/{race}/odds-timeline', [ShutubaController::class, 'oddsTimeline'])
        ->middleware('throttle:120,1')
        ->name('odds-timeline');
});

// 馬・騎手・調教師・競馬場 (閲覧のみ)
Route::resource('horses',   HorseController::class)->only(['index', 'show']);
Route::resource('jockeys',  JockeyController::class)->only(['index', 'show']);
Route::resource('trainers', TrainerController::class)->only(['index', 'show']);
Route::resource('venues',   VenueController::class)->only(['index', 'show']);

// 分析 (Auth 不要のものはゲスト可)
Route::prefix('analytics')->name('analytics.')->group(function () {
    Route::get('/venue',                [AnalyticsController::class, 'venue'])->name('venue');
    Route::get('/course-trends',        [AnalyticsController::class, 'courseTrends'])->name('course-trends');
    Route::get('/pace',                 [AnalyticsController::class, 'pace'])->name('pace');
    Route::get('/pedigree',             [AnalyticsController::class, 'pedigree'])->name('pedigree');
    Route::get('/pedigree/overview',    [AnalyticsController::class, 'pedigreeOverview'])->name('pedigree.overview');
    Route::get('/pedigree/sires',       [AnalyticsController::class, 'pedigreeSires'])->name('pedigree.sires');
    Route::get('/pedigree/broodmares',  [AnalyticsController::class, 'pedigreeBroodmares'])->name('pedigree.broodmares');
    Route::get('/pedigree/heatmap',     [AnalyticsController::class, 'pedigreeHeatmap'])->name('pedigree.heatmap');

    // 推奨
    Route::prefix('recommend')->name('recommend.')->group(function () {
        Route::get('/',            [PedigreeRecommendController::class, 'index'])->name('index');
        Route::get('/conditions',  [PedigreeRecommendController::class, 'conditions'])->name('conditions');
        Route::get('/scan',        [PedigreeRecommendController::class, 'scan'])->name('scan');
        Route::get('/race',        [PedigreeRecommendController::class, 'race'])->name('race');
        Route::get('/race/{race}', [PedigreeRecommendController::class, 'raceShow'])->name('race.show');
    });

    Route::get('/jockey',  [AnalyticsController::class, 'jockey'])->name('jockey');
    Route::get('/horse',   [AnalyticsController::class, 'horse'])->name('horse');
    Route::get('/stats',   [AnalyticsController::class, 'stats'])->name('stats');
    Route::get('/roi',     [AnalyticsController::class, 'roi'])->name('roi');

    // Phase 4-K: コース×ペース×脚質 3D 分析
    Route::get('/pace-style', [AnalyticsController::class, 'paceStyle'])->name('pace-style');
});

// =====================================================================
// 🔓 ゲスト専用 (ログイン/登録フォーム)
// =====================================================================
Route::middleware('guest')->group(function () {
    Route::get('login',    [AuthenticatedSessionController::class, 'create'])->name('login');
    // ログインPOST自体は LoginRequest::ensureIsNotRateLimited() で email+IP単位 5回ロックアウト済み。
    // ここでは更に IP単位の粗いレート制限を重ねて多アカウント総当たりも抑止する。
    Route::post('login',   [AuthenticatedSessionController::class, 'store'])
        ->middleware('throttle:10,1');
    Route::get('register', [RegisteredUserController::class, 'create'])->name('register');
    // セキュリティ強化: 登録フォームの自動投稿・大量アカウント作成を防止 (IP単位 5回/分)
    Route::post('register',[RegisteredUserController::class, 'store'])
        ->middleware('throttle:5,1');
});

// =====================================================================
// 🔐 要ログイン: 書き込み系 + 個人データ系
// =====================================================================
Route::middleware('auth')->group(function () {
    Route::post('logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');

    // プロフィール
    Route::get('/profile',    [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile',  [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    // レース 書き込み系 (resource の create/store/edit/update/destroy を再現)
    Route::get('/races/create',          [RaceController::class, 'create'])->name('races.create');
    Route::post('/races',                [RaceController::class, 'store'])->name('races.store');
    Route::get('/races/{race}/edit',     [RaceController::class, 'edit'])->name('races.edit');
    Route::put('/races/{race}',          [RaceController::class, 'update'])->name('races.update');
    Route::patch('/races/{race}',        [RaceController::class, 'update']);
    Route::delete('/races/{race}',       [RaceController::class, 'destroy'])->name('races.destroy');
    // レース結果の書き込み
    Route::post('races/{race}/results',                  [RaceResultController::class, 'store'])->name('races.results.store');
    Route::put('races/{race}/results/{result}',          [RaceResultController::class, 'update'])->name('races.results.update');
    Route::delete('races/{race}/results/{result}',       [RaceResultController::class, 'destroy'])->name('races.results.destroy');

    // 出馬表 書き込み系 (印・メモ・馬券生成など個人データ)
    //   注: capture-odds はゲスト解放されているため、ここには含まれない
    //       (公共オッズ取得は誰でも可能, throttle:6,1 で保護)
    Route::prefix('shutuba')->name('shutuba.')->group(function () {
        Route::post('/favorite',             [ShutubaController::class, 'toggleFavorite'])->name('favorite');
        Route::post('/{race}/mark',          [ShutubaController::class, 'mark'])->name('mark');
        Route::post('/{race}/auto-mark',     [ShutubaController::class, 'autoMark'])->name('auto-mark');
        Route::post('/{race}/memo',          [ShutubaController::class, 'memo'])->name('memo');
        Route::post('/{race}/race-note',     [ShutubaController::class, 'raceNote'])->name('race-note');
        Route::post('/{race}/generate-bets', [ShutubaController::class, 'generateBets'])->name('generate-bets');
    });

    // 馬・騎手・調教師・競馬場 書き込み (resource の create/store/edit/update/destroy)
    Route::get('/horses/create',        [HorseController::class, 'create'])->name('horses.create');
    Route::post('/horses',              [HorseController::class, 'store'])->name('horses.store');
    Route::get('/horses/{horse}/edit',  [HorseController::class, 'edit'])->name('horses.edit');
    Route::put('/horses/{horse}',       [HorseController::class, 'update'])->name('horses.update');
    Route::patch('/horses/{horse}',     [HorseController::class, 'update']);
    Route::delete('/horses/{horse}',    [HorseController::class, 'destroy'])->name('horses.destroy');

    // メモ
    Route::resource('notes', RaceNoteController::class)->except(['show']);

    // 馬券（収支管理）
    Route::get('/betting', [BettingDashboardController::class, 'index'])->name('betting.dashboard');
    Route::get('/betting/payouts', [BettingDashboardController::class, 'payouts'])->name('betting.payouts');
    Route::get('/betting/payouts/list', [BettingDashboardController::class, 'payoutsList'])->name('betting.payouts.list');

    // Phase 2-F/H/L: 一括精算 / What-if / エクスポート (resource より前)
    Route::post('bets/settle-all', [BetController::class, 'settleAll'])->name('bets.settle-all');
    Route::get('bets/whatif',      [BetController::class, 'whatif'])->name('bets.whatif');
    Route::get('bets/export.csv',  [BetController::class, 'exportCsv'])->name('bets.export-csv');
    Route::get('bets/print',       [BetController::class, 'printView'])->name('bets.print');

    Route::resource('bets', BetController::class);
    Route::post('bets/{bet}/settle', [BetController::class, 'settle'])->name('bets.settle');

    // Phase 2-G: バンクロール管理
    Route::prefix('bankroll')->name('bankroll.')->group(function () {
        Route::get('/',        [\App\Http\Controllers\BankrollController::class, 'index'])->name('index');
        Route::post('/update', [\App\Http\Controllers\BankrollController::class, 'update'])->name('update');
        Route::post('/delete', [\App\Http\Controllers\BankrollController::class, 'destroy'])->name('destroy');
    });

    // 分析 (ユーザー個別: 予想精度トラッキング)
    Route::prefix('analytics')->name('analytics.')->group(function () {
        // 推奨 設定 (個人別)
        Route::prefix('recommend')->name('recommend.')->group(function () {
            Route::get('/settings',        [PedigreeRecommendController::class, 'settings'])->name('settings');
            Route::post('/settings',       [PedigreeRecommendController::class, 'settingsStore'])->name('settings.store');
            Route::post('/settings/reset', [PedigreeRecommendController::class, 'settingsReset'])->name('settings.reset');
        });

        // Phase 4-N: 予想精度トラッキング
        Route::get('/prediction-accuracy',            [PredictionAccuracyController::class, 'index'])->name('prediction-accuracy');
        // Phase 5-E: 予想精度 CSV エクスポート
        Route::get('/prediction-accuracy/export.csv', [PredictionAccuracyController::class, 'exportCsv'])->name('prediction-accuracy.export-csv');
    });

    // Phase 4-W: ウォッチリスト
    Route::resource('watchlist', WatchlistController::class)->only(['index', 'store', 'update', 'destroy']);

    // Phase 6-A: 通知センター
    Route::get('/notifications',                   [NotificationController::class, 'index'])->name('notifications.index');
    Route::get('/notifications/{notification}',    [NotificationController::class, 'read'])->name('notifications.read');
    Route::post('/notifications/read-all',         [NotificationController::class, 'readAll'])->name('notifications.readAll');
    Route::post('/notifications/scan',             [NotificationController::class, 'scan'])->name('notifications.scan');
    Route::delete('/notifications/{notification}', [NotificationController::class, 'destroy'])->name('notifications.destroy');

    // Phase 4-S: 予想スナップショット共有 (認証ユーザ向け管理)
    Route::get('/shares',                 [PredictionShareController::class, 'index'])->name('shares.index');
    Route::post('/shares/race/{race}',    [PredictionShareController::class, 'store'])->name('shares.store');
    Route::post('/shares/{share}/toggle', [PredictionShareController::class, 'toggle'])->name('shares.toggle');
    Route::delete('/shares/{share}',      [PredictionShareController::class, 'destroy'])->name('shares.destroy');

    // 管理: DBビューア (読み取り専用)
    // セキュリティ強化: 全DB内容(ユーザー個人情報含む)が閲覧できるため is_admin 限定
    Route::prefix('admin/db')->name('admin.db.')->middleware('admin')->group(function () {
        Route::get('/',              [DbViewerController::class, 'index'])->name('index');
        Route::get('/stats',         [DbViewerController::class, 'stats'])->name('stats');
        Route::get('/schema',        [DbViewerController::class, 'schema'])->name('schema');
        Route::get('/table/{table}', [DbViewerController::class, 'table'])->name('table');
    });

    // Phase 3-Z: 運用ダッシュボード
    // セキュリティ強化: 手動ジョブ実行はアプリ全体に影響するため is_admin 限定
    Route::prefix('operations')->name('operations.')->middleware('admin')->group(function () {
        Route::get('/',                     [OperationsController::class, 'index'])->name('index');
        Route::post('/run-job',             [OperationsController::class, 'runJob'])->name('run-job');
        Route::get('/odds/{race}',          [OperationsController::class, 'odds'])->name('odds');
        Route::post('/odds/{race}/capture', [OperationsController::class, 'captureOdds'])->name('odds.capture');
    });

    // インポート
    // セキュリティ強化: 外部データの一括取込・OpenAI API呼び出しコストが発生するため is_admin 限定
    Route::prefix('import')->name('import.')->middleware('admin')->group(function () {
        Route::get('/',              [ImportController::class, 'index'])->name('index');
        Route::get('/csv',           [ImportController::class, 'csvForm'])->name('csv');
        Route::post('/csv',          [ImportController::class, 'csvStore'])->name('csv.store');
        Route::get('/netkeiba',      [ImportController::class, 'netkeibaForm'])->name('netkeiba');
        Route::post('/netkeiba',     [ImportController::class, 'netkeibaStore'])->name('netkeiba.store');
        Route::get('/image',         [ImportController::class, 'imageForm'])->name('image');
        Route::post('/image',        [ImportController::class, 'imageStore'])->name('image.store');
        Route::get('/logs',          [ImportController::class, 'logs'])->name('logs');
        Route::get('/progress',      [ImportController::class, 'progress'])->name('progress');
        Route::get('/progress.json', [ImportController::class, 'progressJson'])->name('progress.json');
    });
});
