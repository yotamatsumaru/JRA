<?php

namespace App\Http\Controllers;

use App\Services\PedigreeRecommendService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * 推奨機能(血統+騎手+馬実績スコアリング)のコントローラ
 *
 * Phase 1: index(トップ) / settings(重み設定) のみ
 * Phase 2: conditions(B) / scan(C) を追加予定
 * Phase 3: race(A) を追加予定
 */
class PedigreeRecommendController extends Controller
{
    public function __construct(protected PedigreeRecommendService $svc) {}

    /**
     * 推奨機能トップ(3つの入口の案内 + 現在の重み表示)
     */
    public function index(Request $request): View
    {
        $settings = $this->svc->getSettings();
        return view('analytics.recommend.index', [
            'settings' => $settings,
        ]);
    }

    /**
     * 重み設定フォーム表示
     */
    public function settings(Request $request): View
    {
        $settings = $this->svc->getSettings();
        return view('analytics.recommend.settings', [
            'settings' => $settings,
            'defaults' => PedigreeRecommendService::DEFAULT_WEIGHTS,
        ]);
    }

    /**
     * 重み設定 保存
     */
    public function settingsStore(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'weights.pedigree' => 'required|integer|min:0|max:100',
            'weights.jockey'   => 'required|integer|min:0|max:100',
            'weights.horse'    => 'required|integer|min:0|max:100',
            'weights.roi'      => 'required|integer|min:0|max:100',
            'min_runs'         => 'required|integer|min:1|max:500',
        ]);
        $this->svc->saveSettings($validated['weights'], (int) $validated['min_runs']);

        return redirect()->route('analytics.recommend.settings')->with('success', '設定を保存しました');
    }

    /**
     * 重み設定 デフォルトに戻す
     */
    public function settingsReset(Request $request): RedirectResponse
    {
        $this->svc->saveSettings(
            PedigreeRecommendService::DEFAULT_WEIGHTS,
            PedigreeRecommendService::DEFAULT_MIN_RUNS
        );
        return redirect()->route('analytics.recommend.settings')->with('success', 'デフォルトに戻しました');
    }
}
