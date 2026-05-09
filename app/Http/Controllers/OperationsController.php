<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Bankroll;
use App\Models\Bet;
use App\Models\OddsSnapshot;
use App\Models\Race;
use App\Models\SchedulerLog;
use App\Services\OddsSnapshotService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;

/**
 * 運用ダッシュボード (Phase 3-J/Z)
 *  - スケジューラ実行ログ
 *  - 監査ログ
 *  - 手動でのジョブ実行
 *  - リアルタイムオッズ表示用 JSON エンドポイント (Phase 3-I)
 */
class OperationsController extends Controller
{
    /**
     * 運用ダッシュボード
     */
    public function index(Request $request): View
    {
        // --- スケジューラ実行ログ (直近30件) ---
        $schedulerLogs = $this->safe(
            fn() => Schema::hasTable('scheduler_logs')
                ? SchedulerLog::orderByDesc('id')->limit(30)->get()
                : collect(),
            collect()
        );

        // --- 監査ログ (フィルタ + ページネーション) ---
        $auditLogs = $this->safe(function () use ($request) {
            if (!Schema::hasTable('audit_logs')) {
                return new \Illuminate\Pagination\LengthAwarePaginator([], 0, 50);
            }
            $auditQuery = AuditLog::with('user')->orderByDesc('id');
            if ($request->filled('action')) {
                $auditQuery->where('action', $request->input('action'));
            }
            if ($request->filled('user_id')) {
                $auditQuery->where('user_id', (int) $request->input('user_id'));
            }
            if ($request->filled('from')) {
                $auditQuery->whereDate('created_at', '>=', $request->input('from'));
            }
            if ($request->filled('to')) {
                $auditQuery->whereDate('created_at', '<=', $request->input('to'));
            }
            return $auditQuery->paginate(50)->withQueryString();
        }, new \Illuminate\Pagination\LengthAwarePaginator([], 0, 50));

        // --- スケジューラサマリ ---
        $schedSummary = $this->safe(function () {
            if (!Schema::hasTable('scheduler_logs')) {
                return ['success_24h' => 0, 'failed_24h' => 0, 'running' => 0, 'last_run' => null];
            }
            return [
                'success_24h' => SchedulerLog::where('status', SchedulerLog::STATUS_SUCCESS)
                    ->where('created_at', '>=', now()->subDay())->count(),
                'failed_24h'  => SchedulerLog::where('status', SchedulerLog::STATUS_FAILED)
                    ->where('created_at', '>=', now()->subDay())->count(),
                'running'     => SchedulerLog::where('status', SchedulerLog::STATUS_RUNNING)->count(),
                'last_run'    => SchedulerLog::orderByDesc('id')->first(),
            ];
        }, ['success_24h' => 0, 'failed_24h' => 0, 'running' => 0, 'last_run' => null]);

        // --- ジョブ別 直近実行 ---
        // ONLY_FULL_GROUP_BY 環境でも動くよう、サブクエリを使わず PHP 側で集約
        $jobsSummary = $this->safe(function () {
            if (!Schema::hasTable('scheduler_logs')) return collect();
            $latestIds = SchedulerLog::selectRaw('MAX(id) as last_id')
                ->groupBy('job')
                ->pluck('last_id')
                ->all();
            if (empty($latestIds)) return collect();
            return SchedulerLog::whereIn('id', $latestIds)->orderBy('job')->get();
        }, collect());

        // --- 監査ログサマリ ---
        $actionTotals = $this->safe(function () {
            if (!Schema::hasTable('audit_logs')) return collect();
            return AuditLog::selectRaw('action, COUNT(*) as cnt')
                ->where('created_at', '>=', now()->subDays(30))
                ->groupBy('action')->orderByDesc('cnt')->get();
        }, collect());

        $actions = AuditLog::ACTIONS;

        return view('operations.index', compact(
            'schedulerLogs', 'auditLogs', 'schedSummary',
            'jobsSummary', 'actionTotals', 'actions'
        ));
    }

    /**
     * 個別の集計が落ちても運用画面全体が500にならないようにするヘルパ
     */
    private function safe(\Closure $fn, $default)
    {
        try {
            return $fn();
        } catch (\Throwable $e) {
            Log::error('OperationsController safe() caught', [
                'message' => $e->getMessage(),
                'file'    => $e->getFile(),
                'line'    => $e->getLine(),
            ]);
            return $default;
        }
    }

    /**
     * 手動ジョブ実行 (Phase 3-J)
     *  許可ジョブだけ受け付ける
     */
    public function runJob(Request $request): RedirectResponse
    {
        $allowed = [
            'odds:capture'         => '--minutes=60 --limit=50',
            'bets:resettle'        => '',
            'netkeiba:date'        => '--date=today',
            'netkeiba:shutuba-date'=> '--date=tomorrow',
            'app:backup'           => '--keep=14',
            'jra:check'            => '',
        ];
        $job = (string) $request->input('job');
        if (!isset($allowed[$job])) {
            return back()->withErrors(['job' => '許可されていないジョブです: ' . $job]);
        }

        try {
            Artisan::call('scheduled:run', [
                '--job'  => $job,
                '--args' => $allowed[$job],
            ]);
            AuditLog::record('scheduler.run', null, ['job' => $job, 'manual' => true]);
            return back()->with('status', "{$job} を実行しました (運用ログを確認してください)");
        } catch (\Throwable $e) {
            return back()->withErrors(['job' => "ジョブ実行に失敗: {$e->getMessage()}"]);
        }
    }

    /**
     * リアルタイムオッズ JSON (Phase 3-I)
     *  最新スナップショットと推移を返却
     */
    public function odds(Request $request, Race $race): JsonResponse
    {
        $latest = OddsSnapshot::where('race_id', $race->id)
            ->orderByDesc('captured_at')
            ->first();

        $timeline = (new OddsSnapshotService(app(\App\Services\NetkeibaScraper::class)))
            ->timeline($race->id);

        return response()->json([
            'race_id'       => $race->id,
            'race_name'     => $race->name,
            'latest_at'     => $latest?->captured_at?->toIso8601String(),
            'latest_payload'=> $latest?->payload,
            'timeline'      => $timeline,
            'snapshot_count'=> OddsSnapshot::where('race_id', $race->id)->count(),
        ]);
    }

    /**
     * リアルタイムオッズ取得トリガー (Phase 3-I, 手動)
     */
    public function captureOdds(Request $request, Race $race): JsonResponse
    {
        try {
            $snap = (new OddsSnapshotService(app(\App\Services\NetkeibaScraper::class)))
                ->captureForRace($race);
            if (!$snap) {
                return response()->json(['ok' => false, 'message' => 'スナップショット作成不可 (発走後 / オッズなし)']);
            }
            AuditLog::record('odds.capture', $race, ['source' => 'manual']);
            return response()->json([
                'ok'         => true,
                'captured_at'=> $snap->captured_at->toIso8601String(),
                'payload'    => $snap->payload,
            ]);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 500);
        }
    }
}
