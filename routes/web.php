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
*/

Route::get('/', [HomeController::class, 'index'])->name('home');

// Phase 4-S: 予想スナップショット 公開閲覧 (ゲストアクセス可)
Route::get('/share/{token}', [PredictionShareController::class, 'show'])->name('share.show');

// 認証
Route::middleware('guest')->group(function () {
    Route::get('login', [AuthenticatedSessionController::class, 'create'])->name('login');
    Route::post('login', [AuthenticatedSessionController::class, 'store']);
    Route::get('register', [RegisteredUserController::class, 'create'])->name('register');
    Route::post('register', [RegisteredUserController::class, 'store']);
});

Route::middleware('auth')->group(function () {
    Route::post('logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');

    // ダッシュボード
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

    // プロフィール
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    // レース
    Route::resource('races', RaceController::class);
    Route::post('races/{race}/results', [RaceResultController::class, 'store'])->name('races.results.store');
    Route::put('races/{race}/results/{result}', [RaceResultController::class, 'update'])->name('races.results.update');
    Route::delete('races/{race}/results/{result}', [RaceResultController::class, 'destroy'])->name('races.results.destroy');

    // 出馬表 予想ボード
    Route::prefix('shutuba')->name('shutuba.')->group(function () {
        Route::get('/',                      [ShutubaController::class, 'index'])->name('index');
        Route::post('/favorite',             [ShutubaController::class, 'toggleFavorite'])->name('favorite');
        Route::get('/{race}',                [ShutubaController::class, 'show'])->name('show');
        Route::post('/{race}/mark',          [ShutubaController::class, 'mark'])->name('mark');
        Route::post('/{race}/auto-mark',     [ShutubaController::class, 'autoMark'])->name('auto-mark');
        Route::post('/{race}/memo',          [ShutubaController::class, 'memo'])->name('memo');
        Route::post('/{race}/race-note',     [ShutubaController::class, 'raceNote'])->name('race-note');
        Route::post('/{race}/generate-bets', [ShutubaController::class, 'generateBets'])->name('generate-bets');
    });

    // 馬
    Route::resource('horses', HorseController::class);

    // 騎手
    Route::resource('jockeys', JockeyController::class)->only(['index', 'show']);

    // 調教師
    Route::resource('trainers', TrainerController::class)->only(['index', 'show']);

    // 競馬場
    Route::resource('venues', VenueController::class)->only(['index', 'show']);

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

    // 分析
    Route::prefix('analytics')->name('analytics.')->group(function () {
        Route::get('/venue', [AnalyticsController::class, 'venue'])->name('venue');
        Route::get('/course-trends', [AnalyticsController::class, 'courseTrends'])->name('course-trends');
        Route::get('/pace', [AnalyticsController::class, 'pace'])->name('pace');
        Route::get('/pedigree',             [AnalyticsController::class, 'pedigree'])->name('pedigree');
        Route::get('/pedigree/overview',    [AnalyticsController::class, 'pedigreeOverview'])->name('pedigree.overview');
        Route::get('/pedigree/sires',       [AnalyticsController::class, 'pedigreeSires'])->name('pedigree.sires');
        Route::get('/pedigree/broodmares',  [AnalyticsController::class, 'pedigreeBroodmares'])->name('pedigree.broodmares');
        Route::get('/pedigree/heatmap',     [AnalyticsController::class, 'pedigreeHeatmap'])->name('pedigree.heatmap');

        // 推奨(Phase 1: トップ+重み設定 / Phase 2: 条件指定+全件スキャン / Phase 3: 出馬表ベース推奨)
        Route::prefix('recommend')->name('recommend.')->group(function () {
            Route::get('/',                [PedigreeRecommendController::class, 'index'])->name('index');
            Route::get('/settings',        [PedigreeRecommendController::class, 'settings'])->name('settings');
            Route::post('/settings',       [PedigreeRecommendController::class, 'settingsStore'])->name('settings.store');
            Route::post('/settings/reset', [PedigreeRecommendController::class, 'settingsReset'])->name('settings.reset');
            Route::get('/conditions',      [PedigreeRecommendController::class, 'conditions'])->name('conditions');
            Route::get('/scan',            [PedigreeRecommendController::class, 'scan'])->name('scan');
            Route::get('/race',            [PedigreeRecommendController::class, 'race'])->name('race');
            Route::get('/race/{race}',     [PedigreeRecommendController::class, 'raceShow'])->name('race.show');
        });
        Route::get('/jockey', [AnalyticsController::class, 'jockey'])->name('jockey');
        Route::get('/horse', [AnalyticsController::class, 'horse'])->name('horse');
        Route::get('/stats', [AnalyticsController::class, 'stats'])->name('stats');
        Route::get('/roi', [AnalyticsController::class, 'roi'])->name('roi');

        // Phase 4-N: 予想精度トラッキング
        Route::get('/prediction-accuracy', [PredictionAccuracyController::class, 'index'])->name('prediction-accuracy');
        // Phase 5-E: 予想精度 CSV エクスポート
        Route::get('/prediction-accuracy/export.csv', [PredictionAccuracyController::class, 'exportCsv'])->name('prediction-accuracy.export-csv');
        // Phase 4-K: コース×ペース×脚質 3D 分析
        Route::get('/pace-style', [AnalyticsController::class, 'paceStyle'])->name('pace-style');
    });

    // Phase 4-W: ウォッチリスト
    Route::resource('watchlist', WatchlistController::class)->only(['index', 'store', 'update', 'destroy']);

    // Phase 6-A: 通知センター
    Route::get('/notifications',                  [NotificationController::class, 'index'])->name('notifications.index');
    Route::get('/notifications/{notification}',   [NotificationController::class, 'read'])->name('notifications.read');
    Route::post('/notifications/read-all',        [NotificationController::class, 'readAll'])->name('notifications.readAll');
    Route::post('/notifications/scan',            [NotificationController::class, 'scan'])->name('notifications.scan');
    Route::delete('/notifications/{notification}',[NotificationController::class, 'destroy'])->name('notifications.destroy');

    // Phase 4-S: 予想スナップショット共有 (認証ユーザ向け管理)
    Route::get('/shares',                [PredictionShareController::class, 'index'])->name('shares.index');
    Route::post('/shares/race/{race}',   [PredictionShareController::class, 'store'])->name('shares.store');
    Route::post('/shares/{share}/toggle',[PredictionShareController::class, 'toggle'])->name('shares.toggle');
    Route::delete('/shares/{share}',     [PredictionShareController::class, 'destroy'])->name('shares.destroy');

    // 管理: DBビューア (読み取り専用)
    Route::prefix('admin/db')->name('admin.db.')->group(function () {
        Route::get('/',                [DbViewerController::class, 'index'])->name('index');
        Route::get('/stats',           [DbViewerController::class, 'stats'])->name('stats');
        Route::get('/schema',          [DbViewerController::class, 'schema'])->name('schema');
        Route::get('/table/{table}',   [DbViewerController::class, 'table'])->name('table');
    });

    // Phase 3-Z: 運用ダッシュボード(スケジューラ/監査ログ/手動ジョブ実行/リアルタイムオッズ)
    Route::prefix('operations')->name('operations.')->group(function () {
        Route::get('/',                       [OperationsController::class, 'index'])->name('index');
        Route::post('/run-job',               [OperationsController::class, 'runJob'])->name('run-job');
        Route::get('/odds/{race}',            [OperationsController::class, 'odds'])->name('odds');
        Route::post('/odds/{race}/capture',   [OperationsController::class, 'captureOdds'])->name('odds.capture');
    });

    // インポート
    Route::prefix('import')->name('import.')->group(function () {
        Route::get('/', [ImportController::class, 'index'])->name('index');
        Route::get('/csv', [ImportController::class, 'csvForm'])->name('csv');
        Route::post('/csv', [ImportController::class, 'csvStore'])->name('csv.store');
        Route::get('/netkeiba', [ImportController::class, 'netkeibaForm'])->name('netkeiba');
        Route::post('/netkeiba', [ImportController::class, 'netkeibaStore'])->name('netkeiba.store');
        Route::get('/image', [ImportController::class, 'imageForm'])->name('image');
        Route::post('/image', [ImportController::class, 'imageStore'])->name('image.store');
        Route::get('/logs', [ImportController::class, 'logs'])->name('logs');
        Route::get('/progress', [ImportController::class, 'progress'])->name('progress');
        Route::get('/progress.json', [ImportController::class, 'progressJson'])->name('progress.json');
    });
});
