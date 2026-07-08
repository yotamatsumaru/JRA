<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\AnalyticsController;
use App\Http\Controllers\RaceController;
use App\Http\Controllers\ShutubaController;
use App\Http\Controllers\VenueController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes (Flutterアプリ用)
|--------------------------------------------------------------------------
|
| Laravel Sanctum によるトークン認証。
| 既存の routes/web.php (Blade画面・セッション認証) には一切影響しない、
| 完全に独立したAPIレイヤー。
|
| アクセスポリシー:
|   - ログイン        : 誰でも(POST /api/login)
|   - 閲覧系 (GET)    : 要ログイン (アプリは常にログイン運用のため)
|   - 印付け等の書込   : 要ログイン
|
*/

// =====================================================================
// 🔓 認証不要
// =====================================================================
Route::post('/login', [AuthController::class, 'login'])->name('api.login');

// =====================================================================
// 🔐 要トークン認証 (Sanctum)
// =====================================================================
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout'])->name('api.logout');
    Route::get('/user',    [AuthController::class, 'me'])->name('api.user');

    // 競馬場マスタ
    Route::get('/venues', [VenueController::class, 'index'])->name('api.venues.index');

    // レース一覧・詳細(結果確定後)
    Route::get('/races',        [RaceController::class, 'index'])->name('api.races.index');
    Route::get('/races/{race}', [RaceController::class, 'show'])->name('api.races.show');

    // 出馬表(予想対象レース)
    Route::prefix('shutuba')->name('api.shutuba.')->group(function () {
        Route::get('/',       [ShutubaController::class, 'index'])->name('index');
        Route::get('/{race}', [ShutubaController::class, 'show'])->name('show');

        // 印付け・自動印付け(既存メソッドをそのまま利用、元々JSONを返す設計)
        Route::post('/{race}/mark',          [ShutubaController::class, 'mark'])->name('mark');
        Route::post('/{race}/auto-mark',     [ShutubaController::class, 'autoMark'])->name('auto-mark');
        Route::post('/{race}/memo',          [ShutubaController::class, 'memo'])->name('memo');

        // オッズ推移・最新オッズ取得(既存メソッドをそのまま利用)
        Route::get('/{race}/odds-timeline',  [ShutubaController::class, 'oddsTimeline'])
            ->middleware('throttle:120,1')
            ->name('odds-timeline');
        Route::post('/{race}/capture-odds',  [ShutubaController::class, 'captureOdds'])
            ->middleware('throttle:60,1')
            ->name('capture-odds');
    });

    // 分析(競馬場別傾向・回収率シミュレーター)
    Route::prefix('analytics')->name('api.analytics.')->group(function () {
        Route::get('/venue', [AnalyticsController::class, 'venue'])->name('venue');
        Route::get('/roi',   [AnalyticsController::class, 'roi'])->name('roi');
    });
});
