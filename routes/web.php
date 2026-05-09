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
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\RaceController;
use App\Http\Controllers\RaceNoteController;
use App\Http\Controllers\RaceResultController;
use App\Http\Controllers\VenueController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
*/

Route::get('/', [HomeController::class, 'index'])->name('home');

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

    // 馬
    Route::resource('horses', HorseController::class);

    // 騎手
    Route::resource('jockeys', JockeyController::class)->only(['index', 'show']);

    // 競馬場
    Route::resource('venues', VenueController::class)->only(['index', 'show']);

    // メモ
    Route::resource('notes', RaceNoteController::class)->except(['show']);

    // 馬券（収支管理）
    Route::get('/betting', [BettingDashboardController::class, 'index'])->name('betting.dashboard');
    Route::get('/betting/payouts', [BettingDashboardController::class, 'payouts'])->name('betting.payouts');
    Route::get('/betting/payouts/list', [BettingDashboardController::class, 'payoutsList'])->name('betting.payouts.list');
    Route::resource('bets', BetController::class);
    Route::post('bets/{bet}/settle', [BetController::class, 'settle'])->name('bets.settle');

    // 分析
    Route::prefix('analytics')->name('analytics.')->group(function () {
        Route::get('/venue', [AnalyticsController::class, 'venue'])->name('venue');
        Route::get('/course-trends', [AnalyticsController::class, 'courseTrends'])->name('course-trends');
        Route::get('/pace', [AnalyticsController::class, 'pace'])->name('pace');
        Route::get('/pedigree', [AnalyticsController::class, 'pedigree'])->name('pedigree');
        Route::get('/jockey', [AnalyticsController::class, 'jockey'])->name('jockey');
        Route::get('/horse', [AnalyticsController::class, 'horse'])->name('horse');
        Route::get('/stats', [AnalyticsController::class, 'stats'])->name('stats');
        Route::get('/roi', [AnalyticsController::class, 'roi'])->name('roi');
    });

    // 管理: DBビューア (読み取り専用)
    Route::prefix('admin/db')->name('admin.db.')->group(function () {
        Route::get('/',                [DbViewerController::class, 'index'])->name('index');
        Route::get('/stats',           [DbViewerController::class, 'stats'])->name('stats');
        Route::get('/schema',          [DbViewerController::class, 'schema'])->name('schema');
        Route::get('/table/{table}',   [DbViewerController::class, 'table'])->name('table');
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
