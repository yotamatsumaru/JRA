<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Bankroll;
use App\Models\Bet;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * バンクロール（資金管理）
 *  - 月次予算 (target_stake) / 月次収支目標 (target_profit) を保存
 *  - 実績 (実投資・実収支・回収率) を bets テーブルから集計
 *  - 直近12ヶ月の予算 vs 実績の比較ビュー
 */
class BankrollController extends Controller
{
    /**
     * 一覧 + 当月の編集フォーム
     */
    public function index(Request $request): View
    {
        $userId = Auth::id();
        $now = now();
        $thisYm = $now->format('Y-m');

        // 直近12ヶ月のYMリスト（古い→新しい）
        $months = collect(range(11, 0))->map(fn($i) => $now->copy()->subMonths($i)->format('Y-m'));

        // 予算データ
        $budgets = Bankroll::where('user_id', $userId)
            ->whereIn('ym', $months)
            ->get()
            ->keyBy('ym');

        // 実績集計（投資総額/払戻/収支/件数）
        $actuals = Bet::where('user_id', $userId)
            ->where('is_settled', true)
            ->join('races', 'bets.race_id', '=', 'races.id')
            ->whereIn(DB::raw("DATE_FORMAT(races.race_date, '%Y-%m')"), $months)
            ->selectRaw("
                DATE_FORMAT(races.race_date, '%Y-%m') as ym,
                COUNT(*) as cnt,
                SUM(bets.total_stake)  as stake,
                SUM(bets.total_return) as ret,
                SUM(CASE WHEN bets.hit_count > 0 THEN 1 ELSE 0 END) as hits
            ")
            ->groupBy('ym')
            ->get()
            ->keyBy('ym');

        $rows = $months->map(function ($ym) use ($budgets, $actuals) {
            $b = $budgets[$ym] ?? null;
            $a = $actuals[$ym] ?? null;
            $stake  = (int) ($a->stake ?? 0);
            $ret    = (int) ($a->ret ?? 0);
            $profit = $ret - $stake;
            $tStake  = (int) ($b->target_stake ?? 0);
            $tProfit = (int) ($b->target_profit ?? 0);

            return [
                'ym'             => $ym,
                'target_stake'   => $tStake,
                'target_profit'  => $tProfit,
                'cnt'            => (int) ($a->cnt ?? 0),
                'stake'          => $stake,
                'return'         => $ret,
                'profit'         => $profit,
                'hits'           => (int) ($a->hits ?? 0),
                'stake_pct'      => $tStake > 0 ? round($stake / $tStake * 100, 1) : null,
                'profit_diff'    => $profit - $tProfit,
                'roi'            => $stake > 0 ? round($ret / $stake * 100, 1) : null,
                'notes'          => $b->notes ?? null,
                'is_current'     => $ym === now()->format('Y-m'),
            ];
        })->reverse()->values();

        // 当月の予算編集対象
        $current = Bankroll::firstOrNew(['user_id' => $userId, 'ym' => $thisYm]);

        // 全期間の合計
        $totalStake = $rows->sum('stake');
        $totalReturn = $rows->sum('return');
        $totalProfit = $totalReturn - $totalStake;

        return view('bankroll.index', compact(
            'rows', 'current', 'thisYm', 'totalStake', 'totalReturn', 'totalProfit'
        ));
    }

    /**
     * 月次予算を更新
     */
    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'ym'             => ['required', 'regex:/^\d{4}-\d{2}$/'],
            'target_stake'   => ['nullable', 'integer', 'min:0', 'max:100000000'],
            'target_profit'  => ['nullable', 'integer', 'min:-100000000', 'max:100000000'],
            'notes'          => ['nullable', 'string', 'max:2000'],
        ]);

        $br = Bankroll::updateOrCreate(
            ['user_id' => Auth::id(), 'ym' => $data['ym']],
            [
                'target_stake'  => (int) ($data['target_stake'] ?? 0),
                'target_profit' => (int) ($data['target_profit'] ?? 0),
                'notes'         => $data['notes'] ?? null,
            ]
        );

        try {
            AuditLog::record('bankroll.update', $br, [
                'ym' => $data['ym'],
                'target_stake' => (int) ($data['target_stake'] ?? 0),
                'target_profit' => (int) ($data['target_profit'] ?? 0),
            ]);
        } catch (\Throwable $e) { /* ignore */ }

        return redirect()->route('bankroll.index')->with('status', "{$data['ym']} の予算を更新しました");
    }

    /**
     * 月次予算を削除
     */
    public function destroy(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'ym' => ['required', 'regex:/^\d{4}-\d{2}$/'],
        ]);

        Bankroll::where('user_id', Auth::id())->where('ym', $data['ym'])->delete();

        try {
            AuditLog::record('bankroll.delete', null, ['ym' => $data['ym']]);
        } catch (\Throwable $e) { /* ignore */ }

        return redirect()->route('bankroll.index')->with('status', "{$data['ym']} の予算を削除しました");
    }
}
